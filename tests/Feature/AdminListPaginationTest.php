<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminListPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_servers_list_is_paginated_at_twenty_per_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        for ($i = 0; $i < 25; $i++) {
            Server::create(['name' => "节点 {$i}", 'group_id' => 1, 'host' => "10.0.0.{$i}", 'port' => 443]);
        }

        $paginator = Livewire::actingAs($admin)->test('admin.servers')->viewData('servers');

        $this->assertSame(25, $paginator->total());
        $this->assertCount(20, $paginator->items());
    }

    public function test_users_search_filters_by_email_or_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['email' => 'findme@example.com', 'name' => 'Nobody']);
        User::factory()->create(['email' => 'other@example.com', 'name' => 'Somebody']);

        $component = Livewire::actingAs($admin)->test('admin.users')
            ->set('search', 'findme');

        $this->assertTrue($component->viewData('users')->contains('email', 'findme@example.com'));
        $this->assertFalse($component->viewData('users')->contains('email', 'other@example.com'));
    }

    public function test_orders_search_filters_by_trade_no(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $order = app(\App\Services\OrderService::class)->createOrder($buyer, $plan, 'month');

        $component = Livewire::actingAs($admin)->test('admin.orders')
            ->set('search', $order->trade_no);

        $this->assertTrue($component->viewData('orders')->contains('id', $order->id));
    }

    public function test_tickets_search_filters_by_subject(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $match = Ticket::create(['user_id' => $user->id, 'subject' => '无法连接节点', 'status' => 'open']);
        $noMatch = Ticket::create(['user_id' => $user->id, 'subject' => '发票问题', 'status' => 'open']);

        $component = Livewire::actingAs($admin)->test('admin.tickets')
            ->set('search', '连接');

        $this->assertTrue($component->viewData('tickets')->contains('id', $match->id));
        $this->assertFalse($component->viewData('tickets')->contains('id', $noMatch->id));
    }
}
