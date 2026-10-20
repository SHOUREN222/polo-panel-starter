<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonPath('data.user.balance', 0);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'nope',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_login_and_access_protected_route(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'login@example.com');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_banned_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'banned@example.com',
            'password' => 'password123',
            'banned' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => 'banned@example.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }
}
