<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionTokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function createSession(User $user, string $id = 'existing-session'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_password_change_revokes_all_sessions_tokens_and_remember_cookies(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $user->forceFill(['remember_token' => 'known-remember-token'])->saveQuietly();
        $user->createToken('phone');
        $user->createToken('desktop');
        $this->createSession($user);

        $user->update(['password' => 'new-password']);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertNotSame('known-remember-token', $user->fresh()->remember_token);
    }

    public function test_ban_revokes_all_sessions_and_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $this->createSession($user);

        $user->update(['banned' => true]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_api_middleware_rejects_and_cleans_up_a_stale_banned_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('stale')->plainTextToken;
        $user->forceFill(['banned' => true])->saveQuietly();
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('message', '账号已被封禁');

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_web_middleware_logs_out_a_stale_banned_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->forceFill(['banned' => true])->saveQuietly();

        $this->get('/')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_api_tokens_expire_after_the_configured_lifetime(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old')->plainTextToken;

        $this->travel(31)->days();
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();

        $this->travelBack();
    }
}
