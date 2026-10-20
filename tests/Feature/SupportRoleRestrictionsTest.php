<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 客服 (support) can open 用户管理 to look, but every write action there is
 * blocked server-side — the route middleware alone isn't enough because
 * Livewire methods are directly invocable.
 */
class SupportRoleRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    private function support(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
    }

    public function test_support_cannot_adjust_a_users_balance(): void
    {
        $customer = User::factory()->create(['balance' => 1000]);

        Livewire::actingAs($this->support())->test('admin.users')
            ->call('startAdjust', $customer->id)
            ->set('adjustAmount', '50')
            ->set('adjustNote', 'free money')
            ->call('saveAdjust')
            ->assertSet('error', '客服无权执行此操作（用户页只读）');

        $this->assertSame(1000, $customer->fresh()->balance);
    }

    public function test_support_cannot_ban_a_user(): void
    {
        $customer = User::factory()->create(['banned' => false]);

        Livewire::actingAs($this->support())->test('admin.users')
            ->call('toggleBan', $customer->id)
            ->assertSet('error', '客服无权执行此操作（用户页只读）');

        $this->assertFalse($customer->fresh()->banned);
    }

    public function test_support_cannot_reset_a_users_subscription(): void
    {
        $customer = User::factory()->create();
        $originalToken = $customer->token;

        Livewire::actingAs($this->support())->test('admin.users')
            ->call('resetSubscription', $customer->id)
            ->assertSet('error', '客服无权执行此操作（用户页只读）');

        $this->assertSame($originalToken, $customer->fresh()->token);
    }

    public function test_support_cannot_write_an_admin_note(): void
    {
        $customer = User::factory()->create();

        Livewire::actingAs($this->support())->test('admin.users')
            ->call('viewUser', $customer->id)
            ->set('noteDraft', 'sneaky note')
            ->call('saveAdminNote')
            ->assertSet('error', '客服无权执行此操作（用户页只读）');

        $this->assertNull($customer->fresh()->admin_note);
    }

    public function test_a_super_admin_still_can_do_all_of_these(): void
    {
        $super = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $customer = User::factory()->create(['balance' => 1000]);

        Livewire::actingAs($super)->test('admin.users')
            ->call('startAdjust', $customer->id)
            ->set('adjustAmount', '50')
            ->set('adjustNote', 'comp')
            ->call('saveAdjust');

        $this->assertSame(6000, $customer->fresh()->balance); // 1000 + 5000
    }

    public function test_ban_button_never_touches_an_admin_account(): void
    {
        $super = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $otherAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'banned' => false]);

        Livewire::actingAs($super)->test('admin.users')
            ->call('toggleBan', $otherAdmin->id)
            ->assertSet('error', '不能封禁管理员账号');

        $this->assertFalse($otherAdmin->fresh()->banned);
    }
}
