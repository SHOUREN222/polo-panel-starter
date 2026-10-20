<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SubscriptionLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_plan_produces_a_valid_shadowsocks_link_per_server(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        Server::create([
            'name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5',
            'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm',
        ]);

        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        // markPaid() extends access via $order->user, a separately
        // lazy-loaded instance — this test's $user object never saw that
        // mutation in memory, only the DB row changed. Refresh before use.
        $user->refresh();

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user);

        $this->assertCount(1, $links);
        $this->assertStringStartsWith('ss://', $links[0]);
        $this->assertStringContainsString('@198.51.100.5:8443#', $links[0]);

        [$userInfo] = explode('@', substr($links[0], strlen('ss://')));
        $this->assertSame('aes-256-gcm:'.$user->uuid, base64_decode($userInfo));
    }

    public function test_dedicated_isp_instance_produces_a_link_only_once_provisioned(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        // QUEUE_CONNECTION=sync in testing, so provisioning runs inline.
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user);

        $this->assertCount(1, $links);
        $this->assertStringStartsWith('ss://', $links[0]);
    }

    public function test_pending_instance_without_meta_is_excluded_from_links(): void
    {
        $user = User::factory()->create();
        $user->subscriptionInstances()->create([
            'plan_id' => Plan::create([
                'name' => 'x', 'type' => Plan::TYPE_DEDICATED_ISP,
                'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
            ])->id,
            'transfer_enable' => 1,
            'status' => 'pending',
        ]);

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user);

        $this->assertCount(0, $links);
    }

    public function test_subscribe_endpoint_returns_base64_body_matching_the_links(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.9', 'port' => 443]);

        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $response = $this->get("/subscribe/{$user->token}");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=utf-8');

        $expected = app(SubscriptionLinkService::class)->buildSubscriptionBody($user->refresh());
        $this->assertSame($expected, $response->getContent());
        $this->assertStringStartsWith('ss://', base64_decode($response->getContent()));
    }

    public function test_shared_only_subscribe_endpoint_excludes_dedicated_isp_instances(): void
    {
        $user = User::factory()->create();

        $sharedPlan = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.9', 'port' => 443]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $sharedPlan, 'month'));
        $orderService->markPaid($orderService->createOrder($user, $ispPlan, 'month'));

        // Distinguish the ISP instance's link from the shared server's by
        // giving it a distinct host (StubProvisioner uses shadowsocks too,
        // so protocol alone can't tell them apart in this test).
        SubscriptionInstance::first()->update([
            'meta' => ['ip' => '203.0.113.200', 'port' => 8080, 'protocol' => 'shadowsocks', 'username' => 'u', 'method' => 'aes-256-gcm'],
        ]);

        // The full aggregate endpoint has both.
        $fullBody = base64_decode($this->get("/subscribe/{$user->token}")->getContent());
        $this->assertStringContainsString('198.51.100.9', $fullBody);
        $this->assertStringContainsString('203.0.113.200', $fullBody);

        // The shared-only endpoint has just the shared node.
        $response = $this->get("/subscribe/{$user->token}/shared");
        $response->assertOk();
        $sharedBody = base64_decode($response->getContent());
        $this->assertStringContainsString('198.51.100.9', $sharedBody);
        $this->assertStringNotContainsString('203.0.113.200', $sharedBody);
    }

    public function test_instance_only_subscribe_endpoint_returns_just_that_instances_link(): void
    {
        $user = User::factory()->create();

        $ispPlanA = Plan::create([
            'name' => '专属A', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $ispPlanB = Plan::create([
            'name' => '专属B', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $ispPlanA, 'month'));
        $orderService->markPaid($orderService->createOrder($user, $ispPlanB, 'month'));

        $instances = SubscriptionInstance::orderBy('id')->get();
        $instances[0]->update(['meta' => ['ip' => '203.0.113.10', 'port' => 8080, 'protocol' => 'shadowsocks', 'username' => 'u', 'method' => 'aes-256-gcm']]);
        $instances[1]->update(['meta' => ['ip' => '203.0.113.20', 'port' => 8081, 'protocol' => 'shadowsocks', 'username' => 'u', 'method' => 'aes-256-gcm']]);

        $response = $this->get("/subscribe/{$user->token}/instance/{$instances[0]->id}");

        $response->assertOk();
        $body = base64_decode($response->getContent());
        $this->assertStringContainsString('203.0.113.10', $body);
        $this->assertStringNotContainsString('203.0.113.20', $body);
    }

    public function test_instance_only_subscribe_endpoint_404s_for_another_users_instance(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($owner, $ispPlan, 'month'));
        $instance = SubscriptionInstance::first();

        $this->get("/subscribe/{$intruder->token}/instance/{$instance->id}")->assertNotFound();
    }

    public function test_instance_only_subscribe_endpoint_404s_for_expired_instance(): void
    {
        $user = User::factory()->create();
        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $ispPlan, 'month'));
        $instance = SubscriptionInstance::first();
        $instance->update(['expired_at' => now()->subDay()]);

        $this->get("/subscribe/{$user->token}/instance/{$instance->id}")->assertNotFound();
    }

    public function test_subscribe_endpoint_404s_for_unknown_token(): void
    {
        $this->get('/subscribe/does-not-exist')->assertNotFound();
    }

    public function test_subscribe_endpoint_403s_for_banned_user(): void
    {
        $user = User::factory()->create(['banned' => true]);

        $this->get("/subscribe/{$user->token}")->assertForbidden();
    }

    public function test_each_user_gets_a_unique_token(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->assertNotSame($a->token, $b->token);
    }

    public function test_subscribe_response_includes_userinfo_header_by_default(): void
    {
        $user = User::factory()->create();
        Plan::create(['name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1, 'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true]);
        Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.9', 'port' => 443]);

        $response = $this->get("/subscribe/{$user->token}");

        $response->assertHeader('Subscription-Userinfo');
        $this->assertStringContainsString('upload=', $response->headers->get('Subscription-Userinfo'));
    }

    public function test_userinfo_includes_shared_plan_usage_total_and_expiry(): void
    {
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $server = Server::create(['name' => 's', 'group_id' => 1, 'host' => '198.51.100.9', 'port' => 8443]);
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();

        $this->withToken((string) $server->heartbeat_secret)
            ->postJson("/api/v1/server/UniProxy/push?node_id={$server->id}&node_type=shadowsocks", [
                (string) $user->id => [100, 200],
            ])->assertOk();

        $info = app(SubscriptionLinkService::class)->buildUserinfo($user->fresh());

        $this->assertSame(300, $info['download']); // shared usage folds into download, see buildUserinfo()
        $this->assertSame(1024, $info['total']);
        $this->assertSame($user->plan_expired_at->timestamp, $info['expire']);
    }

    public function test_subscribe_response_omits_userinfo_header_when_disabled(): void
    {
        Setting::set('subscribe_show_userinfo', false);
        $user = User::factory()->create();

        $response = $this->get("/subscribe/{$user->token}");

        $this->assertFalse($response->headers->has('Subscription-Userinfo'));
    }

    public function test_shared_vless_server_produces_a_reality_link_with_the_right_query_params(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        Server::create([
            'name' => '备用节点-CA-1', 'group_id' => 1, 'host' => '203.0.113.20',
            'port' => 8444, 'protocol' => 'vless',
            'config' => [
                'public_key' => 'HnJbfJ8m63KmktR7x_i8aCmSX7F1pXnKma7pzwTY7jc',
                'short_id' => '55307c8fc36f29c0',
                'sni' => 'addons.mozilla.org',
                'flow' => 'xtls-rprx-vision',
            ],
        ]);

        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user);

        $this->assertCount(1, $links);
        $this->assertStringStartsWith("vless://{$user->uuid}@203.0.113.20:8444?", $links[0]);
        $this->assertStringContainsString('security=reality', $links[0]);
        $this->assertStringContainsString('pbk=HnJbfJ8m63KmktR7x_i8aCmSX7F1pXnKma7pzwTY7jc', $links[0]);
        $this->assertStringContainsString('sid=55307c8fc36f29c0', $links[0]);
        $this->assertStringContainsString('sni=addons.mozilla.org', $links[0]);
        $this->assertStringContainsString('flow=xtls-rprx-vision', $links[0]);
    }

    public function test_node_data_for_user_carries_vless_config_through_for_client_config_rendering(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        Server::create([
            'name' => '节点R', 'group_id' => 1, 'host' => '203.0.113.20',
            'port' => 8444, 'protocol' => 'vless',
            'config' => ['public_key' => 'pubkey', 'short_id' => 'abc123', 'sni' => 'addons.mozilla.org', 'flow' => 'xtls-rprx-vision'],
        ]);

        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();

        $nodes = app(SubscriptionLinkService::class)->buildNodeDataForUser($user);

        $this->assertCount(1, $nodes);
        $this->assertSame('vless', $nodes[0]['protocol']);
        $this->assertSame('pubkey', $nodes[0]['config']['public_key']);
        $this->assertSame('abc123', $nodes[0]['config']['short_id']);
    }

    public function test_onip_provisioned_instance_produces_a_socks5_link_with_credentials(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500], 'sell' => true,
        ]);
        $user->subscriptionInstances()->create([
            'plan_id' => $plan->id,
            'transfer_enable' => 1,
            'status' => 'active',
            'expired_at' => now()->addMonth(),
            'meta' => [
                'ip' => '192.168.1.100', 'port' => 8080, 'protocol' => 'socks5',
                'username' => 'user123', 'password' => 'pass123', 'proxy_id' => 'SI1', 'provider' => 'onip',
            ],
        ]);

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user);

        $this->assertCount(1, $links);
        $this->assertStringStartsWith('socks5://user123:pass123@192.168.1.100:8080#', $links[0]);
    }

    public function test_node_data_for_user_carries_socks5_username_as_credential_and_password_via_config(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500], 'sell' => true,
        ]);
        $user->subscriptionInstances()->create([
            'plan_id' => $plan->id,
            'transfer_enable' => 1,
            'status' => 'active',
            'expired_at' => now()->addMonth(),
            'meta' => [
                'ip' => '192.168.1.100', 'port' => 8080, 'protocol' => 'socks5',
                'username' => 'user123', 'password' => 'pass123',
            ],
        ]);

        $nodes = app(SubscriptionLinkService::class)->buildNodeDataForUser($user);

        $this->assertCount(1, $nodes);
        $this->assertSame('user123', $nodes[0]['credential']);
        $this->assertSame('pass123', $nodes[0]['config']['password']);
    }

    public function test_protocol_name_prefix_appears_in_link_when_enabled(): void
    {
        Setting::set('subscribe_show_protocol_name', true);
        $user = User::factory()->create();
        Plan::create(['name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1, 'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443, 'protocol' => 'shadowsocks']);

        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, Plan::first(), 'month'));

        $links = app(SubscriptionLinkService::class)->buildLinksForUser($user->refresh());

        $this->assertStringContainsString(rawurlencode('[SHADOWSOCKS]节点A'), $links[0]);
    }
}
