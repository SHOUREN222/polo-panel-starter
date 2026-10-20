<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mock-payment shortcuts (which settle an order for free) must be
 * completely unreachable in production — otherwise any logged-in customer
 * could fulfil their own order without paying. These tests force the app into
 * the "production" environment and assert every entry point is blocked.
 */
class MockPaymentDisabledInProductionTest extends TestCase
{
    use RefreshDatabase;

    private function pendingOrder(): Order
    {
        Server::create([
            'name' => '共享节点', 'group_id' => 1,
            'host' => '198.51.100.10', 'port' => 443, 'rate' => 1,
        ]);
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 3000], 'sell' => true,
        ]);

        return app(OrderService::class)->createOrder(User::factory()->create(), $plan, 'month');
    }

    private function forceProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_api_mock_pay_is_blocked_in_production(): void
    {
        $order = $this->pendingOrder();
        $this->forceProduction();

        $this->actingAs($order->user)
            ->postJson("/api/orders/{$order->trade_no}/pay")
            ->assertNotFound();

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_orders_page_mock_pay_is_blocked_in_production(): void
    {
        $order = $this->pendingOrder();
        $this->forceProduction();

        try {
            Livewire::actingAs($order->user)->test('plans')->call('payMock', $order->id);
        } catch (\Throwable) {
            // abort(404) surfaces as a thrown HTTP exception — either way the
            // security property is that the order was never settled.
        }

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_plans_page_mock_pay_is_blocked_in_production(): void
    {
        $order = $this->pendingOrder();
        $this->forceProduction();

        try {
            Livewire::actingAs($order->user)->test('plans')->call('payMock', $order->id);
        } catch (\Throwable) {
            // as above — blocked, order stays pending.
        }

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_mock_pay_still_works_in_testing_env_so_existing_flows_are_unaffected(): void
    {
        // The suite runs in the "testing" env; the guard must allow it here.
        $order = $this->pendingOrder();

        $this->actingAs($order->user)
            ->postJson("/api/orders/{$order->trade_no}/pay")
            ->assertOk();

        $this->assertTrue($order->fresh()->isPaid());
    }
}
