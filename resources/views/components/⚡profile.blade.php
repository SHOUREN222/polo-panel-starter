<?php

use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '个人中心'])] class extends Component
{
    public string $old_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';

    public bool $notify_expire = true;
    public bool $notify_traffic = true;

    public string $successMessage = '';
    public string $error = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->notify_expire = $user->notify_expire;
        $this->notify_traffic = $user->notify_traffic;
    }

    public function changePassword(): void
    {
        $this->successMessage = '';
        $this->error = '';

        $this->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = auth()->user();

        if (! Hash::check($this->old_password, $user->password)) {
            $this->error = '旧密码不正确';

            return;
        }

        $user->update(['password' => $this->new_password]);

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/login', navigate: true);
    }

    public function updatedNotifyExpire(bool $value): void
    {
        auth()->user()->update(['notify_expire' => $value]);
    }

    public function updatedNotifyTraffic(bool $value): void
    {
        auth()->user()->update(['notify_traffic' => $value]);
    }

    public function resetSubscription(): void
    {
        auth()->user()->resetSubscriptionCredentials();
        $this->successMessage = '订阅信息已重置，旧的订阅链接已失效';
    }

    public function generateTelegramBindCode(): void
    {
        auth()->user()->generateTelegramBindCode();
    }

    public function unbindTelegram(): void
    {
        auth()->user()->unbindTelegram();
        $this->successMessage = 'Telegram 已解绑';
    }

    public function with(): array
    {
        $user = auth()->user()->fresh();
        $telegram = app(TelegramService::class);

        $bindDeepLink = ($user->telegram_bind_code && $user->telegram_bind_code_expires_at?->isFuture())
            ? $telegram->deepLinkFor($user->telegram_bind_code)
            : null;

        return [
            'balance' => $user->balance,
            'telegramGroupUrl' => Setting::get('telegram_group_url'),
            'telegramGroupAppLink' => $telegram->appLinkForGroup(Setting::get('telegram_group_url')),
            'telegramUser' => $user,
            'telegramBindCode' => $bindDeepLink ? $user->telegram_bind_code : null,
            'telegramBindDeepLink' => $bindDeepLink,
            'telegramBindAppLink' => $bindDeepLink ? $telegram->appLinkFor($user->telegram_bind_code) : null,
            'telegramBotUsername' => $telegram->botUsername(),
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">个人中心</h1>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- 账户余额 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
        <div class="flex items-baseline gap-2 mb-1">
            <span class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($balance / 100, 2) }}</span>
            <span class="text-sm text-gray-400 dark:text-gray-500">CNY</span>
        </div>
        <div class="text-sm text-gray-500 dark:text-gray-400">账户余额</div>
    </div>

    {{-- 修改密码 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-md">
        <h2 class="font-medium text-gray-900 dark:text-gray-100">修改密码</h2>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">旧密码</label>
            <input type="password" wire:model="old_password" placeholder="请输入旧密码" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('old_password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">新密码</label>
            <input type="password" wire:model="new_password" placeholder="请输入新密码" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('new_password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">确认新密码</label>
            <input type="password" wire:model="new_password_confirmation" placeholder="请再次输入新密码" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
        </div>

        <button wire:click="changePassword" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
    </div>

    {{-- 通知 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
        <h2 class="font-medium text-gray-900 dark:text-gray-100">通知</h2>
        <p class="text-xs text-gray-400 dark:text-gray-500 -mt-2">到期/流量提醒会发到你的注册邮箱；如果绑定了 Telegram，也会同步推送一条。</p>

        <label class="flex items-center justify-between text-sm">
            <span class="text-gray-700 dark:text-gray-300">到期提醒</span>
            <input type="checkbox" wire:model="notify_expire" class="w-9 h-5">
        </label>
        <label class="flex items-center justify-between text-sm">
            <span class="text-gray-700 dark:text-gray-300">流量提醒</span>
            <input type="checkbox" wire:model="notify_traffic" class="w-9 h-5">
        </label>
    </div>

    {{-- 绑定 Telegram --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-3">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-medium text-gray-900 dark:text-gray-100">绑定 Telegram</h2>
                @if ($telegramUser->isTelegramBound())
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">已绑定 @{{ $telegramUser->telegram_username ?? $telegramUser->telegram_id }}，到期/流量提醒和工单回复会同步推送。发送 /status 或 /traffic 可随时查询。</p>
                @else
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">绑定后可以在 Telegram 里收到到期/流量提醒，也能用机器人指令查余额和流量。</p>
                @endif
            </div>
            @if ($telegramUser->isTelegramBound())
                <button wire:click="unbindTelegram" wire:confirm="确定要解绑 Telegram 吗？" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">解绑</button>
            @else
                <button wire:click="generateTelegramBindCode" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">生成绑定码</button>
            @endif
        </div>

        @if ($telegramBindDeepLink)
            <div x-data="{ copiedName: false, copiedCmd: false }" class="text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-800 rounded-lg p-3 space-y-3">
                {{-- 手动绑定（最稳，不依赖能否打开 t.me 链接） --}}
                <div class="space-y-2">
                    <p class="text-gray-700 dark:text-gray-200 font-medium">推荐：在 Telegram App 里手动绑定（15 分钟内有效）</p>
                    <ol class="text-gray-600 dark:text-gray-300 list-decimal list-inside space-y-1.5">
                        <li>
                            打开 Telegram，搜索机器人
                            @if ($telegramBotUsername)
                                <code class="bg-gray-200 dark:bg-gray-700 rounded px-1">{{ '@'.$telegramBotUsername }}</code>
                                <button type="button" @click="navigator.clipboard.writeText('{{ '@'.$telegramBotUsername }}'); copiedName = true; setTimeout(() => copiedName = false, 1500)" class="ml-1 text-xs text-teal-600 dark:text-teal-400"><span x-show="!copiedName">复制</span><span x-show="copiedName" x-cloak>已复制</span></button>
                            @endif
                        </li>
                        <li>
                            给它发送
                            <code class="bg-gray-200 dark:bg-gray-700 rounded px-1">/bind {{ $telegramBindCode }}</code>
                            <button type="button" @click="navigator.clipboard.writeText('/bind {{ $telegramBindCode }}'); copiedCmd = true; setTimeout(() => copiedCmd = false, 1500)" class="ml-1 text-xs text-teal-600 dark:text-teal-400"><span x-show="!copiedCmd">复制</span><span x-show="copiedCmd" x-cloak>已复制</span></button>
                        </li>
                    </ol>
                </div>

                {{-- 便捷链接：优先 tg:// 直接唤起 App，t.me 作为备用 --}}
                <div class="flex flex-wrap items-center gap-2 pt-1 border-t border-gray-100 dark:border-gray-800">
                    <span class="text-xs text-gray-400 dark:text-gray-500">或直接点：</span>
                    @if ($telegramBindAppLink)
                        <a href="{{ $telegramBindAppLink }}" class="inline-block text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">打开 Telegram App 绑定</a>
                    @endif
                    <a href="{{ $telegramBindDeepLink }}" target="_blank" class="text-xs text-gray-500 dark:text-gray-400 underline">用网页版打开（打不开就用上面手动方式）</a>
                </div>
            </div>
        @endif
    </div>

    {{-- Telegram 讨论组 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-3">
        <div>
            <h2 class="font-medium text-gray-900 dark:text-gray-100">Telegram 讨论组</h2>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">加入群组获取最新公告和支持。</p>
        </div>
        @if ($telegramGroupUrl)
            <div x-data="{ copied: false }" class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($telegramGroupAppLink)
                        <a href="{{ $telegramGroupAppLink }}" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">在 Telegram App 打开</a>
                    @endif
                    <a href="{{ $telegramGroupUrl }}" target="_blank" class="text-xs text-gray-500 dark:text-gray-400 underline">用网页版打开</a>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">打不开就复制链接，在 Telegram 里打开：</span>
                </div>
                <div class="flex items-center gap-2">
                    <code class="flex-1 text-xs bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 font-mono text-gray-600 dark:text-gray-400 truncate">{{ $telegramGroupUrl }}</code>
                    <button type="button" @click="navigator.clipboard.writeText('{{ $telegramGroupUrl }}'); copied = true; setTimeout(() => copied = false, 1500)" class="shrink-0 text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5"><span x-show="!copied">复制</span><span x-show="copied" x-cloak>已复制</span></button>
                </div>
            </div>
        @else
            <span class="text-xs text-gray-400 dark:text-gray-500">管理员还没有配置群链接</span>
        @endif
    </div>

    {{-- 重置订阅信息 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-3">
        <h2 class="font-medium text-gray-900 dark:text-gray-100">重置订阅信息</h2>
        <div class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 rounded-lg p-3">
            当你的订阅地址或账户发生泄漏被他人滥用时，可以在此重置订阅信息。重置后所有已导入的订阅链接会立即失效，需要重新导入。
        </div>
        <button
            wire:click="resetSubscription"
            wire:confirm="确定要重置订阅信息吗？旧的订阅链接会立即失效。"
            class="text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg px-4 py-2"
        >重置</button>
    </div>
</div>
