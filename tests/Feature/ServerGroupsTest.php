<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_group_exists_after_migration(): void
    {
        $this->assertDatabaseHas('server_groups', ['id' => 1, 'name' => '默认分组']);
    }

    public function test_admin_can_create_a_group(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.groups')
            ->call('create')
            ->set('name', '北美节点组')
            ->call('save');

        $this->assertDatabaseHas('server_groups', ['name' => '北美节点组']);
    }

    public function test_admin_cannot_delete_a_group_in_use(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $group = ServerGroup::create(['name' => '测试组']);
        Server::create(['name' => 'x', 'group_id' => $group->id, 'host' => '1.2.3.4', 'port' => 443]);

        \Livewire\Livewire::actingAs($admin)->test('admin.groups')
            ->call('delete', $group->id)
            ->assertSet('error', fn ($v) => str_contains($v, '无法删除'));

        $this->assertDatabaseHas('server_groups', ['id' => $group->id]);
    }

    public function test_admin_can_delete_an_unused_group(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $group = ServerGroup::create(['name' => '空组']);

        \Livewire\Livewire::actingAs($admin)->test('admin.groups')
            ->call('delete', $group->id);

        $this->assertDatabaseMissing('server_groups', ['id' => $group->id]);
    }
}
