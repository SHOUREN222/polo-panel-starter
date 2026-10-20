<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\TwoFactorService;
use App\Support\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.secure_session' => true,
            'admin.require_two_factor' => true,
            'admin.two_factor_pending_ttl' => 300,
            'admin.verified_session_ttl' => 43200,
            'admin.sensitive_session_ttl' => 900,
        ]);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'is_admin' => true,
            'admin_role' => User::ROLE_SUPER,
            'password' => 'admin-password',
        ], $attributes));
    }

    private function enableTwoFactor(User $user): string
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAAA-BBBBB'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    private function currentCode(string $secret): string
    {
        return app(TwoFactorService::class)->hotp($secret, intdiv(time(), 30));
    }

    private function adminComponentSnapshot(User $admin, string $routeName = 'admin.dashboard'): array
    {
        $this->actingAs($admin);
        AdminSession::markVerified($admin, $admin->hasTwoFactorEnabled());

        $response = $this->get(route($routeName))->assertOk();

        return Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot');
    }

    private function refreshLivewireSnapshot(array $snapshot)
    {
        return $this->withHeader('X-Livewire', 'true')->postJson(
            Livewire::getUpdateUri(),
            [
                'components' => [[
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'updates' => [],
                    'calls' => [],
                ]],
            ],
        );
    }

    public function test_customer_password_login_cannot_create_an_admin_session(): void
    {
        $admin = $this->admin();

        Livewire::test('auth.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login')
            ->assertRedirect('/'.config('admin.path').'/login');

        $this->assertGuest();
    }

    public function test_magic_login_is_never_issued_or_accepted_for_an_admin(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Livewire::test('auth.login-by-email')->set('email', $admin->email)->call('send');
        Notification::assertNotSentTo($admin, MagicLoginLink::class);

        $url = URL::temporarySignedRoute('login-via-email', now()->addMinutes(5), ['id' => $admin->id]);
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_a_session_that_did_not_use_admin_login_is_rejected(): void
    {
        $admin = $this->admin();
        $this->enableTwoFactor($admin);

        $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_admin_login_and_two_factor_produce_a_valid_backend_session(): void
    {
        $admin = $this->admin();
        $secret = $this->enableTwoFactor($admin);

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login')
            ->assertRedirect('/'.config('admin.path').'/2fa');

        Livewire::test('admin.two-factor-challenge')
            ->set('code', $this->currentCode($secret))
            ->call('verify')
            ->assertRedirect('/'.config('admin.path'));

        $this->get(route('admin.system'))->assertOk();
    }

    public function test_admin_without_two_factor_is_limited_to_setup_profile(): void
    {
        $admin = $this->admin();

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login')
            ->assertRedirect('/'.config('admin.path').'/profile');

        $this->get(route('admin.profile'))->assertOk();
        $this->get(route('admin.system'))->assertRedirect(route('admin.profile'));
    }

    public function test_expired_or_password_invalidated_two_factor_challenge_is_rejected(): void
    {
        $admin = $this->admin();
        $this->enableTwoFactor($admin);
        AdminSession::beginTwoFactor($admin);

        $pending = session('admin_2fa.pending');
        $pending['issued_at'] = now()->subMinutes(6)->timestamp;
        session(['admin_2fa.pending' => $pending]);

        Livewire::test('admin.two-factor-challenge')
            ->assertRedirect('/'.config('admin.path').'/login');

        AdminSession::beginTwoFactor($admin);
        $admin->update(['password' => 'changed-password']);

        Livewire::test('admin.two-factor-challenge')
            ->assertRedirect('/'.config('admin.path').'/login');
    }

    public function test_banning_an_admin_revokes_an_existing_verified_session(): void
    {
        config(['admin.require_two_factor' => false]);
        $admin = $this->admin();

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login');

        $admin->forceFill(['banned' => true])->save();

        $this->get(route('admin.system'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_admin_authorization_is_registered_as_livewire_persistent_middleware(): void
    {
        $this->assertContains(EnsureUserIsAdmin::class, Livewire::getPersistentMiddleware());
    }

    public function test_livewire_update_rejects_a_banned_admin_with_an_old_snapshot(): void
    {
        config(['admin.require_two_factor' => false]);
        $admin = $this->admin();
        $snapshot = $this->adminComponentSnapshot($admin);

        $admin->forceFill(['banned' => true])->save();

        $this->refreshLivewireSnapshot($snapshot)->assertForbidden();
        $this->assertGuest();
    }

    public function test_livewire_update_rejects_a_demoted_admin_with_an_old_snapshot(): void
    {
        config(['admin.require_two_factor' => false]);
        $admin = $this->admin();
        $snapshot = $this->adminComponentSnapshot($admin);

        $admin->forceFill(['is_admin' => false])->save();

        $this->refreshLivewireSnapshot($snapshot)->assertForbidden();
    }

    public function test_livewire_update_rejects_expired_two_factor_proof(): void
    {
        $admin = $this->admin();
        $this->enableTwoFactor($admin);
        $snapshot = $this->adminComponentSnapshot($admin);

        $verified = session('admin_auth.verified');
        $verified['two_factor_at'] = now()->subHours(13)->timestamp;
        session(['admin_auth.verified' => $verified]);

        $this->refreshLivewireSnapshot($snapshot)
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_sensitive_sections_require_a_recent_password_and_two_factor_check(): void
    {
        $admin = $this->admin();
        $secret = $this->enableTwoFactor($admin);

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login');
        Livewire::test('admin.two-factor-challenge')
            ->set('code', $this->currentCode($secret))
            ->call('verify');

        $verified = session('admin_auth.verified');
        $verified['two_factor_at'] = now()->subMinutes(16)->timestamp;
        session(['admin_auth.verified' => $verified]);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.system'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_unknown_admin_role_fails_closed(): void
    {
        config(['admin.secure_session' => false, 'admin.require_two_factor' => false]);
        $admin = $this->admin();
        $admin->forceFill(['admin_role' => 'unexpected'])->save();

        $this->actingAs($admin)->get(route('admin.system'))->assertForbidden();

        auth()->logout();
        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'admin-password')
            ->call('login')
            ->assertSet('error', '该账号不是管理员，无法登录后台');
        $this->assertGuest();
    }
}
