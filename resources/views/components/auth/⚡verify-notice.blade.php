<?php

use App\Notifications\VerifyEmailAddress;
use App\Services\WebAbuseLimiter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $status = '';
    public string $error = '';

    public function resend(WebAbuseLimiter $limiter): void
    {
        $this->status = '';
        $this->error = '';

        $user = auth()->user();
        $ip = request()->ip();

        if ($limiter->verificationEmailRetryAfter((int) $user->id, $ip) > 0) {
            $this->error = '请求过于频繁，请稍后再试';

            return;
        }

        $limiter->recordVerificationEmail((int) $user->id, $ip);
        $user->notify(new VerifyEmailAddress());
        $this->status = '验证邮件已重新发送，请查收。';
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/login', navigate: true);
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8 text-center">
    @include('partials.icon', ['name' => 'mail-lg'])
    <h1 class="text-xl font-semibold mt-4 mb-2 text-gray-900 dark:text-gray-100">请验证您的邮箱</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">我们已经向 {{ auth()->user()->email }} 发送了一封验证邮件，请点击邮件中的链接完成验证后再继续使用。</p>

    @if ($status)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3 mb-4">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3 mb-4">{{ $error }}</div>
    @endif

    <button wire:click="resend" wire:loading.attr="disabled" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium mb-2">
        重新发送验证邮件
    </button>
    <button wire:click="logout" class="w-full text-sm text-gray-500 dark:text-gray-400 underline">
        退出登录
    </button>
</div>
