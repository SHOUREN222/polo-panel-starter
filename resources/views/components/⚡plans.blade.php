<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OnipProvisioningService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '购买订阅'])] class extends Component
{
    public string $couponCode = '';

    public string $couponPreview = '';

    public string $giftCardCode = '';

    public string $successMessage = '';

    public string $error = '';

    /**
     * Live-validate the coupon as the customer types, so they see whether it
     * works (and what it does) before choosing a plan — the discount itself is
     * applied at order creation against the specific price.
     */
    public function updatedCouponCode(): void
    {
        $this->couponPreview = '';
        $code = strtoupper(trim($this->couponCode));

        if ($code === '' || ! Setting::getBool('coupon_enabled', true)) {
            return;
        }

        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon || ! $coupon->isValid()) {
            $this->couponPreview = '✗ 优惠券无效或已过期';

            return;
        }

        $this->couponPreview = $coupon->type === Coupon::TYPE_PERCENT
            ? "✓ 有效 · 下单立减 {$coupon->value}%"
            : '✓ 有效 · 下单立减 ¥'.number_format($coupon->value / 100, 2);
    }

    // The order just created by buy()/confirmDynamicBuy() — shown with
    // direct pay buttons right here instead of sending the customer off to
    // "我的订单" for a step that's really part of the same checkout.
    public ?int $justCreatedOrderId = null;

    // Dynamic-region ISP plans: the customer searches onip.cc's real
    // catalog and picks a line themselves instead of the plan carrying a
    // fixed one — see Plan::isDynamicIsp().
    public ?int $pickingForPlanId = null;

    public string $pickingForPeriod = '';

    // When the line picker was opened via "升级到独享" (from an active shared
    // plan) rather than a plain new ISP purchase — flips confirmDynamicBuy
    // to createUpgradeOrder so the shared plan is stopped and credited.
    public bool $upgradeMode = false;

    public ?string $pickingContinent = null;

    public array $continentCities = [];

    public string $lineSearchCountry = '';

    public string $lineSearchCity = '';

    public string $lineSearchBusinessType = '';

    public array $lineSearchResults = [];

    public string $lineSearchError = '';

    public string $selectedLineId = '';

    public array $selectedLineSnapshot = [];

    public string $selectedCityName = '';

    // Buying a top-up pack for one dedicated-ISP line (per-instance quota).

    private const PERIOD_LABELS = [
        'month' => '月付',
        'quarter' => '季付',
        'half_year' => '半年付',
        'year' => '年付',
        'two_year' => '两年付',
        'three_year' => '三年付',
        'gb_100' => '100GB',
        'gb_200' => '200GB',
        'gb_500' => '500GB',
    ];

    private const ONIP_SEARCH_PAGE_SIZE = 20;

    private const ONIP_MIN_PRICE_SCAN_PAGES = 20;

    /**
     * onip.cc's own catalog has no continent field — this groups its city
     * codes (first 3 chars = ISO3 country) into browsing buckets so
     * customers don't have to know/type raw country codes.
     */
    private const CONTINENTS = [
        '北美' => ['USA', 'CAN', 'MEX'],
        '欧洲' => ['GBR', 'DEU', 'FRA', 'ESP', 'ITA', 'NLD'],
        '亚太' => ['CHN', 'JPN', 'KOR', 'SGP', 'MYS', 'PHL', 'VNM', 'IDN', 'IND', 'THA', 'AUS'],
        '中东/非洲' => ['ARE', 'SAU', 'ZAF'],
        '南美' => ['BRA', 'CHL'],
    ];

    public function buy(int $planId, string $period, ?array $selectedLine = null): void
    {
        $this->successMessage = '';
        $this->error = '';

        $plan = Plan::findOrFail($planId);

        try {
            $order = app(OrderService::class)->createOrder(auth()->user(), $plan, $period, $this->couponCode ?: null, $selectedLine);
            $discountNote = $order->discount_amount > 0 ? '，已优惠 ¥'.number_format($order->discount_amount / 100, 2) : '';
            $this->successMessage = "已生成订单 {$order->trade_no}{$discountNote}，选择下面的方式付款";
            $this->justCreatedOrderId = $order->id;
            $this->pickingForPlanId = null;
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function closeCheckout(): void
    {
        $this->justCreatedOrderId = null;
    }

    /**
     * Mirrors 我的订单's mock-pay fallback, kept here too so checkout can
     * complete in one place when no real gateway is configured yet.
     */
    public function payMock(int $orderId): void
    {
        // Local-dev-only shortcut — never a real payment path in production.
        abort_unless(app()->environment(['local', 'testing']), 404);

        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();
        app(OrderService::class)->markPaid($order);

        $this->successMessage = "订单 {$order->trade_no} 已支付";
        $this->justCreatedOrderId = null;
    }

    /**
     * Apply account balance to the just-created order. Full balance settles it
     * outright; otherwise it earmarks what's available and leaves the remainder
     * for a gateway button (which then charges only that remainder). The
     * balance is deducted at payment time, so an abandoned order never locks
     * anything up.
     */
    public function useBalance(int $orderId): void
    {
        $this->error = '';

        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();

        try {
            $order = app(OrderService::class)->applyBalance($order);

            if ($order->isPaid()) {
                $this->successMessage = "订单 {$order->trade_no} 已用余额支付完成";
                $this->justCreatedOrderId = null;
            } else {
                $this->successMessage = '将用余额抵扣 ¥'.number_format($order->balance_amount / 100, 2).'，剩余 ¥'.number_format($order->payableAmount() / 100, 2).' 请选择下面的方式付款（余额在支付完成时扣除）';
            }
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    /**
     * Settle a genuinely free order (total ¥0 because credit/coupon covered it).
     * Guarded to total_amount === 0 so it can never skip a real payment.
     */
    public function settleFree(int $orderId): void
    {
        $this->successMessage = '';
        $this->error = '';

        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();

        if ($order->status !== Order::STATUS_PENDING || $order->total_amount !== 0) {
            $this->error = '该订单需要付款，无法直接开通';

            return;
        }

        app(OrderService::class)->markPaid($order);
        $this->successMessage = "订单 {$order->trade_no} 已开通（无需付款）";
        $this->justCreatedOrderId = null;
    }

    /**
     * Cancel a pending order the customer decided not to pay. Safe with no
     * refund logic: balance is only ever debited at payment (markPaid), so a
     * pending order has taken nothing — cancelling just closes it.
     */
    public function cancelOrder(int $orderId): void
    {
        $this->successMessage = '';
        $this->error = '';

        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();

        if ($order->status !== Order::STATUS_PENDING) {
            $this->error = '只能取消待支付的订单';

            return;
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->successMessage = "订单 {$order->trade_no} 已取消";
        $this->justCreatedOrderId = null;
    }

    public function redeemGiftCard(): void
    {
        $this->successMessage = '';
        $this->error = '';

        try {
            $card = app(GiftCardService::class)->redeem(auth()->user(), strtoupper(trim($this->giftCardCode)));
            $this->successMessage = '礼品卡兑换成功，已到账 ¥'.number_format($card->amount / 100, 2);
            $this->giftCardCode = '';
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function openLinePicker(int $planId, string $period, bool $upgrade = false): void
    {
        $this->error = '';
        $this->upgradeMode = $upgrade;
        $this->pickingForPlanId = $planId;
        $this->pickingForPeriod = $period;
        $this->pickingContinent = null;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchError = '';
        $this->lineSearchCity = '';
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    public function cancelLinePicker(): void
    {
        $this->pickingForPlanId = null;
        $this->pickingContinent = null;
        $this->continentCities = [];
        $this->upgradeMode = false;
    }

    /**
     * The ISO3 country codes we've actually set a selling price for on the
     * plan being picked, for the period being bought. When a dynamic plan has
     * per-country pricing configured, ONLY those countries are sellable — a
     * country we haven't priced must never appear in the picker (we don't want
     * to accidentally sell a high-cost country at some fallback base price).
     *
     * Returns null when the plan has no per-country pricing at all, meaning
     * "no country restriction" — such plans sell every in-stock priced line at
     * their base price, unchanged.
     */
    /**
     * The "¥xx 起" price on a plan card. For an auto-priced dynamic ISP plan
     * this is the real cheapest sellable price right now — the lowest in-stock
     * onip cost run through the pricing formula — cached hourly so cards don't
     * hit onip on every render (one lookup per plan/hour, shared across all
     * period buttons since the min cost is period-independent). Non-auto plans
     * keep their configured cheapest (base + manual overrides).
     */
    public function fromPrice(Plan $plan, string $period): ?int
    {
        $auto = $plan->isDynamicIsp() ? $plan->autoPricing() : ['enabled' => false];
        if (! ($auto['enabled'] ?? false)) {
            return $plan->cheapestPrice($period);
        }

        $minCost = Cache::remember("isp_min_cost_{$plan->id}_scan20", 3600, function () use ($plan) {
            $service = app(OnipProvisioningService::class);
            if (! $service->isConfigured()) {
                return null;
            }
            try {
                $ispType = (int) ($plan->resource_profile['ispType'] ?? 2);

                return $this->cheapestOnipLineCost($service, $ispType);
            } catch (Throwable $e) {
                return null;
            }
        });

        // onip unreachable → fall back to the base "from" price rather than nothing.
        if ($minCost === null) {
            return $plan->cheapestPrice($period);
        }

        return Plan::autoPrice($minCost, $period, $auto['relay'], $auto['profit'], $auto['round'], $auto['discounts'][$period] ?? 0, $auto['monthly_adjustment']);
    }

    private function cheapestOnipLineCost(OnipProvisioningService $service, int $ispType): ?float
    {
        $minCost = null;
        $total = null;

        for ($current = 0; $current < self::ONIP_MIN_PRICE_SCAN_PAGES; $current++) {
            $result = $service->searchLines([
                'ispType' => $ispType,
                'current' => $current,
                'size' => self::ONIP_SEARCH_PAGE_SIZE,
            ]);

            $records = $result['records'] ?? [];
            foreach ($records as $line) {
                $price = $line['price'] ?? null;
                if (! ($line['active'] ?? false)
                    || (int) ($line['status'] ?? 1) !== 0
                    || (int) ($line['quantity'] ?? 0) <= 0
                    || ! is_numeric($price)
                    || (float) $price <= 0
                ) {
                    continue;
                }

                $minCost = $minCost === null ? (float) $price : min($minCost, (float) $price);
            }

            $total = $result['total'] ?? $total;
            if (count($records) < self::ONIP_SEARCH_PAGE_SIZE) {
                break;
            }
            if ($total !== null && (($current + 1) * self::ONIP_SEARCH_PAGE_SIZE) >= (int) $total) {
                break;
            }
        }

        return $minCost;
    }

    private function pricedCountryCodes(): ?array
    {
        $plan = Plan::find($this->pickingForPlanId);

        // Auto-pricing on → every in-stock line is sellable (it gets priced
        // from its own onip cost), so there's no country restriction.
        if ($plan && $plan->autoPricing()['enabled']) {
            return null;
        }

        $map = $plan?->country_prices ?? [];
        if ($map === []) {
            return null;
        }

        $period = $this->pickingForPeriod ?: 'month';

        return collect($map)
            ->filter(fn ($byPeriod) => ! empty($byPeriod[$period]))
            ->keys()
            ->all();
    }

    /**
     * Step 1 of the customer-facing picker: browse by continent instead of
     * typing a raw ISO3 country code.
     */
    public function browseContinent(string $continent): void
    {
        $this->lineSearchError = '';
        $this->pickingContinent = $continent;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchCity = '';

        $service = app(OnipProvisioningService::class);
        if (! $service->isConfigured()) {
            $this->lineSearchError = '线路查询暂时不可用，请稍后再试或联系客服';

            return;
        }

        $countryCodes = self::CONTINENTS[$continent] ?? [];
        $ispType = (int) (Plan::find($this->pickingForPlanId)?->resource_profile['ispType'] ?? 2);

        try {
            // Ask onip for locations of this plan's ispType, and only show
            // ones that actually have stock right now — onip's catalog lists
            // many cities with zero available lines, which would otherwise be
            // dead-end picks for the customer.
            $locations = $service->getAvailableLocations(['ispType' => $ispType]);
            $pricedCountries = $this->pricedCountryCodes();
            $this->continentCities = collect($locations)
                ->filter(fn ($loc) => in_array(substr($loc['code'] ?? '', 0, 3), $countryCodes, true))
                // Only cities in countries we've actually priced (when the plan
                // uses per-country pricing) — unpriced countries aren't for sale.
                ->filter(fn ($loc) => $pricedCountries === null || in_array(substr($loc['code'] ?? '', 0, 3), $pricedCountries, true))
                ->filter(fn ($loc) => $this->locationHasAvailableLines($service, $loc, $ispType))
                ->map(fn ($loc) => ['code' => $loc['code'], 'name' => $loc['name']])
                ->values()
                ->all();

            if (empty($this->continentCities)) {
                $this->lineSearchError = '这个地区暂时没有有货的城市';
            }
        } catch (Throwable $e) {
            $this->lineSearchError = '获取城市列表失败，请稍后再试';
        }
    }

    private function locationHasAvailableLines(OnipProvisioningService $service, array $location, int $ispType): bool
    {
        $cidrs = collect($location['cidrs'] ?? []);
        if ($cidrs->isNotEmpty()) {
            return $cidrs->sum(fn ($c) => (int) ($c['availableCount'] ?? 0)) > 0;
        }

        $cityCode = $location['code'] ?? null;
        if (! $cityCode || ! ($location['active'] ?? false)) {
            return false;
        }

        return Cache::remember("isp_city_stock_{$ispType}_{$cityCode}", 600, function () use ($service, $cityCode, $ispType) {
            try {
                $result = $service->searchLines([
                    'cityCode' => $cityCode,
                    'ispType' => $ispType,
                    'current' => 0,
                    'size' => self::ONIP_SEARCH_PAGE_SIZE,
                ]);

                return collect($result['records'] ?? [])->contains(fn ($line) => ($line['active'] ?? false)
                    && ($line['status'] ?? 1) === 0
                    && ($line['price'] ?? null) !== null
                    && (int) ($line['quantity'] ?? 0) > 0);
            } catch (Throwable) {
                return false;
            }
        });
    }

    public function reselectCity(): void
    {
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    public function backToContinents(): void
    {
        $this->pickingContinent = null;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchError = '';
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    /**
     * Step 2: pick a city within the chosen continent, then auto-pick a
     * line for it — onip's "businessType" (aliexpress/tiktok/google/...)
     * is just its own inventory tagging, not a real usage restriction (all
     * business types for the same city carry the same price/stock), so
     * there's nothing meaningful for the customer to choose there.
     */
    public function browseCity(string $cityCode, string $cityName = ''): void
    {
        $this->lineSearchCity = $cityCode;
        $this->lineSearchCountry = '';
        $this->selectedCityName = $cityName;
        $this->searchLines();
        $this->autoSelectBestLine();
    }

    private function autoSelectBestLine(): void
    {
        $best = collect($this->lineSearchResults)
            ->filter(fn ($line) => ($line['active'] ?? false) && ($line['status'] ?? 1) === 0)
            ->sortByDesc(fn ($line) => $line['quantity'] ?? 0)
            ->first();

        if ($best) {
            $this->selectLine((string) $best['id']);
        }
    }

    public function searchLines(): void
    {
        $this->lineSearchError = '';
        $this->lineSearchResults = [];

        $plan = Plan::find($this->pickingForPlanId);
        $service = app(OnipProvisioningService::class);

        if (! $plan || ! $service->isConfigured()) {
            $this->lineSearchError = '线路查询暂时不可用，请稍后再试或联系客服';

            return;
        }

        try {
            $result = $service->searchLines(array_filter([
                'countryCode' => $this->lineSearchCountry ?: null,
                'cityCode' => $this->lineSearchCity ?: null,
                'businessType' => $this->lineSearchBusinessType ?: null,
                'ispType' => $plan->resource_profile['ispType'] ?? 2,
                'current' => 0,
                'size' => self::ONIP_SEARCH_PAGE_SIZE,
            ]));
            // Only lines that carry a price are actually orderable — some
            // cities have IP blocks in stock but no sellable (priced) line for
            // this ispType; those must not be selectable. And when the plan has
            // per-country pricing, only lines in countries we've set a selling
            // price for are on sale.
            $pricedCountries = $this->pricedCountryCodes();
            $this->lineSearchResults = collect($result['records'] ?? [])
                ->filter(fn ($l) => ($l['price'] ?? null) !== null)
                ->filter(fn ($l) => $pricedCountries === null || in_array($l['countryCode'] ?? null, $pricedCountries, true))
                ->values()
                ->all();

            // Record each sellable line's real onip cost server-side, so the
            // order path can price from a source the client can't tamper with
            // (line_snapshot in the request is client-controlled). See
            // OrderService::withTrustedLine / OnipProvisioningService::cacheLine.
            foreach ($this->lineSearchResults as $line) {
                $service->cacheLine($line);
            }

            if (empty($this->lineSearchResults)) {
                $this->lineSearchError = '这个城市暂时没有可售线路，换一个城市再试';
            }
        } catch (Throwable $e) {
            $this->lineSearchError = '搜索失败，请稍后再试';
        }
    }

    public function selectLine(string $lineId): void
    {
        $line = collect($this->lineSearchResults)->first(fn ($l) => (string) $l['id'] === $lineId);
        if (! $line) {
            return;
        }

        $this->selectedLineId = (string) $line['id'];
        $this->selectedLineSnapshot = [
            'countryCode' => $line['countryCode'] ?? null,
            'cityCode' => $line['cityCode'] ?? null,
            'businessTypeCode' => $line['businessTypeCode'] ?? null,
            'ispType' => $line['ispType'] ?? null,
            'price' => $line['price'] ?? null,
            'currency' => $line['currency'] ?? null,
        ];
    }

    public function confirmDynamicBuy(): void
    {
        if ($this->selectedLineId === '') {
            $this->error = '请先选择一条线路';

            return;
        }

        $selectedLine = [
            'provider' => 'onip',
            'lineId' => $this->selectedLineId,
            'line_snapshot' => $this->selectedLineSnapshot,
        ];

        if ($this->upgradeMode) {
            $this->upgrade($this->pickingForPlanId, $this->pickingForPeriod, $selectedLine);

            return;
        }

        $this->buy($this->pickingForPlanId, $this->pickingForPeriod, $selectedLine);
    }

    /**
     * Upgrade from an active shared plan to a dedicated-ISP line: stops the
     * shared plan and credits its unused days toward the ISP price (handled
     * server-side in OrderService::createUpgradeOrder / fulfill).
     */
    public function upgrade(int $planId, string $period, ?array $selectedLine = null): void
    {
        $this->successMessage = '';
        $this->error = '';

        $plan = Plan::findOrFail($planId);

        try {
            $order = app(OrderService::class)->createUpgradeOrder(auth()->user(), $plan, $period, $selectedLine, $this->couponCode ?: null);
            $this->pickingForPlanId = null;
            $this->upgradeMode = false;
            $creditNote = $order->discount_amount > 0 ? '，已按共享套餐剩余流量折抵 ¥'.number_format($order->discount_amount / 100, 2) : '';

            // Credit covered the whole price — nothing to pay, settle right away
            // instead of leaving a ¥0 order stuck in 待支付.
            if ($order->total_amount === 0) {
                app(OrderService::class)->markPaid($order);
                $this->successMessage = "升级完成{$creditNote}，共享套餐已停用、独享线路开通中";
            } else {
                $this->successMessage = "已生成升级订单 {$order->trade_no}{$creditNote}，付款后共享套餐将停用、独享线路开通";
                $this->justCreatedOrderId = $order->id;
            }
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function changePlan(int $planId, string $period): void
    {
        $this->successMessage = '';
        $this->error = '';

        $plan = Plan::findOrFail($planId);

        try {
            $order = app(OrderService::class)->createChangeOrder(auth()->user(), $plan, $period, $this->couponCode ?: null);
            $discountNote = $order->discount_amount > 0 ? '，已折抵 ¥'.number_format($order->discount_amount / 100, 2) : '';

            if ($order->total_amount === 0) {
                app(OrderService::class)->markPaid($order);
                $this->successMessage = "套餐变更完成{$discountNote}";
            } else {
                $this->successMessage = "已生成套餐变更订单 {$order->trade_no}{$discountNote}，请在弹窗中完成支付";
                $this->justCreatedOrderId = $order->id;
            }
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function periodLabel(string $period): string
    {
        return self::PERIOD_LABELS[$period] ?? $period;
    }

    public function continentNames(): array
    {
        return array_keys(self::CONTINENTS);
    }

    /**
     * Whether $plan has hit its capacity_limit — mirrors
     * OrderService::assertCapacityAvailable()'s counting so what's shown
     * here always matches what buying would actually do.
     */
    private function isSoldOut(Plan $plan): bool
    {
        if (! $plan->capacity_limit) {
            return false;
        }

        $current = $plan->isDedicatedIsp()
            ? SubscriptionInstance::where('plan_id', $plan->id)->whereIn('status', [SubscriptionInstance::STATUS_PENDING, SubscriptionInstance::STATUS_ACTIVE])->count()
            : User::where('plan_id', $plan->id)->where('plan_expired_at', '>', now())->count();

        return $current >= $plan->capacity_limit;
    }

    public function with(): array
    {
        $user = auth()->user();
        $plans = Plan::where('show', true)->orderBy('sort')->get();

        return [
            'plans' => $plans,
            'soldOut' => $plans->mapWithKeys(fn ($plan) => [$plan->id => $this->isSoldOut($plan)]),
            'currentSharedPlanId' => $user->hasActiveSharedPlan() ? $user->plan_id : null,
            'allowPlanChange' => Setting::getBool('subscribe_allow_plan_change', true),
            'prorationEnabled' => Setting::getBool('plan_change_proration_enabled', false),
            'balance' => $user->balance,
            'couponEnabled' => Setting::getBool('coupon_enabled', true),
            'balancePaymentEnabled' => Setting::getBool('balance_payment_enabled', true),
            'justCreatedOrder' => $this->justCreatedOrderId ? Order::find($this->justCreatedOrderId) : null,
            'gatewayPayments' => Payment::where('enable', true)
                ->whereIn('gateway', Payment::checkoutGateways())
                ->get(),
            'pendingOrders' => $user->orders()->where('status', Order::STATUS_PENDING)->latest()->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">购买订阅</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">选择最适合你的套餐</p>
        </div>
        <div class="text-sm text-gray-500 dark:text-gray-400">账户余额：<span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($balance / 100, 2) }}</span></div>
    </div>

    {{-- 不退款提示：虚拟服务、即时开通，下单前明示，减少支付纠纷 --}}
    <p class="text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 rounded-lg px-3 py-2">
        温馨提示：本站为虚拟服务，订阅一经开通即时生效，<span class="font-medium">不支持退款、不支持提现</span>，请确认套餐、地区与时长后再下单。
    </p>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- Newly created orders open an in-place checkout so customers do not
         have to find the order again in the orders page. --}}
    @if ($justCreatedOrder && $justCreatedOrder->status === \App\Models\Order::STATUS_PENDING)
        @php $checkoutPayable = $justCreatedOrder->payableAmount(); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
            <div class="absolute inset-0 bg-gray-950/50" aria-hidden="true"></div>
            <div class="relative w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-700" style="max-width: 32rem;">
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 dark:border-gray-800 px-5 py-4">
                    <div>
                        <h2 id="checkout-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">订单付款</h2>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">订单号：{{ $justCreatedOrder->trade_no }}</p>
                    </div>
                    <button type="button" wire:click="closeCheckout" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 text-xl leading-none" aria-label="关闭付款窗口">×</button>
                </div>

                <div class="px-5 py-5 space-y-4">
                    <div class="flex items-end justify-between rounded-lg bg-gray-50 dark:bg-gray-800/70 px-4 py-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">订单金额</span>
                        <span class="text-2xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($justCreatedOrder->total_amount / 100, 2) }}</span>
                    </div>

                    @if ($justCreatedOrder->balance_amount > 0)
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            已选择余额抵扣 ¥{{ number_format($justCreatedOrder->balance_amount / 100, 2) }}，还需支付
                            <span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($checkoutPayable / 100, 2) }}</span>
                        </div>
                    @endif

                    @if ($checkoutPayable === 0)
                        <button type="button" wire:click="settleFree({{ $justCreatedOrder->id }})" wire:loading.attr="disabled" class="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700">
                            确认开通（无需付款）
                        </button>
                    @else
                        <div>
                            <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">选择付款方式</h3>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @if ($balancePaymentEnabled && $balance > 0 && $justCreatedOrder->balance_amount === 0)
                                    <button type="button" wire:click="useBalance({{ $justCreatedOrder->id }})" wire:loading.attr="disabled" class="rounded-lg bg-green-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-green-700">
                                        @if ($balance >= $checkoutPayable)用余额支付 ¥{{ number_format($checkoutPayable / 100, 2) }}@else用余额抵扣 ¥{{ number_format($balance / 100, 2) }}@endif
                                    </button>
                                @endif
                                @foreach ($gatewayPayments as $payment)
                                    <a href="{{ route('gateway.pay', ['payment' => $payment->id, 'order' => $justCreatedOrder->id]) }}" class="rounded-lg bg-gray-900 px-3 py-2.5 text-center text-sm font-medium text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-300">
                                        {{ $payment->name }} ¥{{ number_format($checkoutPayable / 100, 2) }}
                                    </a>
                                @endforeach
                                @if ($gatewayPayments->isEmpty() && app()->environment(['local', 'testing']))
                                    <button type="button" wire:click="payMock({{ $justCreatedOrder->id }})" wire:loading.attr="disabled" class="rounded-lg bg-gray-900 px-3 py-2.5 text-sm font-medium text-white hover:bg-gray-700">
                                        模拟支付（本地测试）
                                    </button>
                                @endif
                            </div>
                            @if ($gatewayPayments->isEmpty() && ! app()->environment(['local', 'testing']))
                                <p class="mt-2 text-xs text-red-600 dark:text-red-400">当前暂未配置可用的在线支付方式，请联系管理员。</p>
                            @endif
                        </div>
                    @endif

                    <button type="button" wire:click="closeCheckout" class="w-full rounded-lg border border-gray-300 dark:border-gray-700 px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                        稍后支付
                    </button>
                    <p class="text-center text-xs text-gray-400 dark:text-gray-500">关闭窗口后，订单仍会保留在本页的“待支付”列表中。</p>
                </div>
            </div>
        </div>
    @endif

    {{-- 礼品卡（充余额）+ 优惠券（下单抵扣），并排放最上面 --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">兑换礼品卡</h2>
            <div class="flex gap-2">
                <input type="text" wire:model="giftCardCode" placeholder="输入卡密" class="flex-1 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono uppercase">
                <button wire:click="redeemGiftCard" wire:loading.attr="disabled" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4">兑换</button>
            </div>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">充值到账户余额，可用于支付订单。</p>
        </div>
        @if ($couponEnabled)
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">优惠券兑换码（可选）</h2>
                <input type="text" wire:model.live.debounce.500ms="couponCode" placeholder="有优惠码就填在这里" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono uppercase">
                @if ($couponPreview)
                    <p class="mt-1 text-xs {{ str_starts_with($couponPreview, '✓') ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $couponPreview }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">购买 / 续费 / 升级 / 变更 / 加油包 都可用；升级、变更先折抵剩余流量、再用券抵剩余金额。</p>
            </div>
        @endif
    </div>

    {{-- 待支付：所有未支付订单在这里付款 --}}
    @if ($pendingOrders->count())
        <div>
            <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">待支付</h2>
            <div class="space-y-2">
                @foreach ($pendingOrders as $order)
                    @php $payable = $order->payableAmount(); @endphp
                    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-3 bg-white dark:bg-gray-900 text-sm space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="text-gray-700 dark:text-gray-300">
                                <span class="text-xs {{ $order->isRenewal() || $order->isUpgrade() ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500' }}">[{{ $order->typeLabel() }}]</span>
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $order->trade_no }}</span>
                                · ¥{{ number_format($order->total_amount / 100, 2) }}
                            </div>
                            @if ($order->balance_amount > 0)
                                <div class="text-xs text-gray-500 dark:text-gray-400">将抵扣余额 ¥{{ number_format($order->balance_amount / 100, 2) }}，还需 <span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($payable / 100, 2) }}</span></div>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($payable === 0)
                                <button wire:click="settleFree({{ $order->id }})" wire:loading.attr="disabled" class="text-xs bg-green-600 hover:bg-green-700 text-white rounded-lg px-3 py-1.5">确认开通（无需付款）</button>
                            @endif
                            @if ($balancePaymentEnabled && $balance > 0 && $order->balance_amount === 0 && $payable > 0)
                                <button wire:click="useBalance({{ $order->id }})" wire:loading.attr="disabled" class="text-xs bg-green-600 hover:bg-green-700 text-white rounded-lg px-3 py-1.5">
                                    @if ($balance >= $payable)用余额支付 ¥{{ number_format($payable / 100, 2) }}@else用余额抵扣 ¥{{ number_format($balance / 100, 2) }}@endif
                                </button>
                            @endif
                            @if ($payable > 0)
                                @foreach ($gatewayPayments as $payment)
                                    <a href="{{ route('gateway.pay', ['payment' => $payment->id, 'order' => $order->id]) }}" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">{{ $payment->name }} ¥{{ number_format($payable / 100, 2) }}</a>
                                @endforeach
                                @if ($gatewayPayments->isEmpty() && app()->environment(['local', 'testing']))
                                    <button wire:click="payMock({{ $order->id }})" wire:loading.attr="disabled" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">模拟支付（本地测试）</button>
                                @endif
                            @endif
                            <button wire:click="cancelOrder({{ $order->id }})" wire:confirm="确定取消这个待支付订单？" wire:loading.attr="disabled" class="text-xs text-gray-400 hover:text-red-500 dark:text-gray-500 dark:hover:text-red-400 ml-auto">取消订单</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 折抵规则只说明一次（升级/更换套餐时相关），避免每张卡重复整段 --}}
    @if ($allowPlanChange && $currentSharedPlanId)
        <details class="border border-gray-200 dark:border-gray-800 rounded-xl bg-gray-50 dark:bg-gray-900/50 text-sm">
            <summary class="cursor-pointer select-none px-4 py-3 text-gray-600 dark:text-gray-300">升级 / 更换套餐时，剩余价值怎么折抵？</summary>
            <p class="px-4 pb-3 text-xs text-gray-400 dark:text-gray-500 leading-relaxed">折抵 = 当月没用完的流量 + 还没到的月份价值，最多不超过你已付的金额；抵不完的部分退到账户余额，用过的流量不折抵。共享→独享（升级）和共享→共享（更换）都按这个规则。</p>
        </details>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
        @foreach ($plans as $plan)
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 lg:p-5 bg-white dark:bg-gray-900">
                <div class="flex items-center gap-2 mb-1">
                    @include('partials.icon', ['name' => $plan->type === 'shared' ? 'server' : 'shield'])
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $plan->name }}</div>
                </div>
                @if (!empty($plan->tags))
                    <div class="flex flex-wrap gap-1 mb-2">
                        @foreach ($plan->tags as $tag)
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 rounded-full px-2 py-0.5">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                @if ($plan->description)
                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400 whitespace-pre-line">{{ $plan->description }}</p>
                @endif

                @if ($soldOut[$plan->id])
                    <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-800 rounded-lg px-3 py-2 mb-2">
                        已售罄
                    </div>
                @elseif (! $plan->sell)
                    <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-800 rounded-lg px-3 py-2 mb-2">
                        暂不可购买
                    </div>
                @elseif (! $allowPlanChange && $currentSharedPlanId && $plan->type === 'shared' && $plan->id !== $currentSharedPlanId)
                    <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-800 rounded-lg px-3 py-2 mb-2">
                        暂不支持更改订阅
                    </div>
                @else
                {{-- 已有共享套餐时，切到另一个共享套餐只保留下面的「更换」（会折抵剩余价值）——
                     直接购买会替换掉当前套餐且不折抵，是个坑，所以隐藏这里的直接购买按钮。 --}}
                @if (! ($plan->type === 'shared' && $allowPlanChange && $currentSharedPlanId && $plan->id !== $currentSharedPlanId))
                <div class="flex flex-wrap gap-2 mt-3">
                    @foreach ($plan->prices as $period => $price)
                        @if ($plan->isDynamicIsp())
                            <button
                                wire:click="openLinePicker({{ $plan->id }}, '{{ $period }}')"
                                wire:loading.attr="disabled"
                                class="text-xs border border-teal-300 dark:border-teal-700 rounded-lg px-3 py-1.5 text-teal-700 dark:text-teal-400 hover:border-teal-600"
                            >
                                {{ $this->periodLabel($period) }} ¥{{ number_format(($this->fromPrice($plan, $period) ?? $price) / 100, 2) }}起 · 选地区
                            </button>
                        @elseif ($currentSharedPlanId === $plan->id)
                            <button
                                wire:click="buy({{ $plan->id }}, '{{ $period }}')"
                                wire:loading.attr="disabled"
                                class="text-xs border border-teal-300 dark:border-teal-700 rounded-lg px-3 py-1.5 text-teal-700 dark:text-teal-400 hover:border-teal-600"
                            >
                                {{ $this->periodLabel($period) }} 续费 ¥{{ number_format($price / 100, 2) }}
                            </button>
                        @else
                            <button
                                wire:click="buy({{ $plan->id }}, '{{ $period }}')"
                                wire:loading.attr="disabled"
                                class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400"
                            >
                                {{ $this->periodLabel($period) }} ¥{{ number_format($price / 100, 2) }}
                            </button>
                        @endif
                    @endforeach
                </div>
                @endif

                {{-- 共享套餐：从当前共享套餐更换（按剩余流量折抵），与 ISP 升级块并列结构 --}}
                @if ($plan->type === 'shared' && $allowPlanChange && $currentSharedPlanId && $plan->id !== $currentSharedPlanId)
                    <div class="mt-3 border-t border-gray-100 dark:border-gray-800 pt-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">从当前共享套餐更换（停用当前套餐，按剩余价值折抵，更划算）</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($plan->prices as $period => $price)
                                <button
                                    wire:click="changePlan({{ $plan->id }}, '{{ $period }}')"
                                    wire:loading.attr="disabled"
                                    class="text-xs border border-teal-300 dark:border-teal-700 rounded-lg px-3 py-1.5 text-teal-700 dark:text-teal-400 hover:border-teal-600"
                                >
                                    {{ $this->periodLabel($period) }} 更换 ¥{{ number_format($price / 100, 2) }}{{ $prorationEnabled ? '（可折抵）' : '' }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($plan->isDedicatedIsp() && $allowPlanChange && $currentSharedPlanId)
                    <div class="mt-3 border-t border-gray-100 dark:border-gray-800 pt-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">从共享套餐升级到独享（停用共享，按剩余价值折抵）</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($plan->prices as $period => $price)
                                <button
                                    wire:click="{{ $plan->isDynamicIsp() ? "openLinePicker({$plan->id}, '{$period}', true)" : "upgrade({$plan->id}, '{$period}')" }}"
                                    wire:loading.attr="disabled"
                                    class="text-xs border border-teal-300 dark:border-teal-700 rounded-lg px-3 py-1.5 text-teal-700 dark:text-teal-400 hover:border-teal-600"
                                >
                                    {{ $this->periodLabel($period) }} 升级 ¥{{ number_format(($plan->isDynamicIsp() ? ($this->fromPrice($plan, $period) ?? $price) : $price) / 100, 2) }}{{ $plan->isDynamicIsp() ? '起 · 选地区' : '' }} · 可折抵
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                @endif

                {{-- ISP 线路的加油包已挪到「续费订阅」页（每条线路各自加购）——避免和购买页重复。 --}}

                {{-- 机房共享套餐的加油包已挪到「续费订阅」页（管理你已有的套餐）——避免和购买页重复。 --}}

                @if ($pickingForPlanId === $plan->id)
                    <div class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">选择线路地区</h3>
                            <button type="button" wire:click="cancelLinePicker" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">取消</button>
                        </div>

                        @if ($selectedLineId !== '')
                            <div class="flex items-center justify-between bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg px-3 py-2">
                                <div class="text-xs text-gray-700 dark:text-gray-300">
                                    <span class="font-medium text-green-700 dark:text-green-400">已选择</span>
                                    · {{ $selectedCityName }}
                                </div>
                                <button type="button" wire:click="reselectCity" class="text-xs text-red-600 dark:text-red-400 hover:underline">重选</button>
                            </div>
                            @php
                                $pickPlan = $plans->firstWhere('id', $pickingForPlanId);
                                // Uses the full line snapshot (country + onip cost) so an
                                // auto-priced line shows its real computed price here.
                                $pickPrice = $pickPlan?->sellPrice($pickingForPeriod, $selectedLineSnapshot);
                            @endphp
                            <button
                                type="button"
                                wire:click="confirmDynamicBuy"
                                wire:loading.attr="disabled"
                                class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2"
                            >
                                {{ $upgradeMode ? '确认升级' : '确认购买' }}{{ $pickPrice !== null ? '（'.$this->periodLabel($pickingForPeriod).' ¥'.number_format($pickPrice / 100, 2).'）' : '' }}
                            </button>
                        @elseif ($pickingContinent === null)
                            <p class="text-xs text-gray-500 dark:text-gray-400">先选一个大洲：</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->continentNames() as $continent)
                                    <button type="button" wire:click="browseContinent('{{ $continent }}')" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">
                                        {{ $continent }}
                                    </button>
                                @endforeach
                            </div>

                            @if ($lineSearchError)
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $lineSearchError }}</p>
                            @endif
                        @else
                            <div class="flex items-center justify-between">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pickingContinent }} · 选一个城市：</p>
                                <button type="button" wire:click="backToContinents" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">← 换个大洲</button>
                            </div>

                            @if (count($continentCities))
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($continentCities as $city)
                                        <button
                                            type="button"
                                            wire:click="browseCity('{{ $city['code'] }}', '{{ $city['name'] }}')"
                                            wire:loading.attr="disabled"
                                            class="text-xs border rounded-lg px-3 py-1.5 {{ $lineSearchCity === $city['code'] ? 'border-gray-900 dark:border-gray-400 text-gray-900 dark:text-gray-100' : 'border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400' }}"
                                        >
                                            {{ $city['name'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            @if ($lineSearchError)
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $lineSearchError }}</p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
