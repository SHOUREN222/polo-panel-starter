<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionService
{
    /**
     * Award commission to the referrer of a paid order's buyer, if any, and
     * (when 三级分销 is enabled) to that referrer's own referrer and theirs
     * in turn — each tier gets a configured share of the same commission
     * pool computed from the order total, not additional revenue on top.
     * Runs on every paid order (new purchase or renewal) unless 佣金仅首次
     * 发放 is enabled, in which case only the buyer's very first paid order
     * generates any commission at all.
     */
    public function awardForOrder(Order $order): void
    {
        // Master switch for the whole referral system — off means no
        // commission is ever generated, matching the hidden 我的邀请 entry.
        if (! Setting::getBool('referral_enabled', true)) {
            return;
        }

        $buyer = $order->user;

        if ($buyer->referred_by_user_id === null) {
            return;
        }

        if (Setting::getBool('commission_first_payment_only', false) && $this->hasPriorPaidOrder($order)) {
            return;
        }

        $rate = (int) Setting::get('commission_rate', 10);
        if ($rate <= 0) {
            return;
        }

        $pool = (int) round($order->total_amount * $rate / 100);
        if ($pool <= 0) {
            return;
        }

        $tiers = $this->resolveTiers($buyer);

        if (Setting::getBool('three_tier_commission_enabled', false)) {
            $rates = [
                (int) Setting::get('commission_tier1_rate', 100),
                (int) Setting::get('commission_tier2_rate', 0),
                (int) Setting::get('commission_tier3_rate', 0),
            ];
        } else {
            $rates = [100, 0, 0];
        }

        foreach ($tiers as $level => $referrer) {
            $share = (int) round($pool * $rates[$level] / 100);
            if ($share <= 0 || $referrer === null) {
                continue;
            }

            $this->createCommission($referrer, $buyer, $order, $share);
        }
    }

    /**
     * @return array{0:?User,1:?User,2:?User} direct referrer, their
     * referrer, and that person's referrer — null past the actual chain.
     */
    private function resolveTiers(User $buyer): array
    {
        $tier1 = $buyer->referredBy;
        $tier2 = $tier1?->referredBy;
        $tier3 = $tier2?->referredBy;

        return [$tier1, $tier2, $tier3];
    }

    private function hasPriorPaidOrder(Order $order): bool
    {
        return Order::where('user_id', $order->user_id)
            ->where('status', Order::STATUS_PAID)
            ->where('id', '!=', $order->id)
            ->exists();
    }

    private function createCommission(User $referrer, User $buyer, Order $order, int $amount): void
    {
        $holdDays = (int) Setting::get('commission_hold_days', 0);

        Commission::create([
            'user_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'order_id' => $order->id,
            'amount' => $amount,
            'status' => $holdDays > 0 ? Commission::STATUS_PENDING : Commission::STATUS_CONFIRMED,
            'confirmed_at' => $holdDays > 0 ? null : now(),
        ]);
    }

    /**
     * Confirm any pending commissions whose hold period has elapsed. Called
     * lazily when a user views their invite page rather than via a
     * scheduled job — cheap enough at this scale and needs no cron setup.
     */
    public function confirmDueCommissions(User $user): void
    {
        $holdDays = (int) Setting::get('commission_hold_days', 0);

        Commission::where('user_id', $user->id)
            ->where('status', Commission::STATUS_PENDING)
            ->where('created_at', '<=', now()->subDays($holdDays))
            ->update(['status' => Commission::STATUS_CONFIRMED, 'confirmed_at' => now()]);
    }

    /**
     * Move all confirmed, not-yet-transferred commission into the user's
     * balance. Returns the transferred amount in cents. This is the only
     * way commission leaves the ledger — the panel has no cash withdrawal
     * (see untransferredCommission()); commission is store credit spent on
     * plans via account balance.
     */
    public function transfer(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $commissions = Commission::where('user_id', $user->id)
                ->where('status', Commission::STATUS_CONFIRMED)
                ->whereNull('transferred_at')
                ->lockForUpdate()
                ->get();

            $total = $commissions->sum('amount');

            if ($total <= 0) {
                throw ValidationException::withMessages(['commission' => '没有可划转的佣金']);
            }

            Commission::whereIn('id', $commissions->pluck('id'))->update(['transferred_at' => now()]);
            $user->changeBalance($total, \App\Models\BalanceTransaction::TYPE_COMMISSION, '佣金划转');

            return $total;
        });
    }

    /**
     * Confirmed commission not yet moved into the user's spendable balance —
     * i.e. the whole amount 划转到余额 (transfer) will move. The panel has no
     * cash-withdrawal path by design: commission is store credit that can
     * only be spent on plans via account balance, never paid out, so there's
     * nothing to subtract here.
     */
    public function untransferredCommission(User $user): int
    {
        return (int) Commission::where('user_id', $user->id)
            ->where('status', Commission::STATUS_CONFIRMED)
            ->whereNull('transferred_at')
            ->sum('amount');
    }
}
