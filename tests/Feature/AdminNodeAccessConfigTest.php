<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The 服务器管理 page hands out a ready-to-paste V2bX node config. Getting the
 * token or NodeID wrong is the usual reason a freshly added node stays dark,
 * so the panel fills them in rather than making anyone look them up.
 */
class AdminNodeAccessConfigTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
    }

    /** Pull the embedded config.json back out of the generated shell script. */
    private function embeddedConfig(string $script): array
    {
        $this->assertMatchesRegularExpression('/cat > \/etc\/V2bX\/config\.json <<\'JSON\'\n(.*?)\nJSON/s', $script);
        preg_match('/cat > \/etc\/V2bX\/config\.json <<\'JSON\'\n(.*?)\nJSON/s', $script, $m);

        return json_decode($m[1], true) ?? [];
    }

    public function test_install_script_embeds_this_nodes_id_and_token(): void
    {
        Setting::set('site_url', 'https://app.example.com');
        $server = Server::create([
            'name' => '新节点', 'group_id' => 1, 'host' => '198.51.100.9',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ])->fresh();

        $script = Livewire::actingAs($this->admin())->test('admin.servers')
            ->instance()->nodeInstallScript($server);

        $node = $this->embeddedConfig($script)['Nodes'][0];

        $this->assertSame($server->id, $node['NodeID']);
        $this->assertSame($server->heartbeat_secret, $node['ApiKey']);
        $this->assertSame('vless', $node['NodeType']);
        $this->assertSame('https://app.example.com', $node['ApiHost']);
        $this->assertSame('xray', $node['Core']);
    }

    public function test_install_script_is_self_contained_and_runnable(): void
    {
        $server = Server::create([
            'name' => 'n', 'group_id' => 1, 'host' => '198.51.100.10',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ])->fresh();

        $script = Livewire::actingAs($this->admin())->test('admin.servers')
            ->instance()->nodeInstallScript($server);

        // Writes every file a fresh box needs — not a fragment to merge by hand.
        $this->assertStringContainsString('/etc/V2bX/config.json', $script);
        $this->assertStringContainsString('/etc/V2bX/direct_outbound.json', $script);
        $this->assertStringContainsString('/etc/V2bX/direct_route.json', $script);
        // The panel-patched binary keeps ApiKey out of request URLs. Its
        // immutable download is verified before replacing the running agent.
        $this->assertStringContainsString('V2bX-v0.4.0-bearer.1-linux-amd64', $script);
        $this->assertStringContainsString('sha256sum --check --status', $script);
        $this->assertStringContainsString('9c1a27aeccda0e3dba138936b9bf924f8a6242cfe5cfa81f23cd8bff5bf13629', $script);
        // Starts it and proves it worked.
        $this->assertStringContainsString('systemctl restart V2bX', $script);
        $this->assertStringContainsString('journalctl -u V2bX', $script);
        // Checks the node's own port, not a hardcoded one.
        $this->assertStringContainsString(':8444', $script);
        // The embedded config must be valid JSON.
        $this->assertNotEmpty($this->embeddedConfig($script));
    }

    public function test_api_host_is_never_the_closed_plaintext_port(): void
    {
        // Port 8080 was closed to the internet on 2026-07-18; a config pointing
        // at it would silently never connect.
        Setting::set('site_url', 'https://app.example.com');
        $server = Server::create([
            'name' => 'n', 'group_id' => 1, 'host' => '198.51.100.11',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ])->fresh();

        $script = Livewire::actingAs($this->admin())->test('admin.servers')
            ->instance()->nodeInstallScript($server);

        $this->assertStringNotContainsString(':8080', $this->embeddedConfig($script)['Nodes'][0]['ApiHost']);
        $this->assertStringStartsWith('https://', $this->embeddedConfig($script)['Nodes'][0]['ApiHost']);
    }

    public function test_resetting_a_token_locks_out_the_old_one_and_admits_the_new(): void
    {
        $server = Server::create([
            'name' => '泄露节点', 'group_id' => 1, 'host' => '198.51.100.20',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ])->fresh();
        $old = $server->heartbeat_secret;

        // The old token works against the node API before rotating.
        $this->withToken((string) $old)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")->assertOk();

        Livewire::actingAs($this->admin())->test('admin.servers')
            ->call('resetToken', $server->id)
            ->assertSet('configForNode', $server->id); // 自动展开新脚本

        $new = $server->fresh()->heartbeat_secret;
        $this->assertNotSame($old, $new);

        // The leaked one can no longer read the customer list; the new one can.
        $this->withToken((string) $old)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")->assertForbidden();
        $this->withToken((string) $new)
            ->getJson("/api/v1/server/UniProxy/user?node_id={$server->id}")->assertOk();
    }

    public function test_reset_token_only_affects_the_node_it_was_called_on(): void
    {
        $a = Server::create(['name' => 'A', 'group_id' => 1, 'host' => '198.51.100.21', 'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true])->fresh();
        $b = Server::create(['name' => 'B', 'group_id' => 1, 'host' => '198.51.100.22', 'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true])->fresh();
        $bToken = $b->heartbeat_secret;

        Livewire::actingAs($this->admin())->test('admin.servers')->call('resetToken', $a->id);

        $this->assertSame($bToken, $b->fresh()->heartbeat_secret, '不该动别的节点的令牌');
    }

    public function test_the_regenerated_script_carries_the_new_token(): void
    {
        $server = Server::create([
            'name' => 'n', 'group_id' => 1, 'host' => '198.51.100.23',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ])->fresh();
        $old = $server->heartbeat_secret;

        $c = Livewire::actingAs($this->admin())->test('admin.servers');
        $c->call('resetToken', $server->id);

        $script = $c->instance()->nodeInstallScript($server->fresh());

        $this->assertStringContainsString($server->fresh()->heartbeat_secret, $script);
        $this->assertStringNotContainsString($old, $script);
    }

    public function test_reality_key_generator_fills_a_usable_keypair(): void
    {
        $c = Livewire::actingAs($this->admin())->test('admin.servers')
            ->set('protocol', 'vless')
            ->call('generateRealityKeys');

        // X25519 base64url, unpadded — 43 chars, same shape `xray x25519` prints.
        $this->assertSame(43, strlen($c->get('reality_public_key')));
        $this->assertSame(43, strlen($c->get('reality_private_key')));
        $this->assertNotSame($c->get('reality_public_key'), $c->get('reality_private_key'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $c->get('reality_public_key'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $c->get('reality_short_id'));
        $this->assertNotEmpty($c->get('reality_sni'));

        // Two presses must not yield the same key.
        $first = $c->get('reality_public_key');
        $this->assertNotSame($first, $c->call('generateRealityKeys')->get('reality_public_key'));
    }

    public function test_the_guide_and_config_panel_toggle(): void
    {
        $server = Server::create([
            'name' => 'n', 'group_id' => 1, 'host' => '198.51.100.12',
            'port' => 8444, 'protocol' => 'vless', 'rate' => 1, 'show' => true,
        ]);

        Livewire::actingAs($this->admin())->test('admin.servers')
            ->assertSet('showGuide', false)
            ->call('toggleGuide')->assertSet('showGuide', true)
            ->call('toggleGuide')->assertSet('showGuide', false)
            ->call('showNodeConfig', $server->id)->assertSet('configForNode', $server->id)
            ->call('showNodeConfig', $server->id)->assertSet('configForNode', null); // 再点收起
    }
}
