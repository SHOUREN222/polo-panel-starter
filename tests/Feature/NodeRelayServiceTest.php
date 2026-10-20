<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\RelayNode;
use App\Models\RoutingRule;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\NodeRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class NodeRelayServiceTest extends TestCase
{
    use RefreshDatabase;

    private function onipInstance(string $uuid, array $metaOverride = []): SubscriptionInstance
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5500], 'sell' => true,
        ]);

        return SubscriptionInstance::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'uuid' => $uuid,
            'token' => Str::random(16),
            'transfer_enable' => 0,
            'status' => SubscriptionInstance::STATUS_ACTIVE,
            'expired_at' => now()->addDays(30),
            'meta' => array_merge([
                'provider' => 'onip',
                'ip' => '64.32.163.116',
                'port' => 7639,
                'protocol' => 'socks5',
                'username' => 'onipuser',
                'password' => 'onippass',
                'proxy_id' => 'px-1',
            ], $metaOverride),
        ]);
    }

    /** A relay node row (node-specific fields); reality identity stays in Settings. */
    private function makeRelayNode(string $label, int $priority, string $sshHost, string $nodeHost, ?string $sshPassword = 'secret', int $nodePort = 8446): RelayNode
    {
        return RelayNode::create([
            'label' => $label,
            'priority' => $priority,
            'enabled' => true,
            'node_host' => $nodeHost,
            'node_port' => $nodePort,
            'ssh_host' => $sshHost,
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_password' => $sshPassword,
            'ssh_key_path' => null,
            'config_path' => '/etc/xray-relay/config.json',
            'reload_cmd' => 'systemctl restart xray-relay',
        ]);
    }

    public function test_generates_client_route_and_outbound_per_instance(): void
    {
        $svc = new NodeRelayService;
        $inst = $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $cfg = $svc->generateConfig([$inst]);

        // one client on the relay inbound
        $this->assertCount(1, $cfg['clients']);
        $this->assertSame('6896e601-0a63-4af2-bd2d-645c01688dd1', $cfg['clients'][0]['id']);
        // email is the instance's report token (maps xray stats → the instance)
        $this->assertSame($inst->token, $cfg['clients'][0]['email']);
        $this->assertSame('xtls-rprx-vision', $cfg['clients'][0]['flow']);

        // route rule maps that user to their onip outbound
        $this->assertSame([$inst->token], $cfg['routes'][0]['user']);
        $this->assertSame('onip_6896e601_0a63_4af2_bd2d_645c01688dd1', $cfg['routes'][0]['outboundTag']);

        // onip socks outbound carries the residential proxy creds
        $ob = $cfg['outbounds'][0];
        $this->assertSame('onip_6896e601_0a63_4af2_bd2d_645c01688dd1', $ob['tag']);
        $this->assertSame('socks', $ob['protocol']);
        $server = $ob['settings']['servers'][0];
        $this->assertSame('64.32.163.116', $server['address']);
        $this->assertSame(7639, $server['port']);
        $this->assertSame('onipuser', $server['users'][0]['user']);
        $this->assertSame('onippass', $server['users'][0]['pass']);
    }

    public function test_active_onip_instances_excludes_expired_disabled_and_non_onip(): void
    {
        $svc = new NodeRelayService;

        $good = $this->onipInstance('11111111-1111-1111-1111-111111111111');

        // expired
        $expired = $this->onipInstance('22222222-2222-2222-2222-222222222222');
        $expired->update(['expired_at' => now()->subDay()]);

        // disabled
        $disabled = $this->onipInstance('33333333-3333-3333-3333-333333333333');
        $disabled->update(['status' => SubscriptionInstance::STATUS_DISABLED]);

        // non-onip (e.g. a stub/other provider)
        $other = $this->onipInstance('44444444-4444-4444-4444-444444444444', ['provider' => 'stub']);

        $active = $svc->activeOnipInstances();

        $this->assertTrue($active->contains('id', $good->id));
        $this->assertFalse($active->contains('id', $expired->id));
        $this->assertFalse($active->contains('id', $disabled->id));
        $this->assertFalse($active->contains('id', $other->id));
    }

    public function test_generates_nothing_for_empty_set(): void
    {
        $cfg = (new NodeRelayService)->generateConfig([]);

        $this->assertSame([], $cfg['clients']);
        $this->assertSame([], $cfg['routes']);
        $this->assertSame([], $cfg['outbounds']);
    }

    public function test_build_node_files_renders_valid_reality_inbound_and_direct_default(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        Setting::set('relay_reality_sni', 'addons.mozilla.org');
        Setting::set('relay_node_port', 8446);

        $svc = new NodeRelayService;
        $inst = $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');
        $raw = $svc->buildNodeFiles($svc->generateConfig([$inst]))['config'];
        $xray = json_decode($raw, true);

        // policy.levels must serialize as a JSON MAP ({"0":…}), not an array —
        // xray rejects an array here. A PHP ['0'=>…] would encode as an array.
        $this->assertMatchesRegularExpression('/"levels":\s*\{\s*"0"/', $raw);

        $inbound = collect($xray['inbounds'])->firstWhere('protocol', 'vless');
        $this->assertSame('vless', $inbound['protocol']);
        $this->assertSame(8446, $inbound['port']);
        $this->assertSame('none', $inbound['settings']['decryption']);
        $this->assertSame('6896e601-0a63-4af2-bd2d-645c01688dd1', $inbound['settings']['clients'][0]['id']);
        $reality = $inbound['streamSettings']['realitySettings'];
        $this->assertSame('PRIVKEY', $reality['privateKey']);
        $this->assertSame(['abc123'], $reality['shortIds']);
        $this->assertSame(['addons.mozilla.org'], $reality['serverNames']);

        // per-user stats: local-only api inbound + StatsService + policy counters
        $api = collect($xray['inbounds'])->firstWhere('tag', 'api');
        $this->assertSame('dokodemo-door', $api['protocol']);
        $this->assertSame('127.0.0.1', $api['listen']);
        $this->assertSame('api', $xray['api']['tag']);
        $this->assertContains('StatsService', $xray['api']['services']);
        $this->assertTrue($xray['policy']['levels']['0']['statsUserUplink']);
        $this->assertTrue($xray['policy']['levels']['0']['statsUserDownlink']);
        // api routing rule is first so stats queries reach the api handler
        $this->assertSame(['api'], $xray['routing']['rules'][0]['inboundTag']);

        // direct MUST be the first outbound (default) so unmatched traffic stays direct
        $this->assertSame('direct', $xray['outbounds'][0]['tag']);
        $this->assertSame('UseIPv4', $xray['outbounds'][0]['settings']['domainStrategy']);
        $this->assertSame('block', $xray['outbounds'][1]['tag']);
        $this->assertSame('onip_6896e601_0a63_4af2_bd2d_645c01688dd1', $xray['outbounds'][2]['tag']);

        // routing: bittorrent + IPv6 block, the per-user onip rule, relay fallback→direct
        $tags = array_column($xray['routing']['rules'], 'outboundTag');
        $ipv6Block = collect($xray['routing']['rules'])->firstWhere('ip', ['::/0']);
        $this->assertSame('block', $ipv6Block['outboundTag']);
        $this->assertLessThan(
            array_search('onip_6896e601_0a63_4af2_bd2d_645c01688dd1', $tags, true),
            array_search('block', $tags, true),
        );
        $this->assertContains('onip_6896e601_0a63_4af2_bd2d_645c01688dd1', $tags);
        $this->assertSame('direct', $xray['routing']['rules'][count($xray['routing']['rules']) - 1]['outboundTag']);
    }

    public function test_static_isp_routes_bytedance_domains_through_ipv4_resolving_socks(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');

        $svc = new NodeRelayService;
        $inst = $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');
        $xray = json_decode($svc->buildNodeFiles($svc->generateConfig([$inst]))['config'], true);

        $tag = 'onip_6896e601_0a63_4af2_bd2d_645c01688dd1';
        $ipv4Tag = $tag.'_ipv4';

        $normalOutbound = collect($xray['outbounds'])->firstWhere('tag', $tag);
        $ipv4Outbound = collect($xray['outbounds'])->firstWhere('tag', $ipv4Tag);
        $this->assertSame('socks', $normalOutbound['protocol']);
        $this->assertArrayNotHasKey('targetStrategy', $normalOutbound);
        $this->assertSame('socks', $ipv4Outbound['protocol']);
        $this->assertSame('UseIPv4', $ipv4Outbound['targetStrategy']);
        $this->assertSame($normalOutbound['settings'], $ipv4Outbound['settings']);

        $byteDanceRoute = collect($xray['routing']['rules'])->firstWhere('outboundTag', $ipv4Tag);
        $normalRoute = collect($xray['routing']['rules'])->firstWhere('outboundTag', $tag);
        $this->assertSame([$inst->token], $byteDanceRoute['user']);
        $this->assertContains('domain:douyin.com', $byteDanceRoute['domain']);
        $this->assertContains('domain:amemv.com', $byteDanceRoute['domain']);
        $this->assertSame([$inst->token], $normalRoute['user']);

        $routeTags = array_column($xray['routing']['rules'], 'outboundTag');
        $this->assertLessThan(
            array_search($tag, $routeTags, true),
            array_search($ipv4Tag, $routeTags, true),
        );
    }

    public function test_relay_config_includes_admin_routing_rules(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        RoutingRule::create(['name' => '封油管', 'pattern' => '*.youtube.com', 'action' => 'block', 'scope' => 'both', 'enabled' => true, 'sort' => 1]);
        RoutingRule::create(['name' => '内网直连', 'pattern' => '10.0.0.0/8', 'action' => 'direct', 'scope' => 'isp', 'enabled' => true, 'sort' => 2]);
        RoutingRule::create(['name' => '代理(中转跳过)', 'pattern' => '*.foo.com', 'action' => 'proxy', 'scope' => 'both', 'enabled' => true, 'sort' => 3]);
        RoutingRule::create(['name' => '仅共享(中转不应有)', 'pattern' => '*.shared-only.com', 'action' => 'block', 'scope' => 'shared', 'enabled' => true, 'sort' => 4]);

        $svc = new NodeRelayService;
        $xray = json_decode($svc->buildNodeFiles($svc->generateConfig([]))['config'], true);
        $rules = $xray['routing']['rules'];

        // bittorrent block is first; the applicable admin rules follow.
        $blockYt = collect($rules)->firstWhere('domain', ['domain:youtube.com']);
        $this->assertSame('block', $blockYt['outboundTag']);
        $directLan = collect($rules)->firstWhere('ip', ['10.0.0.0/8']);
        $this->assertSame('direct', $directLan['outboundTag']);
        // proxy action has no distinct relay target → not emitted.
        $this->assertNull(collect($rules)->firstWhere('domain', ['domain:foo.com']));
        // shared-only scoped rule must NOT reach the relay.
        $this->assertNull(collect($rules)->firstWhere('domain', ['domain:shared-only.com']));
    }

    public function test_sync_returns_not_pushed_when_unconfigured(): void
    {
        $result = (new NodeRelayService)->sync();

        $this->assertFalse($result['pushed']);
        $this->assertStringContainsString('未配置', $result['message']);
    }

    public function test_admin_can_add_edit_and_disable_a_relay_node(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Add a node.
        Livewire::actingAs($admin)->test('admin.servers')
            ->call('newRelayNode')
            ->set('nf_label', '节点3')
            ->set('nf_priority', '2')
            ->set('nf_node_host', '203.0.113.30')
            ->set('nf_node_port', '8446')
            ->set('nf_ssh_host', '203.0.113.30')
            ->set('nf_ssh_port', '22')
            ->set('nf_ssh_user', 'root')
            ->set('nf_ssh_password', 'pw3')
            ->call('saveRelayNode')
            ->assertHasNoErrors();

        $node = RelayNode::where('label', '节点3')->firstOrFail();
        $this->assertSame('203.0.113.30', $node->node_host);
        $this->assertSame('pw3', $node->ssh_password); // encrypted at rest, decrypts back

        // Editing with a blank password keeps the old one.
        Livewire::actingAs($admin)->test('admin.servers')
            ->call('editRelayNode', $node->id)
            ->set('nf_node_host', '203.0.113.31')
            ->set('nf_ssh_password', '')
            ->call('saveRelayNode')
            ->assertHasNoErrors();
        $node->refresh();
        $this->assertSame('203.0.113.31', $node->node_host);
        $this->assertSame('pw3', $node->ssh_password, 'blank password on edit must not wipe it');

        // Toggle disables it → excluded from targets.
        Livewire::actingAs($admin)->test('admin.servers')->call('toggleRelayNode', $node->id);
        $this->assertFalse($node->fresh()->enabled);
    }

    public function test_servers_page_shows_relay_nodes_in_their_own_section(): void
    {
        // The static-ISP relay is its own section now, separate from the V2bX
        // shared-node table, and its node list renders without any toggle.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20');

        Livewire::actingAs($admin)->test('admin.servers')
            ->assertSee('静态IP 中转节点')
            ->assertSee('203.0.113.10')
            ->assertSee('203.0.113.20')
            ->assertSee('新增中转节点')
            // Reality identity editor is behind its own toggle.
            ->assertDontSee('保存 Reality 身份')
            ->set('showRelayConfig', true)
            ->assertSee('保存 Reality 身份');
    }

    public function test_admin_can_generate_a_relay_node_install_script(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = $this->makeRelayNode('节点X', 5, '203.0.113.40', '203.0.113.40');

        $component = Livewire::actingAs($admin)->test('admin.servers')
            ->call('showRelayInstall', $node->id);

        $script = $component->get('relayInstallScript');
        $this->assertStringContainsString('xray-relay.service', $script);
        $this->assertStringContainsString('disable_ipv6', $script); // the IPv6 gotcha is baked in
        $this->assertStringContainsString('8446', $script);
    }

    public function test_generate_relay_keypair_produces_matching_x25519_pair(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $component = Livewire::actingAs($admin)->test('admin.servers')
            ->call('generateRelayKeypair');

        $priv = $component->get('relay_reality_private_key');
        $pub = $component->get('relay_reality_public_key');
        $this->assertNotEmpty($priv);
        $this->assertNotEmpty($component->get('relay_reality_short_id'));

        // public key must be scalarmult_base(private) — a valid Reality pair
        $privRaw = sodium_base642bin($priv, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $derived = sodium_bin2base64(sodium_crypto_scalarmult_base($privRaw), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->assertSame($pub, $derived);
    }

    public function test_sync_pushes_generated_files_when_configured(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        // capture what would be pushed instead of running real SSH
        $captured = [];
        $svc = new class($captured) extends NodeRelayService
        {
            public function __construct(public &$captured) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                $this->captured = $files;
            }
        };

        $result = $svc->sync();

        $this->assertTrue($result['pushed']);
        $this->assertSame(1, $result['instances']);
        $this->assertArrayHasKey('config', $captured);
        $this->assertStringContainsString('onip_6896e601', $captured['config']);
    }

    public function test_sync_pushes_the_same_config_to_both_primary_and_backup(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20', 'secret2');

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $pushes = [];
        $svc = new class($pushes) extends NodeRelayService
        {
            public function __construct(public &$pushes) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                $this->pushes[] = ['label' => $s['label'] ?? '主力', 'ssh_host' => $s['ssh_host'], 'config' => $files['config']];
            }
        };

        $result = $svc->sync();

        $this->assertTrue($result['pushed']);
        $this->assertSame(['主力', '备用'], $result['nodes']);
        $this->assertCount(2, $pushes);
        $this->assertSame('203.0.113.10', $pushes[0]['ssh_host']);
        $this->assertSame('203.0.113.20', $pushes[1]['ssh_host']);
        // Same customer config pushed to both nodes.
        $this->assertSame($pushes[0]['config'], $pushes[1]['config']);
    }

    public function test_a_dead_primary_still_lets_the_config_reach_backup(): void
    {
        // Regression: 主力 down for a bandwidth suspension → its SSH push
        // throws. The old plain loop aborted on that first failure, so every
        // new provision/renewal silently never updated 备用, and new
        // customers couldn't use the 备用 line at all. Each node must be
        // pushed independently.
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');            // will fail
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20', 'secret2'); // must still get it

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $pushed = [];
        $svc = new class($pushed) extends NodeRelayService
        {
            public function __construct(public &$pushed) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                if (($s['label'] ?? '主力') === '主力') {
                    throw new \RuntimeException('ssh: connect to host 203.0.113.10: Connection timed out');
                }
                $this->pushed[] = $s['label'];
            }
        };

        $result = $svc->sync();

        $this->assertContains('备用', $result['nodes'], '备用 must be updated even though 主力 push failed');
        $this->assertContains('主力', $result['failed']);
        $this->assertTrue($result['pushed'], 'at least one node took the config');
        $this->assertSame(['备用'], $pushed, 'the backup node was actually written to');
    }

    public function test_an_unchanged_node_is_skipped_on_the_next_sync(): void
    {
        // The self-heal schedule runs sync() every few minutes — it must NOT
        // reload a node whose config is already current (that would drop the
        // node's live customer connections on every tick).
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $pushes = 0;
        $svc = new class($pushes) extends NodeRelayService
        {
            public function __construct(public &$pushes) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                $this->pushes++;
            }
        };

        $first = $svc->sync();
        $this->assertSame(['主力'], $first['nodes']);
        $this->assertSame(1, $pushes, 'first sync pushes');

        // Nothing changed → second sync must be a no-op (no push, no reload).
        $second = $svc->sync();
        $this->assertSame(1, $pushes, 'unchanged config must not be re-pushed');
        $this->assertSame(['主力'], $second['unchanged']);
        $this->assertSame([], $second['nodes']);

        // A new customer changes the config → it gets pushed again.
        $this->onipInstance('7896e601-0a63-4af2-bd2d-645c01688dd2');
        $third = $svc->sync();
        $this->assertSame(2, $pushes, 'a changed config is re-pushed');
        $this->assertSame(['主力'], $third['nodes']);
    }

    public function test_force_repushes_even_when_unchanged(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $pushes = 0;
        $svc = new class($pushes) extends NodeRelayService
        {
            public function __construct(public &$pushes) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                $this->pushes++;
            }
        };

        $svc->sync();
        $this->assertSame(1, $pushes);
        $svc->sync();              // unchanged → skipped
        $this->assertSame(1, $pushes);
        $svc->sync(force: true);   // operator 重新同步 → pushes anyway
        $this->assertSame(2, $pushes);
    }

    public function test_a_recovered_node_is_healed_on_the_next_sync_without_touching_the_current_one(): void
    {
        // 主力 down, 备用 up. While 主力 is unreachable it never records a
        // synced-hash, so every sync retries it. When it comes back, the next
        // sync pushes to 主力 (healing it) but SKIPS 备用 (already current) —
        // so 备用's live connections are never disturbed by the heal.
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20', 'secret2');

        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');

        $primaryUp = false;
        $pushes = [];
        $svc = new class($primaryUp, $pushes) extends NodeRelayService
        {
            public function __construct(public &$primaryUp, public &$pushes) {}

            protected function pushFiles(array $files, ?array $s = null): void
            {
                $label = $s['label'] ?? '主力';
                if ($label === '主力' && ! $this->primaryUp) {
                    throw new \RuntimeException('primary down');
                }
                $this->pushes[] = $label;
            }
        };

        // 主力 down: 备用 pushed, 主力 failed (and not hashed, so it'll retry).
        $r1 = $svc->sync();
        $this->assertSame(['备用'], $r1['nodes']);
        $this->assertSame(['主力'], $r1['failed']);
        $this->assertSame(['备用'], $pushes);

        // 主力 recovers.
        $primaryUp = true;
        $r2 = $svc->sync();
        $this->assertSame(['主力'], $r2['nodes'], '主力 healed');
        $this->assertSame(['备用'], $r2['unchanged'], '备用 left untouched');
        $this->assertSame(['备用', '主力'], $pushes, '备用 not re-pushed during the heal');
    }

    public function test_customer_gets_both_primary_and_backup_endpoints(): void
    {
        Setting::set('relay_reality_public_key', 'PUBKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20', 'secret2');

        $inst = $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');
        $nodes = (new NodeRelayService)->customerNodes($inst, '美国', '198.51.100.3');

        $this->assertCount(2, $nodes);
        $this->assertSame('美国 · 主力 · 198.51.100.3', $nodes[0]['name']);
        $this->assertSame('203.0.113.10', $nodes[0]['host']);
        $this->assertSame('美国 · 备用 · 198.51.100.3', $nodes[1]['name']);
        $this->assertSame('203.0.113.20', $nodes[1]['host']);
        // Same uuid + reality identity on both → same onip IP, just a failover path.
        $this->assertSame($nodes[0]['credential'], $nodes[1]['credential']);
        $this->assertSame('PUBKEY', $nodes[1]['config']['public_key']);
    }

    public function test_backup_is_ignored_when_not_enabled(): void
    {
        Setting::set('relay_reality_public_key', 'PUBKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        // a 备用 node that exists but is DISABLED must be excluded
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20')->update(['enabled' => false]);

        $inst = $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1');
        $nodes = (new NodeRelayService)->customerNodes($inst, '美国', '198.51.100.3');

        $this->assertCount(1, $nodes);
        $this->assertSame('美国 · 主力 · 198.51.100.3', $nodes[0]['name']);
    }

    private function configurePrimaryAndBackup(): void
    {
        Setting::set('relay_reality_private_key', 'PRIVKEY');
        Setting::set('relay_reality_short_id', 'abc123');
        $this->makeRelayNode('主力', 0, '203.0.113.10', '203.0.113.10');
        $this->makeRelayNode('备用', 1, '203.0.113.20', '203.0.113.20', 'secret2');
    }

    public function test_relay_statuses_probes_each_node_and_parses_result(): void
    {
        $this->configurePrimaryAndBackup();
        $this->onipInstance('6896e601-0a63-4af2-bd2d-645c01688dd1'); // 1 active line

        // primary healthy, backup unreachable (empty output).
        $svc = new class extends NodeRelayService
        {
            protected function runSshCapture(string $cmd, array $s): string
            {
                return $s['ssh_host'] === '203.0.113.10' ? 'ACT=active LIS=1' : '';
            }
        };

        $st = $svc->relayStatuses();
        $this->assertCount(2, $st);
        $this->assertSame('主力', $st[0]['label']);
        $this->assertTrue($st[0]['reachable']);
        $this->assertTrue($st[0]['active']);
        $this->assertTrue($st[0]['listening']);
        $this->assertSame(1, $st[0]['lines']);
        $this->assertSame('备用', $st[1]['label']);
        $this->assertFalse($st[1]['reachable']);
        $this->assertFalse($st[1]['active']);
    }

    public function test_restart_node_targets_the_named_node_only(): void
    {
        $this->configurePrimaryAndBackup();

        $calls = [];
        $svc = new class($calls) extends NodeRelayService
        {
            public function __construct(public &$calls) {}

            protected function runSsh(string $cmd, ?string $stdin = null, ?array $s = null): void
            {
                $this->calls[] = $s['ssh_host'];
            }
        };

        $svc->restartNode('备用');
        $this->assertSame(['203.0.113.20'], $calls);
    }

    public function test_node_error_log_reads_from_the_named_node(): void
    {
        $this->configurePrimaryAndBackup();

        $svc = new class extends NodeRelayService
        {
            protected function runSshCapture(string $cmd, array $s): string
            {
                return 'log from '.$s['ssh_host'];
            }
        };

        $this->assertSame('log from 203.0.113.10', $svc->nodeErrorLog('主力'));
        $this->assertSame('log from 203.0.113.20', $svc->nodeErrorLog('备用'));
    }
}
