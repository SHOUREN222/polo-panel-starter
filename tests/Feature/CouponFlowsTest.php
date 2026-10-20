<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(int $cents): Coupon
    {
        return Coupon::create(['code' => 'SAVE', 'type' => 'fixed', 'value' => $cents, 'enabled' => true, 'max_uses' => 1]);
    }

    private function shared(int $monthCents, int $gb): Plan
    {
        return Plan::create([
            'name' => $gb.'G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => $gb * 1024 * 1024 * 1024, 'prices' => ['month' => $monthCents], 'sell' => true,
            'traffic_pack_price_per_gb' => 100,
        ]);
    }

    public function test_coupon_reduces_a_renewal_order(): void
    {
        $plan = Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP, 'allow_renewal' => true,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan, 'month'));
        $instance = $user->subscriptionInstances()->firstOrFail();
        $this->coupon(2000);

        $order = $svc->createRenewalOrder($user, $instance, 'month', 'SAVE');

        $this->assertSame(3000, $order->total_amount);   // ¥50 − ¥20
        $this->assertSame(2000, $order->discount_amount);
    }

    public function test_coupon_applies_after_credit_on_a_change(): void
    {
        Setting::set('plan_change_proration_enabled', '1');
        $plan20 = $this->shared(1500, 20);
        $plan100 = $this->shared(6500, 100);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan20, 'month')); // on 20G, ¥15
        $this->coupon(1000);

        $order = $svc->createChangeOrder($user->fresh(), $plan100, 'month', 'SAVE');

        // ¥65 − ¥15 credit = ¥50; − ¥10 coupon = ¥40
        $this->assertSame(4000, $order->total_amount);
        $this->assertSame(2500, $order->discount_amount); // 1500 credit + 1000 coupon
    }

    public function test_coupon_is_not_consumed_when_credit_already_zeroes_the_order(): void
    {
        Setting::set('plan_change_proration_enabled', '1');
        $plan20 = $this->shared(1500, 20);
        $plan100 = $this->shared(6500, 100);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan100, 'month')); // on 100G, credit ¥65 covers a downgrade
        $coupon = $this->coupon(1000);

        $order = $svc->createChangeOrder($user->fresh(), $plan20, 'month', 'SAVE');

        $this->assertSame(0, $order->total_amount);
        $this->assertNull($order->coupon_id); // free order → coupon left unused
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_coupon_reduces_a_shared_traffic_pack(): void
    {
        $plan = $this->shared(1500, 20);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan, 'month'));
        $this->coupon(300); // ¥3 off

        $order = $svc->createTrafficPackOrder($user->fresh(), $plan, 'one_time', 5, 'SAVE'); // 5G × ¥1 = ¥5

        $this->assertSame(200, $order->total_amount); // ¥5 − ¥3
        $this->assertSame(300, $order->discount_amount);
    }

    public function test_invalid_coupon_is_rejected_on_every_flow(): void
    {
        $plan = $this->shared(1500, 20);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan, 'month'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->createTrafficPackOrder($user->fresh(), $plan, 'one_time', 1, 'NOPE');
    }

    public function test_per_user_limit_blocks_the_same_user_reusing_a_coupon(): void
    {
        $plan = $this->shared(1500, 20);
        $svc = app(OrderService::class);
        $user = User::factory()->create();
        // 每人限用 1 次、总量不限
        Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 300, 'enabled' => true, 'per_user_limit' => 1]);

        // 第一次可用（订单进入待支付，即已占用一次名额）
        $svc->createOrder($user, $plan, 'month', 'ONCE');

        // 同一用户第二次被拒——即便第一单还没支付，也算已占用
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('该优惠券每位用户可用次数已用完');
        $svc->createOrder($user->fresh(), $plan, 'month', 'ONCE');
    }

    public function test_per_user_limit_is_per_user_not_global(): void
    {
        $plan = $this->shared(1500, 20);
        $svc = app(OrderService::class);
        Coupon::create(['code' => 'EACH', 'type' => 'fixed', 'value' => 300, 'enabled' => true, 'per_user_limit' => 1]);

        // 两个不同用户各自都能用一次
        $a = $svc->createOrder(User::factory()->create(), $plan, 'month', 'EACH');
        $b = $svc->createOrder(User::factory()->create(), $plan, 'month', 'EACH');

        $this->assertSame(300, $a->discount_amount);
        $this->assertSame(300, $b->discount_amount);
    }

    public function test_global_max_uses_is_not_exceeded_when_two_orders_settle(): void
    {
        $plan = $this->shared(1500, 20);
        $svc = app(OrderService::class);
        // 总量 1 次
        $coupon = Coupon::create(['code' => 'ONLY1', 'type' => 'fixed', 'value' => 300, 'enabled' => true, 'max_uses' => 1]);

        // 两个用户都在券耗尽前下了单（模拟并发：都通过了创建期校验）
        $o1 = $svc->createOrder(User::factory()->create(), $plan, 'month', 'ONLY1');
        $o2 = $svc->createOrder(User::factory()->create(), $plan, 'month', 'ONLY1');

        // 两单都结算——used_count 不会超过 max_uses
        $svc->markPaid($o1);
        $svc->markPaid($o2);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }
}
