<?php

namespace App\Services\Provisioners;

use App\Models\ProvisionTask;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Services\OnipProvisioningService;
use Illuminate\Support\Str;

/**
 * Real provisioner backed by the onip.cc static-proxy vendor API. Unlike
 * StubProvisioner, this actually spends the account's balance — every call
 * to provision() places a real order.
 *
 * Uses sync=true so the vendor's response includes the provisioned
 * instance immediately, fitting ProcessProvisionTask's existing
 * synchronous provision()-returns-a-result contract without needing a
 * public callback URL. The vendor's own recommended default is sync=false
 * + webhook (see OnipCallbackController for the IP-change/expiry side of
 * that), which is worth switching to once this panel has a public URL for
 * the vendor to call back to.
 */
class OnipProvisioner implements ProvisionerInterface
{
    private const DAYS_PER_PERIOD = [
        'month' => 30,
        'quarter' => 90,
        'half_year' => 180,
        'year' => 365,
        'two_year' => 730,
        'three_year' => 1095,
    ];

    public function __construct(private readonly OnipProvisioningService $onip) {}

    /**
     * The external order number sent to onip. It is onip's idempotency key, so
     * it MUST be stable across retries of the same task (a retry must not buy a
     * second line) — but it must ALSO be unique across every install that
     * shares this onip account. Task ids alone are not: a dev/test install's
     * `task-6` collides with production's, and onip then rejects the real
     * order with EXTERNAL_ORDER_EXISTS, leaving a paying customer unprovisioned.
     *
     * So: a per-install random prefix (generated once, stored in settings —
     * same pattern as node_comm_key) + the task id.
     */
    private function externalOrderNo(ProvisionTask $task): string
    {
        $prefix = Setting::get('onip_order_prefix');

        if (! $prefix) {
            $prefix = strtolower(Str::random(8));
            Setting::set('onip_order_prefix', $prefix);
        }

        return $prefix.'-task-'.$task->id;
    }

    public function provision(ProvisionTask $task): array
    {
        $profile = $task->payload['resource_profile'] ?? [];
        $period = $task->payload['period'] ?? 'month';
        $days = self::DAYS_PER_PERIOD[$period] ?? 30;

        $params = array_filter([
            'lineId' => $profile['lineId'] ?? null,
            'countryCode' => $profile['countryCode'] ?? null,
            'cityCode' => $profile['cityCode'] ?? null,
            'businessType' => $profile['businessType'] ?? null,
            'ispType' => $profile['ispType'] ?? null,
            'tag' => $profile['tag'] ?? null,
            'quantity' => 1,
            'days' => $days,
            'orderNo' => $this->externalOrderNo($task),
            'sync' => true,
        ], fn ($v) => $v !== null);

        $order = $this->onip->createOrder($params);
        $instance = $order['instances'][0] ?? null;

        if (! $instance) {
            throw new \RuntimeException('onip order created but no instance returned (order still processing?): '.($order['orderNo'] ?? 'unknown'));
        }

        // The result REPLACES the instance meta, so carry forward the country
        // and onip monthly cost from the previous meta on a renewal re-provision
        // (where the resolved profile is only provider+lineId, no snapshot) —
        // otherwise renewals would lose the country/cost and mis-price the line.
        $priorMeta = $task->subscriptionInstance?->meta ?? [];

        return [
            'ip' => $instance['ip'],
            'port' => $instance['port'],
            'protocol' => 'socks5',
            'username' => $instance['username'],
            'password' => $instance['password'],
            'proxy_id' => $instance['proxyId'],
            'provider' => 'onip',
            'lineId' => $profile['lineId'] ?? null,
            'country' => $profile['countryCode'] ?? ($profile['line_snapshot']['countryCode'] ?? ($priorMeta['country'] ?? null)),
            'city' => $profile['cityCode'] ?? ($profile['line_snapshot']['cityCode'] ?? ($priorMeta['city'] ?? null)),
            // onip monthly cost (yuan) for the picked line — needed to re-derive
            // an auto-price on renewal (see Plan::sellPrice / OrderService).
            'onip_price' => $profile['line_snapshot']['price'] ?? ($priorMeta['onip_price'] ?? null),
            'provisioned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Renewing before expiry keeps the same onip proxyId (ProvisionService
     * never re-provisions in that case) — so the ONLY thing that needs to
     * happen here is telling onip to actually push its own lease out by the
     * new period. Previously nothing did this at all: our own expired_at
     * moved, onip's never did, so onip would release/reassign the IP on its
     * original schedule regardless of what the customer had just paid for.
     */
    public function renewExisting(SubscriptionInstance $instance, string $period): void
    {
        $proxyId = $instance->meta['proxy_id'] ?? null;

        if (! $proxyId) {
            throw new \RuntimeException("Cannot renew onip instance {$instance->id}: no proxy_id in its meta.");
        }

        $days = self::DAYS_PER_PERIOD[$period] ?? 30;

        $prefix = Setting::get('onip_order_prefix');
        if (! $prefix) {
            $prefix = strtolower(Str::random(8));
            Setting::set('onip_order_prefix', $prefix);
        }

        $this->onip->renewInstance($proxyId, $days, $prefix.'-renew-'.$instance->last_order_id);
    }
}
