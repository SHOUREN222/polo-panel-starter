<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantCommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    public function test_a_tenant_customer_buys_a_shared_plan_and_is_provisioned_in_the_tenant_db(): void
    {
        Notification::fake();

        // Shared catalog lives in central.
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);

        $t = Tenant::create(['id' => 'shop']);
        $t->domains()->create(['domain' => 'shop']);
        app(\App\Services\WholesaleService::class)->credit('shop', 100000); // pre-fund 进货余额

        tenancy()->initialize($t);
        $user = User::create(['name' => '分站客户', 'email' => 'buyer@example.com', 'password' => 'x']);

        // Full purchase → fulfill, all in the tenant DB.
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->markPaid($order);

        $user->refresh();
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertTrue($user->hasActiveSharedPlan());        // provisioned
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertSame(1, Order::count());                    // order in tenant DB
        tenancy()->end();

        // Central is untouched by the tenant's purchase.
        $this->assertSame(0, Order::count());
    }

    public function test_a_tenant_customer_buys_a_fixed_isp_line_and_gets_a_subscription_instance(): void
    {
        Notification::fake();

        $plan = Plan::create([
            'name' => '固定ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true, // stub provisioner: no real onip spend
        ]);

        $t = Tenant::create(['id' => 'shop2']);
        $t->domains()->create(['domain' => 'shop2']);
        app(\App\Services\WholesaleService::class)->credit('shop2', 100000); // pre-fund 进货余额

        tenancy()->initialize($t);
        $user = User::create(['name' => 'ISP客户', 'email' => 'isp@example.com', 'password' => 'x']);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->markPaid($order);

        // A subscription instance was created for this tenant customer, in the tenant DB.
        $this->assertSame(1, $user->subscriptionInstances()->count());
        tenancy()->end();
    }
}
