<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\WebAbuseLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_verification_email_when_enabled(): void
    {
        Notification::fake();
        Setting::set('email_verification_enabled', true);

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'verify-me@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $user = User::where('email', 'verify-me@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_registration_does_not_send_verification_email_when_disabled(): void
    {
        Notification::fake();
        Setting::set('email_verification_enabled', false);

        Livewire::test('auth.register')
            ->set('name', 'Test')
            ->set('email', 'no-verify@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        Notification::assertNothingSent();
    }

    public function test_api_registration_sends_verification_email_when_enabled(): void
    {
        Notification::fake();
        Setting::set('email_verification_enabled', true);

        $this->postJson('/api/register', [
            'name' => 'API user',
            'email' => 'api-verify@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.user.email_verified', false);

        $user = User::where('email', 'api-verify@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_unverified_api_user_cannot_access_customer_resources_when_enabled(): void
    {
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', '请先验证邮箱后再继续操作');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);
    }

    public function test_unverified_user_is_redirected_to_verify_notice_when_enforcement_enabled(): void
    {
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->get('/')->assertRedirect('/verify-notice');
    }

    public function test_verified_user_is_not_redirected(): void
    {
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_unverified_user_is_not_blocked_when_enforcement_disabled(): void
    {
        Setting::set('email_verification_enabled', false);
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_admin_is_exempt_from_email_verification_enforcement(): void
    {
        Setting::set('email_verification_enabled', true);
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => null]);

        $this->actingAs($admin)->get('/'.config('admin.path').'/plans')->assertOk();
    }

    public function test_signed_verification_link_marks_user_verified(): void
    {
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->create(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verify-email', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_verification_link_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->get("/verify-email/{$user->id}/wrong-hash?signature=fake")->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_user_can_request_resend_from_notice_page(): void
    {
        Notification::fake();
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->create(['email_verified_at' => null]);

        Livewire::actingAs($user)->test('auth.verify-notice')->call('resend');

        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_verification_email_resend_is_rate_limited(): void
    {
        Notification::fake();
        Setting::set('email_verification_enabled', true);
        $user = User::factory()->create(['email_verified_at' => null]);

        for ($i = 0; $i < WebAbuseLimiter::VERIFY_EMAIL_USER_MAX; $i++) {
            Livewire::actingAs($user)->test('auth.verify-notice')->call('resend');
        }

        Livewire::actingAs($user)->test('auth.verify-notice')
            ->call('resend')
            ->assertSet('error', '请求过于频繁，请稍后再试');

        $this->assertCount(
            WebAbuseLimiter::VERIFY_EMAIL_USER_MAX,
            Notification::sent($user, VerifyEmailAddress::class)
        );
    }
}
