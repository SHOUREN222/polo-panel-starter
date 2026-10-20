<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PlanChangeTest extends TestCase
{
    use RefreshDatabase;

    private function makeSharedPlan(string $name, int $monthPrice): Plan
    {
        return Plan::create([
            'name' => $name, 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024, 'prices' => ['month' => $monthPrice], 'sell' => true,
        ]);
    }

    public function test_change_order_has_no_credit_when_proration_is_disabled(): void
    {
        Setting::set('plan_change_proration_enabled', false);

        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(15)]);

        $order = app(OrderService::class)->createChangeOrder($user, $newPlan, 'month');

        $this->assertSame(0, $order->discount_amount);
        $this->assertSame(2000, $order->total_amount);
        $this->assertSame(Order::TYPE_CHANGE, $order->type);
    }

    public function test_change_order_credits_remaining_traffic_when_proration_is_enabled(): void
    {
        Setting::set('plan_change_proration_enabled', true);

        // 旧套餐 ¥30 / 20GB，已用 12GB → 剩 8GB → 30 × 8/20 = ¥12 折抵（按流量）
        [$user] = $this->makeSharedUser(3000, 20, 12);
        $newPlan = $this->makeSharedPlan('新套餐', 5000);

        $order = app(OrderService::class)->createChangeOrder($user, $newPlan, 'month');

        $this->assertSame(1200, $order->discount_amount);
        $this->assertSame(3800, $order->total_amount);
    }

    public function test_change_order_credit_never_exceeds_new_plan_price(): void
    {
        Setting::set('plan_change_proration_enabled', true);

        // 旧套餐 ¥300 / 20GB 全未用 → 折抵值 ¥300，但封顶在新套餐价 ¥5
        [$user] = $this->makeSharedUser(30000, 20, 0);
        $newPlan = $this->makeSharedPlan('便宜套餐', 500);

        $order = app(OrderService::class)->createChangeOrder($user, $newPlan, 'month');

        $this->assertSame(500, $order->discount_amount);
        $this->assertSame(0, $order->total_amount);
    }

    public function test_change_order_rejected_for_dedicated_isp_plan(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createChangeOrder($user, $plan, 'month');
    }

    public function test_paying_change_order_switches_plan_with_fresh_period(): void
    {
        Setting::set('plan_change_proration_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-07-01'));

        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        $orderService = app(OrderService::class);
        $order = $orderService->createChangeOrder($user, $newPlan, 'month');
        $orderService->markPaid($order);

        $user->refresh();
        $this->assertSame($newPlan->id, $user->plan_id);
        $this->assertTrue($user->plan_expired_at->isSameDay(now()->addMonth()));

        Carbon::setTestNow();
    }

    public function test_customer_sees_change_plan_button_for_other_shared_plans(): void
    {
        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        Livewire::actingAs($user)->test('plans')
            ->assertSee('更换')
            ->assertSee($newPlan->name);
    }

    public function test_other_shared_plan_offers_only_change_not_a_plain_buy_that_forfeits_credit(): void
    {
        // A customer already on a shared plan must NOT see a plain "buy" button
        // for a different shared plan — plain buy replaces their plan with no
        // credit for the remaining value. Only 更换 (change, with proration
        // credit) should be offered for it.
        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);   // ¥10
        $newPlan = $this->makeSharedPlan('新套餐', 2000);   // ¥20
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        Livewire::actingAs($user)->test('plans')
            ->assertSee('月付 更换 ¥20.00')   // the switch path is offered...
            ->assertDontSee('月付 ¥20.00');    // ...but not a plain buy of the new plan
    }

    public function test_customer_can_submit_plan_change_via_component(): void
    {
        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        Livewire::actingAs($user)->test('plans')
            ->call('changePlan', $newPlan->id, 'month')
            ->assertSet('error', '');

        $this->assertSame(1, Order::where('type', Order::TYPE_CHANGE)->count());
    }

    public function test_create_change_order_is_blocked_when_plan_change_globally_disabled(): void
    {
        Setting::set('subscribe_allow_plan_change', false);

        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createChangeOrder($user, $newPlan, 'month');
    }

    public function test_customer_does_not_see_change_button_or_plain_buy_fallback_when_plan_change_disabled(): void
    {
        Setting::set('subscribe_allow_plan_change', false);

        $oldPlan = $this->makeSharedPlan('旧套餐', 1000);
        $newPlan = $this->makeSharedPlan('新套餐', 2000);
        $user = User::factory()->create(['plan_id' => $oldPlan->id, 'plan_expired_at' => now()->addDays(5)]);

        $response = Livewire::actingAs($user)->test('plans');

        $response->assertDontSee('更换');
        $response->assertSeeText('暂不支持更改订阅');
    }

    private function makeDedicatedPlan(string $name, int $monthPrice): Plan
    {
        return Plan::create([
            'name' => $name, 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => $monthPrice],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
    }

    private const GB = 1024 * 1024 * 1024;

    private function makeSharedUser(int $monthPrice, int $quotaGb, int $usedGb): array
    {
        $shared = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => $quotaGb * self::GB,
            'prices' => ['month' => $monthPrice], 'sell' => true,
        ]);
        $user = User::factory()->create([
            'plan_id' => $shared->id,
            'plan_expired_at' => now()->addDays(20),
            'shared_traffic_reset_at' => now()->toDateString(),
        ]);
        if ($usedGb > 0) {
            TrafficLog::create([
                'user_id' => $user->id, 'server_id' => null, 'date' => now(),
                'upload_bytes' => $usedGb * self::GB, 'download_bytes' => 0, 'rate' => 1,
            ]);
        }

        // The paid order that granted this plan — the switch credit is capped
        // at what was actually paid (see OrderService::currentSharedPlanPaidCents).
        Order::create([
            'user_id' => $user->id, 'plan_id' => $shared->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => 'seed-'.$user->id, 'total_amount' => $monthPrice,
            'status' => Order::STATUS_PAID, 'paid_at' => now(),
        ]);

        return [$user->fresh(), $shared];
    }

    public function test_upgrade_order_credits_shared_remaining_traffic_toward_dedicated_price(): void
    {
        // ¥15 / 20GB 共享套餐，已用 12GB → 剩 8GB → 15 × 8/20 = ¥6 折抵
        [$user] = $this->makeSharedUser(1500, 20, 12);
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 5500);

        $order = app(OrderService::class)->createUpgradeOrder($user, $dedicated, 'month');

        $this->assertSame(600, $order->discount_amount);
        $this->assertSame(4900, $order->total_amount);
        $this->assertSame(Order::TYPE_UPGRADE, $order->type);
    }

    public function test_upgrade_credit_is_capped_at_the_dedicated_price(): void
    {
        // 共享剩余流量价值（¥90 全额未用）远超独享价格 → 折抵封顶在独享价
        [$user] = $this->makeSharedUser(9000, 20, 0);
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 2000);

        $order = app(OrderService::class)->createUpgradeOrder($user, $dedicated, 'month');

        $this->assertSame(2000, $order->discount_amount);   // 抵到订单的
        $this->assertSame(0, $order->total_amount);
        $this->assertSame(7000, $order->balance_credit_amount); // 超出的 ¥70 → 余额
    }

    public function test_upgrade_excess_value_is_credited_to_balance_on_payment(): void
    {
        // ¥90/20GB 全未用，升级 ¥20 独享：¥20 抵单、¥70 到账余额，客户一分不亏。
        [$user] = $this->makeSharedUser(9000, 20, 0);
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 2000);
        $before = $user->balance;

        $service = app(OrderService::class);
        $service->markPaid($service->createUpgradeOrder($user, $dedicated, 'month'));

        $this->assertSame($before + 7000, $user->fresh()->balance);
    }

    public function test_switch_credit_is_capped_at_what_was_actually_paid(): void
    {
        // 套餐标价 ¥90，但客户用优惠券只花了 ¥50。全未用时"流量价值"是 ¥90，
        // 但折抵总额封顶在实付 ¥50 —— 堵死"用券买 + 升级套现"薅羊毛。
        [$user, $shared] = $this->makeSharedUser(9000, 20, 0);
        Order::where('user_id', $user->id)->where('plan_id', $shared->id)->update(['total_amount' => 5000]);
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 2000);

        $order = app(OrderService::class)->createUpgradeOrder($user, $dedicated, 'month');

        // returnable = min(流量¥90, 实付¥50) = ¥50 → ¥20 抵单 + ¥30 余额（总额=实付）
        $this->assertSame(2000, $order->discount_amount);
        $this->assertSame(3000, $order->balance_credit_amount);
    }

    public function test_paying_an_upgrade_provisions_dedicated_instance_and_stops_shared_plan(): void
    {
        $shared = $this->makeSharedPlan('共享', 3000);
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 5000);
        $user = User::factory()->create(['plan_id' => $shared->id, 'plan_expired_at' => now()->addDays(10)]);

        $service = app(OrderService::class);
        $service->markPaid($service->createUpgradeOrder($user, $dedicated, 'month'));

        // 独享实例开出来了
        $this->assertDatabaseHas('subscription_instances', [
            'user_id' => $user->id, 'plan_id' => $dedicated->id,
        ]);
        // 共享套餐被停用（到期时间落到当下，不再是 active）
        $this->assertFalse($user->fresh()->hasActiveSharedPlan());
    }

    public function test_upgrade_requires_an_active_shared_plan(): void
    {
        $dedicated = $this->makeDedicatedPlan('独享 ISP', 5000);
        $user = User::factory()->create(['plan_id' => null, 'plan_expired_at' => null]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createUpgradeOrder($user, $dedicated, 'month');
    }

    public function test_upgrade_rejects_a_shared_target_plan(): void
    {
        $shared = $this->makeSharedPlan('共享', 3000);
        $anotherShared = $this->makeSharedPlan('另一个共享', 4000);
        $user = User::factory()->create(['plan_id' => $shared->id, 'plan_expired_at' => now()->addDays(10)]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createUpgradeOrder($user, $anotherShared, 'month');
    }

    public function test_dynamic_isp_upgrade_end_to_end_auto_prices_credits_provisions_and_stops_shared(): void
    {
        // ¥15 / 20GB 共享，已用 12GB → 剩 8GB → ¥6 折抵.
        [$user] = $this->makeSharedUser(1500, 20, 12);

        Setting::set('provisioner_driver', 'onip');
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');

        $dynamic = Plan::create([
            'name' => '独享 ISP｜任选地区', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * self::GB, 'prices' => ['month' => 5500],
            'resource_profile' => [
                'provider' => 'onip_dynamic', 'ispType' => 2,
                'auto_pricing' => ['enabled' => true, 'relay' => 15, 'profit' => 20, 'round' => 5],
            ],
            'sell' => true,
        ]);

        // The customer picked a ¥30-cost USA line → auto price 30+15+20 = ¥65.
        $selectedLine = [
            'provider' => 'onip', 'lineId' => 'LINE-USA',
            'line_snapshot' => ['countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'price' => 30],
        ];

        \Illuminate\Support\Facades\Http::fake(fn ($request) => \Illuminate\Support\Facades\Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'orderNo' => 'ORD1',
                'instances' => [[
                    'proxyId' => 'SI1', 'ip' => '203.0.113.50', 'port' => 8080,
                    'username' => 'u', 'password' => 'p',
                ]],
            ],
        ]));

        // The server recorded this line's real onip cost when it was searched;
        // the order path prices from here, not the request's line_snapshot.
        app(\App\Services\OnipProvisioningService::class)->cacheLine([
            'id' => 'LINE-USA', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'price' => 30, 'currency' => 'CNY',
        ]);

        $service = app(OrderService::class);
        $order = $service->createUpgradeOrder($user, $dynamic, 'month', $selectedLine);

        // Auto-priced ¥65, minus ¥6 shared-traffic credit → pay ¥59.
        $this->assertSame(6500 - 600, $order->total_amount);
        $this->assertSame(600, $order->discount_amount);

        $service->markPaid($order);

        // Dedicated instance provisioned on the picked line's real IP.
        $instance = \App\Models\SubscriptionInstance::where('user_id', $user->id)
            ->where('plan_id', $dynamic->id)->firstOrFail();
        $this->assertSame(\App\Models\SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertSame('203.0.113.50', $instance->meta['ip']);
        $this->assertSame('USA', $instance->meta['country']);
        // Shared plan stopped.
        $this->assertFalse($user->fresh()->hasActiveSharedPlan());
    }

    public function test_shared_customer_sees_upgrade_button_on_dynamic_isp_plan(): void
    {
        $shared = $this->makeSharedPlan('共享', 3000);
        Plan::create([
            'name' => '独享 ISP｜任选地区', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'onip_dynamic'], 'sell' => true,
        ]);
        $user = User::factory()->create(['plan_id' => $shared->id, 'plan_expired_at' => now()->addDays(10)]);

        Livewire::actingAs($user)->test('plans')
            ->assertSeeText('从共享套餐升级到独享') // separate upgrade section
            ->assertSeeText('选地区');               // plain new-purchase button still there
    }
}
