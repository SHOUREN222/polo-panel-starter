<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdminLoginAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserAdminNoteAndLoginTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_and_clear_internal_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        Livewire::actingAs($admin)->test('admin.users')
            ->call('viewUser', $user->id)
            ->set('noteDraft', 'VIP · 投诉过')
            ->call('saveAdminNote');
        $this->assertSame('VIP · 投诉过', $user->fresh()->admin_note);

        // clearing to empty stores null, not ''
        Livewire::actingAs($admin)->test('admin.users')
            ->call('viewUser', $user->id)
            ->set('noteDraft', '')
            ->call('saveAdminNote');
        $this->assertNull($user->fresh()->admin_note);
    }

    public function test_note_is_not_mass_assignable_from_user_input(): void
    {
        $user = User::factory()->create();
        $user->fill(['admin_note' => 'hacked']);
        $this->assertNull($user->admin_note);
    }

    public function test_login_records_last_login_time_and_ip(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->last_login_at);

        Auth::login($user);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->last_login_at);
        $this->assertNotNull($fresh->last_login_ip);
    }

    public function test_admin_login_from_new_ip_fires_alert(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Auth::login($admin);

        Notification::assertSentTo($admin, AdminLoginAlert::class);
    }

    public function test_normal_user_login_does_not_fire_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Auth::login($user);

        Notification::assertNothingSent();
    }

    public function test_admin_login_from_same_ip_does_not_re_alert(): void
    {
        Notification::fake();
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => User::ROLE_SUPER,
            'last_login_ip' => '127.0.0.1', // test requests come from 127.0.0.1
        ]);

        Auth::login($admin);

        Notification::assertNothingSentTo($admin);
    }
}
