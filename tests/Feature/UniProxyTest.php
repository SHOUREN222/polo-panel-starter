<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\RoutingRule;
use App\Models\Server;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\OrderService;
use App\Support\NodeUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniProxyTest extends TestCase
{
    use RefreshDatabase;

    private function asNode(Server|string $node): static
    {
        $token = $node instanceof Server ? (string) $node->heartbeat_secret : $node;

        return $this->withToken($token);
    }

    private function sharedGroupUser(int $groupId = 1): User
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => $groupId,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return $user->refresh();
    }

    public function test_config_endpoint_returns_shadowsocks_node_info(): void
    {
        $server = Server::create([
            'name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5',
            'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm',
        ]);

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/config?node_id={$server->id}&node_type=shadowsocks");

        $response->assertOk();
        $response->assertJson([
            'host' => '198.51.100.5',
            'server_port' => 8443,
            'cipher' => 'aes-256-gcm',
        ]);
        $this->assertTrue($server->fresh()->isOnline());
    }

    public function test_config_endpoint_includes_enabled_routing_rules(): void
    {
        $server = Server::create([
            'name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5',
            'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm',
        ]);
        RoutingRule::create(['name' => '封油管', 'pattern' => '*.youtube.com', 'action' => 'block', 'scope' => 'both', 'enabled' => true, 'sort' => 1]);
        RoutingRule::create(['name' => '内网直连', 'pattern' => '10.0.0.0/8', 'action' => 'direct', 'scope' => 'shared', 'enabled' => true, 'sort' => 2]);
        RoutingRule::create(['name' => '停用的', 'pattern' => '*.disabled.com', 'action' => 'block', 'scope' => 'both', 'enabled' => false, 'sort' => 3]);
        RoutingRule::create(['name' => '仅ISP', 'pattern' => '*.isp-only.com', 'action' => 'block', 'scope' => 'isp', 'enabled' => true, 'sort' => 4]);

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/config?node_id={$server->id}&node_type=shadowsocks");

        $response->assertOk();
        $routes = $response->json('routes');
        $this->assertCount(2, $routes); // 停用的 + 仅ISP-scoped excluded
        $this->assertSame(['match' => ['domain:youtube.com'], 'action' => 'block'], collect($routes[0])->only(['match', 'action'])->all());
        $this->assertSame(['match' => ['10.0.0.0/8'], 'action' => 'direct'], collect($routes[1])->only(['match', 'action'])->all());
    }

    public function test_config_endpoint_returns_vless_reality_node_info_including_private_key(): void
    {
        $server = Server::create([
            'name' => '节点R', 'group_id' => 1, 'host' => '203.0.113.20',
            'port' => 8444, 'protocol' => 'vless', 'method' => 'none',
            'config' => [
                'public_key' => 'pubkey', 'private_key' => 'privkey',
                'short_id' => 'abc123', 'sni' => 'addons.mozilla.org', 'flow' => 'xtls-rprx-vision',
            ],
        ]);

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/config?node_id={$server->id}&node_type=vless");

        $response->assertOk();
        $json = $response->json();
        $this->assertSame(2, $json['tls']);
        $this->assertSame('privkey', $json['tls_settings']['private_key']);
        $this->assertSame('pubkey', $json['tls_settings']['public_key']);
        $this->assertSame('abc123', $json['tls_settings']['short_id']);
        $this->assertSame('addons.mozilla.org', $json['tls_settings']['server_name']);
        // Matched to what V2bX accepts (see UniProxyController): the field is
        // `decryption` (null), NOT `encryption` (which crashes xray); and
        // `flow` must be xtls-rprx-vision so the server account matches the
        // client subscription's flow (otherwise xray rejects the connection).
        $this->assertSame('xtls-rprx-vision', $json['flow']);
        $this->assertNull($json['decryption']);
        $this->assertArrayNotHasKey('encryption', $json);
        $this->assertFalse($json['tls_settings']['allow_insecure']);
    }

    public function test_config_endpoint_rejects_wrong_token(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        $this->asNode('wrong')->getJson("/api/v1/server/UniProxy/config?node_id={$server->id}&node_type=shadowsocks")
            ->assertStatus(403);
    }

    public function test_query_token_is_rejected_by_default(): void
    {
        config(['security.allow_legacy_node_query_token' => false]);
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        $this->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&token={$server->heartbeat_secret}")
            ->assertForbidden();
    }

    public function test_query_token_can_be_enabled_only_during_node_migration(): void
    {
        config(['security.allow_legacy_node_query_token' => true]);
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        $this->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&token={$server->heartbeat_secret}")
            ->assertOk();
    }

    public function test_config_endpoint_rejects_unsupported_protocol(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 443, 'protocol' => 'vmess']);

        $this->asNode($server)->getJson("/api/v1/server/UniProxy/config?node_id={$server->id}&node_type=vmess")
            ->assertStatus(400);
    }

    public function test_user_endpoint_returns_only_eligible_users_for_the_servers_group(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $eligible = $this->sharedGroupUser(1);
        $wrongGroup = $this->sharedGroupUser(2);
        $banned = $this->sharedGroupUser(1);
        $banned->update(['banned' => true]);

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&node_type=shadowsocks");

        $response->assertOk();
        $uuids = collect($response->json('users'))->pluck('uuid');
        $this->assertTrue($uuids->contains($eligible->uuid));
        $this->assertFalse($uuids->contains($wrongGroup->uuid));
        $this->assertFalse($uuids->contains($banned->uuid));
    }

    public function test_user_endpoint_excludes_users_with_expired_plan(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $user = $this->sharedGroupUser(1);
        $user->update(['plan_expired_at' => now()->subDay()]);

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&node_type=shadowsocks");

        $uuids = collect($response->json('users'))->pluck('uuid');
        $this->assertFalse($uuids->contains($user->uuid));
    }

    public function test_user_endpoint_reports_the_plans_real_speed_limit(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $plan = Plan::create([
            'name' => '限速套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024 * 100, 'speed_limit' => 50, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&node_type=shadowsocks");

        $entry = collect($response->json('users'))->firstWhere('uuid', $user->uuid);
        $this->assertSame(50, $entry['speed_limit']);
    }

    public function test_user_endpoint_excludes_users_who_exceeded_their_shared_quota(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $user = $this->sharedGroupUser(1); // transfer_enable = 1 byte in the helper's plan

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", [
            (string) $user->id => [100, 100],
        ])->assertOk();

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&node_type=shadowsocks");

        $uuids = collect($response->json('users'))->pluck('uuid');
        $this->assertFalse($uuids->contains($user->uuid));
    }

    public function test_user_endpoint_still_includes_users_under_their_shared_quota(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $plan = Plan::create([
            'name' => '大流量共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 10 * 1024 * 1024 * 1024, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", [
            (string) $user->id => [1000, 1000],
        ])->assertOk();

        $response = $this->asNode($server)->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}&node_type=shadowsocks");

        $uuids = collect($response->json('users'))->pluck('uuid');
        $this->assertTrue($uuids->contains($user->uuid));
    }

    public function test_push_endpoint_accumulates_traffic_into_traffic_log(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443, 'rate' => 2]);
        $user = $this->sharedGroupUser(1);

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", [
            (string) $user->id => [1000, 2000],
        ])->assertOk();

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", [
            (string) $user->id => [500, 300],
        ])->assertOk();

        $log = TrafficLog::where('user_id', $user->id)->where('server_id', $server->id)->first();
        $this->assertSame(1500, $log->upload_bytes);
        $this->assertSame(2300, $log->download_bytes);
        $this->assertSame('2.00', $log->rate);
    }

    public function test_push_endpoint_records_multiple_users_in_one_batch(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $first = $this->sharedGroupUser();
        $second = $this->sharedGroupUser();

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
            (string) $first->id => [100, 200],
            (string) $second->id => [300, 400],
        ])->assertOk();

        $this->assertDatabaseHas('traffic_logs', [
            'user_id' => $first->id, 'upload_bytes' => 100, 'download_bytes' => 200,
        ]);
        $this->assertDatabaseHas('traffic_logs', [
            'user_id' => $second->id, 'upload_bytes' => 300, 'download_bytes' => 400,
        ]);
        $this->assertDatabaseCount('traffic_logs', 2);
    }

    public function test_push_endpoint_does_not_write_zero_traffic(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $user = $this->sharedGroupUser();

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
            (string) $user->id => [0, 0],
        ])->assertOk();

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_push_endpoint_rejects_wrong_token(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        $this->asNode('wrong')->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", ['1' => [10, 10]])
            ->assertStatus(403);
    }

    public function test_push_rejects_the_entire_batch_when_any_entry_is_invalid(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $user = $this->sharedGroupUser();

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
            (string) $user->id => [100, 200],
            '2' => [-1, 10],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_push_rejects_non_integer_and_oversized_traffic_values(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        foreach ([['10', 20], [10.5, 20], [1_099_511_627_777, 20], ['upload' => 10, 'download' => 20]] as $traffic) {
            $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
                '1' => $traffic,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_push_rejects_invalid_global_user_ids(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        foreach (['0', (string) NodeUserId::OFFSET] as $globalId) {
            $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
                $globalId => [10, 20],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_push_limits_the_number_of_users_in_one_report(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $payload = [];
        for ($id = 1; $id <= 10_001; $id++) {
            $payload[(string) $id] = [0, 0];
        }

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_push_rejects_counter_overflow_before_writing(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $user = $this->sharedGroupUser();
        TrafficLog::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'subscription_instance_id' => null,
            'date' => now()->toDateString(),
            'upload_bytes' => PHP_INT_MAX,
            'download_bytes' => 0,
            'rate' => 1,
        ]);

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}", [
            (string) $user->id => [1, 1],
        ])->assertUnprocessable();

        $log = TrafficLog::first();
        $this->assertSame(PHP_INT_MAX, $log->upload_bytes);
        $this->assertSame(0, $log->download_bytes);
    }

    public function test_alivelist_and_alive_endpoints_respond_without_enforcing_anything(): void
    {
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);

        $this->asNode($server)->getJson("/api/v1/server/UniProxy/alivelist?node_id={$server->id}&node_type=shadowsocks")
            ->assertOk()
            ->assertJson(['alive' => []]);

        $this->asNode($server)->postJson("/api/v1/server/UniProxy/alive?node_id={$server->id}&node_type=shadowsocks", [
            '1' => ['203.0.113.5'],
        ])->assertOk();
    }
}
