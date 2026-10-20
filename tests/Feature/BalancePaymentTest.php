<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BalancePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function sharedPlan(int $priceCents = 3000): Plan
    {
        Server::create([
            'name' => '共享节点', 'group_id' => 1,
            'host' => '198.51.100.10', 'port' => 443, 'rate' => 1,
        ]);

        return Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => $priceCents], 'sell' => true,
        ]);
    }

    public function test_full_balance_payment_settles_the_order_and_deducts_balance(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 5000;
        $user->save();

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        $paid = app(OrderService::class)->applyBalance($order);

        $this->assertTrue($paid->isPaid());
        $this->assertSame(3000, $paid->balance_amount);
        $this->assertSame(0, $paid->payableAmount());
        $this->assertSame(2000, $user->fresh()->balance);
        // Fulfillment ran: the shared plan is now active on the user.
        $this->assertSame($plan->id, $user->fresh()->plan_id);
    }

    public function test_partial_balance_earmarks_without_deducting_and_keeps_order_pending(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 1000;
        $user->save();

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        $order = app(OrderService::class)->applyBalance($order);

        $this->assertFalse($order->isPaid());
        $this->assertSame(1000, $order->balance_amount);
        $this->assertSame(2000, $order->payableAmount());
        // Balance is only earmarked, not deducted — nothing to refund if abandoned.
        $this->assertSame(1000, $user->fresh()->balance);
        $this->assertNull($user->fresh()->plan_id);
    }

    public function test_gateway_remainder_deducts_the_earmarked_balance_at_settlement(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 1000;
        $user->save();

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->applyBalance($order);
        $this->assertSame(1000, $user->fresh()->balance);

        // Gateway callback settles the ¥20 remainder — balance is drawn now.
        app(OrderService::class)->markPaid($order->fresh());

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertSame(0, $user->fresh()->balance);
    }

    public function test_abandoned_partial_order_leaves_balance_fully_available(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 1000;
        $user->save();

        $orderA = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->applyBalance($orderA); // earmark, never pay

        // The same ¥10 is still fully available for a different order.
        $orderB = app(OrderService::class)->createOrder($user, $plan, 'month');
        $orderB = app(OrderService::class)->applyBalance($orderB);

        $this->assertSame(1000, $orderB->balance_amount);
        $this->assertSame(1000, $user->fresh()->balance);
    }

    public function test_settlement_never_overdraws_balance_when_earmarked_twice(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 1000;
        $user->save();

        $orderA = app(OrderService::class)->createOrder($user, $plan, 'month');
        $orderB = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->applyBalance($orderA);
        app(OrderService::class)->applyBalance($orderB);

        app(OrderService::class)->markPaid($orderA->fresh());
        app(OrderService::class)->markPaid($orderB->fresh());

        // A drew the whole ¥10; B found none left and drew ¥0 — balance floored at 0.
        $this->assertSame(1000, $orderA->fresh()->balance_amount);
        $this->assertSame(0, $orderB->fresh()->balance_amount);
        $this->assertSame(0, $user->fresh()->balance);
    }

    public function test_applying_balance_with_zero_balance_throws(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create(['balance' => 0]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        $this->expectException(ValidationException::class);
        app(OrderService::class)->applyBalance($order);
    }

    public function test_orders_page_full_balance_button_settles_the_order(): void
    {
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create();
        $user->balance = 5000;
        $user->save();

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        Livewire::actingAs($user)->test('plans')
            ->call('useBalance', $order->id)
            ->assertSee('已用余额支付完成');

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(2000, $user->fresh()->balance);
    }
}
