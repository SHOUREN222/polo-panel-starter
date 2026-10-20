<?php

use App\Models\Payment;
use App\Support\PublicHttpUrl;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '支付配置'])] class extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $gateway = '';
    public bool $enable = true;
    public string $icon_url = '';
    public string $notify_domain = '';
    public string $fee_percent = '0';
    public string $fee_fixed = '0';

    // Gateway-specific credential fields — only the ones matching the
    // selected gateway get validated/persisted (see gatewayConfigKeys()).
    public string $cfg_gateway_url = '';
    public string $cfg_pid = '';
    public string $cfg_key = '';
    public string $cfg_app_id = '';
    public string $cfg_private_key = '';
    public string $cfg_alipay_public_key = '';
    public string $cfg_server_url = '';
    public string $cfg_api_key = '';
    public string $cfg_store_id = '';
    public string $cfg_webhook_secret = '';
    public string $cfg_public_key = '';
    public string $cfg_ipn_secret = '';
    public string $cfg_currency = 'USD';

    public string $successMessage = '';
    public string $error = '';

    /**
     * Which config fields apply to each gateway type — drives both which
     * inputs the form shows and which ones get saved into `config`.
     */
    private function gatewayConfigKeys(): array
    {
        return match ($this->gateway) {
            Payment::GATEWAY_EPAY_ALIPAY, Payment::GATEWAY_EPAY_WXPAY => ['gateway_url', 'pid', 'key'],
            Payment::GATEWAY_ALIPAYF2F => ['app_id', 'private_key', 'alipay_public_key'],
            Payment::GATEWAY_BTCPAY => ['server_url', 'api_key', 'store_id', 'webhook_secret', 'currency'],
            Payment::GATEWAY_COINBASE => ['api_key', 'webhook_secret', 'currency'],
            Payment::GATEWAY_COINPAYMENTS => ['public_key', 'private_key', 'ipn_secret', 'currency'],
            default => [],
        };
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $paymentId): void
    {
        $payment = Payment::findOrFail($paymentId);

        $this->editingId = $payment->id;
        $this->name = $payment->name;
        $this->gateway = $payment->gateway;
        $this->enable = $payment->enable;
        $this->icon_url = $payment->icon_url ?? '';
        $this->notify_domain = $payment->notify_domain ?? '';
        $this->fee_percent = (string) $payment->fee_percent;
        $this->fee_fixed = number_format($payment->fee_fixed / 100, 2, '.', '');

        $config = $payment->config ?? [];
        foreach ($config as $key => $value) {
            $property = 'cfg_'.$key;
            if (property_exists($this, $property)) {
                $this->{$property} = (string) $value;
            }
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'gateway' => ['required', 'string', 'in:'.implode(',', array_merge([Payment::GATEWAY_MOCK, Payment::GATEWAY_EPAY_ALIPAY, Payment::GATEWAY_EPAY_WXPAY], array_keys(Payment::GATEWAY_LABELS)))],
            'icon_url' => ['nullable', 'url'],
            'notify_domain' => ['nullable', 'url'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['required', 'numeric', 'min:0'],
        ]);

        // Gateway credentials are required for every real gateway (mock needs
        // none). Caught here so a half-filled config is rejected at save time
        // instead of only surfacing as a 503 at checkout.
        foreach ($this->gatewayConfigKeys() as $key) {
            if (trim((string) $this->{'cfg_'.$key}) === '') {
                $this->addError('cfg_'.$key, '该字段为必填');
            }
        }

        if ($this->gateway === Payment::GATEWAY_BTCPAY && trim($this->cfg_server_url) !== '') {
            try {
                $this->cfg_server_url = app(PublicHttpUrl::class)->resolve($this->cfg_server_url)['url'];
            } catch (InvalidArgumentException $e) {
                $this->addError('cfg_server_url', $e->getMessage());
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $config = [];
        foreach ($this->gatewayConfigKeys() as $key) {
            $config[$key] = $this->{'cfg_'.$key};
        }

        $data = [
            'name' => $this->name,
            'gateway' => $this->gateway,
            'enable' => $this->enable,
            'icon_url' => $this->icon_url ?: null,
            'notify_domain' => $this->notify_domain ?: null,
            'fee_percent' => (float) $this->fee_percent,
            // Entered in yuan, stored in cents (matches plan price convention),
            // because amountWithFee() adds fee_fixed onto a cents amount.
            'fee_fixed' => (int) round(((float) $this->fee_fixed) * 100),
            'config' => $config ?: null,
        ];

        if ($this->editingId) {
            Payment::findOrFail($this->editingId)->update($data);
            $this->successMessage = '支付方式已更新';
        } else {
            Payment::create($data);
            $this->successMessage = '支付方式已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggle(int $paymentId): void
    {
        $payment = Payment::findOrFail($paymentId);
        $payment->update(['enable' => ! $payment->enable]);
    }

    public function delete(int $paymentId): void
    {
        Payment::findOrFail($paymentId)->delete();
        $this->successMessage = '支付方式已删除';
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->gateway = '';
        $this->enable = true;
        $this->icon_url = '';
        $this->notify_domain = '';
        $this->fee_percent = '0';
        $this->fee_fixed = '0';
        $this->cfg_gateway_url = '';
        $this->cfg_pid = '';
        $this->cfg_key = '';
        $this->cfg_app_id = '';
        $this->cfg_private_key = '';
        $this->cfg_alipay_public_key = '';
        $this->cfg_server_url = '';
        $this->cfg_api_key = '';
        $this->cfg_store_id = '';
        $this->cfg_webhook_secret = '';
        $this->cfg_public_key = '';
        $this->cfg_ipn_secret = '';
        $this->cfg_currency = 'USD';
    }

    public function with(): array
    {
        return [
            'payments' => Payment::orderBy('id')->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">支付配置</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">在这里可以配置支付方式，包括支付宝、微信、加密货币等。</p>
        </div>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5 shrink-0">添加支付方式</button>
        @endunless
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    @if ($showForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑支付方式' : '添加支付方式' }}</h2>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">显示名称</label>
                <input type="text" wire:model="name" placeholder="请输入支付名称" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于前端显示</p>
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">图标URL</label>
                <input type="text" wire:model="icon_url" placeholder="https://example.com/icon.svg" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('icon_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于前端显示的图标地址</p>
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">通知域名</label>
                <input type="text" wire:model="notify_domain" placeholder="https://example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('notify_domain') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">网关通知将发送到该域名</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">百分比手续费（%）</label>
                    <input type="number" step="0.01" wire:model="fee_percent" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('fee_percent') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">固定手续费（元）</label>
                    <input type="number" step="0.01" min="0" wire:model="fee_fixed" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('fee_fixed') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">支付接口</label>
                <select wire:model.live="gateway" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    <option value="">请选择支付接口</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_MOCK }}">模拟支付（测试用）</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_EPAY_ALIPAY }}">EPay（支付宝）</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_EPAY_WXPAY }}">EPay（微信）</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_ALIPAYF2F }}">AlipayF2F</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_BTCPAY }}">BTCPay</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_COINPAYMENTS }}">CoinPayments</option>
                    <option value="{{ \App\Models\Payment::GATEWAY_COINBASE }}">Coinbase</option>
                </select>
                @error('gateway') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    @if (in_array($gateway, [\App\Models\Payment::GATEWAY_EPAY_ALIPAY, \App\Models\Payment::GATEWAY_EPAY_WXPAY]))
                        如果支付宝和微信都走同一个易支付账号，两条各自的商户号/密钥填一样的就行。
                    @endif
                </p>
            </div>

            {{-- 每个真实网关自己的凭证字段，只有选中对应接口时才显示 --}}
            @if (in_array($gateway, [\App\Models\Payment::GATEWAY_EPAY_ALIPAY, \App\Models\Payment::GATEWAY_EPAY_WXPAY]))
                <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">网关地址</label>
                        <input type="text" wire:model="cfg_gateway_url" placeholder="https://your-epay-domain.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">商户 ID（pid）</label>
                            <input type="text" wire:model="cfg_pid" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">商户密钥（key）</label>
                            <input type="text" wire:model="cfg_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        @if ($editingId)
                            在易支付后台把回调地址设置成 {{ url("/api/payment/{$editingId}/notify") }}。
                        @else
                            先保存一次，回到编辑页面才能看到完整的回调地址。
                        @endif
                    </p>
                </div>
            @elseif ($gateway === \App\Models\Payment::GATEWAY_ALIPAYF2F)
                <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">APPID</label>
                        <input type="text" wire:model="cfg_app_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">商户私钥（RSA2）</label>
                        <textarea wire:model="cfg_private_key" rows="3" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">支付宝公钥</label>
                        <textarea wire:model="cfg_alipay_public_key" rows="3" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono"></textarea>
                    </div>
                </div>
            @elseif ($gateway === \App\Models\Payment::GATEWAY_BTCPAY)
                <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">BTCPay 服务器地址</label>
                        <input type="text" wire:model="cfg_server_url" placeholder="https://btcpay.example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">API Key</label>
                            <input type="text" wire:model="cfg_api_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Store ID</label>
                            <input type="text" wire:model="cfg_store_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Webhook 密钥</label>
                        <input type="text" wire:model="cfg_webhook_secret" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            @if ($editingId)
                                在 BTCPay 后台 Store → Webhooks 里新建一个指向 {{ url("/api/payment/{$editingId}/notify") }} 的 webhook 并复制这里的密钥。
                            @else
                                先保存一次，回到编辑页面才能看到完整的回调地址。
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">结算币种</label>
                        <input type="text" wire:model="cfg_currency" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
            @elseif ($gateway === \App\Models\Payment::GATEWAY_COINBASE)
                <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">API Key</label>
                        <input type="text" wire:model="cfg_api_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Webhook 共享密钥</label>
                        <input type="text" wire:model="cfg_webhook_secret" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            @if ($editingId)
                                Coinbase Commerce 后台 Settings → Webhook subscriptions 里配置，指向 {{ url("/api/payment/{$editingId}/notify") }}。
                            @else
                                先保存一次，回到编辑页面才能看到完整的回调地址。
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">结算币种</label>
                        <input type="text" wire:model="cfg_currency" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
            @elseif ($gateway === \App\Models\Payment::GATEWAY_COINPAYMENTS)
                <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Public Key</label>
                            <input type="text" wire:model="cfg_public_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Private Key</label>
                            <input type="text" wire:model="cfg_private_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">IPN Secret</label>
                        <input type="text" wire:model="cfg_ipn_secret" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">CoinPayments 账户设置里单独生成的 IPN Secret，不是 Private Key。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">结算币种</label>
                        <input type="text" wire:model="cfg_currency" placeholder="BTC / LTC / ..." class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="enable">
                启用
            </label>

            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">ID</th>
                <th class="text-left px-4 py-2">启用</th>
                <th class="text-left px-4 py-2">显示名称</th>
                <th class="text-left px-4 py-2">支付接口</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $payment->id }}</td>
                    <td class="px-4 py-2">
                        <button
                            wire:click="toggle({{ $payment->id }})"
                            class="text-xs font-medium rounded-full px-2 py-1 {{ $payment->enable ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-500' }}"
                        >
                            {{ $payment->enable ? '已启用' : '已禁用' }}
                        </button>
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $payment->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs">{{ \App\Models\Payment::GATEWAY_LABELS[$payment->gateway] ?? ($payment->gateway === 'mock' ? '模拟支付' : $payment->gateway) }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="edit({{ $payment->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $payment->id }})" wire:confirm="确定删除这个支付方式？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有配置任何支付方式</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
