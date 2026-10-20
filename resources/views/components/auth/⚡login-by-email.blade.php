<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\WebAbuseLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';
    public string $status = '';
    public string $error = '';

    public function mount(): void
    {
        abort_unless(Setting::getBool('magic_login_enabled', true), 404);
    }

    public function send(WebAbuseLimiter $limiter): void
    {
        $this->status = '';
        $this->error = '';

        abort_unless(Setting::getBool('magic_login_enabled', true), 404);

        $this->validate(['email' => ['required', 'email', 'max:255']]);

        $email = strtolower(trim($this->email));
        $ip = request()->ip();

        if ($limiter->magicLoginRetryAfter($email, $ip) > 0) {
            $this->error = '请求过于频繁，请稍后再试';

            return;
        }
        $limiter->recordMagicLoginRequest($email, $ip);

        $user = User::where('email', $email)->first();

        // Same anti-enumeration pattern as 找回密码 — identical message
        // whether or not the address is registered.
        if ($user && ! $user->banned && ! $user->isAdmin()) {
            $user->notify(new MagicLoginLink());
        }

        $this->status = '如果该邮箱已注册，登录链接已经发送过去了，请查收（5 分钟内有效）。';
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-1 text-gray-900 dark:text-gray-100">邮件登录</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">输入注册邮箱，我们会发一条登录链接过去，无需密码。</p>

    @if ($status)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3 mb-4">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="send" class="space-y-4">
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮箱</label>
            <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
            @error('email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
            发送登录链接
        </button>
    </form>

    <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
        <a href="/login" class="text-gray-900 dark:text-gray-100 underline">使用密码登录</a>
    </p>
</div>
