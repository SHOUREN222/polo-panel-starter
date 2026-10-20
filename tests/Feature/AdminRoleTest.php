<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'is_owner' => true]);
    }

    private function super(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
    }

    private function support(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
    }

    public function test_super_admin_can_open_restricted_sections(): void
    {
        $this->actingAs($this->super())->get(route('admin.system'))->assertOk();
    }

    public function test_support_is_blocked_from_super_only_sections(): void
    {
        $support = $this->support();
        $this->actingAs($support)->get(route('admin.system'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.payments'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.servers'))->assertForbidden();
    }

    public function test_support_can_open_allowed_sections(): void
    {
        $support = $this->support();
        $this->actingAs($support)->get(route('admin.users'))->assertOk();
        $this->actingAs($support)->get(route('admin.tickets'))->assertOk();
        $this->actingAs($support)->get(route('admin.orders'))->assertOk();
    }

    public function test_owner_can_promote_to_super_change_and_revoke(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create();

        Livewire::actingAs($owner)->test('admin.users')
            ->call('setAdminRole', $user->id, User::ROLE_SUPPORT);
        $this->assertTrue($user->fresh()->isSupport());

        Livewire::actingAs($owner)->test('admin.users')
            ->call('setAdminRole', $user->id, User::ROLE_SUPER);
        $this->assertTrue($user->fresh()->isSuperAdmin());

        Livewire::actingAs($owner)->test('admin.users')
            ->call('setAdminRole', $user->id, null);
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_non_owner_super_can_manage_support_tier_only(): void
    {
        $super = $this->super();
        $normal = User::factory()->create();

        // can promote a normal user to 客服 …
        Livewire::actingAs($super)->test('admin.users')
            ->call('setAdminRole', $normal->id, User::ROLE_SUPPORT);
        $this->assertTrue($normal->fresh()->isSupport());

        // … and revoke a 客服 back to normal
        Livewire::actingAs($super)->test('admin.users')
            ->call('setAdminRole', $normal->id, null);
        $this->assertFalse($normal->fresh()->is_admin);
    }

    public function test_non_owner_super_cannot_grant_super(): void
    {
        $super = $this->super();
        $normal = User::factory()->create();

        Livewire::actingAs($super)->test('admin.users')
            ->call('setAdminRole', $normal->id, User::ROLE_SUPER)
            ->assertSet('error', '只有站长可以设置或变更超级管理员');
        $this->assertFalse($normal->fresh()->is_admin);
    }

    public function test_non_owner_super_cannot_demote_or_revoke_another_super(): void
    {
        $super = $this->super();
        $otherSuper = $this->super();

        Livewire::actingAs($super)->test('admin.users')
            ->call('setAdminRole', $otherSuper->id, User::ROLE_SUPPORT)
            ->assertSet('error', '只有站长可以设置或变更超级管理员');
        $this->assertTrue($otherSuper->fresh()->isSuperAdmin());

        Livewire::actingAs($super)->test('admin.users')
            ->call('setAdminRole', $otherSuper->id, null)
            ->assertSet('error', '只有站长可以设置或变更超级管理员');
        $this->assertTrue($otherSuper->fresh()->isSuperAdmin());
    }

    public function test_owner_account_cannot_be_modified_by_anyone(): void
    {
        $owner = $this->owner();
        $otherSuper = $this->super();

        // another super cannot touch the owner
        Livewire::actingAs($otherSuper)->test('admin.users')
            ->call('setAdminRole', $owner->id, User::ROLE_SUPPORT)
            ->assertSet('error', '站长账号不可修改');
        $this->assertTrue($owner->fresh()->isOwner());
    }

    public function test_support_cannot_change_roles(): void
    {
        $support = $this->support();
        $target = User::factory()->create();

        Livewire::actingAs($support)->test('admin.users')
            ->call('setAdminRole', $target->id, User::ROLE_SUPPORT)
            ->assertSet('error', '只有超级管理员可以管理权限');
        $this->assertFalse($target->fresh()->is_admin);
    }

    public function test_owner_cannot_change_own_role(): void
    {
        $owner = $this->owner();
        Livewire::actingAs($owner)->test('admin.users')
            ->call('setAdminRole', $owner->id, User::ROLE_SUPPORT)
            ->assertSet('error', '不能修改自己的管理员权限');
        $this->assertTrue($owner->fresh()->isOwner());
    }
}
