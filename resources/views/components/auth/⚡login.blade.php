<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\WebAbuseLimiter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    public function mount(): void
    {
        $this->error = (string) session()->pull('auth_error', '');
    }

    public function login(WebAbuseLimiter $limiter): void
    {
        $this->error = '';

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = strtolower(trim($this->email));
        $ip = request()->ip();
        $retryAfter = $limiter->loginRetryAfter($email, $ip);

        if ($retryAfter > 0) {
            $minutes = max(1, (int) ceil($retryAfter / 60));
            $this->error = "登录尝试次数过多，请 {$minutes} 分钟后再试";

            return;
        }

        // Validate without creating an authenticated session yet. Admin users
        // must be redirected to the dedicated backend flow before Auth::login,
        // otherwise this customer endpoint briefly bypasses its 2FA gate.
        $credentials = ['email' => $email, 'password' => $this->password];
        if (! Auth::validate($credentials)) {
            $limiter->recordLoginFailure($email, $ip);

            $this->error = '邮箱或密码错误';

            return;
        }

        // Keep the per-IP bucket so rotating account names cannot bypass the
        // fixed 20/minute ceiling. A valid password only clears this account.
        $limiter->clearLoginAccount($email, $ip);

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            $this->error = '邮箱或密码错误';

            return;
        }

        if ($user->banned) {
            $this->error = '账号已被封禁';

            return;
        }

        if ($user->isAdmin()) {
            session()->invalidate();
            session()->regenerateToken();
            $this->redirect('/' . config('admin.path') . '/login', navigate: true);

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirect('/', navigate: true);
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-6 text-gray-900 dark:text-gray-100">登录</h1>

    @if ($error)
        <div class="mb-4 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮箱</label>
            <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
            @error('email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm text-gray-600 dark:text-gray-400">密码</label>
                <a href="/forgot-password" class="text-xs text-gray-500 dark:text-gray-400 underline">忘记密码？</a>
            </div>
            <input type="password" wire:model="password" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
            登录
        </button>
    </form>

    <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
        @unless (\App\Models\Setting::getBool('stop_register'))
            还没有账号？<a href="/register" class="text-gray-900 dark:text-gray-100 underline">注册</a>
        @endunless
        @if (\App\Models\Setting::getBool('magic_login_enabled', true))
            @unless (\App\Models\Setting::getBool('stop_register')) · @endunless<a href="/login-by-email" class="text-gray-900 dark:text-gray-100 underline">邮件登录</a>
        @endif
    </p>
</div>
