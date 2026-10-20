<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword']);
        $user->createToken('phone');

        Livewire::actingAs($user)->test('profile')
            ->set('old_password', 'oldpassword')
            ->set('new_password', 'newpassword123')
            ->set('new_password_confirmation', 'newpassword123')
            ->call('changePassword')
            ->assertRedirect('/login');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertGuest();
    }

    public function test_wrong_old_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword']);

        Livewire::actingAs($user)->test('profile')
            ->set('old_password', 'wrongpassword')
            ->set('new_password', 'newpassword123')
            ->set('new_password_confirmation', 'newpassword123')
            ->call('changePassword')
            ->assertSet('error', '旧密码不正确');

        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password));
    }

    public function test_notification_toggle_persists_immediately(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('profile')
            ->set('notify_expire', false);

        $this->assertFalse($user->fresh()->notify_expire);
    }

    public function test_reset_subscription_invalidates_old_link(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->token;

        $this->get("/subscribe/{$oldToken}")->assertStatus(200);

        Livewire::actingAs($user)->test('profile')->call('resetSubscription');

        $this->get("/subscribe/{$oldToken}")->assertStatus(404);
        $this->get("/subscribe/{$user->fresh()->token}")->assertStatus(200);
    }

    public function test_telegram_group_link_shown_when_configured(): void
    {
        Setting::set('telegram_group_url', 'https://t.me/example');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertSee('https://t.me/example');
    }

    public function test_admin_can_change_own_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'oldpassword']);
        $admin->createToken('phone');

        Livewire::actingAs($admin)->test('admin.profile')
            ->set('old_password', 'oldpassword')
            ->set('new_password', 'newpassword123')
            ->set('new_password_confirmation', 'newpassword123')
            ->call('changePassword')
            ->assertRedirect('/'.config('admin.path').'/login');

        $this->assertTrue(Hash::check('newpassword123', $admin->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $admin->id]);
        $this->assertGuest();
    }

    public function test_admin_can_configure_telegram_group_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('telegram_group_url', 'https://t.me/mygroup')
            ->call('saveTelegram');

        $this->assertSame('https://t.me/mygroup', Setting::get('telegram_group_url'));
    }
}
