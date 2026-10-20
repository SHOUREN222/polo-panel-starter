<?php

use App\Services\TelegramService;
use App\Services\TwoFactorService;
use App\Support\AdminSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '个人中心'])] class extends Component
{
    public string $old_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';

    public string $successMessage = '';
    public string $error = '';

    // 两步验证 (2FA) 启用流程
    public bool $showingTwoFactorSetup = false;
    public string $twoFactorSecret = '';   // shown to the user as QR + manual key during setup
    public string $twoFactorUri = '';
    public string $twoFactorConfirmCode = '';
    public array $recoveryCodes = [];       // shown once, right after enabling / regenerating
    public string $disablePassword = '';

    /** Begin setup: mint a secret (not yet saved) and build its otpauth URI. */
    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
    {
        $this->resetTwoFactorMessages();

        if (auth()->user()->hasTwoFactorEnabled()) {
            return;
        }

        $this->twoFactorSecret = $twoFactor->generateSecret();
        $this->twoFactorUri = $twoFactor->otpauthUri(auth()->user(), $this->twoFactorSecret);
        $this->twoFactorConfirmCode = '';
        $this->recoveryCodes = [];
        $this->showingTwoFactorSetup = true;
    }

    public function cancelTwoFactorSetup(): void
    {
        $this->showingTwoFactorSetup = false;
        $this->twoFactorSecret = '';
        $this->twoFactorUri = '';
        $this->twoFactorConfirmCode = '';
    }

    /** Confirm the user really scanned the QR by checking a live code, then persist. */
    public function confirmTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->resetTwoFactorMessages();

        if (! $this->showingTwoFactorSetup || $this->twoFactorSecret === '') {
            return;
        }

        if (! $twoFactor->verify($this->twoFactorSecret, $this->twoFactorConfirmCode)) {
            $this->error = '验证码不正确，请确认手机时间准确后重试';

            return;
        }

        $codes = $twoFactor->generateRecoveryCodes();

        auth()->user()->forceFill([
            'two_factor_secret' => $this->twoFactorSecret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();
        AdminSession::markTwoFactorVerified(auth()->user()->fresh());

        $this->recoveryCodes = $codes;
        $this->showingTwoFactorSetup = false;
        $this->twoFactorSecret = '';
        $this->twoFactorUri = '';
        $this->twoFactorConfirmCode = '';
        $this->successMessage = '两步验证已开启，请妥善保存下面的恢复码';
    }

    /** Turn 2FA off — requires the account password so a hijacked open session can't. */
    public function disableTwoFactor(): void
    {
        $this->resetTwoFactorMessages();

        if (config('admin.require_two_factor', true)) {
            $this->error = '后台已强制开启两步验证，不能关闭';

            return;
        }

        if (! Hash::check($this->disablePassword, auth()->user()->password)) {
            $this->error = '密码不正确，无法关闭两步验证';

            return;
        }

        auth()->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->disablePassword = '';
        $this->recoveryCodes = [];
        $this->successMessage = '两步验证已关闭';
    }

    public function regenerateRecoveryCodes(TwoFactorService $twoFactor): void
    {
        $this->resetTwoFactorMessages();

        if (! auth()->user()->hasTwoFactorEnabled()) {
            return;
        }

        $codes = $twoFactor->generateRecoveryCodes();
        auth()->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();

        $this->recoveryCodes = $codes;
        $this->successMessage = '已生成新的恢复码，旧的恢复码立即失效';
    }

    private function resetTwoFactorMessages(): void
    {
        $this->successMessage = '';
        $this->error = '';
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
        $this->redirect('/'.config('admin.path').'/login', navigate: true);
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
            'telegramUser' => $user,
            'telegramBindCode' => $bindDeepLink ? $user->telegram_bind_code : null,
            'telegramBindDeepLink' => $bindDeepLink,
            'telegramBindAppLink' => $bindDeepLink ? $telegram->appLinkFor($user->telegram_bind_code) : null,
            'telegramBotUsername' => $telegram->botUsername(),
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
        ];
    }
};
?>

<div class="space-y-6">
    @if (session('admin_2fa_setup_required'))
        <div class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-lg p-3 max-w-md">
            {{ session('admin_2fa_setup_required') }}
        </div>
    @endif
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">个人中心</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ auth()->user()->email }}</p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-md">
        <h2 class="font-medium text-gray-900 dark:text-gray-100">修改密码</h2>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">旧密码</label>
            <input type="password" wire:model="old_password" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('old_password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">新密码</label>
            <input type="password" wire:model="new_password" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            @error('new_password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">确认新密码</label>
            <input type="password" wire:model="new_password_confirmation" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
        </div>

        <button wire:click="changePassword" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
    </div>

    {{-- 两步验证 (2FA) --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-md">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-medium text-gray-900 dark:text-gray-100">两步验证（2FA）</h2>
                @if ($twoFactorEnabled)
                    <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">● 已开启，登录后台时需要输入动态验证码</p>
                @else
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">用身份验证器 App（Google Authenticator / Authy / 1Password）保护后台登录</p>
                @endif
            </div>
            @if (! $twoFactorEnabled && ! $showingTwoFactorSetup)
                <button wire:click="startTwoFactorSetup" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2 shrink-0">开启</button>
            @endif
        </div>

        {{-- 恢复码：开启 / 重新生成后展示一次 --}}
        @if (! empty($recoveryCodes))
            <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-lg p-3 space-y-2">
                <p class="text-xs text-amber-700 dark:text-amber-400 font-medium">请立即保存这些恢复码（每个仅可用一次，用于手机丢失时登录）。离开本页后将无法再次完整查看。</p>
                <div class="grid grid-cols-2 gap-1 font-mono text-sm text-gray-800 dark:text-gray-200">
                    @foreach ($recoveryCodes as $rc)
                        <span>{{ $rc }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 启用流程：扫码 + 输入验证码确认 --}}
        @if ($showingTwoFactorSetup)
            <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                <p class="text-sm text-gray-700 dark:text-gray-300">1. 用身份验证器 App 扫描二维码（或手动输入密钥）：</p>

                <div class="flex flex-col items-center gap-3">
                    {{-- QR 由 app.js 的 window.renderTotpQr 客户端渲染；渲染不出时退回手动密钥 --}}
                    <div
                        x-data
                        x-init="window.renderQrCode && window.renderQrCode($refs.qr, @js($twoFactorUri))"
                        class="bg-white p-3 rounded-lg"
                    >
                        <canvas x-ref="qr" width="180" height="180"></canvas>
                    </div>

                    <div class="w-full text-center">
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-1">手动输入密钥：</p>
                        <code class="text-sm font-mono bg-gray-100 dark:bg-gray-800 rounded px-2 py-1 break-all select-all">{{ $twoFactorSecret }}</code>
                    </div>
                </div>

                <p class="text-sm text-gray-700 dark:text-gray-300 pt-1">2. 输入 App 里显示的 6 位验证码确认：</p>
                <input
                    type="text"
                    inputmode="numeric"
                    wire:model="twoFactorConfirmCode"
                    placeholder="6 位验证码"
                    class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm tracking-widest text-center"
                >
                <div class="flex gap-2">
                    <button wire:click="confirmTwoFactor" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">确认开启</button>
                    <button wire:click="cancelTwoFactorSetup" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
                </div>
            </div>
        @endif

        {{-- 已开启：重新生成恢复码 / 关闭 --}}
        @if ($twoFactorEnabled && ! $showingTwoFactorSetup)
            <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-3">
                <button wire:click="regenerateRecoveryCodes" wire:confirm="重新生成后，旧的恢复码将全部失效，确定？" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">重新生成恢复码</button>

                @if (config('admin.require_two_factor', true))
                    <p class="text-xs text-gray-400 dark:text-gray-500">后台已强制启用两步验证，不能关闭。</p>
                @else
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">关闭两步验证需输入当前密码</label>
                            <input type="password" wire:model="disablePassword" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <button wire:click="disableTwoFactor" class="text-sm border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg px-4 py-2 shrink-0">关闭</button>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- 绑定 Telegram --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-3 max-w-md">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-medium text-gray-900 dark:text-gray-100">绑定 Telegram</h2>
                @if ($telegramUser->isTelegramBound())
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">已绑定 @{{ $telegramUser->telegram_username ?? $telegramUser->telegram_id }}，新订单支付、新工单提交会推送到这里。</p>
                @else
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">绑定后可以第一时间收到新订单支付、新工单提交的通知。</p>
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
                <div class="space-y-2">
                    <p class="text-gray-700 dark:text-gray-200 font-medium">推荐：在 Telegram App 里手动绑定（15 分钟内有效，不依赖能否打开 t.me）</p>
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
</div>
