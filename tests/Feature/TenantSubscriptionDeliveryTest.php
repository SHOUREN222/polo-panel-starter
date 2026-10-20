<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SharedNodeService;
use App\Services\WholesaleService;
use App\Support\NodeUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end proof that a 分站 customer is actually *served by the shared node*,
 * not just recorded in a DB. Ties the whole delivery chain together the way a
 * real V2bX node exercises it:
 *
 *   real purchase (markPaid) → the customer's own subscription link renders a
 *   working node URI with THEIR uuid → the node's /user list references the
 *   SAME uuid under the tenant's global id → traffic the node reports decrements
 *   THAT 分站's usage, never central's or another 分站's.
 *
 * The subscription link (what the customer pastes into their client) and the
 * node's user-list (what the node authorizes connections against) must name the
 * same uuid, or the customer pays and can't connect. Nothing else tested this.
 */
class TenantSubscriptionDeliveryTest extends TestCase
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
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);
    }

    private function sharedNode(): Server
    {
        // shadowsocks renders the simplest verifiable URI: ss://<b64(method:uuid)>@host:port#name
        return Server::create([
            'name' => '共享节点', 'group_id' => 1, 'host' => '198.51.100.5',
            'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm',
            'rate' => 1, 'show' => true,
        ]);
    }

    public function test_a_tenant_customer_buys_and_gets_a_working_link_the_node_agrees_with(): void
    {
        Notification::fake();

        $plan = $this->sharedPlan();
        $server = $this->sharedNode();

        $t = Tenant::create(['id' => 'shop', 'node_ordinal' => 1]);
        $t->domains()->create(['domain' => 'shop']);
        app(WholesaleService::class)->credit('shop', 100000); // pre-fund 进货余额

        // Real purchase → fulfill, inside the 分站 DB.
        tenancy()->initialize($t);
        $customer = User::create(['name' => '分站客户', 'email' => 'buyer@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($customer, $plan, 'month');
        app(OrderService::class)->markPaid($order);
        $customer->refresh();
        $uuid = $customer->uuid;
        $token = $customer->token;
        $localId = $customer->id;
        $this->assertTrue($customer->hasActiveSharedPlan()); // provisioned
        tenancy()->end();

        // (a) The link the customer pastes: fetched on the 分站 subdomain, it
        //     renders the shared node's real address carrying the customer's uuid.
        $body = $this->get("http://shop.example.test/subscribe/{$token}")->assertOk()->getContent();
        $decoded = base64_decode($body);
        $this->assertStringContainsString('@198.51.100.5:8443#', $decoded); // real node endpoint
        $ssLine = trim(explode("\n", trim($decoded))[0]);
        $this->assertStringStartsWith('ss://', $ssLine);
        [$userInfo] = explode('@', substr($ssLine, strlen('ss://')));
        $this->assertSame('aes-256-gcm:'.$uuid, base64_decode($userInfo)); // link names THIS customer

        // (b) The node's /user list: the same uuid appears, under the 分站 global id.
        $users = collect($this->withToken((string) $server->heartbeat_secret)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")
            ->assertOk()->json('users'));
        $entry = $users->firstWhere('id', NodeUserId::encode(1, $localId));
        $this->assertNotNull($entry, '分站客户应出现在节点用户表里');
        $this->assertSame($uuid, $entry['uuid']); // link ⇄ node agree → connection authorizes

        // (c) Traffic the node reports for that global id decrements THIS 分站's usage.
        $this->withToken((string) $server->heartbeat_secret)
            ->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
                (string) NodeUserId::encode(1, $localId) => [1024, 4096],
            ])->assertOk();

        tenancy()->initialize($t);
        $used = app(SharedNodeService::class)->sharedTrafficUsedBytes($customer->fresh());
        $this->assertSame(1024 + 4096, $used); // rate 1
        tenancy()->end();

        // Central 主站 saw none of the tenant's traffic.
        $this->assertSame(0, TrafficLog::where('server_id', $server->id)->count());
    }

    public function test_two_tenants_with_colliding_local_ids_stay_isolated_on_the_shared_node(): void
    {
        $plan = $this->sharedPlan();
        $server = $this->sharedNode();

        // A central customer is user #1 in central …
        $central = User::factory()->create([
            'plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth(), 'banned' => false,
        ]);

        // … and each 分站's first customer is ALSO local id #1 — the exact
        // collision the global-id scheme exists to defuse.
        $a = Tenant::create(['id' => 'shopA', 'node_ordinal' => 1]);
        $b = Tenant::create(['id' => 'shopB', 'node_ordinal' => 2]);

        $la = $a->run(fn () => User::create([
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'x',
            'plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth(),
        ])->id);
        $lb = $b->run(fn () => User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'x',
            'plan_id' => $plan->id, 'plan_expired_at' => now()->addMonth(),
        ])->id);

        $this->assertSame(1, $central->id);
        $this->assertSame(1, $la);
        $this->assertSame(1, $lb); // all three local ids collide on 1

        // The node sees three DISTINCT users despite the collision.
        $ids = collect($this->withToken((string) $server->heartbeat_secret)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")
            ->assertOk()->json('users'))->pluck('id');
        $this->assertContains($central->id, $ids->all());          // central: raw 1
        $this->assertContains(NodeUserId::encode(1, $la), $ids->all()); // shopA: 1e9+1
        $this->assertContains(NodeUserId::encode(2, $lb), $ids->all()); // shopB: 2e9+1
        $this->assertSame($ids->count(), $ids->unique()->count());

        // Traffic reported for shopA's global id lands in shopA only — not
        // central, not shopB, even though all three are "user 1".
        $this->withToken((string) $server->heartbeat_secret)
            ->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
                (string) NodeUserId::encode(1, $la) => [10, 20],
            ])->assertOk();

        $this->assertSame(30, $a->run(fn () => TrafficLog::where('user_id', $la)->sum('upload_bytes')
            + TrafficLog::where('user_id', $la)->sum('download_bytes')));
        $this->assertSame(0, $b->run(fn () => TrafficLog::where('server_id', $server->id)->count()));
        $this->assertSame(0, TrafficLog::where('server_id', $server->id)->count()); // central clean
    }
}
