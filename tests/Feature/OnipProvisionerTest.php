<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ProvisionTask;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Provisioners\OnipProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnipProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function switchToOnipDriver(): void
    {
        Setting::set('provisioner_driver', 'onip');
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');
    }

    public function test_paying_for_a_dedicated_isp_plan_provisions_a_real_onip_instance_end_to_end(): void
    {
        $this->switchToOnipDriver();

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertSame('USA', $body['countryCode']);
            $this->assertSame(30, $body['days']);
            $this->assertTrue($body['sync']);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'orderNo' => 'ORD1',
                    'instances' => [[
                        'proxyId' => 'SI20250825212833004',
                        'ip' => '192.168.1.100',
                        'port' => 8080,
                        'username' => 'user123',
                        'password' => 'pass123',
                    ]],
                ],
            ]);
        });

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP｜美国', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => ['countryCode' => 'USA', 'businessType' => 'RESIDENTIAL', 'ispType' => 1],
            'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertSame('192.168.1.100', $instance->meta['ip']);
        $this->assertSame('socks5', $instance->meta['protocol']);
        $this->assertSame('user123', $instance->meta['username']);
        $this->assertSame('pass123', $instance->meta['password']);
        $this->assertSame('SI20250825212833004', $instance->meta['proxy_id']);
    }

    public function test_provisioning_fails_gracefully_when_vendor_rejects_the_order(): void
    {
        $this->switchToOnipDriver();

        Http::fake(fn () => Http::response([
            'success' => false, 'code' => 'ACCOUNT_BALANCE_INSUFFICIENT', 'message' => '余额不足', 'data' => null,
        ]));

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => ['countryCode' => 'USA'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $this->assertSame(SubscriptionInstance::STATUS_PENDING, $instance->status);
        $this->assertNull($instance->meta);
    }

    public function test_stub_driver_is_the_default_and_never_calls_the_real_api(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        Http::assertNothingSent();

        $instance = SubscriptionInstance::first();
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertSame('stub', $instance->meta['provider']);
    }

    /**
     * Regression: the external order number sent to onip is its idempotency
     * key. A bare "task-<id>" collides with any other install sharing the onip
     * account (dev/test), and onip then rejects the REAL order with
     * EXTERNAL_ORDER_EXISTS — a paying customer never gets provisioned.
     * It must be install-scoped, yet stable across retries of the same task.
     */
    public function test_the_onip_order_no_is_install_scoped_and_stable_across_retries(): void
    {
        $this->switchToOnipDriver();

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $body = json_decode($request->body(), true);
            if (isset($body['orderNo'])) {
                $seen[] = $body['orderNo'];
            }

            return Http::response(['success' => true, 'code' => 'SUCCESS', 'data' => [
                'orderNo' => 'ORD1',
                'instances' => [['proxyId' => 'SI1', 'ip' => '1.2.3.4', 'port' => 80, 'username' => 'u', 'password' => 'p']],
            ]]);
        });

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => ['countryCode' => 'USA'], 'sell' => true,
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => 'T-ORDERNO-1', 'total_amount' => 5500,
            'status' => Order::STATUS_PENDING,
        ]);
        $instance = SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'created_order_id' => $order->id,
            'transfer_enable' => 1, 'status' => SubscriptionInstance::STATUS_PENDING,
            'expired_at' => now()->addMonth(),
        ]);
        $task = ProvisionTask::create([
            'order_id' => $order->id,
            'subscription_instance_id' => $instance->id,
            'status' => 'pending',
            'payload' => ['resource_profile' => ['countryCode' => 'USA'], 'period' => 'month'],
        ]);

        $provisioner = app(OnipProvisioner::class);
        $provisioner->provision($task);
        $provisioner->provision($task); // a retry of the SAME task

        $prefix = Setting::get('onip_order_prefix');
        $this->assertNotEmpty($prefix, 'an install-scoped prefix should be generated once and stored');

        $this->assertCount(2, $seen);
        $this->assertSame($seen[0], $seen[1], 'a retry must reuse the same orderNo so onip does not sell a second line');
        $this->assertSame($prefix.'-task-'.$task->id, $seen[0]);
        $this->assertNotSame('task-'.$task->id, $seen[0], 'must not be the collision-prone bare task id');
    }

    public function test_renewing_before_expiry_extends_the_onip_lease_for_the_full_period(): void
    {
        // Regression: a 半年 renewal moved OUR expired_at out 6 months but
        // never told onip to extend ITS lease, so onip released/reassigned
        // the IP on its original monthly schedule — the customer paid for
        // half a year of an IP that onip only kept for one month.
        $this->switchToOnipDriver();

        $renewCalls = [];
        Http::fake(function ($request) use (&$renewCalls) {
            if (str_contains($request->url(), '/static/instances/renew')) {
                $renewCalls[] = json_decode($request->body(), true);

                return Http::response(['success' => true, 'code' => 'SUCCESS', 'data' => []]);
            }

            // create-order
            return Http::response(['success' => true, 'code' => 'SUCCESS', 'data' => [
                'orderNo' => 'ORD1',
                'instances' => [['proxyId' => 'SI-RENEW-1', 'ip' => '1.2.3.4', 'port' => 80, 'username' => 'u', 'password' => 'p']],
            ]]);
        });

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500, 'half_year' => 30000],
            'resource_profile' => ['countryCode' => 'USA'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $this->assertSame('SI-RENEW-1', $instance->meta['proxy_id']);

        // Renew for half a year, well before the one-month term lapses.
        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'half_year');
        $orderService->markPaid($renewOrder);

        $this->assertCount(1, $renewCalls, 'the onip renew endpoint must be hit exactly once');
        $this->assertSame(['SI-RENEW-1'], $renewCalls[0]['proxyIds']);
        $this->assertSame(180, $renewCalls[0]['days'], 'half_year must extend the onip lease by 180 days');
    }

    public function test_a_failed_onip_renewal_rolls_back_the_whole_payment(): void
    {
        // If onip can't actually extend the lease, the customer must not be
        // charged for time they won't get — the payment transaction rolls
        // back rather than silently moving only our own expired_at.
        $this->switchToOnipDriver();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/static/instances/renew')) {
                return Http::response(['success' => false, 'code' => 'ACCOUNT_BALANCE_INSUFFICIENT', 'message' => '余额不足', 'data' => null]);
            }

            return Http::response(['success' => true, 'code' => 'SUCCESS', 'data' => [
                'orderNo' => 'ORD1',
                'instances' => [['proxyId' => 'SI-RENEW-2', 'ip' => '1.2.3.4', 'port' => 80, 'username' => 'u', 'password' => 'p']],
            ]]);
        });

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500, 'half_year' => 30000],
            'resource_profile' => ['countryCode' => 'USA'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $plan, 'month'));

        $instance = SubscriptionInstance::first();
        $originalExpiry = $instance->expired_at;

        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'half_year');

        try {
            $orderService->markPaid($renewOrder);
            $this->fail('a failed onip renewal should have thrown');
        } catch (\Throwable) {
            // expected
        }

        // Neither the order nor the instance term may have moved.
        $this->assertSame(Order::STATUS_PENDING, $renewOrder->fresh()->status);
        $this->assertEquals($originalExpiry->timestamp, $instance->fresh()->expired_at->timestamp);
    }
}
