<?php

namespace App\Services;

use App\Models\BalanceTransaction;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reseller (代理) operations. An agent generates gift cards from their own
 * balance at a discount (agent_discount %) and resells the codes at face value;
 * the margin is their profit. Reuses the existing gift-card + balance-ledger
 * machinery — an agent is a customer with this extra capability, not an admin.
 */
class AgentService
{
    public const MAX_QUANTITY = 100;
    public const MIN_FACE_CENTS = 100; // ¥1.00

    /**
     * The per-card cost to the agent for a given face value — face × (1 − discount).
     */
    public function unitCost(User $agent, int $faceCents): int
    {
        $discount = max(0, min(90, (int) $agent->agent_discount));

        return (int) round($faceCents * (100 - $discount) / 100);
    }

    /**
     * Generate $quantity gift cards each worth $faceCents, charging the
     * discounted total to the agent's balance atomically.
     *
     * @return Collection<int, GiftCard>
     */
    public function generateCards(User $agent, int $faceCents, int $quantity): Collection
    {
        if (! $agent->isAgent()) {
            throw ValidationException::withMessages(['agent' => '你还不是代理，无法生成卡密']);
        }
        if ($faceCents < self::MIN_FACE_CENTS) {
            throw ValidationException::withMessages(['amount' => '单张面额至少 ¥1.00']);
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => '数量需在 1–'.self::MAX_QUANTITY.' 张之间']);
        }

        $unitCost = $this->unitCost($agent, $faceCents);
        $totalCost = $unitCost * $quantity;

        return DB::transaction(function () use ($agent, $faceCents, $quantity, $unitCost, $totalCost) {
            // Lock the agent row and re-read balance so concurrent generations
            // can't oversell past the available balance.
            $agent = User::whereKey($agent->id)->lockForUpdate()->firstOrFail();

            if ((int) $agent->balance < $totalCost) {
                throw ValidationException::withMessages([
                    'balance' => '余额不足：本次进货需 ¥'.number_format($totalCost / 100, 2).'，当前余额 ¥'.number_format($agent->balance / 100, 2),
                ]);
            }

            $agent->changeBalance(
                -$totalCost,
                BalanceTransaction::TYPE_AGENT_GENERATE,
                "代理进货：{$quantity} 张 ¥".number_format($faceCents / 100, 2).' 卡密',
            );

            $cards = collect();
            for ($i = 0; $i < $quantity; $i++) {
                $cards->push(GiftCard::create([
                    'code' => $this->uniqueCode(),
                    'amount' => $faceCents,
                    'redeemed' => false,
                    'created_by_agent_id' => $agent->id,
                    'agent_cost' => $unitCost,
                ]));
            }

            return $cards;
        });
    }

    /**
     * The agent's discounted cost to open $plan/$period (cents), based on the
     * plan's retail price for that period. Dynamic (任选地区) ISP plans return
     * null — they need a region pick and aren't supported via 代客开通.
     */
    public function openCost(User $agent, Plan $plan, string $period, ?array $lineSnapshot = null): ?int
    {
        if ($plan->isDynamicIsp()) {
            // A dynamic (任选地区) line is priced by the region the agent picked.
            $retail = $lineSnapshot ? $plan->sellPrice($period, $lineSnapshot) : null;
        } else {
            $retail = (int) ($plan->prices[$period] ?? 0);
        }

        if (! $retail || $retail <= 0) {
            return null;
        }

        $discount = max(0, min(90, (int) $agent->agent_discount));

        return (int) round($retail * (100 - $discount) / 100);
    }

    /**
     * Open $plan/$period on $customer's account, charging the agent's discounted
     * cost to the agent's balance. The customer pays nothing (order
     * balance_amount stays 0); markPaid provisions on the customer. Returns the
     * fulfilled order, stamped with paid_by_agent_id for attribution. For a
     * dynamic (任选地区) ISP plan, $selectedLine carries the agent-picked region
     * (createOrder validates it + prices it region-adjusted).
     */
    public function openForCustomer(User $agent, User $customer, Plan $plan, string $period, ?array $selectedLine = null): Order
    {
        if (! $agent->isAgent()) {
            throw ValidationException::withMessages(['agent' => '你还不是代理，无法代客开通']);
        }
        if ($customer->is_admin) {
            throw ValidationException::withMessages(['customer' => '不能给管理员账号代开通']);
        }
        if ($customer->id === $agent->id) {
            throw ValidationException::withMessages(['customer' => '不能给自己代开通，直接在购买页下单即可']);
        }

        return DB::transaction(function () use ($agent, $customer, $plan, $period, $selectedLine) {
            $agent = User::whereKey($agent->id)->lockForUpdate()->firstOrFail();

            // Creates the customer's order at retail (validates buyable/period/
            // capacity; for dynamic ISP, validates + region-prices the line).
            $order = app(OrderService::class)->createOrder($customer, $plan, $period, null, $selectedLine);

            $discount = max(0, min(90, (int) $agent->agent_discount));
            $cost = (int) round($order->total_amount * (100 - $discount) / 100);

            if ((int) $agent->balance < $cost) {
                throw ValidationException::withMessages([
                    'balance' => '余额不足：本次代开通需 ¥'.number_format($cost / 100, 2).'，当前余额 ¥'.number_format($agent->balance / 100, 2),
                ]);
            }

            $agent->changeBalance(
                -$cost,
                BalanceTransaction::TYPE_AGENT_ORDER,
                "代客开通：{$customer->email} · {$plan->name}",
            );

            $order->update(['paid_by_agent_id' => $agent->id]);
            app(OrderService::class)->markPaid($order);

            return $order->fresh();
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        } while (GiftCard::where('code', $code)->exists());

        return $code;
    }
}
