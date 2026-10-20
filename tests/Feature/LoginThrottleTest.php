<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('login:locked@example.com');
        RateLimiter::clear('login:shared@example.com');
        RateLimiter::clear('login:success@example.com');
        RateLimiter::clear('login:default@example.com');

        parent::tearDown();
    }

    public function test_customer_login_locks_out_after_configured_attempts(): void
    {
        Setting::set('login_attempt_limit_enabled', true);
        Setting::set('login_max_attempts', 3);
        Setting::set('login_lockout_minutes', 60);

        $user = User::factory()->create(['email' => 'locked@example.com', 'password' => 'correct-password']);

        for ($i = 0; $i < 3; $i++) {
            Livewire::test('auth.login')
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertSet('error', '邮箱或密码错误');
        }

        // 4th attempt, even with the CORRECT password, should now be blocked.
        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', fn ($v) => str_contains($v, '尝试次数过多'));

        $this->assertGuest();
    }

    public function test_successful_login_clears_the_attempt_counter(): void
    {
        Setting::set('login_attempt_limit_enabled', true);
        Setting::set('login_max_attempts', 3);

        $user = User::factory()->create(['email' => 'success@example.com', 'password' => 'correct-password']);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', '');

        $this->assertAuthenticatedAs($user);
    }

    public function test_configurable_lockout_can_be_disabled_but_fixed_floor_remains(): void
    {
        Setting::set('login_attempt_limit_enabled', false);
        Setting::set('login_max_attempts', 2);

        $user = User::factory()->create(['email' => 'default@example.com', 'password' => 'correct-password']);

        for ($i = 0; $i < 4; $i++) {
            Livewire::test('auth.login')
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login');
        }

        // The configurable 2-attempt lockout is disabled. Four failures are
        // still below the non-configurable 5/minute safety floor.
        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', '');

        $this->assertAuthenticatedAs($user);
    }

    public function test_fixed_login_floor_cannot_be_disabled(): void
    {
        Setting::set('login_attempt_limit_enabled', false);

        $user = User::factory()->create(['email' => 'floor@example.com', 'password' => 'correct-password']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.login')
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertSet('error', '邮箱或密码错误');
        }

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', fn ($value) => str_contains($value, '尝试次数过多'));

        $this->assertGuest();
    }

    public function test_fixed_ip_floor_blocks_attempts_that_rotate_email_addresses(): void
    {
        Setting::set('login_attempt_limit_enabled', false);
        $user = User::factory()->create(['email' => 'ip-victim@example.com', 'password' => 'correct-password']);

        for ($i = 0; $i < 20; $i++) {
            Livewire::test('auth.login')
                ->set('email', "rotated-{$i}@example.com")
                ->set('password', 'wrong-password')
                ->call('login');
        }

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', fn ($value) => str_contains($value, '尝试次数过多'));

        $this->assertGuest();
    }

    public function test_admin_and_customer_login_share_the_same_throttle_bucket(): void
    {
        Setting::set('login_attempt_limit_enabled', true);
        Setting::set('login_max_attempts', 2);
        Setting::set('login_lockout_minutes', 60);

        $admin = User::factory()->create(['email' => 'shared@example.com', 'password' => 'correct-password', 'is_admin' => true]);

        // Two failures via the customer login page...
        Livewire::test('auth.login')->set('email', $admin->email)->set('password', 'wrong')->call('login');
        Livewire::test('auth.login')->set('email', $admin->email)->set('password', 'wrong')->call('login');

        // ...should also lock out the admin login page for the same account.
        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', fn ($v) => str_contains($v, '尝试次数过多'));

        $this->assertGuest();
    }

    public function test_admin_can_configure_login_throttle_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('login_attempt_limit_enabled', false)
            ->set('login_max_attempts', '10')
            ->set('login_lockout_minutes', '30')
            ->call('saveSecurity');

        $this->assertFalse(Setting::getBool('login_attempt_limit_enabled'));
        $this->assertSame(10, (int) Setting::get('login_max_attempts'));
        $this->assertSame(30, (int) Setting::get('login_lockout_minutes'));
    }
}
