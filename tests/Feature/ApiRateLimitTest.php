<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_locks_out_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        // 5 wrong-password tries are allowed (each a normal 401)...
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'wrong'])
                ->assertStatus(401);
        }

        // ...the 6th within the window is throttled, so the API can't be used
        // to brute-force past the web login's per-account lockout.
        $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_a_correct_login_within_the_limit_still_succeeds(): void
    {
        User::factory()->create(['email' => 'ok@example.com']); // factory password is 'password'

        $this->postJson('/api/login', ['email' => 'ok@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_api_register_honours_the_admin_ip_register_limit(): void
    {
        Setting::set('ip_register_limit_enabled', true);
        Setting::set('ip_register_max_attempts', 1);

        $this->postJson('/api/register', [
            'name' => 'A', 'email' => 'a@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated();

        // Second registration from the same IP is refused — the API no longer
        // bypasses RegistrationGuard.
        $this->postJson('/api/register', [
            'name' => 'B', 'email' => 'b@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'b@example.com']);
    }

    public function test_api_register_is_throttled_as_a_floor_without_admin_config(): void
    {
        // Even with the admin IP limit off, the throttle:register middleware
        // caps mass signups (10/hour/IP) — the 11th attempt is blocked.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', [
                'name' => "U{$i}", 'email' => "u{$i}@example.com",
                'password' => 'password123', 'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/register', [
            'name' => 'U10', 'email' => 'u10@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }
}
