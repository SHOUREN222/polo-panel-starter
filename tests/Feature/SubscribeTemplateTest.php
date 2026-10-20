<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscribeTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function userWithSharedNode(): User
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443, 'protocol' => 'shadowsocks', 'method' => 'aes-256-gcm']);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return $user->refresh();
    }

    private function userWithVlessRealityNode(): User
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create([
            'name' => '节点R', 'group_id' => 1, 'host' => '203.0.113.20', 'port' => 8444, 'protocol' => 'vless',
            'config' => ['public_key' => 'pubkey', 'short_id' => 'abc123', 'sni' => 'addons.mozilla.org', 'flow' => 'xtls-rprx-vision'],
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return $user->refresh();
    }

    public function test_clash_user_agent_gets_rendered_clash_template(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_CLASH,
            'content' => "proxies:\n{{proxies}}\n",
        ]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('type: ss');
        $response->assertSeeText('节点A');
    }

    public function test_clash_meta_user_agent_takes_priority_over_plain_clash(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_CLASH, 'content' => 'PLAIN-CLASH-MARKER {{proxies}}']);
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_CLASH_META, 'content' => 'META-MARKER {{proxies}}']);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'clash-verge/1.0']);

        $response->assertSeeText('META-MARKER');
        $response->assertDontSeeText('PLAIN-CLASH-MARKER');
    }

    public function test_singbox_gets_valid_json_from_the_default_template_when_none_is_saved(): void
    {
        // No SubscribeTemplate row for sing-box → must fall back to the built-in
        // default and return valid JSON, NOT the plain base64 link list (which
        // sing-box can't consume). This is the production bug this guards.
        $this->assertSame(0, SubscribeTemplate::count());
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'sing-box/1.9.0']);

        $response->assertOk();
        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json, 'sing-box 应拿到合法 JSON 配置，而非原始链接');
        $this->assertArrayHasKey('outbounds', $json);
        $hasRealNode = collect($json['outbounds'])
            ->contains(fn ($o) => ($o['type'] ?? '') === 'shadowsocks' && ($o['server'] ?? '') === '198.51.100.5');
        $this->assertTrue($hasRealNode, '默认 sing-box 模板里 {{proxies}} 应被真实节点替换');
    }

    public function test_clash_gets_the_default_yaml_template_when_none_is_saved(): void
    {
        $this->assertSame(0, SubscribeTemplate::count());
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('proxies:');
        $response->assertSeeText('节点A');
    }

    public function test_singbox_user_agent_gets_json_outbounds(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_SINGBOX, 'content' => '{"outbounds": [{{proxies}}]}']);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'sing-box/1.8.0']);

        $response->assertOk();
        $json = json_decode($response->getContent(), true);
        $this->assertSame('shadowsocks', $json['outbounds'][0]['type']);
        $this->assertSame('198.51.100.5', $json['outbounds'][0]['server']);
    }

    public function test_surge_user_agent_gets_ini_style_proxy_line(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_SURGE, 'content' => "[Proxy]\n{{proxies}}"]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'Surge iOS/1789']);

        $response->assertSeeText('encrypt-method=aes-256-gcm');
    }

    public function test_unrecognized_client_falls_back_to_plain_link_list(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_CLASH, 'content' => 'proxies: {{proxies}}']);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'curl/8.0']);

        $this->assertStringStartsWith('ss://', base64_decode($response->getContent()));
    }

    public function test_unknown_client_user_agent_falls_back_to_plain_link_list(): void
    {
        $user = $this->userWithSharedNode();

        // A UA matching none of the 6 templated clients (v2rayN, Shadowrocket,
        // Streisand, …) has no template to render, so it gets the plain base64
        // link list — which those clients import natively.
        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'v2rayN/6.0']);

        $this->assertStringStartsWith('ss://', base64_decode($response->getContent()));
    }

    public function test_subs_link_and_subscribe_info_tokens_are_substituted(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_SURGE, 'content' => "link={{subs_link}}\ninfo={{subscribe_info}}"]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'Surge iOS/1789']);

        $response->assertSeeText("link={$user->subscriptionUrl()}", false);
        $response->assertSeeText('info=upload=');
    }

    public function test_clash_template_renders_vless_reality_proxy_with_correct_fields(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_CLASH, 'content' => "proxies:\n{{proxies}}\n"]);
        $user = $this->userWithVlessRealityNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('type: vless');
        $response->assertSeeText('reality-opts');
        $response->assertSeeText('pubkey');
        $response->assertSeeText('abc123');
    }

    public function test_singbox_template_renders_vless_reality_outbound_with_correct_fields(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_SINGBOX, 'content' => '{"outbounds": [{{proxies}}]}']);
        $user = $this->userWithVlessRealityNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'sing-box/1.8.0']);

        $response->assertOk();
        $json = json_decode($response->getContent(), true);
        $outbound = $json['outbounds'][0];
        $this->assertSame('vless', $outbound['type']);
        $this->assertSame('203.0.113.20', $outbound['server']);
        $this->assertSame($user->uuid, $outbound['uuid']);
        $this->assertTrue($outbound['tls']['reality']['enabled']);
        $this->assertSame('pubkey', $outbound['tls']['reality']['public_key']);
        $this->assertSame('abc123', $outbound['tls']['reality']['short_id']);
    }

    public function test_surge_template_renders_vless_reality_proxy_line_with_correct_fields(): void
    {
        SubscribeTemplate::create(['client' => SubscribeTemplate::CLIENT_SURGE, 'content' => "[Proxy]\n{{proxies}}"]);
        $user = $this->userWithVlessRealityNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'Surge iOS/1789']);

        $response->assertSeeText('= vless');
        $response->assertSeeText('reality-public-key=pubkey');
        $response->assertSeeText('reality-short-id=abc123');
    }

    public function test_shared_only_endpoint_renders_clash_template_for_clash_user_agent(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_CLASH,
            'content' => "proxies:\n{{proxies}}\n",
        ]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}/shared", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('type: ss');
        $response->assertSeeText('节点A');
    }

    public function test_shared_only_endpoint_excludes_dedicated_isp_nodes_from_yaml(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_CLASH,
            'content' => "proxies:\n{{proxies}}\n",
        ]);
        $user = $this->userWithSharedNode();

        $ispPlan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $ispPlan, 'month'));
        \App\Models\SubscriptionInstance::first()->update([
            'meta' => ['ip' => '203.0.113.200', 'port' => 8080, 'protocol' => 'shadowsocks', 'username' => 'u', 'method' => 'aes-256-gcm'],
        ]);

        $response = $this->get("/subscribe/{$user->token}/shared", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('198.51.100.5');
        $response->assertDontSeeText('203.0.113.200');
    }

    public function test_shared_only_endpoint_falls_back_to_plain_link_list_for_unknown_client(): void
    {
        $user = $this->userWithSharedNode();

        // Unknown client → plain link list (a known client would get the
        // default rendered template even on the shared-only endpoint).
        $response = $this->get("/subscribe/{$user->token}/shared", ['User-Agent' => 'v2rayN/6.0']);

        $this->assertStringStartsWith('ss://', base64_decode($response->getContent()));
    }

    public function test_shared_only_endpoint_renders_default_template_for_a_known_client(): void
    {
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}/shared", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('proxies:');
        $response->assertSeeText('节点A');
    }

    public function test_instance_only_endpoint_renders_clash_template_for_clash_user_agent(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_CLASH,
            'content' => "proxies:\n{{proxies}}\n",
        ]);
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '专属', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));
        $instance = \App\Models\SubscriptionInstance::first();
        $instance->update(['meta' => ['ip' => '203.0.113.50', 'port' => 8080, 'protocol' => 'shadowsocks', 'username' => 'u', 'method' => 'aes-256-gcm']]);

        $response = $this->get("/subscribe/{$user->token}/instance/{$instance->id}", ['User-Agent' => 'ClashForWindows/1.0']);

        $response->assertOk();
        $response->assertSeeText('type: ss');
        $response->assertSeeText('203.0.113.50');
    }

    public function test_admin_can_save_and_reset_subscribe_template(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('subscribeTemplateClient', SubscribeTemplate::CLIENT_SINGBOX)
            ->set('subscribeTemplateContent', 'custom content {{proxies}}')
            ->call('saveSubscribeTemplate');

        $this->assertDatabaseHas('subscribe_templates', [
            'client' => SubscribeTemplate::CLIENT_SINGBOX,
            'content' => 'custom content {{proxies}}',
        ]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('subscribeTemplateClient', SubscribeTemplate::CLIENT_SINGBOX)
            ->call('resetSubscribeTemplate');

        $this->assertDatabaseMissing('subscribe_templates', ['client' => SubscribeTemplate::CLIENT_SINGBOX]);
    }

    public function test_default_singbox_template_renders_valid_json_with_groups_and_routing(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_SINGBOX,
            'content' => \App\Support\DefaultSubscribeTemplates::for(SubscribeTemplate::CLIENT_SINGBOX),
        ]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'sing-box/1.11']);

        $response->assertOk();
        $config = json_decode($response->getContent(), true);
        $this->assertNotNull($config, 'sing-box template must render valid JSON');

        $tags = array_column($config['outbounds'], 'tag');
        $this->assertContains('节点选择', $tags);
        $this->assertContains('自动选择', $tags);
        $this->assertContains('direct', $tags);
        $this->assertContains('节点A', $tags, 'the real node must be spliced into outbounds');
        // The selector references the node by name.
        $selector = collect($config['outbounds'])->firstWhere('tag', '节点选择');
        $this->assertContains('节点A', $selector['outbounds']);
        $this->assertSame('节点选择', $config['route']['final']);
        $this->assertStringNotContainsString('{{', $response->getContent());
    }

    public function test_default_clash_template_renders_valid_yaml_with_groups_and_rules(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_CLASH_META,
            'content' => \App\Support\DefaultSubscribeTemplates::for(SubscribeTemplate::CLIENT_CLASH_META),
        ]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'clash-verge/2.0']);

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('proxy-groups:', $body);
        $this->assertStringContainsString('节点选择', $body);
        $this->assertStringContainsString('GEOIP,CN,DIRECT', $body);
        $this->assertStringContainsString('type: ss', $body);
        $this->assertStringContainsString('节点A', $body);
        $this->assertStringNotContainsString('{{', $body);
    }

    public function test_default_surge_template_has_proxy_group_and_rule_sections(): void
    {
        SubscribeTemplate::create([
            'client' => SubscribeTemplate::CLIENT_SURGE,
            'content' => \App\Support\DefaultSubscribeTemplates::for(SubscribeTemplate::CLIENT_SURGE),
        ]);
        $user = $this->userWithSharedNode();

        $response = $this->get("/subscribe/{$user->token}", ['User-Agent' => 'Surge iOS/1789']);

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('[Proxy Group]', $body);
        $this->assertStringContainsString('节点选择 = select', $body);
        $this->assertStringContainsString('FINAL,节点选择', $body);
        $this->assertStringContainsString('节点A', $body);
        $this->assertStringNotContainsString('{{', $body);
    }
}
