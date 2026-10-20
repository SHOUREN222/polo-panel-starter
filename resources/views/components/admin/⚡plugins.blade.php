<?php

use App\Models\Payment;
use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '插件管理'])] class extends Component
{
    public string $tab = 'all';
    public string $search = '';

    /**
     * Built-in feature toggles — not "plugins" in the sense of dynamically
     * loaded/uploaded code (see the missing 上传插件 button: deliberately
     * not built, arbitrary plugin upload is real-code-execution risk this
     * project isn't taking on), just first-party integrations already in
     * the codebase, presented the way Xboard presents theirs.
     */
    private const FEATURE_PLUGINS = [
        [
            'key' => 'plugin.subscription_endpoint_enabled',
            'name' => '订阅端点',
            'slug' => 'subscribe_endpoint',
            'description' => '控制客户端订阅链接（/subscribe/{token}）是否可用，关闭后所有客户端订阅会返回 503。',
        ],
        [
            'key' => 'plugin.auto_provision_enabled',
            'name' => '静态 IP 自动开通',
            'slug' => 'auto_provision',
            'description' => '控制静态 ISP 订单支付后是否自动创建开通任务并分配 IP。关闭期间的新订单会停留在待处理状态，重新启用后需要手动处理。',
        ],
        [
            'key' => 'plugin.telegram_integration_enabled',
            'name' => 'Telegram Bot 集成',
            'slug' => 'telegram',
            'description' => '控制 Telegram 通知发送和机器人命令响应（/bind /status /traffic）。关闭后已绑定的账号不受影响，只是暂停收发。',
            'configRoute' => 'admin.system',
        ],
        [
            'key' => 'email_verification_enabled',
            'name' => '邮箱验证',
            'slug' => 'email_verification',
            'description' => '强制新用户注册后验证邮箱才能使用，未验证用户会被拦截到验证提示页。',
            'default' => false,
            'configRoute' => 'admin.system',
        ],
        [
            'key' => 'magic_login_enabled',
            'name' => '邮件登录',
            'slug' => 'magic_login',
            'description' => '免密邮件登录入口，关闭后登录页隐藏入口，已发出的登录链接也会立即失效。',
            'default' => true,
            'configRoute' => 'admin.system',
        ],
        [
            'key' => 'three_tier_commission_enabled',
            'name' => '三级分销',
            'slug' => 'three_tier_commission',
            'description' => '按配置比例把佣金分给上级、上上级推荐人，不开启则只有直接推荐人拿全部佣金。',
            'default' => false,
            'configRoute' => 'admin.system',
        ],
        [
            'key' => 'plan_change_proration_enabled',
            'name' => '套餐折抵换购',
            'slug' => 'plan_proration',
            'description' => '用户更换共享套餐时，当前套餐剩余天数按日折算抵扣新套餐价格。',
            'default' => false,
            'configRoute' => 'admin.system',
        ],
        [
            'key' => 'mail_reminders_enabled',
            'name' => '邮件提醒',
            'slug' => 'mail_reminders',
            'description' => '订阅到期、流量不足时自动发送提醒邮件的定时任务。',
            'default' => true,
            'configRoute' => 'admin.system',
        ],
        [
            // Reuses the existing `stop_register` setting (already consumed by
            // the register component + 系统配置 安全设置 tab) so there is one
            // source of truth, not two competing switches. invert: the plugin
            // card shows 开放注册 = ON when stop_register is OFF.
            'key' => 'stop_register',
            'name' => '开放注册',
            'slug' => 'registration',
            'description' => '控制是否开放新用户注册。关闭后注册页返回提示、登录页隐藏注册入口，只有已有账号能登录。',
            'default' => false,
            'invert' => true,
        ],
        [
            'key' => 'registration_invite_only',
            'name' => '邀请制注册',
            'slug' => 'registration_invite_only',
            'description' => '开启后必须填写有效邀请码才能注册（配合「开放注册」使用）。适合做封闭邀请制。',
            'default' => false,
        ],
        [
            'key' => 'ticket_system_enabled',
            'name' => '工单系统',
            'slug' => 'ticket_system',
            'description' => '客户提交/查看工单的功能。关闭后客户端隐藏「我的工单」入口、无法新建工单（只用 Telegram/群做客服时可关）。',
            'default' => true,
        ],
        [
            'key' => 'coupon_enabled',
            'name' => '优惠券',
            'slug' => 'coupon',
            'description' => '结账页的优惠券兑换框。关闭后不显示优惠券输入、下单也不再套用优惠码（没做促销时可关）。',
            'default' => true,
        ],
        [
            'key' => 'balance_payment_enabled',
            'name' => '余额支付',
            'slug' => 'balance_payment',
            'description' => '客户用账户余额全额支付或抵扣订单。关闭后隐藏「用余额支付」按钮、也不允许用余额结算。',
            'default' => true,
        ],
        [
            'key' => 'referral_enabled',
            'name' => '邀请返佣',
            'slug' => 'referral',
            'description' => '整个邀请返佣体系总开关。关闭后不再产生佣金、客户端隐藏「我的邀请」入口（比把佣金比例设 0 更彻底）。',
            'default' => true,
        ],
    ];

    public function toggleFeature(string $key): void
    {
        $definition = collect(self::FEATURE_PLUGINS)->firstWhere('key', $key);
        $default = $definition['default'] ?? true;

        $current = Setting::getBool($key, $default);
        Setting::set($key, ! $current);
    }

    public function togglePayment(int $paymentId): void
    {
        $payment = Payment::findOrFail($paymentId);
        $payment->update(['enable' => ! $payment->enable]);
    }

    public function deletePayment(int $paymentId): void
    {
        Payment::findOrFail($paymentId)->delete();
    }

    public function with(): array
    {
        $features = collect(self::FEATURE_PLUGINS)->map(function ($p) {
            $default = $p['default'] ?? true;
            $raw = Setting::getBool($p['key'], $default);

            return [
                ...$p,
                'category' => 'feature',
                'enabled' => ($p['invert'] ?? false) ? ! $raw : $raw,
            ];
        });

        $payments = Payment::orderBy('id')->get()->map(fn (Payment $payment) => [
            'key' => "payment.{$payment->id}",
            'payment_id' => $payment->id,
            'name' => $payment->name,
            'slug' => $payment->gateway,
            'description' => (Payment::GATEWAY_LABELS[$payment->gateway] ?? $payment->gateway).' 支付渠道',
            'category' => 'payment',
            'enabled' => $payment->enable,
        ]);

        $all = $features->concat($payments);

        if ($this->tab !== 'all') {
            $all = $all->where('category', $this->tab);
        }

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $all = $all->filter(fn ($p) => str_contains(mb_strtolower($p['name']), $needle) || str_contains(mb_strtolower($p['slug']), $needle));
        }

        return [
            'plugins' => $all->values(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">插件管理</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">管理内置功能模块和支付渠道的启用状态。</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <input
            type="text"
            wire:model.live="search"
            placeholder="搜索插件名称或描述..."
            class="flex-1 min-w-[200px] border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"
        >
        <div class="flex gap-1.5">
            <button type="button" wire:click="$set('tab', 'feature')" class="text-xs rounded-lg px-3 py-1.5 {{ $tab === 'feature' ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">功能</button>
            <button type="button" wire:click="$set('tab', 'payment')" class="text-xs rounded-lg px-3 py-1.5 {{ $tab === 'payment' ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">支付方式</button>
            <button type="button" wire:click="$set('tab', 'all')" class="text-xs rounded-lg px-3 py-1.5 {{ $tab === 'all' ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">所有插件</button>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($plugins as $plugin)
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ $plugin['name'] }}</h3>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ $plugin['category'] === 'payment' ? '支付方式' : '功能' }}</span>
                        @if ($plugin['enabled'])
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400">已启用</span>
                        @else
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">已禁用</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 font-mono mb-1">{{ $plugin['slug'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xl">{{ $plugin['description'] }}</p>
                </div>
                <div class="shrink-0 flex items-center gap-2">
                    @if ($plugin['category'] === 'payment')
                        <a href="{{ route('admin.payments') }}" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5">配置</a>
                        <button
                            wire:click="togglePayment({{ $plugin['payment_id'] }})"
                            class="text-xs font-medium rounded-lg px-3 py-1.5 {{ $plugin['enabled'] ? 'border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400' : 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' }}"
                        >{{ $plugin['enabled'] ? '禁用' : '启用' }}</button>
                        <button wire:click="deletePayment({{ $plugin['payment_id'] }})" wire:confirm="确定卸载「{{ $plugin['name'] }}」吗？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">卸载</button>
                    @else
                        @if (isset($plugin['configRoute']))
                            <a href="{{ route($plugin['configRoute']) }}" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5">配置</a>
                        @endif
                        <button
                            wire:click="toggleFeature('{{ $plugin['key'] }}')"
                            wire:confirm="确定要{{ $plugin['enabled'] ? '禁用' : '启用' }}「{{ $plugin['name'] }}」吗？"
                            class="text-xs font-medium rounded-lg px-3 py-1.5 {{ $plugin['enabled'] ? 'border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400' : 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' }}"
                        >{{ $plugin['enabled'] ? '禁用' : '启用' }}</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-8">没有匹配的插件</p>
        @endforelse
    </div>
</div>
