<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_whitelist_blocks_disallowed_domain(): void
    {
        Setting::set('email_whitelist_enabled', true);
        Setting::set('email_whitelist', 'gmail.com,outlook.com');

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'someone@yahoo.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertDatabaseMissing('users', ['email' => 'someone@yahoo.com']);
    }

    public function test_email_whitelist_allows_listed_domain(): void
    {
        Setting::set('email_whitelist_enabled', true);
        Setting::set('email_whitelist', 'gmail.com,outlook.com');

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'someone@gmail.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertDatabaseHas('users', ['email' => 'someone@gmail.com']);
    }

    public function test_gmail_alias_block_rejects_second_registration_with_same_normalized_inbox(): void
    {
        Setting::set('block_gmail_alias', true);
        User::factory()->create(['email' => 'some.one@gmail.com']);

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'someone+work@gmail.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertDatabaseMissing('users', ['email' => 'someone+work@gmail.com']);
    }

    public function test_captcha_rejects_wrong_answer(): void
    {
        Setting::set('captcha_enabled', true);

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'captcha-test@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('captcha_answer', '-1')
            ->call('register')
            ->assertHasErrors(['captcha_answer']);

        $this->assertDatabaseMissing('users', ['email' => 'captcha-test@example.com']);
    }

    public function test_captcha_accepts_correct_answer(): void
    {
        Setting::set('captcha_enabled', true);

        $component = Livewire::test('auth.register');
        $a = $component->get('captchaA');
        $b = $component->get('captchaB');

        $component->set('name', 'Test')
            ->set('email', 'captcha-ok@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('captcha_answer', (string) ($a + $b))
            ->call('register');

        $this->assertDatabaseHas('users', ['email' => 'captcha-ok@example.com']);
    }

    public function test_ip_registration_limit_blocks_after_max_attempts(): void
    {
        Setting::set('ip_register_limit_enabled', true);
        Setting::set('ip_register_max_attempts', 2);
        Setting::set('ip_register_lockout_minutes', 60);

        for ($i = 0; $i < 2; $i++) {
            Livewire::test('auth.register')
                ->set('name', 'Test')
                ->set('email', "ip-test-{$i}@example.com")
                ->set('password', 'password123')
                ->set('password_confirmation', 'password123')
                ->call('register');
        }

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'ip-test-blocked@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertDatabaseMissing('users', ['email' => 'ip-test-blocked@example.com']);
    }

    public function test_fixed_registration_floor_applies_when_admin_ip_limit_is_disabled(): void
    {
        Setting::set('ip_register_limit_enabled', false);

        // Invalid submissions still consume the fixed request budget, so an
        // attacker cannot avoid the limit by deliberately failing validation.
        for ($i = 0; $i < 10; $i++) {
            Livewire::test('auth.register')
                ->set('name', 'Test')
                ->set('email', 'not-an-email')
                ->set('password', 'password123')
                ->set('password_confirmation', 'password123')
                ->call('register')
                ->assertHasErrors(['email']);
        }

        Livewire::test('auth.register')
            ->set('name', 'Blocked')
            ->set('email', 'fixed-floor@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertSet('error', '该IP注册请求过于频繁，请稍后再试');

        $this->assertDatabaseMissing('users', ['email' => 'fixed-floor@example.com']);
    }

    public function test_safe_mode_403s_requests_to_unmatched_host(): void
    {
        Setting::set('safe_mode_enabled', true);
        Setting::set('site_url', 'https://correct-domain.example.com');

        $this->get('https://evil-domain.example.com/login')->assertForbidden();
    }

    public function test_safe_mode_allows_requests_to_matched_host(): void
    {
        Setting::set('safe_mode_enabled', true);
        Setting::set('site_url', 'http://localhost');

        $this->get('http://localhost/login')->assertOk();
    }

    public function test_safe_mode_is_noop_without_site_url_configured(): void
    {
        Setting::set('safe_mode_enabled', true);

        $this->get('/login')->assertOk();
    }
}
