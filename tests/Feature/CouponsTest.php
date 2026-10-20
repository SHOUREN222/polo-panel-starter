<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CouponsTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);
    }

    public function test_admin_can_create_a_coupon(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.coupons')
            ->call('create')
            ->set('code', 'SAVE20')
            ->set('type', 'percent')
            ->set('value', '20')
            ->call('save');

        $this->assertDatabaseHas('coupons', ['code' => 'SAVE20', 'type' => 'percent', 'value' => 20]);
    }

    public function test_percent_coupon_discounts_order_total(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();
        Coupon::create(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'enabled' => true]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month', 'SAVE20');

        $this->assertSame(300, $order->discount_amount);
        $this->assertSame(1200, $order->total_amount);
    }

    public function test_fixed_coupon_discount_is_capped_at_price(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();
        Coupon::create(['code' => 'BIGDISCOUNT', 'type' => 'fixed', 'value' => 999999, 'enabled' => true]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month', 'BIGDISCOUNT');

        $this->assertSame(1500, $order->discount_amount);
        $this->assertSame(0, $order->total_amount);
    }

    public function test_invalid_coupon_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createOrder($user, $plan, 'month', 'NOPE');
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();
        Coupon::create(['code' => 'OLD', 'type' => 'percent', 'value' => 10, 'enabled' => true, 'expires_at' => now()->subDay()]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createOrder($user, $plan, 'month', 'OLD');
    }

    public function test_coupon_used_count_increments_on_payment(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();
        $coupon = Coupon::create(['code' => 'ONEUSE', 'type' => 'percent', 'value' => 10, 'max_uses' => 1, 'enabled' => true]);

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($user, $plan, 'month', 'ONEUSE');
        $orderService->markPaid($order);

        $this->assertSame(1, $coupon->fresh()->used_count);

        // A second redemption should now be rejected — max_uses reached.
        $this->expectException(ValidationException::class);
        $orderService->createOrder($user, $plan, 'month', 'ONEUSE');
    }

    public function test_admin_can_batch_generate_distinct_single_use_coupons(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.coupons')
            ->call('batchCreate')
            ->set('type', 'percent')->set('value', '20')
            ->set('batchCount', 25)->set('batchPrefix', 'SUMMER')
            ->call('generateBatch')
            ->assertCount('generatedCodes', 25);

        $coupons = Coupon::all();
        $this->assertCount(25, $coupons);
        // all distinct, prefixed, 20% off, single-use
        $this->assertSame(25, $coupons->pluck('code')->unique()->count());
        $this->assertTrue($coupons->every(fn ($c) => str_starts_with($c->code, 'SUMMER') && $c->value === 20 && $c->max_uses === 1));
    }
}
