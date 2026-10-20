<?php

use App\Services\WebAbuseLimiter;
use App\Support\AdminSession;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin-guest')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    public function mount(): void
    {
        $this->error = (string) session()->pull('admin_auth_error', '');
    }

    public function login(WebAbuseLimiter $limiter): void
    {
        $this->error = '';

        $credentials = $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $credentials['email'] = strtolower(trim($credentials['email']));

        $ip = request()->ip();
        $retryAfter = $limiter->loginRetryAfter($credentials['email'], $ip);

        if ($retryAfter > 0) {
            $minutes = max(1, (int) ceil($retryAfter / 60));
            $this->error = "登录尝试次数过多，请 {$minutes} 分钟后再试";

            return;
        }

        // Verify the password WITHOUT logging in yet — if 2FA is on we must
        // hold the session at the door until the code is also proven.
        if (! Auth::validate($credentials)) {
            $limiter->recordLoginFailure($credentials['email'], $ip);

            $this->error = '邮箱或密码错误';

            return;
        }

        $limiter->clearLoginAccount($credentials['email'], $ip);

        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if ($user->banned) {
            $this->error = '账号已被封禁';

            return;
        }

        if (! $user->isAdmin()) {
            $this->error = '该账号不是管理员，无法登录后台';

            return;
        }

        // Password is correct. If this admin has 2FA, stash a pending marker and
        // hand off to the challenge page instead of logging in — the actual
        // Auth::login only happens after the TOTP/recovery code checks out.
        if ($user->hasTwoFactorEnabled()) {
            AdminSession::beginTwoFactor($user);

            $this->redirect('/' . config('admin.path') . '/2fa', navigate: true);

            return;
        }

        Auth::login($user);
        session()->regenerate();
        AdminSession::clearPending();
        AdminSession::markVerified($user, false);

        if (config('admin.require_two_factor', true)) {
            session()->flash('admin_2fa_setup_required', '后台账号必须开启两步验证后才能继续操作');
        }

        $destination = config('admin.require_two_factor', true)
            ? '/' . config('admin.path') . '/profile'
            : '/' . config('admin.path');
        $this->redirect($destination, navigate: true);
    }
};
?>

<div class="max-w-sm mx-auto mt-24 bg-gray-900 border border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-1 text-gray-100">管理后台登录</h1>
    <p class="text-xs text-gray-500 mb-6">此入口仅限管理员账号</p>

    @if ($error)
        <div class="text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg p-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="block text-sm text-gray-400 mb-1">邮箱</label>
            <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-700 bg-gray-800 text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
            @error('email') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm text-gray-400 mb-1">密码</label>
            <input type="password" wire:model="password" maxlength="255" class="w-full border border-gray-700 bg-gray-800 text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('password') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full bg-gray-100 text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
            登录
        </button>
    </form>
</div>
