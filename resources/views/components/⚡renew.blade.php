<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\SubscriptionInstance;
use App\Services\OrderService;
use App\Services\SharedNodeService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '续费订阅'])] class extends Component
{
    public string $couponCode = '';
    public string $couponPreview = '';
    public string $successMessage = '';
    public string $error = '';

    // The order just created — paid right here (待支付 section) instead of
    // sending the customer off to 我的订单, same as the 购买订阅 page.
    public ?int $justCreatedOrderId = null;

    // ISP 线路加油包：跟着某条线路展开
    public ?int $packInstanceId = null;
    public string $packGigabytes = '1';

    // 机房共享套餐加油包：'' | one_time | resettable
    public string $sharedPackKind = '';
    public string $sharedPackGigabytes = '1';

    private const PERIOD_LABELS = [
        'month' => '月付',
        'quarter' => '季付',
        'half_year' => '半年付',
        'year' => '年付',
    ];

    public function updatedCouponCode(): void
    {
        $this->couponPreview = '';
        $code = strtoupper(trim($this->couponCode));

        if ($code === '' || ! \App\Models\Setting::getBool('coupon_enabled', true)) {
            return;
        }

        $coupon = \App\Models\Coupon::where('code', $code)->first();

        if (! $coupon || ! $coupon->isValid()) {
            $this->couponPreview = '✗ 优惠券无效或已过期';

            return;
        }

        $this->couponPreview = $coupon->type === \App\Models\Coupon::TYPE_PERCENT
            ? "✓ 有效 · 立减 {$coupon->value}%"
            : '✓ 有效 · 立减 ¥'.number_format($coupon->value / 100, 2);
    }

    public function renew(int $instanceId, string $period): void
    {
        $this->successMessage = '';
        $this->error = '';

        // Scope to the caller's own instances — createRenewalOrder already
        // rejects a non-owned instance, but resolving it through the ownership
        // relation keeps another user's row from ever being loaded here.
        $instance = auth()->user()->subscriptionInstances()->findOrFail($instanceId);

        try {
            $order = app(OrderService::class)->createRenewalOrder(auth()->user(), $instance, $period, $this->couponCode ?: null);
            $keepsSameIp = $instance->expired_at?->isFuture() ?? false;
            $ipNote = $keepsSameIp ? '不会更换 IP' : '已过期，支付后将重新分配一个新 IP';
            $discountNote = $order->discount_amount > 0 ? '，已优惠 ¥'.number_format($order->discount_amount / 100, 2) : '';
            $this->justCreatedOrderId = $order->id;
            $this->successMessage = "已生成续费订单 {$order->trade_no}{$discountNote}，请在下方完成支付（续费{$ipNote}）";
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function openPack(int $instanceId): void
    {
        $this->packInstanceId = $instanceId;
        $this->packGigabytes = '1';
        $this->successMessage = '';
        $this->error = '';
    }

    public function cancelPack(): void
    {
        $this->packInstanceId = null;
    }

    public function buyPack(): void
    {
        $this->successMessage = '';
        $this->error = '';

        $instance = auth()->user()->subscriptionInstances()->findOrFail($this->packInstanceId);

        try {
            $order = app(OrderService::class)->createInstanceTrafficPackOrder(auth()->user(), $instance, (int) $this->packGigabytes, $this->couponCode ?: null);
            $this->packInstanceId = null;
            $discountNote = $order->discount_amount > 0 ? '，已优惠 ¥'.number_format($order->discount_amount / 100, 2) : '';
            $this->justCreatedOrderId = $order->id;
            $this->successMessage = "已生成流量包订单 {$order->trade_no}{$discountNote}，请在下方完成支付，支付后流量立即到账（永久有效、用完不清零）";
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function openSharedPack(string $kind): void
    {
        $this->sharedPackKind = in_array($kind, ['one_time', 'resettable'], true) ? $kind : '';
        $this->sharedPackGigabytes = '1';
        $this->successMessage = '';
        $this->error = '';
    }

    public function cancelSharedPack(): void
    {
        $this->sharedPackKind = '';
    }

    public function buySharedPack(): void
    {
        $this->successMessage = '';
        $this->error = '';

        $plan = auth()->user()->plan;
        if (! $plan || $this->sharedPackKind === '') {
            return;
        }

        try {
            $order = app(OrderService::class)->createTrafficPackOrder(auth()->user(), $plan, $this->sharedPackKind, (int) $this->sharedPackGigabytes, $this->couponCode ?: null);
            $this->sharedPackKind = '';
            $discountNote = $order->discount_amount > 0 ? '，已优惠 ¥'.number_format($order->discount_amount / 100, 2) : '';
            $this->justCreatedOrderId = $order->id;
            $this->successMessage = "已生成流量包订单 {$order->trade_no}{$discountNote}，请在下方完成支付，支付后流量立即到账";
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    // --- 就地付款：与「购买订阅」页同一套（余额 / 网关 / 免费结算 / 取消）---

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
     * The price a renewal actually costs — for a 任选地区 (dynamic) line that's
     * the country-adjusted price (reusing the country + onip cost saved on the
     * instance), same as createRenewalOrder charges, so the button never shows
     * a different number from what gets billed.
     */
    public function renewalPrice(SubscriptionInstance $instance, string $period): int
    {
        $plan = $instance->plan;
        $base = (int) ($plan->prices[$period] ?? 0);

        if ($plan->isDynamicIsp()) {
            return $plan->sellPrice($period, [
                'countryCode' => $instance->meta['country'] ?? null,
                'price' => $instance->meta['onip_price'] ?? null,
            ]) ?? $base;
        }

        return $base;
    }

    public function periodLabel(string $period): string
    {
        return self::PERIOD_LABELS[$period] ?? $period;
    }

    public function with(): array
    {
        $user = auth()->user();
        $hasShared = $user->hasActiveSharedPlan();
        // Only surface a shared 加油包 card when the current plan actually sells packs.
        $sharedPlan = $hasShared && $user->plan && ($user->plan->traffic_pack_price_per_gb || $user->plan->traffic_reset_pack_price_per_gb)
            ? $user->plan
            : null;
        $shared = app(SharedNodeService::class);

        return [
            'instances' => $user->subscriptionInstances()->with('plan')->latest()->get(),
            'hasSharedPlan' => $hasShared,
            'sharedPlan' => $sharedPlan,
            'sharedUsedBytes' => $sharedPlan ? $shared->sharedTrafficUsedBytes($user) : 0,
            'sharedQuotaBytes' => $sharedPlan ? $shared->effectiveSharedQuotaBytes($user) : 0,
            'couponEnabled' => \App\Models\Setting::getBool('coupon_enabled', true),
            'balance' => $user->balance,
            'balancePaymentEnabled' => \App\Models\Setting::getBool('balance_payment_enabled', true),
            'gatewayPayments' => Payment::where('enable', true)->whereIn('gateway', Payment::checkoutGateways())->get(),
            'pendingOrders' => $user->orders()->where('status', Order::STATUS_PENDING)->latest()->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">续费订阅</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">你已购买的订阅都在这里管理：静态 ISP 线路可单独续费、加购流量包；机房共享套餐可加购加油包。ISP 到期前续费保留原 IP，已过期续费后重新分配新 IP。</p>
        @if ($hasSharedPlan)
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">机房共享套餐的<strong>续费</strong>仍在<a href="/plans" class="underline">购买订阅</a>页（找到当前套餐点"续费"直接延长有效期）；这里只加购加油包。</p>
        @endif
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    @if ($couponEnabled && ($instances->count() || $sharedPlan))
        <div class="max-w-xs">
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">优惠券兑换码（续费、加油包都可用）</label>
            <input type="text" wire:model.live.debounce.500ms="couponCode" placeholder="有优惠码就填在这里" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono uppercase">
            @if ($couponPreview)
                <p class="mt-1 text-xs {{ str_starts_with($couponPreview, '✓') ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $couponPreview }}</p>
            @endif
        </div>
    @endif

    {{-- 待支付：续费 / 加油包下单后就在这里付款，不用跳「我的订单」 --}}
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

    {{-- 机房共享套餐 · 加油包（续费在购买订阅页；这里只加购流量） --}}
    @if ($sharedPlan)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 max-w-2xl">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">机房共享套餐</span>
                <a href="/plans" class="text-xs text-teal-600 dark:text-teal-400 hover:underline">去续费 →</a>
            </div>
            <h3 class="font-medium text-gray-900 dark:text-gray-100 mb-1">{{ $sharedPlan->name }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">到期时间：{{ auth()->user()->plan_expired_at?->format('Y-m-d H:i') }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">已用 {{ number_format($sharedUsedBytes / 1073741824, 1) }} / {{ number_format($sharedQuotaBytes / 1073741824, 0) }} GB</p>

            <div class="border-t border-gray-100 dark:border-gray-800 pt-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">流量不够用？加购加油包，即时到账</p>
                <ul class="text-xs text-gray-400 dark:text-gray-500 mb-2 space-y-0.5">
                    @if ($sharedPlan->traffic_pack_price_per_gb)
                        <li>· 流量包：永久有效，用完不清零、不随月重置，适合长期备用（需配合有效套餐使用，套餐到期期间无法使用，续费后自动恢复）</li>
                    @endif
                    @if ($sharedPlan->traffic_reset_pack_price_per_gb)
                        <li>· 重置包：仅当期有效，下次流量重置时作废，适合临时超量</li>
                    @endif
                </ul>
                <div class="flex flex-wrap gap-2">
                    @if ($sharedPlan->traffic_pack_price_per_gb)
                        <button type="button" wire:click="openSharedPack('one_time')" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">
                            流量包 ¥{{ number_format($sharedPlan->traffic_pack_price_per_gb / 100, 2) }}/GB · 永久
                        </button>
                    @endif
                    @if ($sharedPlan->traffic_reset_pack_price_per_gb)
                        <button type="button" wire:click="openSharedPack('resettable')" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">
                            重置包 ¥{{ number_format($sharedPlan->traffic_reset_pack_price_per_gb / 100, 2) }}/GB · 当期
                        </button>
                    @endif
                </div>
                @if ($sharedPackKind !== '')
                    <div class="mt-3 flex items-end gap-2">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $sharedPackKind === 'resettable' ? '重置包' : '流量包' }}数量（GB）</label>
                            <input type="number" min="1" wire:model="sharedPackGigabytes" class="w-24 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-1.5 text-sm">
                        </div>
                        <button type="button" wire:click="buySharedPack" wire:loading.attr="disabled" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">购买</button>
                        <button type="button" wire:click="cancelSharedPack" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">取消</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($instances->count())
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($instances as $instance)
                @php
                    $isExpired = $instance->isExpired();
                    $statusLabel = match (true) {
                        $isExpired => '已过期',
                        $instance->status === 'active' => '运行中',
                        $instance->status === 'pending' => '开通中',
                        default => '已停用',
                    };
                    $statusStyles = match (true) {
                        $isExpired => 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400',
                        $instance->status === 'active' => 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400',
                        default => 'bg-gray-100 dark:bg-gray-800 text-gray-500',
                    };
                    $ip = $instance->meta['ip'] ?? null;
                    $country = $instance->meta['country'] ?? null;
                @endphp
                <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">专属线路</span>
                        <span class="text-xs font-medium px-2.5 py-1 rounded-full {{ $statusStyles }}">{{ $statusLabel }}</span>
                    </div>
                    <h3 class="font-medium text-gray-900 dark:text-gray-100 mb-1">{{ $instance->plan->name }}</h3>
                    @if ($ip || $country)
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mb-1">{{ trim(($country ?? '').' '.($ip ?? '')) }}</p>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                        到期时间：{{ $instance->expired_at?->format('Y-m-d H:i') }}
                        @if ($isExpired)
                            · <span class="text-rose-600 dark:text-rose-400">续费将分配新 IP</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">已用 {{ number_format(($instance->u + $instance->d) / 1073741824, 1) }} / {{ number_format($instance->effectiveQuotaBytes() / 1073741824, 0) }} GB</p>

                    {{-- 续费按钮（每条线路单独续费；动态线路按其所在地区的实际价格显示） --}}
                    <div class="flex flex-wrap gap-2">
                        @foreach ($instance->plan->prices as $period => $price)
                            <button
                                wire:click="renew({{ $instance->id }}, '{{ $period }}')"
                                wire:loading.attr="disabled"
                                class="text-xs border border-teal-300 dark:border-teal-700 rounded-lg px-3 py-1.5 text-teal-700 dark:text-teal-400 hover:border-teal-600"
                            >
                                {{ $this->periodLabel($period) }} 续费 ¥{{ number_format($this->renewalPrice($instance, $period) / 100, 2) }}
                            </button>
                        @endforeach
                    </div>

                    {{-- 加油包：给这条线路加购流量（永久有效、用完不清零） --}}
                    @if ($instance->plan->traffic_pack_price_per_gb)
                        <div class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-3">
                            @if ($packInstanceId === $instance->id)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">加购流量包 · ¥{{ number_format($instance->plan->traffic_pack_price_per_gb / 100, 2) }}/GB · 永久有效</p>
                                <div class="flex items-end gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">数量（GB）</label>
                                        <input type="number" min="1" wire:model="packGigabytes" class="w-24 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-1.5 text-sm">
                                    </div>
                                    <button type="button" wire:click="buyPack" wire:loading.attr="disabled" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">购买</button>
                                    <button type="button" wire:click="cancelPack" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">取消</button>
                                </div>
                            @else
                                <button type="button" wire:click="openPack({{ $instance->id }})" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">
                                    流量不够用？加购流量包 ¥{{ number_format($instance->plan->traffic_pack_price_per_gb / 100, 2) }}/GB
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif (! $sharedPlan)
        <p class="text-sm text-gray-500 dark:text-gray-400">还没有可续费的专属线路，去<a href="/plans" class="underline">购买订阅</a>看看。</p>
    @endif
</div>
