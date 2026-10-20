<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\Order;
use App\Models\TenantWholesale;
use App\Models\TenantWholesaleSettlement;
use App\Models\TenantWholesaleTopup;
use App\Models\User;
use App\Notifications\WholesaleCreditExhausted;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * 批发结算 (wholesale settlement) for 分站 orders.
 *
 * **The discount is taken once, at top-up.** An agent on 30% pays ¥70 cash for
 * ¥100 of 进货余额, and that balance is denominated in retail: a delivered
 * order consumes its full retail total, 1:1. So the balance reads as "how much
 * more I can sell", which is the number an agent actually needs, and it matches
 * how the older 代理 system already prices gift cards (see AgentService).
 *
 * Until 2026-07-19 the discount was applied here instead, at settlement. Both
 * are self-consistent — ¥1 paid buys ¥1.43 of retail either way — but 站长 was
 * quoting agents the discounted price at top-up while the code discounted again
 * on delivery, which would have handed every agent a second 30% the platform
 * never charged for. If you are tempted to reintroduce a discount in
 * wholesaleCost(), that is the bug you are recreating.
 *
 * All methods are **no-ops in central (主站) context**.
 */
class WholesaleService
{
    public function balance(string $tenantId): int
    {
        return (int) (TenantWholesale::where('tenant_id', $tenantId)->value('balance') ?? 0);
    }

    /**
     * 站长 tops up a 分站's 进货余额 (when the agent pays).
     *
     * $amount is FACE value — what the agent may go on to sell. What they hand
     * over in cash is less, per their discount: see cashPriceFor(). $cashCollected
     * is that cash figure, logged alongside the credit purely for the 分站
     * admin's own record (see TenantWholesaleTopup) — nothing here depends on it.
     */
    public function credit(string $tenantId, int $amount, ?int $cashCollected = null): int
    {
        $row = TenantWholesale::firstOrCreate(['tenant_id' => $tenantId], ['balance' => 0]);
        $row->increment('balance', $amount);

        TenantWholesaleTopup::create([
            'tenant_id' => $tenantId,
            'amount' => $amount,
            'cash_collected' => $cashCollected,
        ]);

        return (int) $row->fresh()->balance;
    }

    /** What an agent hands over in cash for $faceValue of 进货余额. */
    public function cashPriceFor(object $tenant, int $faceValue): int
    {
        $d = max(0, min(90, (int) ($tenant->agent_discount ?? 0)));

        return (int) round($faceValue * (100 - $d) / 100);
    }

    /**
     * Gift-card face value a 分站 may still safely issue, in cents.
     *
     * Minting deliberately does NOT debit 进货余额: the card only becomes an
     * order later, and that order consumes the balance then — charging at mint
     * too would bill the agent twice for one sale. What minting can do is let
     * an agent oversell: issue ¥5000 of cards against ¥400 of balance, and
     * their customers redeem fine and then hit "开通失败" at checkout, one
     * after another, on our nodes.
     *
     * Balance is denominated in retail, so the cap is the balance less every
     * yuan already promised to a customer but not yet consumed by a delivered
     * order — that's unredeemed gift cards (redeeming converts card face value
     * into User.balance) PLUS every customer's current account balance,
     * regardless of how it got there (gift card redemption or a direct
     * gateway top-up). Both are money a customer can spend on our nodes at
     * any moment, and wholesale only actually debits at delivery — so until
     * then, both must count against headroom or an agent can effectively
     * double-spend the same 进货余额 (redeem ¥100, spend ¥90, and the
     * un-spent ¥10 would otherwise vanish from every check: no longer an
     * unredeemed card, not yet debited by an order).
     */
    public function giftCardHeadroom(object $tenant): int
    {
        $outstandingCards = (int) GiftCard::where('redeemed', false)->sum('amount');
        $customerBalances = (int) User::sum('balance');

        return max(0, $this->balance($tenant->getTenantKey()) - $outstandingCards - $customerBalances);
    }

    /**
     * What a delivered order consumes from 进货余额: its full retail total.
     *
     * Not discounted — the agent already paid the discount when they bought the
     * balance. See the class docblock.
     */
    public function wholesaleCost(Order $order): int
    {
        return (int) $order->total_amount;
    }

    /**
     * Atomically reserve the current tenant's wholesale cost for an order.
     * Repeated calls reuse the same central ledger row and never debit twice.
     */
    public function reserve(Order $order): ?TenantWholesaleSettlement
    {
        if (! ($tenant = tenant())) {
            return null;
        }

        $tenantId = (string) $tenant->getTenantKey();
        $cost = $this->wholesaleCost($order);

        $settlement = $this->centralConnection()->transaction(function () use ($tenantId, $order, $cost) {
            $existing = TenantWholesaleSettlement::where('tenant_id', $tenantId)
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status !== TenantWholesaleSettlement::STATUS_RELEASED) {
                $this->assertSettlementMatches($existing, $order, $cost);

                return $existing;
            }

            if ($cost > 0) {
                $debited = TenantWholesale::where('tenant_id', $tenantId)
                    ->where('balance', '>=', $cost)
                    ->decrement('balance', $cost);

                if ($debited !== 1) {
                    return null;
                }
            }

            if ($existing) {
                $this->assertSettlementMatches($existing, $order, $cost);
                $existing->update([
                    'status' => TenantWholesaleSettlement::STATUS_RESERVED,
                    'reserved_at' => now(),
                    'completed_at' => null,
                    'released_at' => null,
                ]);

                return $existing->fresh();
            }

            return TenantWholesaleSettlement::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->getKey(),
                'trade_no' => $order->trade_no,
                'amount' => $cost,
                'status' => TenantWholesaleSettlement::STATUS_RESERVED,
                'reserved_at' => now(),
            ]);
        });

        if (! $settlement) {
            $this->rejectInsufficientCredit($tenant, $cost);
        }

        return $settlement;
    }

    /** Return an existing ledger row without creating or debiting one. */
    public function existing(Order $order): ?TenantWholesaleSettlement
    {
        if (! ($tenant = tenant())) {
            return null;
        }

        $settlement = TenantWholesaleSettlement::where('tenant_id', $tenant->getTenantKey())
            ->where('order_id', $order->getKey())
            ->first();

        if ($settlement) {
            $this->assertSettlementMatches($settlement, $order, $this->wholesaleCost($order));
        }

        return $settlement;
    }

    /** Mark a reserved debit delivered. Idempotent and central-context safe. */
    public function complete(?TenantWholesaleSettlement $settlement): void
    {
        if (! $settlement) {
            return;
        }

        TenantWholesaleSettlement::whereKey($settlement->getKey())
            ->where('status', TenantWholesaleSettlement::STATUS_RESERVED)
            ->update([
                'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
                'completed_at' => now(),
                'released_at' => null,
            ]);
    }

    /**
     * Refund a reservation when tenant fulfillment rolls back. The ledger row
     * remains as an audit record and can be reserved again by a later retry.
     */
    public function release(?TenantWholesaleSettlement $settlement): void
    {
        if (! $settlement) {
            return;
        }

        $this->centralConnection()->transaction(function () use ($settlement) {
            $locked = TenantWholesaleSettlement::whereKey($settlement->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== TenantWholesaleSettlement::STATUS_RESERVED) {
                return;
            }

            if ($locked->amount > 0) {
                TenantWholesale::where('tenant_id', $locked->tenant_id)
                    ->increment('balance', $locked->amount);
            }

            $locked->update([
                'status' => TenantWholesaleSettlement::STATUS_RELEASED,
                'released_at' => now(),
            ]);
        });
    }

    /** Throw if the current 分站 can't cover this order's wholesale. No-op on 主站. */
    public function assertCanSettle(Order $order): void
    {
        if (! ($tenant = tenant())) {
            return;
        }
        $cost = $this->wholesaleCost($order);
        if ($this->balance($tenant->getTenantKey()) < $cost) {
            $this->rejectInsufficientCredit($tenant, $cost);
        }
    }

    private function rejectInsufficientCredit(object $tenant, int $cost): never
    {
        // The agent is the one who has to act, and they have no other way of
        // finding out before a customer complains that delivery is blocked.
        $this->alertAgentOutOfCredit($tenant, $cost);

        // Do not reveal the platform/wholesale relationship to the customer.
        throw ValidationException::withMessages([
            'wholesale' => '开通失败，请联系客服处理（订单已支付，稍后可重试）。',
        ]);
    }

    /** Tell the 分站's own admins their credit ran out, once per hour at most. */
    private function alertAgentOutOfCredit(object $tenant, int $cost): void
    {
        $key = 'wholesale-empty:'.$tenant->getTenantKey();

        if (! Cache::add($key, 1, now()->addHour())) {
            return; // already warned recently — don't spam on every failed order
        }

        try {
            $balance = $this->balance($tenant->getTenantKey());
            User::where('is_admin', true)->get()
                ->each->notify(new WholesaleCreditExhausted($balance, $cost));
        } catch (\Throwable $e) {
            // Never let a notification failure block the order path.
            report($e);
        }
    }

    private function assertSettlementMatches(TenantWholesaleSettlement $settlement, Order $order, int $cost): void
    {
        if ($settlement->trade_no !== $order->trade_no || $settlement->amount !== $cost) {
            throw new \LogicException('Wholesale settlement does not match tenant order.');
        }
    }

    private function centralConnection(): Connection
    {
        return (new TenantWholesaleSettlement)->getConnection();
    }
}
