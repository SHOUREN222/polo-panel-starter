<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status): Order
    {
        $user = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'type' => Plan::TYPE_SHARED, 'group_id' => 1, 'transfer_enable' => 1, 'prices' => ['month' => 1500]]);

        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => 'T'.uniqid(), 'total_amount' => 1500,
            'status' => $status, 'paid_at' => $status === Order::STATUS_PAID ? now() : null,
        ]);
    }

    public function test_admin_can_delete_a_pending_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order(Order::STATUS_PENDING);

        Livewire::actingAs($admin)->test('admin.orders')->call('deleteOrder', $order->id);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_admin_cannot_delete_a_paid_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order(Order::STATUS_PAID);

        Livewire::actingAs($admin)->test('admin.orders')
            ->call('deleteOrder', $order->id)
            ->assertSet('error', '已支付订单是收入记录，不可删除');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_clear_pending_removes_only_pending_orders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->order(Order::STATUS_PENDING);
        $this->order(Order::STATUS_PENDING);
        $paid = $this->order(Order::STATUS_PAID);

        Livewire::actingAs($admin)->test('admin.orders')->call('clearPending');

        $this->assertSame(0, Order::where('status', Order::STATUS_PENDING)->count());
        $this->assertDatabaseHas('orders', ['id' => $paid->id]); // paid kept
    }
}
