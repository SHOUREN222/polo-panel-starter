<?php

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AdminSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin-guest')] class extends Component
{
    public string $code = '';
    public string $error = '';
    public bool $useRecovery = false;

    /** No pending login → nothing to challenge; send them back to sign in. */
    public function mount(): void
    {
        if (! AdminSession::pendingUser()) {
            $this->redirect('/' . config('admin.path') . '/login', navigate: true);
        }
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;
        $this->code = '';
        $this->error = '';
    }

    public function verify(TwoFactorService $twoFactor): void
    {
        $this->error = '';

        $user = AdminSession::pendingUser();
        if (! $user) {
            $this->redirect('/' . config('admin.path') . '/login', navigate: true);

            return;
        }

        // A 6-digit code is only 1M combinations — throttle hard so the second
        // factor can't be brute-forced while a stolen password is held.
        $throttleKey = \App\Support\SiteKey::scope('admin-2fa:'.$user->id);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);
            $this->error = "尝试次数过多，请 {$minutes} 分钟后再试";

            return;
        }

        $passed = $this->useRecovery
            ? $this->consumeRecoveryCode($user, $this->code)
            : $twoFactor->verify($user->two_factor_secret ?? '', $this->code);

        if (! $passed) {
            RateLimiter::hit($throttleKey, 300);
            $this->error = $this->useRecovery ? '恢复码无效或已使用' : '验证码错误';

            return;
        }

        RateLimiter::clear($throttleKey);
        AdminSession::clearPending();

        Auth::login($user);
        session()->regenerate();
        AdminSession::markVerified($user, true);

        $this->redirect('/' . config('admin.path'), navigate: true);
    }

    /** One-time recovery codes: match case-insensitively, then burn it. */
    private function consumeRecoveryCode(User $user, string $input): bool
    {
        $input = strtoupper(trim($input));
        $codes = $user->two_factor_recovery_codes ?? [];

        $remaining = array_values(array_filter($codes, fn ($c) => strtoupper($c) !== $input));

        if (count($remaining) === count($codes)) {
            return false; // no match
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }
};
?>

<div class="max-w-sm mx-auto mt-24 bg-gray-900 border border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-1 text-gray-100">两步验证</h1>
    <p class="text-xs text-gray-500 mb-6">
        {{ $useRecovery ? '输入一个恢复码完成登录' : '打开身份验证器 App，输入 6 位动态验证码' }}
    </p>

    @if ($error)
        <div class="text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg p-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="verify" class="space-y-4">
        <div>
            <input
                type="text"
                wire:model="code"
                inputmode="{{ $useRecovery ? 'text' : 'numeric' }}"
                autocomplete="one-time-code"
                placeholder="{{ $useRecovery ? '恢复码（如 A1B2C-D3E4F）' : '6 位验证码' }}"
                class="w-full border border-gray-700 bg-gray-800 text-gray-100 rounded-lg px-3 py-2 text-sm tracking-widest text-center"
                autofocus
            >
        </div>

        <button type="submit" class="w-full text-sm bg-gray-100 text-gray-900 rounded-lg px-4 py-2 font-medium">验证并登录</button>
    </form>

    <div class="mt-4 text-center">
        <button wire:click="toggleRecovery" class="text-xs text-gray-500 hover:text-gray-300">
            {{ $useRecovery ? '← 改用验证器动态码' : '丢了手机？改用恢复码' }}
        </button>
    </div>
</div>
