<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $error = '';
    public bool $done = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->error = '';

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            [
                'email' => strtolower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $this->done = true;

            return;
        }

        $this->error = '重置链接无效或已过期，请重新申请。';
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-6 text-gray-900 dark:text-gray-100">重置密码</h1>

    @if ($done)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3 mb-4">
            密码已重置，可以用新密码登录了。
        </div>
        <a href="/login" class="block text-center w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium">去登录</a>
    @else
        @if ($error)
            <div class="mb-4 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
        @endif

        <form wire:submit="resetPassword" class="space-y-4">
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮箱</label>
                <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">新密码</label>
                <input type="password" wire:model="password" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
                @error('password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">确认新密码</label>
                <input type="password" wire:model="password_confirmation" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>

            <button type="submit" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
                重置密码
            </button>
        </form>
    @endif
</div>
