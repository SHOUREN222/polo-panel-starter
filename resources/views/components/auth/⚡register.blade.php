<?php

use App\Models\InviteCode;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\RegistrationGuard;
use App\Services\WebAbuseLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $captcha_answer = '';
    public bool $registrationStopped = false;
    public ?string $inviteCode = null;
    public bool $inviteOnly = false;
    public bool $captchaEnabled = false;
    public int $captchaA = 0;
    public int $captchaB = 0;
    public string $error = '';

    public function mount(): void
    {
        $this->registrationStopped = Setting::getBool('stop_register');
        $this->inviteCode = request()->query('ref') ?: null;
        $this->inviteOnly = Setting::getBool('registration_invite_only', false);
        $this->captchaEnabled = Setting::getBool('captcha_enabled', false);
        $this->refreshCaptcha();
    }

    private function refreshCaptcha(): void
    {
        $this->captchaA = random_int(1, 9);
        $this->captchaB = random_int(1, 9);
        session(['register_captcha_answer' => $this->captchaA + $this->captchaB]);
    }

    public function register(RegistrationGuard $guard, WebAbuseLimiter $limiter): void
    {
        $this->error = '';

        if (Setting::getBool('stop_register')) {
            $this->registrationStopped = true;

            return;
        }

        $ip = request()->ip();

        if ($limiter->registrationRetryAfter($ip) > 0) {
            $this->error = '该IP注册请求过于频繁，请稍后再试';

            return;
        }

        // Count every submission, including validation failures. This is the
        // fixed floor that remains active when the admin IP switch is off.
        $limiter->recordRegistrationAttempt($ip);

        if ($guard->ipLimitExceeded($ip)) {
            $this->error = '该IP注册次数过多，请稍后再试';

            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)],
            'inviteCode' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $guard->emailAllowedByWhitelist($data['email'])) {
            $this->addError('email', '该邮箱后缀不在允许注册的白名单内');

            return;
        }

        if ($guard->isBlockedGmailAlias($data['email'])) {
            $this->addError('email', '该邮箱对应的 Gmail 账号已经注册过');

            return;
        }

        if (! $guard->verifyCaptcha(session('register_captcha_answer'), $this->captcha_answer)) {
            $this->addError('captcha_answer', '验证码不正确');
            $this->refreshCaptcha();

            return;
        }

        // A missing, invalid, or already-used (when codes are single-use)
        // invite code is not an error — registration just proceeds without
        // a referrer rather than blocking the whole form.
        $usedCode = $this->inviteCode
            ? InviteCode::where('code', $this->inviteCode)->first()
            : null;

        if ($usedCode && ! $usedCode->isUsable()) {
            $usedCode = null;
        }

        // Invite-only mode: a valid, usable invite code is mandatory. Unlike
        // the normal flow (where a bad code just drops the referrer), here a
        // missing/invalid code blocks registration entirely.
        if (Setting::getBool('registration_invite_only', false) && ! $usedCode) {
            $this->error = '当前仅限邀请注册，请填写有效的邀请码';

            return;
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $guard->recordRegistration($ip);

        if ($usedCode) {
            // Deliberately not mass-assigned — referred_by_user_id and
            // invite_code_id are excluded from $fillable so they can only be
            // set here, after validating the invite code, never from
            // arbitrary form input. invite_code_id records which specific
            // code was used (for per-code stats), while referred_by_user_id
            // is who actually gets the commission — kept separate because a
            // referrer can own several codes that all pay out to them.
            $user->referred_by_user_id = $usedCode->user_id;
            $user->invite_code_id = $usedCode->id;
            $user->save();
            $usedCode->markUsed();
        }

        if (Setting::getBool('email_verification_enabled', false)) {
            $user->notify(new VerifyEmailAddress());
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirect('/', navigate: true);
    }
};
?>

<div class="max-w-sm mx-auto mt-16 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-8">
    <h1 class="text-xl font-semibold mb-6 text-gray-900 dark:text-gray-100">注册</h1>

    @if ($registrationStopped)
        <div class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 rounded-lg p-3">
            当前站点已停止新用户注册。
        </div>
    @else
        @if ($inviteCode)
            <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded-lg p-2.5 mb-4">
                使用邀请码 <span class="font-mono">{{ $inviteCode }}</span> 注册
            </div>
        @endif

        @if ($error)
            <div class="text-sm text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3 mb-4">{{ $error }}</div>
        @endif

        <form wire:submit="register" class="space-y-4">
            @if ($inviteOnly && ! $inviteCode)
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邀请码</label>
                    <input type="text" wire:model="inviteCode" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" placeholder="仅限邀请注册，请填写邀请码">
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">昵称</label>
                <input type="text" wire:model="name" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" autofocus>
                @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮箱</label>
                <input type="email" wire:model="email" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">密码</label>
                <input type="password" wire:model="password" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">确认密码</label>
                <input type="password" wire:model="password_confirmation" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>

            @if ($captchaEnabled)
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">验证码：{{ $captchaA }} + {{ $captchaB }} = ?</label>
                    <input type="text" wire:model="captcha_answer" inputmode="numeric" maxlength="3" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('captcha_answer') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="w-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg py-2 text-sm font-medium" wire:loading.attr="disabled">
                注册
            </button>
        </form>
    @endif

    <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
        已经有账号？<a href="/login" class="text-gray-900 dark:text-gray-100 underline">登录</a>
    </p>
</div>
