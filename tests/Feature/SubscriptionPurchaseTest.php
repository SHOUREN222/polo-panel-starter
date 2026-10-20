<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_buying_a_shared_plan_grants_access_to_its_node_group(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);

        Server::create([
            'name' => '共享节点 01', 'group_id' => 1,
            'host' => '198.51.100.10', 'port' => 443, 'rate' => 1,
        ]);

        $headers = $this->authHeader($user);

        // No access before purchase.
        $this->withHeaders($headers)->getJson('/api/servers')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $order = $this->withHeaders($headers)->postJson('/api/orders', [
            'plan_id' => $plan->id,
            'period' => 'month',
        ])->assertStatus(201);

        $tradeNo = $order->json('data.trade_no');

        $this->withHeaders($headers)->postJson("/api/orders/{$tradeNo}/pay")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid');

        // Sanctum's guard caches the resolved user for the life of the
        // container; without this, the next call would see the pre-purchase
        // user even though the DB was updated (a test-only artifact — real
        // HTTP requests are separate processes and don't share this cache).
        Auth::forgetGuards();

        // Access granted after purchase.
        $this->withHeaders($headers)->getJson('/api/servers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host', '198.51.100.10');

        $user->refresh();
        $this->assertTrue($user->hasActiveSharedPlan());
    }

    public function test_buying_a_dedicated_isp_plan_provisions_an_instance_with_an_ip(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub', 'country' => 'US', 'city' => 'San Francisco'],
            'sell' => true,
        ]);

        $headers = $this->authHeader($user);

        $order = $this->withHeaders($headers)->postJson('/api/orders', [
            'plan_id' => $plan->id,
            'period' => 'month',
        ])->assertStatus(201);

        $tradeNo = $order->json('data.trade_no');

        $this->withHeaders($headers)->postJson("/api/orders/{$tradeNo}/pay")
            ->assertStatus(200);

        // QUEUE_CONNECTION=sync in testing, so the provision job has already
        // run synchronously by the time the pay request returns.
        $response = $this->withHeaders($headers)->getJson('/api/instances')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');

        $this->assertNotEmpty($response->json('data.0.meta.ip'));
    }

    public function test_renewing_an_instance_keeps_the_same_ip(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'],
            'sell' => true,
        ]);

        $orderService = app(OrderService::class);

        $buyOrder = $orderService->createOrder($user, $plan, 'month');
        $orderService->markPaid($buyOrder);

        $this->assertSame(1, SubscriptionInstance::count());
        $instance = SubscriptionInstance::first();
        $originalIp = $instance->meta['ip'];
        $originalExpiry = $instance->expired_at;

        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $this->assertSame(Order::TYPE_RENEWAL, $renewOrder->type);

        $orderService->markPaid($renewOrder);

        // Still exactly one instance — renewal must not create a second one.
        $this->assertSame(1, SubscriptionInstance::count());

        $instance->refresh();
        $this->assertSame($originalIp, $instance->meta['ip']);
        $this->assertTrue($instance->expired_at->greaterThan($originalExpiry));
        $this->assertSame($renewOrder->id, $instance->last_order_id);
    }

    public function test_quarterly_purchase_grants_three_months_of_traffic_up_front(): void
    {
        // 方案A: traffic is granted for the whole purchased period up front.
        // A quarterly (3-month) buy of a 50G/月 plan yields a 150G quota, not 50G.
        $user = User::factory()->create();
        $monthly = 50 * 1024 * 1024 * 1024;
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => $monthly, 'prices' => ['month' => 5500, 'quarter' => 15000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'quarter'));

        $instance = SubscriptionInstance::first();
        $this->assertSame(3 * $monthly, $instance->transfer_enable);
    }

    public function test_isp_expiry_uses_fixed_day_counts_matching_the_onip_lease(): void
    {
        // The panel's ISP expiry must count the SAME fixed days onip's lease
        // does (30/90/180/365…), not calendar months — otherwise the panel
        // reads "active" for the few days after onip has already released the
        // IP. half_year = exactly 180 days, not ~183 calendar days.
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500, 'half_year' => 30000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);

        $before = now();
        $orderService->markPaid($orderService->createOrder($user, $plan, 'half_year'));
        $instance = SubscriptionInstance::first();

        // 180 days exactly (allow a second of test execution slop), NOT a
        // calendar half-year which would be ~183.
        $this->assertEqualsWithDelta(180, $before->diffInDays($instance->expired_at), 1);

        // A renewal before expiry stacks another 180 days, still day-based.
        $expiryBeforeRenew = $instance->expired_at->copy();
        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'half_year');
        $orderService->markPaid($renewOrder);
        $this->assertEqualsWithDelta(180, $expiryBeforeRenew->diffInDays($instance->fresh()->expired_at), 1);
    }

    public function test_renewal_before_expiry_stacks_remaining_traffic_and_keeps_usage(): void
    {
        // 方案A: renewing an unexpired instance adds the new period's quota
        // onto the existing quota, and keeps u/d — the customer never loses
        // the balance they already paid for and haven't used.
        $user = User::factory()->create();
        $monthly = 50 * 1024 * 1024 * 1024;
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => $monthly, 'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $instance->update(['u' => 10 * 1024 * 1024 * 1024, 'd' => 5 * 1024 * 1024 * 1024]);

        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $orderService->markPaid($renewOrder);

        $instance->refresh();
        // quota stacked: 50G + 50G = 100G
        $this->assertSame(2 * $monthly, $instance->transfer_enable);
        // usage preserved, not zeroed
        $this->assertSame(10 * 1024 * 1024 * 1024, $instance->u);
        $this->assertSame(5 * 1024 * 1024 * 1024, $instance->d);
    }

    public function test_renewal_after_expiry_starts_fresh_with_just_the_new_period(): void
    {
        // 方案A: once the lease has lapsed the old IP and its unused balance
        // are forfeited — renewing sets quota back to just this period and
        // zeroes usage (new IP, fresh accounting).
        $user = User::factory()->create();
        $monthly = 50 * 1024 * 1024 * 1024;
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => $monthly, 'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $instance->update([
            'u' => 10 * 1024 * 1024 * 1024, 'd' => 5 * 1024 * 1024 * 1024,
            'expired_at' => now()->subDay(),
        ]);

        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $orderService->markPaid($renewOrder);

        $instance->refresh();
        $this->assertSame($monthly, $instance->transfer_enable);
        $this->assertSame(0, $instance->u);
        $this->assertSame(0, $instance->d);
    }

    public function test_renewing_an_already_expired_instance_gets_a_new_ip(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'],
            'sell' => true,
        ]);

        $orderService = app(OrderService::class);

        $buyOrder = $orderService->createOrder($user, $plan, 'month');
        $orderService->markPaid($buyOrder);

        $instance = SubscriptionInstance::first();
        $originalIp = $instance->meta['ip'];

        // Simulate the lease having already lapsed — the upstream API would
        // have released this IP back to its pool by now.
        $instance->update(['expired_at' => now()->subDay()]);

        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $orderService->markPaid($renewOrder);

        // Still the same instance (same id/token/subscription URL) — only
        // the underlying IP changed.
        $this->assertSame(1, SubscriptionInstance::count());

        $instance->refresh();
        $this->assertNotSame($originalIp, $instance->meta['ip']);
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertTrue($instance->expired_at->isFuture());
    }

    public function test_buying_the_same_plan_again_allocates_a_separate_instance_and_ip(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'],
            'sell' => true,
        ]);

        $orderService = app(OrderService::class);

        $order1 = $orderService->createOrder($user, $plan, 'month');
        $orderService->markPaid($order1);

        $order2 = $orderService->createOrder($user, $plan, 'month');
        $orderService->markPaid($order2);

        $instances = SubscriptionInstance::where('user_id', $user->id)->get();

        $this->assertCount(2, $instances);
        $this->assertNotSame($instances[0]->meta['ip'], $instances[1]->meta['ip']);
    }

    public function test_cannot_renew_another_users_instance(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1500],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($owner, $plan, 'month'));
        $instance = SubscriptionInstance::first();

        $this->expectException(ValidationException::class);
        $orderService->createRenewalOrder($intruder, $instance, 'month');
    }

    public function test_cannot_buy_a_plan_with_an_unsupported_period(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Monthly only',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 1,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);

        $this->withHeaders($this->authHeader($user))->postJson('/api/orders', [
            'plan_id' => $plan->id,
            'period' => 'year',
        ])->assertStatus(422);
    }

    public function test_cannot_buy_a_shared_plan_that_is_at_capacity(): void
    {
        $plan = Plan::create([
            'name' => '限量套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'capacity_limit' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder(User::factory()->create(), $plan, 'month'));

        $this->expectException(ValidationException::class);
        $orderService->createOrder(User::factory()->create(), $plan, 'month');
    }

    public function test_capacity_limit_does_not_block_a_second_user_once_the_first_expires(): void
    {
        $plan = Plan::create([
            'name' => '限量套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'capacity_limit' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $first = User::factory()->create();
        $orderService->markPaid($orderService->createOrder($first, $plan, 'month'));
        $first->update(['plan_expired_at' => now()->subDay()]);

        $order = $orderService->createOrder(User::factory()->create(), $plan, 'month');
        $this->assertNotNull($order->id);
    }

    public function test_cannot_buy_a_dedicated_isp_plan_that_is_at_capacity(): void
    {
        $plan = Plan::create([
            'name' => '限量静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'capacity_limit' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder(User::factory()->create(), $plan, 'month'));

        $this->expectException(ValidationException::class);
        $orderService->createOrder(User::factory()->create(), $plan, 'month');
    }

    public function test_cannot_renew_when_the_plan_has_renewal_disabled(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '停止续费的套餐', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1500],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true, 'allow_renewal' => false,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));
        $instance = SubscriptionInstance::first();

        $this->expectException(ValidationException::class);
        $orderService->createRenewalOrder($user, $instance, 'month');
    }
}
