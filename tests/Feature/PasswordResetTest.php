<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Services\WebAbuseLimiter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_email_is_sent_immediately_so_delivery_failures_are_visible(): void
    {
        $this->assertNotInstanceOf(ShouldQueue::class, new QueuedResetPassword('token'));
    }

    public function test_requesting_a_reset_link_sends_notification_for_a_real_account(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Livewire::test('auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertSet('status', fn ($v) => str_contains($v, '已经发送'));

        Notification::assertSentTo($user, QueuedResetPassword::class);
    }

    public function test_requesting_a_reset_link_for_unknown_email_shows_same_generic_message(): void
    {
        Notification::fake();

        Livewire::test('auth.forgot-password')
            ->set('email', 'nobody@example.com')
            ->call('sendResetLink')
            ->assertSet('status', fn ($v) => str_contains($v, '已经发送'));

        Notification::assertNothingSent();
    }

    public function test_mail_delivery_failure_is_shown_instead_of_false_success(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new \RuntimeException('SMTP unavailable'));

        Livewire::test('auth.forgot-password')
            ->set('email', 'customer@example.com')
            ->call('sendResetLink')
            ->assertSet('status', '')
            ->assertSet('error', fn ($value) => str_contains($value, '发送失败'));
    }

    public function test_ip_rate_limit_blocks_reset_requests_that_rotate_email_addresses(): void
    {
        Notification::fake();

        for ($i = 0; $i < WebAbuseLimiter::PASSWORD_RESET_IP_MAX; $i++) {
            Livewire::test('auth.forgot-password')
                ->set('email', "rotated-{$i}@example.com")
                ->call('sendResetLink');
        }

        Livewire::test('auth.forgot-password')
            ->set('email', 'another@example.com')
            ->call('sendResetLink')
            ->assertSet('error', '请求过于频繁，请稍后再试');

        Notification::assertNothingSent();
    }

    public function test_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword']);
        $user->createToken('phone');
        DB::table('sessions')->insert([
            'id' => 'old-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => now()->timestamp,
        ]);
        $token = Password::createToken($user);

        Livewire::test('auth.reset-password', ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'newpassword123')
            ->set('password_confirmation', 'newpassword123')
            ->call('resetPassword')
            ->assertSet('done', true);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword']);

        Livewire::test('auth.reset-password', ['token' => 'bogus-token'])
            ->set('email', $user->email)
            ->set('password', 'newpassword123')
            ->set('password_confirmation', 'newpassword123')
            ->call('resetPassword')
            ->assertSet('done', false)
            ->assertSet('error', fn ($v) => $v !== '');

        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password));
    }
}
