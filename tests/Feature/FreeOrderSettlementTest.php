<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FreeOrderSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPackOrder(int $total): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1000], 'sell' => true,
            'traffic_pack_price_per_gb' => 100,
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'type' => Order::TYPE_TRAFFIC_PACK, 'period' => 'once',
            'trade_no' => uniqid('t'), 'total_amount' => $total, 'status' => Order::STATUS_PENDING,
            'pack_kind' => 'one_time', 'pack_bytes' => 1073741824,
        ]);

        return [$user, $order];
    }

    public function test_free_order_can_be_completed_from_the_orders_page(): void
    {
        [$user, $order] = $this->pendingPackOrder(0);

        Livewire::actingAs($user)->test('plans')
            ->assertSee('确认开通（无需付款）')
            ->call('settleFree', $order->id)
            ->assertHasNoErrors();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_settle_free_refuses_an_order_that_actually_costs_money(): void
    {
        [$user, $order] = $this->pendingPackOrder(5000); // ¥50, not free

        Livewire::actingAs($user)->test('plans')
            ->call('settleFree', $order->id)
            ->assertSet('error', '该订单需要付款，无法直接开通');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_settle_free_refuses_another_users_order(): void
    {
        [, $order] = $this->pendingPackOrder(0);
        $intruder = User::factory()->create();

        // Scoped to the acting user — another user's order isn't found.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($intruder)->test('plans')->call('settleFree', $order->id);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }
}
