<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private function pendingOrder(): array
    {
        $plan = Plan::create([
            'name' => '20G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1500], 'sell' => true, 'show' => true,
        ]);
        $user = User::factory()->create();
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        return [$user, $order];
    }

    public function test_customer_can_cancel_a_pending_order(): void
    {
        [$user, $order] = $this->pendingOrder();

        Livewire::actingAs($user)->test('plans')
            ->call('cancelOrder', $order->id)
            ->assertHasNoErrors();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cannot_cancel_a_paid_order(): void
    {
        [$user, $order] = $this->pendingOrder();
        app(OrderService::class)->markPaid($order);

        Livewire::actingAs($user)->test('plans')
            ->call('cancelOrder', $order->id)
            ->assertSet('error', '只能取消待支付的订单');

        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_cannot_cancel_another_users_order(): void
    {
        [, $order] = $this->pendingOrder();
        $intruder = User::factory()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($intruder)->test('plans')->call('cancelOrder', $order->id);
    }
}
