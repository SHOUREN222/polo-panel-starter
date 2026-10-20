<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TwoFactorAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private function adminPath(string $suffix = ''): string
    {
        return '/'.config('admin.path').$suffix;
    }

    private function code(string $secret): string
    {
        return app(TwoFactorService::class)->hotp($secret, intdiv(time(), 30));
    }

    private function enable2fa(User $user, string $secret, array $recovery = ['AAAAA-BBBBB']): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_admin_can_enable_2fa_from_profile_with_a_valid_code(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        $component = Livewire::actingAs($admin)->test('admin.profile')
            ->call('startTwoFactorSetup')
            ->assertSet('showingTwoFactorSetup', true);

        $secret = $component->get('twoFactorSecret');
        $this->assertNotEmpty($secret);

        $component->set('twoFactorConfirmCode', $this->code($secret))
            ->call('confirmTwoFactor')
            ->assertSet('showingTwoFactorSetup', false);

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertCount(8, $admin->two_factor_recovery_codes); // shown once
    }

    public function test_enabling_rejects_a_wrong_confirmation_code(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.profile')
            ->call('startTwoFactorSetup')
            ->set('twoFactorConfirmCode', '000000')
            ->call('confirmTwoFactor')
            ->assertSet('error', '验证码不正确，请确认手机时间准确后重试');

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_2fa_holds_the_session_and_redirects_to_challenge(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $this->enable2fa($admin, $secret);

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect($this->adminPath('/2fa'));

        $this->assertFalse(auth()->check());                     // NOT logged in yet
        $this->assertSame((string) $admin->id, session('admin_2fa.pending.user_id'));
    }

    public function test_challenge_logs_in_with_a_correct_totp_code(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $this->enable2fa($admin, $secret);
        AdminSession::beginTwoFactor($admin);

        Livewire::test('admin.two-factor-challenge')
            ->set('code', $this->code($secret))
            ->call('verify')
            ->assertRedirect($this->adminPath());

        $this->assertTrue(auth()->check());
        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('admin_2fa.pending'));
    }

    public function test_challenge_rejects_a_wrong_code_and_stays_logged_out(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $this->enable2fa($admin, $secret);
        AdminSession::beginTwoFactor($admin);

        Livewire::test('admin.two-factor-challenge')
            ->set('code', '000000')
            ->call('verify')
            ->assertSet('error', '验证码错误');

        $this->assertFalse(auth()->check());
    }

    public function test_recovery_code_logs_in_and_is_consumed(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $this->enable2fa($admin, $secret, ['AAAAA-BBBBB', 'CCCCC-DDDDD']);
        AdminSession::beginTwoFactor($admin);

        Livewire::test('admin.two-factor-challenge')
            ->set('useRecovery', true)
            ->set('code', 'aaaaa-bbbbb') // case-insensitive
            ->call('verify')
            ->assertRedirect($this->adminPath());

        $this->assertTrue(auth()->check());
        $this->assertSame(['CCCCC-DDDDD'], array_values($admin->fresh()->two_factor_recovery_codes));
    }

    public function test_disabling_2fa_requires_the_account_password(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $this->enable2fa($admin, $secret);

        // Wrong password → refused, still on.
        Livewire::actingAs($admin)->test('admin.profile')
            ->set('disablePassword', 'nope')
            ->call('disableTwoFactor')
            ->assertSet('error', '密码不正确，无法关闭两步验证');
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());

        // Correct password → off.
        Livewire::actingAs($admin->fresh())->test('admin.profile')
            ->set('disablePassword', 'password')
            ->call('disableTwoFactor');
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_admin_without_2fa_still_logs_in_normally(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect($this->adminPath());

        $this->assertTrue(auth()->check());
    }
}
