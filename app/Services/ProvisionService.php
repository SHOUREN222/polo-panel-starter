<?php

namespace App\Services;

use App\Jobs\ProcessProvisionTask;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ProvisionTask;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Services\Provisioners\ProvisionerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProvisionService
{
    public function __construct(private readonly ProvisionerInterface $provisioner) {}

    /**
     * Create (or renew) a dedicated-ISP subscription instance for a paid
     * order, and enqueue a provision task for a worker to fulfill.
     */
    public function provisionForOrder(Order $order, Plan $plan): SubscriptionInstance
    {
        [$instance, $task] = DB::transaction(function () use ($order, $plan) {
            $instance = SubscriptionInstance::create([
                'user_id' => $order->user_id,
                'plan_id' => $plan->id,
                'created_order_id' => $order->id,
                'last_order_id' => $order->id,
                // Traffic is granted for the whole purchased period up front
                // (季付 = 3× the plan's monthly quota, etc.), usable freely
                // across the period — no monthly reset. Renewal stacks more.
                'transfer_enable' => $plan->transfer_enable * $this->monthsForPeriod($order->period),
                'status' => SubscriptionInstance::STATUS_PENDING,
                'expired_at' => $this->calculateExpiration($order->period),
            ]);

            $task = $this->createTask($order, $instance, $plan);

            return [$instance, $task];
        });

        $this->dispatchIfEnabled($task);

        return $instance;
    }

    /**
     * Renew an instance the user already owns. The upstream provisioning
     * API can only keep the same IP while the lease hasn't lapsed yet — once
     * an IP expires it's released back to the provider's pool, so a renewal
     * after expiry must go through provisioning again and gets a new IP,
     * same as a fresh purchase (the instance/token/subscription URL stay
     * the same, only the underlying IP changes).
     */
    public function renewInstance(Order $order, SubscriptionInstance $instance): SubscriptionInstance
    {
        $keepsSameIp = $instance->expired_at?->isFuture() ?? false;
        $newExpiry = $this->addPeriod($keepsSameIp ? $instance->expired_at : now(), $order->period);
        $addedTraffic = $instance->plan->transfer_enable * $this->monthsForPeriod($order->period);

        if ($keepsSameIp) {
            // Tell the supplier to actually extend ITS lease on this IP
            // BEFORE touching our own row. Previously nothing did this — our
            // expired_at moved out, onip's never did, so onip would release
            // or reassign the IP on its original schedule regardless of what
            // the customer just paid for. Unguarded (unlike the relay sync
            // below): if the upstream extension fails, the whole renewal
            // must fail too, so the payment transaction rolls back instead
            // of quietly taking the customer's money for time onip never
            // actually granted.
            if (($instance->meta['provider'] ?? null) === 'onip') {
                $this->provisioner->renewExisting($instance, $order->period);
            }

            $instance->update([
                'last_order_id' => $order->id,
                'status' => SubscriptionInstance::STATUS_ACTIVE,
                'expired_at' => $newExpiry,
                // Renewed before expiry: stack the new period's traffic onto
                // the unused balance, keeping u/d — the customer never loses
                // what they already paid for and haven't used.
                'transfer_enable' => $instance->transfer_enable + $addedTraffic,
            ]);

            // Unlike the lapsed-renewal branch below, this path never goes
            // through a ProvisionTask (same IP, nothing to provision) — so
            // nothing else re-pushes the relay config. Needed whenever this
            // instance had fallen out of the last pushed config (e.g. it had
            // briefly lapsed before this renewal landed). Guarded the same
            // way as the post-provision sync: a node/SSH hiccup must not
            // fail the (already-committed) renewal.
            if (($instance->meta['provider'] ?? null) === 'onip') {
                try {
                    app(NodeRelayService::class)->sync();
                } catch (\Throwable $e) {
                    Log::warning('relay sync after renewal failed', ['instance_id' => $instance->id, 'error' => $e->getMessage()]);
                }
            }

            return $instance->fresh();
        }

        $task = DB::transaction(function () use ($order, $instance, $newExpiry, $addedTraffic) {
            $instance->update([
                'last_order_id' => $order->id,
                'status' => SubscriptionInstance::STATUS_PENDING,
                'expired_at' => $newExpiry,
                // Lapsed before renewing: the old IP was released and its
                // unused balance forfeited, so start fresh with just this
                // period's traffic.
                'transfer_enable' => $addedTraffic,
                'u' => 0,
                'd' => 0,
            ]);

            return $this->createTask($order, $instance, $instance->plan);
        });

        $this->dispatchIfEnabled($task);

        return $instance->fresh();
    }

    /**
     * The "静态 IP 自动开通" plugin toggle in 插件管理. When disabled, the
     * task/instance are still created (so nothing is lost) but stay pending
     * — no worker job runs until the plugin is switched back on.
     */
    private function dispatchIfEnabled(ProvisionTask $task): void
    {
        if (Setting::getBool('plugin.auto_provision_enabled', true)) {
            ProcessProvisionTask::dispatch($task->id);
        }
    }

    private function createTask(Order $order, SubscriptionInstance $instance, Plan $plan): ProvisionTask
    {
        return ProvisionTask::create([
            'order_id' => $order->id,
            'subscription_instance_id' => $instance->id,
            'status' => ProvisionTask::STATUS_PENDING,
            'payload' => [
                'plan_id' => $plan->id,
                'resource_profile' => $this->resolveResourceProfile($order, $instance, $plan),
                'period' => $order->period,
            ],
        ]);
    }

    /**
     * Which line to provision, in priority order:
     * 1. The customer's own real-time pick on this order (a fresh purchase
     *    of a dynamic-region plan).
     * 2. The line the instance was already provisioned on before (an
     *    expired renewal re-provisioning — same region, new IP; see
     *    OnipProvisioner, which round-trips lineId back into instance meta).
     * 3. The plan's own fixed line (ordinary, non-dynamic dedicated-ISP plans).
     */
    private function resolveResourceProfile(Order $order, SubscriptionInstance $instance, Plan $plan): ?array
    {
        if ($order->selected_resource_profile) {
            return $order->selected_resource_profile;
        }

        if (! empty($instance->meta['lineId'])) {
            return ['provider' => 'onip', 'lineId' => $instance->meta['lineId']];
        }

        return $plan->resource_profile;
    }

    private function calculateExpiration(string $period): \DateTimeInterface
    {
        return $this->addPeriod(now(), $period);
    }

    /**
     * How many months of traffic a purchase of this period grants — the
     * per-period traffic multiplier applied to the plan's monthly quota.
     */
    private function monthsForPeriod(string $period): int
    {
        return match ($period) {
            'quarter' => 3,
            'half_year' => 6,
            'year' => 12,
            'two_year' => 24,
            'three_year' => 36,
            default => 1,
        };
    }

    /**
     * Fixed day counts per period, matching onip's own lease model
     * (OnipProvisioner::DAYS_PER_PERIOD) exactly. A dedicated-ISP instance's
     * expiry MUST use the same day math as the upstream, or the panel shows
     * an expiry a few days LATER than onip actually holds the IP (calendar
     * months average 30.44 days vs onip's flat 30), and the customer's line
     * dies while the panel still reads "active". Traffic quota still scales by
     * whole months (monthsForPeriod) — that's data allowance, not lease time.
     */
    private const DAYS_PER_PERIOD = [
        'month' => 30,
        'quarter' => 90,
        'half_year' => 180,
        'year' => 365,
        'two_year' => 730,
        'three_year' => 1095,
    ];

    private function addPeriod(\DateTimeInterface $from, string $period): \DateTimeInterface
    {
        $days = self::DAYS_PER_PERIOD[$period] ?? 30;

        return Carbon::instance($from)->copy()->addDays($days);
    }
}
