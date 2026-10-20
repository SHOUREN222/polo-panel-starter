<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '品牌设置'])] class extends Component
{
    public string $site_name = '';
    public string $site_logo_url = '';
    public string $site_description = '';
    public string $theme_primary_color = '';
    public bool $support_widget_enabled = false;
    public string $support_telegram = '';
    public string $support_telegram_group = '';
    public string $support_email = '';
    public string $support_wechat = '';
    public string $support_qq = '';
    public string $support_note = '';
    public string $telegram_group_url = '';
    public string $telegram_bot_token = '';
    public string $telegram_bot_username = '';
    public string $successMessage = '';

    public function mount(): void
    {
        $this->site_name = (string) Setting::get('site_name', config('app.name'));
        $this->site_logo_url = (string) Setting::get('site_logo_url', '');
        $this->site_description = (string) Setting::get('site_description', '');
        $this->theme_primary_color = (string) Setting::get('theme_primary_color', '');
        $this->support_widget_enabled = Setting::getBool('support_widget_enabled', false);
        $this->support_telegram = (string) Setting::get('support_telegram', '');
        $this->support_telegram_group = (string) Setting::get('support_telegram_group', '');
        $this->support_email = (string) Setting::get('support_email', '');
        $this->support_wechat = (string) Setting::get('support_wechat', '');
        $this->support_qq = (string) Setting::get('support_qq', '');
        $this->support_note = (string) Setting::get('support_note', '');
        $this->telegram_group_url = (string) Setting::get('telegram_group_url', '');
        $this->telegram_bot_token = (string) Setting::get('telegram_bot_token', '');
        $this->telegram_bot_username = (string) Setting::get('telegram_bot_username', '');
    }

    public function save(): void
    {
        $this->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'site_logo_url' => ['nullable', 'url'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'theme_primary_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'support_email' => ['nullable', 'email'],
            'support_note' => ['nullable', 'string', 'max:500'],
        ], [], ['theme_primary_color' => '主题色']);

        foreach ([
            'site_name', 'site_logo_url', 'site_description', 'theme_primary_color',
            'support_telegram', 'support_telegram_group', 'support_email',
            'support_wechat', 'support_qq', 'support_note', 'telegram_group_url',
        ] as $key) {
            Setting::set($key, trim((string) $this->{$key}));
        }
        Setting::set('support_widget_enabled', $this->support_widget_enabled);

        // 分站 only — 主站's bot is configured under 系统设置 → Telegram 设置,
        // and writing it from here would just shadow that with a duplicate.
        if (tenant()) {
            Setting::set('telegram_bot_token', trim($this->telegram_bot_token));
            Setting::set('telegram_bot_username', ltrim(trim($this->telegram_bot_username), '@'));
        }

        $this->successMessage = tenant() ? '本分站品牌已保存' : '品牌设置已保存';
    }
};
?>

<div class="space-y-6 max-w-xl">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">品牌设置</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            @if (tenant())
                自定义本分站的门面：站名、logo、主题色、客服。留空的项沿用平台默认。
            @else
                主站品牌。分站可在各自后台单独设置，覆盖这里。
            @endif
        </p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">外观</h2>
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">站点名称</label>
            <input type="text" wire:model="site_name" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('site_name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">LOGO 图片地址（可选）</label>
            <input type="text" wire:model="site_logo_url" placeholder="https://…（留空显示站名文字）" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('site_logo_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">站点描述（可选）</label>
            <input type="text" wire:model="site_description" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">主题色（#RRGGBB，可选）</label>
            <input type="text" wire:model="theme_primary_color" placeholder="#0d9488" class="w-40 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
            @error('theme_primary_color') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">客服（悬浮入口）</h2>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="support_widget_enabled"> 开启悬浮客服
        </label>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Telegram 客服号</label>
                <input type="text" wire:model="support_telegram" placeholder="@YourSupport" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Telegram 交流群</label>
                <input type="text" wire:model="support_telegram_group" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">客服邮箱</label>
                <input type="email" wire:model="support_email" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('support_email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">微信号</label>
                <input type="text" wire:model="support_wechat" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">QQ</label>
                <input type="text" wire:model="support_qq" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">提示语（可选）</label>
            <input type="text" wire:model="support_note" placeholder="如：工作时间 10:00-22:00" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Telegram 交流群链接</label>
            <input type="text" wire:model="telegram_group_url" placeholder="https://t.me/your_group" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                客户在「个人中心」会看到这个群的入口。
                @if (tenant())
                    留空则不显示——<strong>不会</strong>把你的客户引导到平台的群里。
                @endif
            </p>
        </div>
    </div>

    @if (tenant())
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <div>
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">Telegram 机器人（可选）</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    填了之后，本站客户绑定的就是<strong>你自己的机器人</strong>，到期/流量提醒也从它发出。
                    留空则使用平台的公用机器人（功能一样，只是名字不是你的）。
                </p>
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">机器人 Token</label>
                <input type="password" wire:model="telegram_bot_token" placeholder="从 @BotFather 获取，形如 123456:AAE..." class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">机器人用户名</label>
                <input type="text" wire:model="telegram_bot_username" placeholder="YourShopBot（不用加 @）" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">客户在「个人中心 → 绑定 Telegram」会看到这个名字，用来找到你的机器人。</p>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500">
                怎么弄：在 Telegram 里找 <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">@BotFather</code> →
                发送 <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">/newbot</code> → 起名 → 把它给的 Token 粘到上面。
                保存后最多两分钟生效。
            </p>
        </div>
    @endif

    <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
</div>
