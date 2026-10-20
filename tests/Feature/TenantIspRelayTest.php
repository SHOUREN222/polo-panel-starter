<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\NodeRelayService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIspRelayTest extends TestCase
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

    private function ispPlan(): Plan
    {
        return Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'onip'], 'sell' => true,
        ]);
    }

    private function onipInstance(int $userId, int $planId): SubscriptionInstance
    {
        return SubscriptionInstance::create([
            'user_id' => $userId, 'plan_id' => $planId, 'status' => SubscriptionInstance::STATUS_ACTIVE,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'meta' => ['provider' => 'onip', 'ip' => '203.0.113.5', 'port' => 7000, 'username' => 'u', 'password' => 'p'],
        ]);
    }

    public function test_relay_config_aggregates_onip_lines_across_central_and_tenants(): void
    {
        $plan = $this->ispPlan();

        $cu = User::factory()->create();
        $central = $this->onipInstance($cu->id, $plan->id);

        $t = Tenant::create(['id' => 'ispshop', 'node_ordinal' => 1]);
        tenancy()->initialize($t);
        $tu = User::create(['name' => 't', 'email' => 't@example.com', 'password' => 'x']);
        $tenantInst = $this->onipInstance($tu->id, $plan->id);
        $tenantUuid = $tenantInst->uuid;
        tenancy()->end();

        $svc = app(NodeRelayService::class);
        $config = $svc->generateConfig($svc->allActiveOnipInstances());
        $clientIds = collect($config['clients'])->pluck('id')->all();

        $this->assertContains($central->uuid, $clientIds);   // 主站 ISP 线路
        $this->assertContains($tenantUuid, $clientIds);      // 分站 ISP 线路
    }

    public function test_isp_relay_traffic_reports_into_the_tenant_db(): void
    {
        $plan = $this->ispPlan();

        $t = Tenant::create(['id' => 'ispshop2', 'node_ordinal' => 1]);
        tenancy()->initialize($t);
        $tu = User::create(['name' => 't', 'email' => 't@example.com', 'password' => 'x']);
        $inst = $this->onipInstance($tu->id, $plan->id);
        $token = $inst->token;
        $instId = $inst->id;
        tenancy()->end();

        $this->postJson('/api/traffic/report', [
            'type' => 'instance', 'secret' => $token, 'upload' => 500, 'download' => 1500,
        ])->assertOk();

        // Usage accrued on the tenant's instance + a TrafficLog in the tenant DB.
        tenancy()->initialize($t);
        $inst = SubscriptionInstance::find($instId);
        $this->assertSame(500, (int) $inst->u);
        $this->assertSame(1500, (int) $inst->d);
        $this->assertNotNull(TrafficLog::where('subscription_instance_id', $instId)->first());
        tenancy()->end();

        // Central didn't record it.
        $this->assertSame(0, TrafficLog::count());
    }

    public function test_renewing_a_still_active_onip_instance_re_syncs_the_relay(): void
    {
        // Renewing before expiry only bumps expired_at/transfer_enable — it
        // never goes through a ProvisionTask (same IP, nothing to
        // provision), so nothing else would re-push the relay config. Had
        // this instance fallen out of the last pushed config (e.g. it briefly
        // lapsed before this renewal landed), a missing sync here would leave
        // it unreachable despite the renewal succeeding.
        $plan = $this->ispPlan();
        $user = User::factory()->create();
        $instance = $this->onipInstance($user->id, $plan->id);
        $instance->update(['expired_at' => now()->addDays(10)]);

        $this->mock(NodeRelayService::class, function ($mock) {
            $mock->shouldReceive('sync')->once()->andReturn(['pushed' => true, 'instances' => 1, 'message' => 'ok']);
        });

        $orderService = app(OrderService::class);
        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $orderService->markPaid($renewOrder);

        $instance->refresh();
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
    }
}
