<?php

namespace Tests\Feature;

use App\Models\RoutingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_routing_rule(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.routing')
            ->call('create')
            ->set('name', '屏蔽 BT')
            ->set('pattern', '*.torrent')
            ->set('action', 'block')
            ->call('save');

        $this->assertDatabaseHas('routing_rules', ['name' => '屏蔽 BT', 'action' => 'block']);
    }

    public function test_admin_can_delete_a_routing_rule(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $rule = RoutingRule::create(['name' => 'x', 'pattern' => 'x', 'action' => 'proxy']);

        \Livewire\Livewire::actingAs($admin)->test('admin.routing')
            ->call('delete', $rule->id);

        $this->assertDatabaseMissing('routing_rules', ['id' => $rule->id]);
    }
}
