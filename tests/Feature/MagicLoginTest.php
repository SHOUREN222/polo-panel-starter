<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\WebAbuseLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class MagicLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_login_link_sends_notification_for_registered_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'exists@example.com']);

        Livewire::test('auth.login-by-email')
            ->set('email', 'exists@example.com')
            ->call('send');

        Notification::assertSentTo($user, MagicLoginLink::class);
    }

    public function test_requesting_a_login_link_shows_same_message_for_unregistered_email(): void
    {
        Notification::fake();

        $component = Livewire::test('auth.login-by-email')
            ->set('email', 'nobody@example.com')
            ->call('send');

        $component->assertSet('status', '如果该邮箱已注册，登录链接已经发送过去了，请查收（5 分钟内有效）。');
        Notification::assertNothingSent();
    }

    public function test_banned_user_does_not_receive_login_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'banned@example.com', 'banned' => true]);

        Livewire::test('auth.login-by-email')
            ->set('email', 'banned@example.com')
            ->call('send');

        Notification::assertNotSentTo($user, MagicLoginLink::class);
    }

    public function test_signed_login_link_authenticates_user(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute('login-via-email', now()->addMinutes(5), ['id' => $user->id]);

        $this->get($url)->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_tampered_login_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get("/login-via-email/{$user->id}?signature=fake")->assertForbidden();
        $this->assertGuest();
    }

    public function test_banned_user_cannot_use_a_valid_link(): void
    {
        $user = User::factory()->create(['banned' => true]);

        $url = URL::temporarySignedRoute('login-via-email', now()->addMinutes(5), ['id' => $user->id]);

        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_rate_limit_blocks_repeated_requests(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ratelimited@example.com']);

        for ($i = 0; $i < 3; $i++) {
            Livewire::test('auth.login-by-email')->set('email', 'ratelimited@example.com')->call('send');
        }

        $component = Livewire::test('auth.login-by-email')
            ->set('email', 'ratelimited@example.com')
            ->call('send');

        $component->assertSet('error', '请求过于频繁，请稍后再试');
    }

    public function test_ip_rate_limit_blocks_requests_that_rotate_email_addresses(): void
    {
        Notification::fake();

        for ($i = 0; $i < WebAbuseLimiter::MAGIC_LOGIN_IP_MAX; $i++) {
            Livewire::test('auth.login-by-email')
                ->set('email', "rotated-{$i}@example.com")
                ->call('send');
        }

        Livewire::test('auth.login-by-email')
            ->set('email', 'another@example.com')
            ->call('send')
            ->assertSet('error', '请求过于频繁，请稍后再试');

        Notification::assertNothingSent();
    }

    public function test_request_page_returns_404_when_disabled(): void
    {
        Setting::set('magic_login_enabled', false);

        $this->get('/login-by-email')->assertNotFound();
    }

    public function test_signed_login_link_is_rejected_when_disabled(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute('login-via-email', now()->addMinutes(5), ['id' => $user->id]);

        Setting::set('magic_login_enabled', false);

        $this->get($url)->assertNotFound();
        $this->assertGuest();
    }

    public function test_login_page_hides_magic_login_link_when_disabled(): void
    {
        Setting::set('magic_login_enabled', false);

        Livewire::test('auth.login')->assertDontSee('邮件登录');
    }

    public function test_login_page_shows_magic_login_link_by_default(): void
    {
        Livewire::test('auth.login')->assertSee('邮件登录');
    }
}
