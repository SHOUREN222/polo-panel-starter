<?php

namespace Tests\Feature;

use App\Models\AppClient;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_subscription_card_when_user_has_nothing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSeeText('一键订阅');
        $response->assertDontSeeText('打开链接');
        $response->assertSeeText('还没有任何订阅');
    }

    public function test_one_aggregate_subscription_card_for_a_shared_only_user_points_at_the_aggregate_link(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/');

        $response->assertOk();
        // One consolidated 一键订阅 card, no per-line import, no separate 聚合订阅 card.
        $response->assertSeeText('一键订阅');
        $response->assertDontSeeText('聚合订阅');
        $response->assertDontSeeText('复制聚合订阅');
        // The link is the aggregate token (covers everything), not the /shared variant.
        $response->assertSee($user->token, false);
        $response->assertDontSee($user->token.'\\/shared', false);
    }

    public function test_dashboard_offers_raw_node_links_as_a_fallback_for_failed_subscription_imports(): void
    {
        // Customers whose client can't fetch the subscription URL can paste
        // the raw node links instead — the import-failure workaround.
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm']);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/');

        $response->assertOk();
        // The "复制节点链接" label itself is x-text (JS-rendered), so assert on
        // the static hint span + the embedded raw link (its host) instead.
        $response->assertSeeText('导入订阅失败时用');
        $response->assertSee('198.51.100.5', false);
    }

    public function test_one_aggregate_subscription_card_for_a_dedicated_only_user_points_at_the_aggregate_link(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();
        $instance = $user->subscriptionInstances()->first();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSeeText('一键订阅');
        $response->assertSee($user->token, false);
        $response->assertDontSee($user->token.'\\/instance\\/'.$instance->id, false);
    }

    public function test_a_shared_plus_dedicated_user_still_gets_exactly_one_aggregate_card(): void
    {
        $user = User::factory()->create();
        $sharedPlan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $sharedPlan, 'month'));
        $orderService->markPaid($orderService->createOrder($user, $ispPlan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/');

        $response->assertOk();
        $response->assertSeeText('一键订阅');
        $response->assertDontSeeText('聚合订阅');
        // Exactly one aggregate import card on the page.
        $this->assertSame(1, substr_count($response->getContent(), 'oneClickOpen = true'));
        $response->assertSee($user->token, false);
    }

    public function test_expired_only_dedicated_line_still_shows_its_card_but_no_import_button(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $user->refresh();
        $user->subscriptionInstances()->first()->update(['expired_at' => now()->subDay()]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // Card is still visible (so the customer can renew) …
        $response->assertSeeText('专属');
        $response->assertSeeText('已过期');
        // … but there's nothing importable, so no aggregate import button.
        $response->assertDontSeeText('一键订阅');
        $response->assertDontSeeText('打开链接');
    }

    public function test_client_download_shortcut_hidden_when_no_platform_configured(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSeeText('客户端下载');
    }

    public function test_client_download_shortcut_lists_only_configured_platforms_including_ios(): void
    {
        AppClient::create(['platform' => 'ios', 'name' => 'Shadowrocket', 'url' => 'https://apps.apple.com/app/id932747118']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSeeText('客户端下载');
        $response->assertSeeText('iOS');
        $response->assertSeeText('Shadowrocket');
        $response->assertDontSeeText('Windows');
        $response->assertDontSeeText('Android');
    }

    public function test_client_download_shortcut_lists_multiple_clients_under_the_same_platform(): void
    {
        AppClient::create(['platform' => 'ios', 'name' => 'Shadowrocket', 'url' => 'https://apps.apple.com/app/id932747118']);
        AppClient::create(['platform' => 'ios', 'name' => 'Stash', 'url' => 'https://apps.apple.com/app/id1596063349']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSeeText('Shadowrocket');
        $response->assertSeeText('Stash');
    }

    /**
     * The header count must match the number of subscription cards below it.
     * It used to add up shared NODES (which are "多人共享", not independent
     * lines, and all belong to ONE plan) — so a customer with 1 shared plan on
     * 3 nodes + 1 ISP line was told they had "4 个独立线路" while seeing 2 cards.
     */
    public function test_header_counts_subscriptions_not_shared_nodes(): void
    {
        $user = User::factory()->create();
        $sharedPlan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        // One shared plan delivered over THREE nodes — still one subscription.
        foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12'] as $host) {
            Server::create(['name' => 'node-'.$host, 'group_id' => 1, 'host' => $host, 'port' => 8443]);
        }
        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($user, $sharedPlan, 'month'));
        $orderService->markPaid($orderService->createOrder($user, $ispPlan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/');

        $response->assertOk();
        // 1 shared plan + 1 ISP line = 2 subscriptions (NOT 3 nodes + 1 = 4).
        $response->assertSeeText('当前账号共 2 个订阅');
        $response->assertDontSeeText('4 个独立线路');
    }
}
