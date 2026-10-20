<?php

namespace App\Services;

use App\Models\BalanceTransaction;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\TenantWholesaleSettlement;
use App\Models\TrafficPack;
use App\Models\User;
use App\Notifications\NewOrderPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly ProvisionService $provisionService,
        private readonly SharedNodeService $sharedNodeService,
        private readonly CommissionService $commissionService,
        private readonly WholesaleService $wholesaleService,
    ) {}

    /**
     * Create a pending "buy" order. Always results in a fresh resource on
     * payment (a new dedicated-ISP instance gets its own new IP) — this is
     * the entry point for "I want another line", not "extend the one I have".
     *
     * $selectedLine is the customer's own real-time line pick (lineId +
     * a display snapshot) for a dynamic-region onip plan — required for
     * those plans since the Plan itself carries no fixed lineId to fall
     * back on; see ProvisionService::createTask().
     */
    public function createOrder(User $user, Plan $plan, string $period, ?string $couponCode = null, ?array $selectedLine = null): Order
    {
        $price = $this->assertBuyable($plan, $period);
        $this->assertCapacityAvailable($plan);
        $this->assertLineSelected($plan, $selectedLine);
        $selectedLine = $this->withTrustedLine($plan, $selectedLine);
        $price = $this->effectivePrice($plan, $period, $selectedLine, $price);
        [$coupon, $discount] = $this->resolveCoupon($couponCode, $price, $user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_NEW,
            'period' => $period,
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => $price - $discount,
            'discount_amount' => $discount,
            'status' => Order::STATUS_PENDING,
            'selected_resource_profile' => $selectedLine,
        ]);
    }

    private function assertLineSelected(Plan $plan, ?array $selectedLine): void
    {
        if ($plan->isDynamicIsp() && empty($selectedLine['lineId'])) {
            throw ValidationException::withMessages(['line' => '请先选择一条真实线路再购买']);
        }
    }

    /**
     * Replace a dynamic-ISP order's line_snapshot with the server-cached,
     * onip-authoritative one. The client picks WHICH line (lineId), but its
     * price — which drives the sell price for auto-priced plans — is read back
     * from what searchLines() fetched from onip, never from the request. Every
     * order entry point runs through here, so a forged line_snapshot['price']
     * in a directly-invoked Livewire call can't buy a real IP for pennies.
     */
    private function withTrustedLine(Plan $plan, ?array $selectedLine): ?array
    {
        if (! $plan->isDynamicIsp()) {
            return $selectedLine;
        }

        $lineId = $selectedLine['lineId'] ?? null;
        if (! $lineId) {
            throw ValidationException::withMessages(['line' => '请先选择一条真实线路再购买']);
        }

        $trusted = app(OnipProvisioningService::class)->cachedLine((string) $lineId);
        if (! $trusted || ($trusted['price'] ?? null) === null) {
            throw ValidationException::withMessages(['line' => '线路信息已过期，请重新搜索并选择线路后再下单']);
        }

        return array_merge($selectedLine, ['line_snapshot' => $trusted]);
    }

    /**
     * Country-adjusted price for a dynamic-region ISP purchase — the line the
     * customer picked determines the country, which may carry its own price
     * (see Plan::priceForCountry). Non-dynamic plans just keep the base price.
     * The country is read from the server-built line snapshot.
     */
    private function effectivePrice(Plan $plan, string $period, ?array $selectedLine, int $basePrice): int
    {
        if (! $plan->isDynamicIsp()) {
            return $basePrice;
        }

        // The plan's pricing brain returns the manual override, the auto-priced
        // value, or null when this country/period isn't for sale. A country we
        // never priced (and don't auto-price) must be rejected, not silently
        // billed at the base price — which may not cover its cost.
        $price = $plan->sellPrice($period, $selectedLine['line_snapshot'] ?? null);
        if ($price === null) {
            throw ValidationException::withMessages(['line' => '该地区暂未开放销售，请另选地区']);
        }

        return $price;
    }

    /**
     * Validate a coupon code against a price and return [Coupon|null, discountCents].
     * A blank code is not an error — it just means no discount applies.
     */
    private function resolveCoupon(?string $couponCode, int $price, ?User $user = null): array
    {
        // Coupon system disabled: ignore any submitted code entirely (the
        // input is hidden, but this guards a directly-posted couponCode too).
        if (! $couponCode || ! Setting::getBool('coupon_enabled', true)) {
            return [null, 0];
        }

        $coupon = Coupon::where('code', $couponCode)->first();

        if (! $coupon || ! $coupon->isValid()) {
            throw ValidationException::withMessages(['coupon' => '优惠券无效或已过期']);
        }

        // Per-user cap: count this user's own non-cancelled orders already on
        // this coupon (pending + paid). Counting pending too means racing
        // several unpaid orders through can't slip past the limit — the global
        // used_count race is only about the shared max_uses, this is airtight
        // per account.
        if ($user && $coupon->per_user_limit !== null) {
            $usedByUser = Order::where('user_id', $user->id)
                ->where('coupon_id', $coupon->id)
                ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PAID])
                ->count();

            if ($usedByUser >= $coupon->per_user_limit) {
                throw ValidationException::withMessages(['coupon' => '该优惠券每位用户可用次数已用完']);
            }
        }

        $discount = $coupon->discountFor($price);

        // Don't attach (or consume) the coupon when it yields nothing — e.g. an
        // order already ¥0 from traffic credit. Keeps the code usable next time.
        return $discount > 0 ? [$coupon, $discount] : [null, 0];
    }

    /**
     * Create a pending "renew" order against a specific instance the user
     * already owns. On payment this extends that same instance's expiry —
     * it never touches provisioning, so the IP never changes.
     */
    public function createRenewalOrder(User $user, SubscriptionInstance $instance, string $period, ?string $couponCode = null): Order
    {
        if ($instance->user_id !== $user->id) {
            throw ValidationException::withMessages(['instance_id' => '无权续费该实例']);
        }

        $plan = $instance->plan;
        if (! $plan->allow_renewal) {
            throw ValidationException::withMessages(['plan_id' => '该套餐当前不可续费']);
        }

        $price = $this->assertBuyable($plan, $period);
        // Renew at the same country's price the line was bought at (dynamic
        // ISP) — reusing the country + onip cost persisted on the instance, so
        // both manual and auto-priced lines renew at a consistent price. Falls
        // back to the base price rather than blocking an existing customer.
        if ($plan->isDynamicIsp()) {
            $snapshot = [
                'countryCode' => $instance->meta['country'] ?? null,
                'price' => $instance->meta['onip_price'] ?? null,
            ];
            $price = $plan->sellPrice($period, $snapshot) ?? $price;
        }

        [$coupon, $discount] = $this->resolveCoupon($couponCode, $price, $user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'renew_subscription_instance_id' => $instance->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_RENEWAL,
            'period' => $period,
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => max(0, $price - $discount),
            'discount_amount' => $discount,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    /**
     * Create a pending "change plan" order for a user's active shared
     * subscription (dedicated-ISP instances aren't supported here — each
     * instance would need its own independent switch, plus a decision on
     * whether that re-provisions a new IP, which is a bigger feature than
     * this scoped pass covers). Only meaningful when 开启折抵方案 is on;
     * otherwise this behaves like a plain new purchase (no credit, no
     * period carry-over) — still useful as a "switch active plan" action,
     * just without proration.
     */
    public function createChangeOrder(User $user, Plan $newPlan, string $period, ?string $couponCode = null): Order
    {
        if (! Setting::getBool('subscribe_allow_plan_change', true)) {
            throw ValidationException::withMessages(['plan_id' => '当前不允许更改订阅']);
        }

        if ($newPlan->type !== Plan::TYPE_SHARED) {
            throw ValidationException::withMessages(['plan_id' => '暂不支持专属线路的套餐变更']);
        }

        $price = $this->assertBuyable($newPlan, $period);
        $this->assertCapacityAvailable($newPlan);
        [$credit, $toBalance] = Setting::getBool('plan_change_proration_enabled', false)
            ? $this->sharedSwitchCredit($user, $price)
            : [0, 0];

        // Coupon applies to what's left after the customer's own traffic credit.
        $afterCredit = max(0, $price - $credit);
        [$coupon, $couponDiscount] = $this->resolveCoupon($couponCode, $afterCredit, $user);

        $this->cancelPendingSwitchOrders($user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $newPlan->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_CHANGE,
            'period' => $period,
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => max(0, $afterCredit - $couponDiscount),
            'discount_amount' => $credit + $couponDiscount,
            'balance_credit_amount' => $toBalance,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    /**
     * Create a pending "upgrade" order: buy a dedicated-ISP line while the
     * user still holds an active shared plan. On payment this provisions the
     * ISP instance (new IP) AND stops the shared plan, crediting the shared
     * plan's unused *traffic* toward the ISP price. $selectedLine is required
     * for dynamic-region ISP plans (same as a fresh ISP purchase).
     */
    public function createUpgradeOrder(User $user, Plan $dedicatedPlan, string $period, ?array $selectedLine = null, ?string $couponCode = null): Order
    {
        if (! Setting::getBool('subscribe_allow_plan_change', true)) {
            throw ValidationException::withMessages(['plan_id' => '当前不允许更改订阅']);
        }

        if (! $user->hasActiveSharedPlan()) {
            throw ValidationException::withMessages(['plan_id' => '只有持有有效共享套餐才能升级到独享线路']);
        }

        if (! $dedicatedPlan->isDedicatedIsp()) {
            throw ValidationException::withMessages(['plan_id' => '升级目标必须是独享 ISP 线路']);
        }

        $price = $this->assertBuyable($dedicatedPlan, $period);
        $this->assertCapacityAvailable($dedicatedPlan);
        $this->assertLineSelected($dedicatedPlan, $selectedLine);
        $selectedLine = $this->withTrustedLine($dedicatedPlan, $selectedLine);
        $price = $this->effectivePrice($dedicatedPlan, $period, $selectedLine, $price);
        // Upgrade always credits the shared plan's unused *traffic* (that's the
        // whole point of "升级") — unlike shared↔shared 折抵 it isn't gated
        // behind the proration toggle. Value beyond the ISP price goes to
        // balance (capped at what was paid), so nothing is forfeited.
        [$credit, $toBalance] = $this->sharedSwitchCredit($user, $price);

        $afterCredit = max(0, $price - $credit);
        [$coupon, $couponDiscount] = $this->resolveCoupon($couponCode, $afterCredit, $user);

        $this->cancelPendingSwitchOrders($user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $dedicatedPlan->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_UPGRADE,
            'period' => $period,
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => max(0, $afterCredit - $couponDiscount),
            'discount_amount' => $credit + $couponDiscount,
            'balance_credit_amount' => $toBalance,
            'status' => Order::STATUS_PENDING,
            'selected_resource_profile' => $selectedLine,
        ]);
    }

    /**
     * How to split the credit when a user switches off their current shared
     * plan onto a new order costing $price. Returns [appliedToOrder, toBalance]:
     * the unused *traffic* value (same basis as 升级) is first applied to the
     * order, and any remainder is returned to the user's balance so they never
     * forfeit paid value. The TOTAL returned is capped at what the user
     * actually paid for the current plan — so a coupon-discounted plan can't be
     * turned into more balance than was paid (no free money).
     *
     * @return array{0:int,1:int} [applied, toBalance]
     */
    /**
     * A change/upgrade order switches the user OFF their current shared plan and
     * credits its unused traffic. That credit is computed at creation from the
     * live plan, so two switch orders created before either is paid would each
     * credit the SAME traffic — paying both would double-credit (free balance).
     * Only one switch can be in flight: cancel any earlier pending one so its
     * stale credit can never be realized.
     */
    private function cancelPendingSwitchOrders(User $user): void
    {
        Order::where('user_id', $user->id)
            ->where('status', Order::STATUS_PENDING)
            ->whereIn('type', [Order::TYPE_CHANGE, Order::TYPE_UPGRADE])
            ->update(['status' => Order::STATUS_CANCELLED]);
    }

    private function sharedSwitchCredit(User $user, int $price): array
    {
        $returnable = min(
            $this->sharedRemainingSubscriptionValueCents($user),
            $this->currentSharedPlanPaidCents($user),
        );
        $applied = min($returnable, $price);

        return [$applied, $returnable - $applied];
    }

    /**
     * Fair switch-credit basis across billing periods: the current window's
     * unused *traffic* (usage-aware — traffic already used is never credited)
     * PLUS the full monthly value of each remaining paid month not yet started.
     * So a quarterly/yearly buyer who switches early is credited for the months
     * they paid for but haven't used — not just the current month, which is the
     * bug that made 年付→月付 forfeit most of the subscription. Always capped at
     * money actually paid (in sharedSwitchCredit), so it can never over-credit.
     */
    private function sharedRemainingSubscriptionValueCents(User $user): int
    {
        $currentWindowUnused = $this->sharedRemainingTrafficValueCents($user);

        $plan = Plan::find($user->plan_id);
        $monthlyPrice = (int) ($plan?->priceFor('month') ?? 0);

        if ($monthlyPrice <= 0 || ! ($user->plan_expired_at?->isFuture() ?? false)) {
            return $currentWindowUnused;
        }

        // Whole future monthly windows still paid for, beyond the current one.
        // round() (not floor) so a full N-month span counts as N despite the
        // few milliseconds elapsed since purchase making it read N − 0.0001.
        $remainingFullMonths = max(0, (int) round(now()->diffInMonths($user->plan_expired_at)) - 1);

        return $currentWindowUnused + $remainingFullMonths * $monthlyPrice;
    }

    /**
     * What the user actually paid for their current shared plan — the total of
     * the most recent paid new/change order for it. Caps the switch credit so
     * it can never exceed real money in (guards the coupon/discount case where
     * the plan's list price exceeds what was paid).
     */
    private function currentSharedPlanPaidCents(User $user): int
    {
        if ($user->plan_id === null) {
            return 0;
        }

        return (int) (Order::where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->where('status', Order::STATUS_PAID)
            ->whereIn('type', [Order::TYPE_NEW, Order::TYPE_CHANGE])
            ->latest('paid_at')
            ->value('total_amount') ?? 0);
    }

    /**
     * Cash value (cents) of the unused *traffic* left in the shared plan's
     * current window — priced off the plan's own ¥/GB (monthly price ÷ base
     * quota). Used by shared→独享 升级 折抵. E.g. a ¥15 / 20GB plan with 8GB
     * left credits 15 × 8/20 = ¥6. Traffic packs bought separately don't
     * count; usage is measured against the plan's base allowance only.
     */
    private function sharedRemainingTrafficValueCents(User $user): int
    {
        if (! $user->hasActiveSharedPlan() || $user->plan_id === null) {
            return 0;
        }

        $currentPlan = Plan::find($user->plan_id);
        $monthlyPrice = $currentPlan?->priceFor('month');
        $baseQuota = (int) ($currentPlan?->transfer_enable ?? 0);
        if ($monthlyPrice === null || $baseQuota <= 0) {
            return 0;
        }

        $used = $this->sharedNodeService->sharedTrafficUsedBytes($user);
        $remaining = max(0, $baseQuota - $used);

        return (int) round($monthlyPrice * $remaining / $baseQuota);
    }

    /**
     * Choose how much of the user's account balance to put toward a pending
     * order. This only *records the intent* on the order (balance_amount) — it
     * does NOT touch the user's balance yet. The balance is actually deducted
     * at settlement (see markPaid), so there is never anything to refund: an
     * order the customer abandons leaves their balance completely untouched.
     * The payment gateway is then asked only for the remainder
     * (Order::payableAmount()); if balance covers the whole order it's settled
     * right here with no gateway step.
     *
     * $requestedCents caps how much to apply (a partial "抵扣一部分" choice);
     * null means "as much as helps", bounded by what the user actually has.
     */
    public function applyBalance(Order $order, ?int $requestedCents = null): Order
    {
        if (! Setting::getBool('balance_payment_enabled', true)) {
            throw ValidationException::withMessages(['balance' => '余额支付已关闭']);
        }

        return DB::transaction(function () use ($order, $requestedCents) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::STATUS_PENDING) {
                throw ValidationException::withMessages(['order' => '订单状态不允许支付']);
            }

            $user = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();

            $intent = min($user->balance, $order->total_amount, $requestedCents ?? $order->total_amount);

            if ($intent <= 0) {
                throw ValidationException::withMessages(['balance' => '账户余额不足']);
            }

            $order->update(['balance_amount' => $intent]);
            $order->refresh();

            // Balance covers the whole order — settle now (markPaid does the
            // actual deduction), no gateway step needed.
            if ($order->payableAmount() === 0) {
                $this->markPaid($order);
            }

            return $order->fresh();
        });
    }

    /**
     * Mark an order paid and fulfill it. This is the single settlement point
     * for every path (full-balance, gateway remainder, gateway-only, mock) and
     * the *only* place the user's balance is actually debited: whatever balance
     * was earmarked on the order (balance_amount) is deducted here, capped at
     * what the user genuinely has right now, so balance can never go negative
     * and there is never a reservation left dangling to refund.
     */
    public function markPaid(Order $order): Order
    {
        $settlement = null;

        return DB::transaction(function () use ($order, &$settlement) {
            // SQLite ignores SELECT ... FOR UPDATE. A no-op UPDATE acquires its
            // write lock before the central reservation, serializing duplicate
            // callbacks for this tenant order just like lockForUpdate on MySQL.
            DB::table($order->getTable())
                ->where('id', $order->getKey())
                ->update(['status' => DB::raw('status')]);

            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->isPaid()) {
                // New-flow crash recovery: complete a durable reservation left
                // after tenant commit. A historical paid order has no ledger
                // row, so duplicate old callbacks can never debit it again.
                $this->completeWholesaleAfterCommit($this->wholesaleService->existing($order));

                return $order->fresh();
            }

            $settlement = $this->wholesaleService->reserve($order);

            try {
                if ($order->balance_amount > 0) {
                    $user = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                    $deduct = min($order->balance_amount, $user->balance);

                    if ($deduct > 0) {
                        $user->changeBalance(-$deduct, BalanceTransaction::TYPE_ORDER_PAYMENT, '订单 '.$order->trade_no);
                    }

                    // Record what was actually drawn (may be less than earmarked if
                    // the balance was spent elsewhere first), so the order total is
                    // honest about how it was paid.
                    if ($deduct !== $order->balance_amount) {
                        $order->update(['balance_amount' => $deduct]);
                    }
                }

                $order->update([
                    'status' => Order::STATUS_PAID,
                    'paid_at' => now(),
                ]);

                // Unused shared-plan value beyond the order price → user's balance,
                // so switching/upgrading never forfeits paid value (already capped
                // at what was paid in sharedSwitchCredit()).
                if ($order->balance_credit_amount > 0) {
                    User::whereKey($order->user_id)->lockForUpdate()->first()
                        ->changeBalance($order->balance_credit_amount, BalanceTransaction::TYPE_UPGRADE_CREDIT, '订单 '.$order->trade_no.' 折抵余额');
                }

                if ($order->coupon_id) {
                    // Atomic + capped: only bump used_count while it's still under
                    // max_uses, so two orders settling at once can't push the shared
                    // counter past the global limit (the check-then-increment race).
                    Coupon::whereKey($order->coupon_id)
                        ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
                        ->increment('used_count');
                }

                $this->fulfill($order);
                $this->commissionService->awardForOrder($order);

                Notification::send(User::adminsToNotify(), new NewOrderPaid($order));
            } catch (\Throwable $e) {
                // This runs while the tenant order write lock is still held, so
                // another callback cannot reuse the reservation while it is
                // being refunded. The surrounding tenant transaction rolls back.
                $this->wholesaleService->release($settlement);

                throw $e;
            }

            $this->completeWholesaleAfterCommit($settlement);

            return $order->fresh();
        });
    }

    private function completeWholesaleAfterCommit(?TenantWholesaleSettlement $settlement): void
    {
        if (! $settlement) {
            return;
        }

        DB::afterCommit(fn () => $this->wholesaleService->complete($settlement));
    }

    /**
     * A one-off top-up on a user's active shared plan — 流量包 (one_time)
     * never resets, 重置包 (resettable) only counts toward the quota while
     * its purchase falls inside the current window (see
     * SharedNodeService::effectiveSharedQuotaBytes()). Priced per-GB off
     * the plan's own traffic_pack_price_per_gb / traffic_reset_pack_price_per_gb.
     */
    public function createTrafficPackOrder(User $user, Plan $plan, string $kind, int $gigabytes, ?string $couponCode = null): Order
    {
        if (! $user->hasActiveSharedPlan() || $user->plan_id !== $plan->id) {
            throw ValidationException::withMessages(['plan_id' => '只能给当前生效的共享套餐购买流量包']);
        }

        if ($gigabytes < 1) {
            throw ValidationException::withMessages(['gigabytes' => '数量必须大于 0']);
        }

        $pricePerGb = $kind === TrafficPack::KIND_ONE_TIME
            ? $plan->traffic_pack_price_per_gb
            : $plan->traffic_reset_pack_price_per_gb;

        if (! $pricePerGb) {
            throw ValidationException::withMessages(['kind' => '该套餐没有开放这种流量包']);
        }

        $price = $pricePerGb * $gigabytes;
        [$coupon, $discount] = $this->resolveCoupon($couponCode, $price, $user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_TRAFFIC_PACK,
            'period' => 'once',
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => max(0, $price - $discount),
            'discount_amount' => $discount,
            'status' => Order::STATUS_PENDING,
            'pack_kind' => $kind,
            'pack_bytes' => $gigabytes * 1024 * 1024 * 1024,
        ]);
    }

    /**
     * Buy a traffic pack for one dedicated-ISP line. Unlike shared packs
     * (which top up a monthly-resetting pool), an ISP line's quota is a
     * per-period pool, so ISP packs are one-time top-ups scoped to that
     * instance (see SubscriptionInstance::effectiveQuotaBytes()). Priced off
     * the line's plan traffic_pack_price_per_gb.
     */
    public function createInstanceTrafficPackOrder(User $user, SubscriptionInstance $instance, int $gigabytes, ?string $couponCode = null): Order
    {
        if ($instance->user_id !== $user->id) {
            throw ValidationException::withMessages(['instance' => '只能给自己的线路购买流量包']);
        }

        if (! $instance->isAvailable()) {
            throw ValidationException::withMessages(['instance' => '该线路未生效或已过期']);
        }

        $plan = $instance->plan;

        if (! $plan || ! $plan->isDedicatedIsp()) {
            throw ValidationException::withMessages(['instance' => '该线路不支持流量包']);
        }

        if ($gigabytes < 1) {
            throw ValidationException::withMessages(['gigabytes' => '数量必须大于 0']);
        }

        $pricePerGb = $plan->traffic_pack_price_per_gb;
        if (! $pricePerGb) {
            throw ValidationException::withMessages(['gigabytes' => '该线路没有开放流量包']);
        }

        $price = $pricePerGb * $gigabytes;
        [$coupon, $discount] = $this->resolveCoupon($couponCode, $price, $user);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'renew_subscription_instance_id' => $instance->id,
            'coupon_id' => $coupon?->id,
            'type' => Order::TYPE_TRAFFIC_PACK,
            'period' => 'once',
            'trade_no' => $this->generateTradeNo(),
            'total_amount' => max(0, $price - $discount),
            'discount_amount' => $discount,
            'status' => Order::STATUS_PENDING,
            'pack_kind' => TrafficPack::KIND_ONE_TIME,
            'pack_bytes' => $gigabytes * 1024 * 1024 * 1024,
        ]);
    }

    private function assertCapacityAvailable(Plan $plan): void
    {
        if (! $plan->capacity_limit) {
            return;
        }

        $current = $plan->isDedicatedIsp()
            ? SubscriptionInstance::where('plan_id', $plan->id)->whereIn('status', [SubscriptionInstance::STATUS_PENDING, SubscriptionInstance::STATUS_ACTIVE])->count()
            : User::where('plan_id', $plan->id)->where('plan_expired_at', '>', now())->count();

        if ($current >= $plan->capacity_limit) {
            throw ValidationException::withMessages(['plan_id' => '该套餐名额已满']);
        }
    }

    /**
     * Route a paid order to the correct fulfillment path based on plan type
     * and whether it's a new purchase or a renewal of an owned instance.
     */
    private function fulfill(Order $order): void
    {
        $plan = $order->plan;

        if ($order->type === Order::TYPE_TRAFFIC_PACK) {
            TrafficPack::create([
                'user_id' => $order->user_id,
                'plan_id' => $order->plan_id,
                // Set for an ISP-line pack (scoped to that instance), null for a
                // shared-pool pack — reuses the order's instance link.
                'subscription_instance_id' => $order->renew_subscription_instance_id,
                'order_id' => $order->id,
                'kind' => $order->pack_kind,
                'bytes' => $order->pack_bytes,
            ]);

            return;
        }

        if ($plan->isDedicatedIsp()) {
            // Traffic quota is granted for the whole purchased period up front
            // and stacked on renewal — provisionForOrder / renewInstance own
            // that, there's no separate monthly reset to trigger here.
            if ($order->isRenewal()) {
                $this->provisionService->renewInstance($order, $order->renewInstance);
            } else {
                $this->provisionService->provisionForOrder($order, $plan);
            }

            // A shared→独享 升级 also stops the shared plan the credit was
            // computed against — the customer is moving up, not adding a line.
            if ($order->isUpgrade()) {
                $this->stopSharedPlan($order->user);
            }

            return;
        }

        if ($order->type === Order::TYPE_CHANGE) {
            $this->sharedNodeService->switchPlan($order->user, $order, $plan);

            return;
        }

        $this->sharedNodeService->extendAccess($order->user, $order, $plan);
    }

    /**
     * End the user's shared-plan access immediately (used when they upgrade
     * to a dedicated line). Expiring it — rather than nulling plan_id — keeps
     * the historical plan reference intact and matches the normal "access
     * lapsed" state the rest of the app already handles.
     */
    private function stopSharedPlan(User $user): void
    {
        $user->update(['plan_expired_at' => now()]);
    }

    private function assertBuyable(Plan $plan, string $period): int
    {
        if (! $plan->sell) {
            throw ValidationException::withMessages(['plan_id' => '该套餐当前不可购买']);
        }

        $price = $plan->priceFor($period);
        if ($price === null) {
            throw ValidationException::withMessages(['period' => '该套餐不支持所选付款周期']);
        }

        return $price;
    }

    private function generateTradeNo(): string
    {
        return now()->format('YmdHis').strtoupper(Str::random(10));
    }
}
