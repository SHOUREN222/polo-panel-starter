<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TrafficLog;
use App\Models\User;
use App\Support\NodeUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantNodeDeliveryTest extends TestCase
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

    private function sharedPlan(): Plan
    {
        return Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
    }

    private function node(): Server
    {
        return Server::create([
            'name' => '节点1', 'group_id' => 1, 'host' => '198.51.100.1', 'port' => 443,
            'rate' => 1, 'show' => true,
        ]);
    }

    public function test_node_user_list_spans_central_and_tenants_with_unique_ids(): void
    {
        $plan = $this->sharedPlan();
        $server = $this->node();

        $central = User::factory()->create(['plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth(), 'banned' => false]);

        $t = Tenant::create(['id' => 'shopn', 'node_ordinal' => 1]);
        tenancy()->initialize($t);
        $tenantUser = User::create(['name' => 't', 'email' => 't@example.com', 'password' => 'x', 'plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth()]);
        tenancy()->end();

        $ids = collect($this->withToken((string) $server->heartbeat_secret)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")
            ->assertOk()->json('users'))->pluck('id');

        $this->assertContains($central->id, $ids->all());                          // 主站: raw id
        $this->assertContains(NodeUserId::encode(1, $tenantUser->id), $ids->all()); // 分站: global id
        $this->assertSame($ids->count(), $ids->unique()->count());                 // no collisions
    }

    public function test_push_attributes_tenant_traffic_to_the_tenant_db_not_central(): void
    {
        $plan = $this->sharedPlan();
        $server = $this->node();

        $t = Tenant::create(['id' => 'shopn2', 'node_ordinal' => 1]);
        tenancy()->initialize($t);
        $tenantUser = User::create(['name' => 't', 'email' => 't@example.com', 'password' => 'x', 'plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth()]);
        tenancy()->end();

        $globalId = NodeUserId::encode(1, $tenantUser->id);
        $this->withToken((string) $server->heartbeat_secret)
            ->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
                (string) $globalId => [1000, 2000],
            ])->assertOk();

        // Traffic landed in the 分站's own DB…
        tenancy()->initialize($t);
        $log = TrafficLog::where('user_id', $tenantUser->id)->where('server_id', $server->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(1000, (int) $log->upload_bytes);
        $this->assertSame(2000, (int) $log->download_bytes);
        tenancy()->end();

        // …and NOT in central.
        $this->assertSame(0, TrafficLog::where('server_id', $server->id)->count());
    }
}
