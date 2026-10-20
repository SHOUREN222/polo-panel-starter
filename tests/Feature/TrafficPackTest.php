<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficPack;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SharedNodeService;
use App\Services\SubscriptionLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TrafficPackTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedUser(array $planAttrs = []): array
    {
        $plan = Plan::create(array_merge([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1000], 'sell' => true,
            'traffic_pack_price_per_gb' => 1500, 'traffic_reset_pack_price_per_gb' => 1000,
        ], $planAttrs));
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return [$user->fresh(), $plan];
    }

    private function ispInstance(): array
    {
        $plan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP, 'show' => true,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
            'traffic_pack_price_per_gb' => 200,
        ]);
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return [$user->fresh(), $plan, $user->subscriptionInstances()->firstOrFail()];
    }

    public function test_isp_instance_pack_order_price_bytes_and_instance_link(): void
    {
        [$user, , $instance] = $this->ispInstance();

        $order = app(OrderService::class)->createInstanceTrafficPackOrder($user, $instance, 5);

        $this->assertSame(1000, $order->total_amount); // 5 * ¥2.00
        $this->assertSame(Order::TYPE_TRAFFIC_PACK, $order->type);
        $this->assertSame(TrafficPack::KIND_ONE_TIME, $order->pack_kind);
        $this->assertSame($instance->id, $order->renew_subscription_instance_id);
        $this->assertSame(5 * 1024 * 1024 * 1024, $order->pack_bytes);
    }

    public function test_paying_isp_pack_scopes_it_to_the_instance_and_raises_that_lines_quota(): void
    {
        [$user, , $instance] = $this->ispInstance();
        $base = $instance->effectiveQuotaBytes();

        app(OrderService::class)->markPaid(
            app(OrderService::class)->createInstanceTrafficPackOrder($user, $instance, 5)
        );

        $pack = TrafficPack::where('subscription_instance_id', $instance->id)->firstOrFail();
        $this->assertSame(5 * 1024 * 1024 * 1024, $pack->bytes);
        $this->assertSame($base + 5 * 1024 * 1024 * 1024, $instance->fresh()->effectiveQuotaBytes());
    }

    public function test_isp_pack_does_not_leak_into_the_shared_quota(): void
    {
        [$user, $sharedPlan] = $this->subscribedUser(); // shared base = 1024 bytes
        $ispPlan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
            'traffic_pack_price_per_gb' => 200,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $ispPlan, 'month'));
        $instance = $user->subscriptionInstances()->firstOrFail();

        app(OrderService::class)->markPaid(
            app(OrderService::class)->createInstanceTrafficPackOrder($user->fresh(), $instance, 5)
        );

        // The ISP pack raised only the instance; the shared pool is untouched.
        $this->assertSame(1024, app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh()));
        $this->assertSame(
            50 * 1024 * 1024 * 1024 + 5 * 1024 * 1024 * 1024,
            $instance->fresh()->effectiveQuotaBytes()
        );
    }

    public function test_cannot_buy_isp_pack_for_another_users_instance(): void
    {
        [, , $instance] = $this->ispInstance();
        $intruder = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createInstanceTrafficPackOrder($intruder, $instance, 5);
    }

    public function test_customer_can_buy_an_isp_line_pack_from_the_renew_page(): void
    {
        // ISP line packs live on the 续费订阅 page (每条线路各自加购), not the buy page.
        [$user, , $instance] = $this->ispInstance();

        Livewire::actingAs($user)->test('renew')
            ->assertSee('加购流量包 ¥2.00/GB')
            ->call('openPack', $instance->id)
            ->set('packGigabytes', '5')
            ->call('buyPack')
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->where('type', Order::TYPE_TRAFFIC_PACK)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame($instance->id, $order->renew_subscription_instance_id);
        $this->assertSame(1000, $order->total_amount); // 5 * ¥2.00
    }

    public function test_one_time_pack_purchase_price_and_bytes_are_computed_correctly(): void
    {
        [$user, $plan] = $this->subscribedUser();

        $order = app(OrderService::class)->createTrafficPackOrder($user, $plan, TrafficPack::KIND_ONE_TIME, 3);

        $this->assertSame(4500, $order->total_amount); // 3 * ¥15.00
        $this->assertSame(Order::TYPE_TRAFFIC_PACK, $order->type);
        $this->assertSame('one_time', $order->pack_kind);
        $this->assertSame(3 * 1024 * 1024 * 1024, $order->pack_bytes);
    }

    public function test_paying_for_a_traffic_pack_creates_it_and_increases_the_quota(): void
    {
        [$user, $plan] = $this->subscribedUser();

        $order = app(OrderService::class)->createTrafficPackOrder($user, $plan, TrafficPack::KIND_ONE_TIME, 2);
        app(OrderService::class)->markPaid($order);

        $pack = TrafficPack::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(2 * 1024 * 1024 * 1024, $pack->bytes);

        $quota = app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh());
        $this->assertSame(1024 + 2 * 1024 * 1024 * 1024, $quota);
    }

    public function test_subscription_userinfo_total_includes_traffic_pack(): void
    {
        [$user, $plan] = $this->subscribedUser();
        app(OrderService::class)->markPaid(
            app(OrderService::class)->createTrafficPackOrder($user, $plan, TrafficPack::KIND_ONE_TIME, 2)
        );

        // The total the client reads (Subscription-Userinfo header) must reflect
        // the plan base plus the purchased pack, not just the base quota.
        $info = app(SubscriptionLinkService::class)->buildUserinfo($user->fresh());

        $this->assertSame(1024 + 2 * 1024 * 1024 * 1024, $info['total']);
    }

    public function test_cannot_buy_a_pack_kind_the_plan_does_not_offer(): void
    {
        [$user, $plan] = $this->subscribedUser(['traffic_reset_pack_price_per_gb' => null]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createTrafficPackOrder($user, $plan, TrafficPack::KIND_RESETTABLE, 1);
    }

    public function test_cannot_buy_a_pack_for_a_plan_youre_not_subscribed_to(): void
    {
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1000], 'sell' => true,
            'traffic_pack_price_per_gb' => 1500,
        ]);
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createTrafficPackOrder($user, $plan, TrafficPack::KIND_ONE_TIME, 1);
    }

    public function test_customer_can_buy_a_shared_traffic_pack_from_the_renew_page(): void
    {
        // Shared-plan 加油包 lives on the 续费订阅 page (管理已有的订阅), not the buy page.
        [$user, $plan] = $this->subscribedUser();

        Livewire::actingAs($user)->test('renew')
            ->assertSee('流量包')
            ->call('openSharedPack', 'one_time')
            ->set('sharedPackGigabytes', '2')
            ->call('buySharedPack')
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->where('type', Order::TYPE_TRAFFIC_PACK)->firstOrFail();
        $this->assertSame(3000, $order->total_amount);
    }

    public function test_renew_page_pays_the_pack_order_inline_without_leaving_the_page(): void
    {
        // The 续费页 shows a 待支付 section and settles the order right there — no
        // detour to 我的订单.
        [$user] = $this->subscribedUser();
        $user->update(['balance' => 10000]); // ¥100 store credit

        $component = Livewire::actingAs($user->fresh())->test('renew')
            ->call('openSharedPack', 'one_time')
            ->set('sharedPackGigabytes', '2')
            ->call('buySharedPack')
            ->assertHasNoErrors()
            ->assertSee('待支付');

        $order = Order::where('user_id', $user->id)->where('type', Order::TYPE_TRAFFIC_PACK)->latest()->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, $order->status);

        // Pay inline with balance — the order settles without navigating away.
        $component->call('useBalance', $order->id);

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(7000, $user->fresh()->balance); // 100 − 30
    }
}
