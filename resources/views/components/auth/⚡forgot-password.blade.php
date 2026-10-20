<?php

use App\Services\WebAbuseLimiter;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';
    public string $status = '';
    public string $error = '';

    public function sendResetLink(WebAbuseLimiter $limiter): void
    {
        $this->status = '';
        $this->error = '';

        $this->validate(['email' => ['required', 'email', 'max:255']]);

        $ip = request()->ip();
        if ($limiter->passwordResetRetryAfter($ip) > 0) {
            $this->error = '请求过于频繁，请稍后再试';

            return;
        }

        $limiter->recordPasswordResetRequest($ip);

        // Lowercase to match how emails are stored (User::email()) — otherwise
        // a customer registered as "Me@QQ.com" gets the "sent" message but no mail.
        try {
            $result = Password::sendResetLink(['email' => strtolower(trim($this->email))]);
        } catch (\Throwable $e) {
            report($e);
            $this->error = '重置邮件发送失败，请稍后重试或联系客服';

            return;
        }

        // Deliberately show the same message whether or not the email is
        // registered — confirming/denying an account's existence here
        // would let anyone enumerate real accounts by trying addresses.
        if ($result === Password::RESET_THROTTLED) {
            $this->error = '请求过于频繁，请稍后再试';

            return;
        }

        $this->status = '如果该邮箱已注册，重置密码的链接已经发送过去了，请查收。';
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-1 text-gray-900 dark:text-gray-100">找回密码</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">输入注册邮箱，我们会发一条重置密码的链接过去。</p>

    @if ($status)
        <div class="mb-4 text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="mb-4 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-4">
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮箱</label>
            <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
            @error('email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
            发送重置链接
        </button>
    </form>

    <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
        想起来了？<a href="/login" class="text-gray-900 dark:text-gray-100 underline">返回登录</a>
    </p>
</div>
