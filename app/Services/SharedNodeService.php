<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting;
use App\Models\TrafficLog;
use App\Models\TrafficPack;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SharedNodeService
{
    /**
     * Grant/extend a user's access to a shared plan after a paid order.
     * Renewals extend from the current expiry (if still active) instead of
     * from now, so paying early never costs the user remaining time.
     */
    public function extendAccess(User $user, Order $order, Plan $plan): void
    {
        $base = ($user->plan_id === $plan->id && $user->plan_expired_at?->isFuture())
            ? $user->plan_expired_at
            : now();

        $user->update([
            'plan_id' => $plan->id,
            'plan_expired_at' => $this->addPeriod($base, $order->period),
            // Only a brand-new grant sets this — it's the permanent anchor
            // date quota windows are computed from (see currentWindowStart()),
            // a same-plan renewal must never move it.
            'shared_traffic_reset_at' => $user->shared_traffic_reset_at ?? now()->toDateString(),
        ]);
    }

    /**
     * Switch a user onto a different shared plan, starting a fresh period
     * from now — unlike extendAccess(), this never carries over remaining
     * time from the old plan, because that remaining value was already
     * credited as a discount on the change order's price (see
     * OrderService::createChangeOrder()'s proration calc). Carrying over
     * *and* crediting it would double-count the same value.
     *
     * Usage resets too — a different plan is a different quota, so last
     * plan's counted bytes shouldn't count against the new one.
     */
    public function switchPlan(User $user, Order $order, Plan $newPlan): void
    {
        $user->update([
            'plan_id' => $newPlan->id,
            'plan_expired_at' => $this->addPeriod(now(), $order->period),
            'shared_traffic_reset_at' => now()->toDateString(),
        ]);
    }

    /**
     * Bytes used on shared servers since the current quota window started —
     * server-side `rate` multiplier applied, same accounting
     * TrafficLog::totalBytes() uses everywhere else. Shared-node rows have
     * no subscription_instance_id (that's how push() tells them apart from
     * dedicated-ISP traffic).
     */
    public function sharedTrafficUsedBytes(User $user): int
    {
        $since = $this->currentWindowStart($user);

        return TrafficLog::where('user_id', $user->id)
            ->whereNull('subscription_instance_id')
            ->where('date', '>=', $since)
            ->get()
            ->sum(fn (TrafficLog $log) => $log->totalBytes());
    }

    /**
     * The plan's base quota plus any traffic packs — one-time packs count
     * forever once bought, resettable packs only count while their
     * purchase falls inside the current window (buy one, use it up before
     * the next reset, buy another next time).
     */
    public function effectiveSharedQuotaBytes(User $user): int
    {
        $base = (int) ($user->plan?->transfer_enable ?? 0);
        $windowStart = $this->currentWindowStart($user);

        // Only shared-pool packs (no instance) count toward the shared quota;
        // dedicated-ISP packs are scoped to their own line.
        $oneTime = (int) TrafficPack::where('user_id', $user->id)
            ->whereNull('subscription_instance_id')
            ->where('kind', TrafficPack::KIND_ONE_TIME)
            ->sum('bytes');

        $resettable = (int) TrafficPack::where('user_id', $user->id)
            ->whereNull('subscription_instance_id')
            ->where('kind', TrafficPack::KIND_RESETTABLE)
            ->where('created_at', '>=', $windowStart)
            ->sum('bytes');

        return $base + $oneTime + $resettable;
    }

    public function hasExceededSharedQuota(User $user): bool
    {
        $quota = $this->effectiveSharedQuotaBytes($user);
        if ($quota <= 0) {
            return false;
        }

        return $this->sharedTrafficUsedBytes($user) >= $quota;
    }

    public function getAvailableServers(User $user): Collection
    {
        if (! $user->hasActiveSharedPlan()) {
            return collect();
        }

        $groupId = $user->plan?->group_id;
        if (! $groupId) {
            return collect();
        }

        return Server::visible()->forGroup($groupId)->orderBy('name')->get();
    }

    /**
     * Inverse of getAvailableServers() — every user currently entitled to
     * connect to a given shared server, for handing to a node agent
     * (UniProxyController) so it knows who to accept.
     */
    public function getEligibleUsers(Server $server): Collection
    {
        // Resolve the shared plan ids for this node's group FIRST (Plan is
        // central-pinned), then match users by plan_id — a plain whereIn works
        // in a tenant DB too, unlike whereHas() which can't cross DB connections.
        $planIds = Plan::where('group_id', $server->group_id)
            ->where('type', Plan::TYPE_SHARED)
            ->pluck('id')
            ->all();

        if (empty($planIds)) {
            return collect();
        }

        $candidates = User::where('banned', false)
            ->where('plan_expired_at', '>', now())
            ->whereIn('plan_id', $planIds)
            ->get();

        return $candidates->reject(fn (User $user) => $this->hasExceededSharedQuota($user))->values();
    }

    /**
     * Start of the user's current traffic-quota window, computed live from
     * their plan's traffic_reset_mode — no scheduled job needed, the
     * boundary is a pure function of "now":
     *
     * - anniversary (default): the most recent monthly anniversary of the
     *   day the user first got this plan (shared_traffic_reset_at, set once
     *   and never touched again — see extendAccess()).
     * - never: the grant date itself — one running total for the whole
     *   subscription, it doesn't recur.
     */
    private function currentWindowStart(User $user): Carbon
    {
        $grantedAt = $user->shared_traffic_reset_at
            ? Carbon::parse($user->shared_traffic_reset_at)->startOfDay()
            : $user->created_at->copy()->startOfDay();

        $mode = $user->plan?->traffic_reset_mode ?? Plan::RESET_MODE_ANNIVERSARY;

        return match ($mode) {
            Plan::RESET_MODE_NEVER => $grantedAt,
            default => $this->mostRecentMonthlyOccurrence($grantedAt->day, $grantedAt),
        };
    }

    /**
     * The most recent date (not after now) that falls on the given
     * day-of-month, clamped to each month's actual length (e.g. day 31
     * lands on Feb 28/29) — optionally never earlier than $notBefore.
     */
    private function mostRecentMonthlyOccurrence(int $day, ?Carbon $notBefore = null): Carbon
    {
        $now = now();
        $candidate = $now->copy()->startOfDay()->day(min($day, $now->daysInMonth));

        if ($candidate->greaterThan($now)) {
            $prevMonth = $candidate->copy()->subMonthNoOverflow();
            $candidate = $prevMonth->day(min($day, $prevMonth->daysInMonth));
        }

        return $notBefore && $candidate->lessThan($notBefore) ? $notBefore : $candidate;
    }

    private function addPeriod(\DateTimeInterface $from, string $period): \DateTimeInterface
    {
        $carbon = \Illuminate\Support\Carbon::instance($from);

        return match ($period) {
            'month' => $carbon->copy()->addMonth(),
            'quarter' => $carbon->copy()->addMonths(3),
            'half_year' => $carbon->copy()->addMonths(6),
            'year' => $carbon->copy()->addYear(),
            'two_year' => $carbon->copy()->addYears(2),
            'three_year' => $carbon->copy()->addYears(3),
            default => $carbon->copy()->addMonth(),
        };
    }
}
