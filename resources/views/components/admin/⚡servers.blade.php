<?php

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '服务器管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public int $group_id = 1;
    public string $host = '';
    public int $port = 443;
    public string $protocol = 'shadowsocks';
    public string $method = 'aes-256-gcm';
    public string $rate = '1.00';
    public bool $show = true;

    // Reality-only fields, shown conditionally when protocol = vless.
    public string $reality_public_key = '';
    public string $reality_private_key = '';
    public string $reality_short_id = '';
    public string $reality_sni = '';
    public string $reality_flow = 'xtls-rprx-vision';

    // Named successMessage (not "message") so it doesn't collide with
    // Blade's own $message variable inside @error(...)...@enderror blocks.
    public string $successMessage = '';
    public string $error = '';

    // 「如何添加新节点」指南 + 每个节点的现成接入配置
    public bool $showGuide = false;
    public ?int $configForNode = null;

    public function toggleGuide(): void
    {
        $this->showGuide = ! $this->showGuide;
    }

    public function showNodeConfig(?int $id): void
    {
        $this->configForNode = $this->configForNode === $id ? null : $id;
    }

    /**
     * Rotate a node's token (use it if one leaks — it grants read access to
     * every customer's UUID). Opens that node's 接入配置 straight away, since
     * the node is offline-for-syncing until it's reconfigured with the new one.
     */
    public function resetToken(int $id): void
    {
        $server = Server::findOrFail($id);
        $server->rotateHeartbeatSecret();

        $this->configForNode = $id;
        $this->successMessage = "节点「{$server->name}」的令牌已重置，旧令牌立即失效。"
            .'请立刻用下方新脚本更新该节点并重启 V2bX——在那之前它无法与面板同步用户和流量'
            .'（已连接的客户不会掉线）。';
    }

    /**
     * Generate a Reality keypair so nobody has to go find a box with
     * `xray x25519` on it. Reality keys are plain X25519, which is exactly
     * what libsodium's crypto_box keys are, base64url-encoded without padding
     * (43 chars) — the same form xray prints.
     */
    public function generateRealityKeys(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $b64url = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->reality_private_key = $b64url(sodium_crypto_box_secretkey($keypair));
        $this->reality_public_key = $b64url(sodium_crypto_box_publickey($keypair));
        $this->reality_short_id = bin2hex(random_bytes(4));
        $this->reality_sni = $this->reality_sni ?: 'www.microsoft.com';

        $this->successMessage = '已生成 Reality 密钥对，保存后即可用于新节点。';
    }

    /**
     * A complete, paste-and-run install for THIS node. The panel already knows
     * its id, token and port, and those are exactly what people mistype, so we
     * fill them in and write the whole config rather than handing over a
     * fragment to merge by hand.
     *
     * The route/outbound files are copied verbatim from the working nodes
     * (direct + blackhole, BitTorrent blocked) so a new box lands on the same
     * known-good setup instead of a guess. ApiHost is the https domain on
     * purpose — 8080 was closed to the internet on 2026-07-18, so a config
     * copied off an older node points at a dead endpoint.
     */
    public function nodeInstallScript(Server $server): string
    {
        $apiHost = rtrim(Setting::get('site_url') ?: url('/'), '/');
        $binaryUrl = $apiHost.'/downloads/V2bX-v0.4.0-bearer.1-linux-amd64';
        $binarySha256 = '9c1a27aeccda0e3dba138936b9bf924f8a6242cfe5cfa81f23cd8bff5bf13629';

        $config = json_encode([
            'Log' => ['Level' => 'info', 'Output' => ''],
            'Cores' => [[
                'Type' => 'xray',
                'Log' => ['Level' => 'info', 'AccessPath' => '/etc/V2bX/xray_access.log', 'ErrorPath' => '/etc/V2bX/xray_error.log'],
                'AssetPath' => '/etc/V2bX',
                'DnsConfigPath' => '',
                'RouteConfigPath' => '/etc/V2bX/direct_route.json',
                'ConnectionConfig' => [
                    'handshake' => 4, 'connIdle' => 300, 'uplinkOnly' => 2, 'downlinkOnly' => 5,
                    'statsUserUplink' => true, 'statsUserDownlink' => true, 'bufferSize' => 4,
                ],
                'InboundConfigPath' => '',
                'OutboundConfigPath' => '/etc/V2bX/direct_outbound.json',
            ]],
            'Nodes' => [[
                'Core' => 'xray',
                'ApiHost' => $apiHost,
                'ApiKey' => $server->heartbeat_secret,
                'NodeID' => $server->id,
                'NodeType' => 'vless',
                'Timeout' => 30,
                'ListenIP' => '0.0.0.0',
                'SendIP' => '0.0.0.0',
                'DeviceOnlineMinTraffic' => 200,
                'MinReportTraffic' => 0,
                'TCPFastOpen' => false,
                'SniffEnabled' => true,
                'CertConfig' => [
                    'CertMode' => 'self', 'RejectUnknownSni' => false, 'CertDomain' => 'example.com',
                    'CertFile' => '/etc/V2bX/fullchain.cer', 'KeyFile' => '/etc/V2bX/cert.key',
                    'Provider' => 'cloudflare', 'DNSEnv' => ['EnvName' => 'env1'],
                ],
                'EnableDNS' => true,
                'DNSType' => 'UseIPv4',
                'Name' => $server->name,
                'EnableTFO' => false,
                'EnableProxyProtocol' => false,
                'EnableFallback' => false,
                'DisableSniffing' => false,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $port = (int) $server->port;

        return <<<SH
# ===== 节点 #{$server->id}「{$server->name}」一键接入 =====
# 在【新服务器】上以 root 粘贴执行。已含本节点的 NodeID 与令牌，无需修改。

# ① V2bX 未安装时先准备基础服务（装过会跳过）
if [ ! -x /usr/local/V2bX/V2bX ]; then
  echo "未检测到 V2bX，请先安装官方版后重跑本脚本："
  echo "  bash <(curl -Ls https://raw.githubusercontent.com/wyx2685/V2bX-script/master/install.sh)"
  exit 1
fi

mkdir -p /etc/V2bX

# ② 安装面板固定的 Bearer-Header 构建，并校验完整性
node_binary="\$(mktemp)"
trap 'rm -f "\$node_binary"' EXIT
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
  --output "\$node_binary" '{$binaryUrl}'
printf '%s  %s\n' '{$binarySha256}' "\$node_binary" | sha256sum --check --status
install -m 0755 "\$node_binary" /usr/local/V2bX/V2bX

# ③ 路由与出站（与现有节点一致：直连 + 拦 BitTorrent）
cat > /etc/V2bX/direct_outbound.json <<'JSON'
[{"tag":"direct","protocol":"freedom","settings":{}},{"tag":"block","protocol":"blackhole","settings":{}}]
JSON
cat > /etc/V2bX/direct_route.json <<'JSON'
{"domainStrategy":"AsIs","rules":[{"type":"field","protocol":["bittorrent"],"outboundTag":"block"}]}
JSON

# ④ 本节点配置
cat > /etc/V2bX/config.json <<'JSON'
{$config}
JSON

# ⑤ 启动
systemctl enable V2bX >/dev/null 2>&1
systemctl restart V2bX
sleep 6

# ⑥ 自检
echo "--- 服务状态 ---"; systemctl is-active V2bX
echo "--- 端口 {$port} ---"; ss -tlnp 2>/dev/null | grep ":{$port}" || echo "  ⚠️ 端口未监听，看下方日志"
echo "--- 最近日志（应有 Added N new users）---"
journalctl -u V2bX -n 12 --no-pager 2>/dev/null | tail -6
echo
echo "还要放行防火墙端口 {$port}，然后回面板「节点监控」确认 60 秒内上线。"
SH;
    }

    // 静态IP中转控制台：探测到的各中转节点状态 + 当前查看的错误日志
    public array $relayStatuses = [];
    public string $relayLog = '';
    public string $relayLogNode = '';

    // 静态IP中转：Reality 身份是全平台共享的（每个中转节点必须用同一套，客户
    // uuid 才能在节点间无缝切换），存 Setting；各中转节点存 relay_nodes 表。
    public string $relay_reality_sni = 'addons.mozilla.org';
    public string $relay_reality_private_key = '';
    public string $relay_reality_public_key = '';
    public string $relay_reality_short_id = '';
    public bool $showRelayConfig = false;

    // 中转节点增改表单（editingNodeId=null 表示新增）
    public ?int $editingNodeId = null;
    public string $nf_label = '';
    public string $nf_priority = '0';
    public bool $nf_enabled = true;
    public string $nf_node_host = '';
    public string $nf_node_port = '8446';
    public string $nf_ssh_host = '';
    public string $nf_ssh_port = '22';
    public string $nf_ssh_user = 'root';
    public string $nf_ssh_password = '';
    public string $nf_ssh_key_path = '';
    public bool $showNodeForm = false;

    // 一键接入脚本弹窗
    public string $relayInstallScript = '';
    public string $relayInstallLabel = '';

    public function mount(): void
    {
        $this->relay_reality_sni = Setting::get('relay_reality_sni', 'addons.mozilla.org');
        $this->relay_reality_private_key = Setting::get('relay_reality_private_key', '');
        $this->relay_reality_public_key = Setting::get('relay_reality_public_key', '');
        $this->relay_reality_short_id = Setting::get('relay_reality_short_id', '');
    }

    public function saveRealityIdentity(): void
    {
        $this->validate(['relay_reality_sni' => ['required', 'string', 'max:255']]);

        foreach (['relay_reality_sni', 'relay_reality_private_key', 'relay_reality_public_key', 'relay_reality_short_id'] as $key) {
            Setting::set($key, $this->{$key});
        }

        $this->successMessage = 'Reality 身份已保存（所有中转节点共用）';
    }

    public function generateRelayKeypair(): void
    {
        $priv = sodium_crypto_box_keypair();
        $privRaw = sodium_crypto_box_secretkey($priv);
        $pubRaw = sodium_crypto_scalarmult_base($privRaw);

        $this->relay_reality_private_key = sodium_bin2base64($privRaw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->relay_reality_public_key = sodium_bin2base64($pubRaw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->relay_reality_short_id = bin2hex(random_bytes(8));

        $this->successMessage = '已生成新的 Reality 密钥对，记得点"保存 Reality 身份"';
    }

    /** Open the add-node form (blank) or edit an existing node. */
    public function newRelayNode(): void
    {
        $this->reset(['nf_label', 'nf_node_host', 'nf_ssh_host', 'nf_ssh_password', 'nf_ssh_key_path']);
        $this->editingNodeId = null;
        $this->nf_priority = (string) ((int) (\App\Models\RelayNode::max('priority')) + 1);
        $this->nf_enabled = true;
        $this->nf_node_port = '8446';
        $this->nf_ssh_port = '22';
        $this->nf_ssh_user = 'root';
        $this->showNodeForm = true;
    }

    public function editRelayNode(int $id): void
    {
        $n = \App\Models\RelayNode::findOrFail($id);
        $this->editingNodeId = $n->id;
        $this->nf_label = $n->label;
        $this->nf_priority = (string) $n->priority;
        $this->nf_enabled = (bool) $n->enabled;
        $this->nf_node_host = $n->node_host;
        $this->nf_node_port = (string) $n->node_port;
        $this->nf_ssh_host = $n->ssh_host;
        $this->nf_ssh_port = (string) $n->ssh_port;
        $this->nf_ssh_user = $n->ssh_user;
        $this->nf_ssh_password = ''; // never echo the stored password back
        $this->nf_ssh_key_path = (string) $n->ssh_key_path;
        $this->showNodeForm = true;
    }

    public function saveRelayNode(): void
    {
        $this->validate([
            'nf_label' => ['required', 'string', 'max:40'],
            'nf_priority' => ['required', 'integer', 'min:0'],
            'nf_node_host' => ['required', 'string', 'max:255'],
            'nf_node_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'nf_ssh_host' => ['required', 'string', 'max:255'],
            'nf_ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'nf_ssh_user' => ['required', 'string', 'max:64'],
        ]);

        $data = [
            'label' => $this->nf_label,
            'priority' => (int) $this->nf_priority,
            'enabled' => $this->nf_enabled,
            'node_host' => trim($this->nf_node_host),
            'node_port' => (int) $this->nf_node_port,
            'ssh_host' => trim($this->nf_ssh_host),
            'ssh_port' => (int) $this->nf_ssh_port,
            'ssh_user' => $this->nf_ssh_user,
            'ssh_key_path' => trim($this->nf_ssh_key_path) ?: null,
        ];
        // Only overwrite the password when a new one was typed (blank on edit = keep).
        if ($this->nf_ssh_password !== '') {
            $data['ssh_password'] = $this->nf_ssh_password;
        }

        if ($this->editingNodeId) {
            \App\Models\RelayNode::findOrFail($this->editingNodeId)->update($data);
        } else {
            $data['ssh_password'] = $this->nf_ssh_password ?: null;
            \App\Models\RelayNode::create($data);
        }

        $this->showNodeForm = false;
        $this->refreshRelayStatus();
        $this->successMessage = '中转节点已保存，记得点「重新同步」把配置推过去';
    }

    public function deleteRelayNode(int $id): void
    {
        \App\Models\RelayNode::whereKey($id)->delete();
        $this->refreshRelayStatus();
        $this->successMessage = '中转节点已删除';
    }

    public function toggleRelayNode(int $id): void
    {
        $n = \App\Models\RelayNode::findOrFail($id);
        $n->update(['enabled' => ! $n->enabled]);
        $this->refreshRelayStatus();
    }

    /**
     * One-click install script for a relay node — installs xray + a systemd
     * xray-relay unit + a placeholder config the panel then overwrites on
     * sync. Mirrors the shared-node 接入配置 flow but for the standalone relay.
     */
    public function showRelayInstall(int $id): void
    {
        $n = \App\Models\RelayNode::findOrFail($id);
        $this->relayInstallLabel = $n->label;
        $this->relayInstallScript = $this->buildRelayInstallScript($n);
    }

    private function buildRelayInstallScript(\App\Models\RelayNode $n): string
    {
        $port = (int) $n->node_port;
        $configPath = $n->config_path;
        $reload = $n->reload_cmd;

        return <<<SH
# ===== 静态中转「{$n->label}」一键接入 =====
# 在【新中转机】以 root 粘贴执行：装 xray + xray-relay 服务 + 占位配置。
# 之后回面板点该节点「重新同步」，面板会 SSH 推入真实客户配置并 reload。

set -e

# ① 装官方 xray（已装则跳过）
if [ ! -x /usr/local/bin/xray ]; then
  bash <(curl -Ls https://github.com/XTLS/Xray-install/raw/main/install-release.sh) install
fi

# ② 关掉系统 IPv6（Reality dest 回落会误走 IPv6 挂掉；本机若无 IPv6 出口必做）
cat > /etc/sysctl.d/99-disable-ipv6.conf <<'CONF'
net.ipv6.conf.all.disable_ipv6 = 1
net.ipv6.conf.default.disable_ipv6 = 1
CONF
sysctl --system >/dev/null 2>&1 || true

# ③ 占位配置（面板同步后会整体覆盖）
mkdir -p "\$(dirname {$configPath})"
[ -f {$configPath} ] || cat > {$configPath} <<'JSON'
{"log":{"loglevel":"warning"},"inbounds":[],"outbounds":[{"protocol":"freedom","tag":"direct"}]}
JSON

# ④ systemd 服务
cat > /etc/systemd/system/xray-relay.service <<UNIT
[Unit]
Description=xray static-ISP relay
After=network.target
[Service]
ExecStart=/usr/local/bin/xray run -c {$configPath}
Restart=on-failure
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable xray-relay >/dev/null 2>&1
{$reload}
sleep 2

# ⑤ 自检
echo "--- 服务 ---"; systemctl is-active xray-relay
echo "--- 端口 {$port} ---"; ss -tlnp 2>/dev/null | grep ":{$port}" || echo "  ⚠️ 占位配置无 inbound 是正常的，面板同步后才监听"
echo
echo "接着：① 把面板的公钥加到本机 /root/.ssh/authorized_keys（或在面板填 SSH 密码）"
echo "     ② 放行防火墙端口 {$port}"
echo "     ③ 回面板点该节点「重新同步」，再看「刷新」变🟢在线"
SH;
    }

    public function closeRelayInstall(): void
    {
        $this->relayInstallScript = '';
        $this->relayInstallLabel = '';
    }

    public function refreshRelayStatus(): void
    {
        $this->relayLog = '';
        $this->error = '';
        try {
            $this->relayStatuses = app(\App\Services\NodeRelayService::class)->relayStatuses();
        } catch (\Throwable $e) {
            $this->error = '获取中转状态失败：'.$e->getMessage();
        }
    }

    public function syncRelay(): void
    {
        $this->successMessage = '';
        $this->error = '';
        try {
            // Operator pressed 重新同步 explicitly — force every node even if
            // its content hash says it's already current (they may be
            // recovering a node whose config drifted out-of-band).
            $result = app(\App\Services\NodeRelayService::class)->sync(force: true);
            $nodes = ! empty($result['nodes']) ? '（'.implode('+', $result['nodes']).'）' : '';
            $failedNote = ! empty($result['failed']) ? '；推送失败：'.implode('+', $result['failed']) : '';
            $this->successMessage = $result['pushed']
                ? "已重新同步 {$result['instances']} 条静态线路到中转节点{$nodes}{$failedNote}"
                : '未同步：'.$result['message'];
            $this->refreshRelayStatus();
        } catch (\Throwable $e) {
            $this->error = '同步失败：'.$e->getMessage();
        }
    }

    public function restartRelayNode(string $label): void
    {
        $this->successMessage = '';
        $this->error = '';
        try {
            app(\App\Services\NodeRelayService::class)->restartNode($label);
            $this->successMessage = "已重启 {$label} 中转";
            $this->refreshRelayStatus();
        } catch (\Throwable $e) {
            $this->error = "重启 {$label} 失败：".$e->getMessage();
        }
    }

    public function viewRelayLog(string $label): void
    {
        $this->relayLogNode = $label;
        try {
            $this->relayLog = app(\App\Services\NodeRelayService::class)->nodeErrorLog($label);
        } catch (\Throwable $e) {
            $this->relayLog = '读取失败：'.$e->getMessage();
        }
    }

    public function closeRelayLog(): void
    {
        $this->relayLog = '';
        $this->relayLogNode = '';
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $serverId): void
    {
        $server = Server::findOrFail($serverId);

        $this->editingId = $server->id;
        $this->name = $server->name;
        $this->group_id = $server->group_id;
        $this->host = $server->host;
        $this->port = $server->port;
        $this->protocol = $server->protocol;
        $this->method = $server->method;
        $this->rate = (string) $server->rate;
        $this->show = $server->show;

        $config = $server->config ?? [];
        $this->reality_public_key = $config['public_key'] ?? '';
        $this->reality_private_key = $config['private_key'] ?? '';
        $this->reality_short_id = $config['short_id'] ?? '';
        $this->reality_sni = $config['sni'] ?? '';
        $this->reality_flow = $config['flow'] ?? 'xtls-rprx-vision';

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->error = '';

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'group_id' => ['required', 'integer', 'min:1'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', 'in:shadowsocks,vmess,vless'],
            'rate' => ['required', 'numeric', 'min:0'],
            'show' => ['boolean'],
        ]);

        if ($this->protocol === 'vless') {
            $this->validate([
                'reality_public_key' => ['required', 'string'],
                'reality_short_id' => ['required', 'string'],
                'reality_sni' => ['required', 'string'],
            ]);
            $data['method'] = 'none';
            $data['config'] = [
                'public_key' => $this->reality_public_key,
                'private_key' => $this->reality_private_key,
                'short_id' => $this->reality_short_id,
                'sni' => $this->reality_sni,
                'flow' => $this->reality_flow ?: 'xtls-rprx-vision',
            ];
        } else {
            $data['method'] = $this->method ?: 'aes-256-gcm';
            $data['config'] = null;
        }

        if ($this->editingId) {
            Server::findOrFail($this->editingId)->update($data);
            $this->successMessage = '节点已更新';
        } else {
            Server::create($data);
            $this->successMessage = '节点已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function delete(int $serverId): void
    {
        $this->successMessage = '';
        Server::findOrFail($serverId)->delete();
        $this->successMessage = '节点已删除';
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->group_id = 1;
        $this->host = '';
        $this->port = 443;
        $this->protocol = 'shadowsocks';
        $this->method = 'aes-256-gcm';
        $this->rate = '1.00';
        $this->show = true;
        $this->reality_public_key = '';
        $this->reality_private_key = '';
        $this->reality_short_id = '';
        $this->reality_sni = '';
        $this->reality_flow = 'xtls-rprx-vision';
    }

    public function with(): array
    {
        return [
            'servers' => Server::orderBy('group_id')->orderBy('name')->paginate(20),
            'groups' => ServerGroup::orderBy('id')->get(),
        ];
    }
};
?>

<div class="space-y-6" wire:init="refreshRelayStatus">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">服务器管理</h1>
        <div class="flex items-center gap-2">
            <button wire:click="toggleGuide" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">
                {{ $showGuide ? '收起指南' : '如何添加新节点？' }}
            </button>
            @unless ($showForm)
                <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">新建节点</button>
            @endunless
        </div>
    </div>

    @if ($showGuide)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 text-sm">
            <div>
                <h2 class="font-medium text-gray-900 dark:text-gray-100">添加一台新节点服务器</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    在这个页面「新建节点」只完成<strong>面板这一半</strong>（建记录、生成令牌）。节点不会自己找上门——
                    还要在新服务器上装 V2bX 并让它连回面板，它才会出现在「节点监控」里。
                </p>
            </div>

            <ol class="space-y-3 text-gray-700 dark:text-gray-300 list-decimal list-inside">
                <li>
                    <strong>点「新建节点」</strong>：填名称、地址（新服务器 IP）、端口（如 8444），协议选
                    <span class="font-medium">VLESS</span>，再点表单里的
                    <span class="font-medium">「生成 Reality 密钥」</span>——公钥/私钥/short_id 会自动填好，
                    SNI 保持默认即可。保存。
                </li>
                <li>
                    <strong>点该节点的「接入配置」</strong>：会生成一整段安装脚本，
                    <span class="font-medium">NodeID 和令牌都已填好</span>，复制。
                </li>
                <li>
                    <strong>SSH 进新服务器，整段粘贴执行。</strong>
                    脚本会写好全部配置、启动 V2bX、并打印自检结果。
                </li>
                <li>
                    <strong>放行防火墙端口</strong>，然后回「节点监控」——60 秒内该节点应显示在线。
                </li>
            </ol>

            <div class="border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-500/10 rounded-lg p-3 text-amber-900 dark:text-amber-200">
                <p class="font-medium">⚠️ 别照抄老节点的 config.json</p>
                <p class="mt-1">
                    老配置里的 <code class="bg-amber-100 dark:bg-amber-900/40 px-1 rounded">ApiHost: http://IP:8080</code>
                    <strong>已经连不上了</strong>——面板的 8080 明文端口于 2026-07-18 起只对本机开放。
                    用上面生成的脚本就不会踩这个坑（它用的是 https 域名）。
                </p>
            </div>

            <div class="text-gray-500 dark:text-gray-400 space-y-1 text-xs">
                <p><strong>排查</strong>：节点不上线，先在节点上看 <code>journalctl -u V2bX -n 20</code>。
                   有 <em>“Added N new users”</em> = 已连上面板；没有则多半是令牌或 NodeID 不对（重新复制脚本即可）。</p>
                <p><strong>一台机器可以跑多个节点</strong>：每个节点一份独立配置。现有两台老节点机各跑着两个服务
                   （<code>V2bX.service</code> 与 <code>V2bX-panel.service</code>，配置文件不同）——排查老节点时别只看一个。</p>
                <p><strong>静态 IP 中转</strong>是另一套（下方「静态IP 中转节点」区），不走这个流程。</p>
            </div>
        </div>
    @endif

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    @if ($showForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑节点' : '新建节点' }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">节点名称</label>
                    <input type="text" wire:model="name" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">权限组</label>
                    <select wire:model="group_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">要跟套餐的权限组一致，用户才能看到这个节点</p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">主机地址</label>
                    <input type="text" wire:model="host" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('host') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">端口</label>
                    <input type="number" wire:model="port" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('port') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">倍率</label>
                    <input type="text" wire:model="rate" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('rate') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">协议</label>
                    <select wire:model.live="protocol" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="shadowsocks">Shadowsocks</option>
                        <option value="vmess">VMess</option>
                        <option value="vless">VLESS + Reality</option>
                    </select>
                    @error('protocol') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                @if ($protocol === 'shadowsocks')
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">加密方式</label>
                        <input type="text" wire:model="method" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" placeholder="aes-256-gcm">
                    </div>
                @endif
            </div>

            @if ($protocol === 'vless')
                <div class="border border-gray-200 dark:border-gray-800 rounded-lg p-4 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Reality 参数</span>
                    <button type="button" wire:click="generateRealityKeys" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">
                        生成 Reality 密钥
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Reality 公钥（客户端用）</label>
                        <input type="text" wire:model="reality_public_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                        @error('reality_public_key') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Short ID</label>
                        <input type="text" wire:model="reality_short_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                        @error('reality_short_id') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">伪装域名 (SNI)</label>
                        <input type="text" wire:model="reality_sni" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" placeholder="addons.mozilla.org">
                        @error('reality_sni') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Flow</label>
                        <input type="text" wire:model="reality_flow" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm" placeholder="xtls-rprx-vision">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Reality 私钥（仅 V2bX 节点同步需要）</label>
                        <input type="password" wire:model="reality_private_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    </div>
                    <p class="col-span-2 text-xs text-gray-400 dark:text-gray-500">节点跑 V2bX（拉本面板配置自动生成 Xray 配置）时，私钥必须填；私钥不会出现在任何用户可见的订阅链接里。点上方「生成 Reality 密钥」可一键填好公钥/私钥/short_id。</p>
                </div>
                </div>
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="show">
                启用（对用户可见）
            </label>

            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">名称</th>
                <th class="text-left px-4 py-2">分组</th>
                <th class="text-left px-4 py-2">地址</th>
                <th class="text-left px-4 py-2">协议</th>
                <th class="text-left px-4 py-2">倍率</th>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($servers as $server)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $server->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $groups->firstWhere('id', $server->group_id)?->name ?? $server->group_id }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $server->host }}:{{ $server->port }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 uppercase text-xs">{{ $server->protocol }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $server->rate }}x</td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                        @if ($server->show)
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">启用中</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 rounded-full px-2 py-1">已停用</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="showNodeConfig({{ $server->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">接入配置</button>
                        <button wire:click="resetToken({{ $server->id }})" wire:confirm="重置后旧令牌立即失效，该节点在你用新配置更新并重启 V2bX 之前无法与面板同步（已连接的客户不会掉线）。确定重置？" class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300">重置令牌</button>
                        <button wire:click="edit({{ $server->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $server->id }})" wire:confirm="确定删除这个节点？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>

                @if ($configForNode === $server->id)
                    <tr class="border-t border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/30">
                        <td colspan="7" class="px-4 py-3">
                            <div class="text-xs text-gray-600 dark:text-gray-400 mb-2">
                                <strong>SSH 进新服务器（root），整段复制粘贴执行即可</strong>——配置、启动、自检都在里面。
                                <span class="text-amber-700 dark:text-amber-400">含此节点的接入令牌，请勿外传。</span>
                            </div>
                            <pre class="bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto text-xs leading-relaxed select-all"><code>{{ $this->nodeInstallScript($server) }}</code></pre>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Reality 密钥由面板通过 <code>/UniProxy/config</code> 自动下发给节点，不在这份配置里——
                                所以换密钥只需在面板改，节点无需重配。
                            </p>
                        </td>
                    </tr>
                @endif
            @endforeach

        </tbody>
    </table>

    {{ $servers->links() }}

    {{-- ══════════ 静态IP 中转节点（独立于上面的 V2bX 共享节点）══════════ --}}
    @php
        $allRelayNodes = \App\Models\RelayNode::orderBy('priority')->orderBy('id')->get();
        $statusByLabel = collect($relayStatuses)->keyBy('label');
        $relayLines = collect($relayStatuses)->first()['lines'] ?? null;
    @endphp
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-800">
            <div>
                <h2 class="text-sm font-medium text-gray-800 dark:text-gray-200">静态IP 中转节点</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">独享静态IP客户经此中转到 onip 出口（8446 独立 xray 服务）。客户端按优先级故障转移，同一 IP 不变。</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <button type="button" wire:click="refreshRelayStatus" wire:loading.attr="disabled" class="text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                    <span wire:loading.remove wire:target="refreshRelayStatus,syncRelay">刷新状态</span>
                    <span wire:loading wire:target="refreshRelayStatus,syncRelay">探测中…</span>
                </button>
                <button type="button" wire:click="syncRelay" wire:confirm="将当前配置 SSH 推送到所有启用的中转节点并 reload。确定？" class="text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">重新同步全部</button>
                <button type="button" wire:click="$toggle('showRelayConfig')" class="text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">{{ $showRelayConfig ? '收起 Reality' : 'Reality 身份' }}</button>
                <button type="button" wire:click="newRelayNode" class="bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">+ 新增中转节点</button>
            </div>
        </div>

        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">优先级</th>
                    <th class="text-left px-4 py-2">名称</th>
                    <th class="text-left px-4 py-2">对外地址</th>
                    <th class="text-left px-4 py-2">SSH</th>
                    <th class="text-left px-4 py-2">承载</th>
                    <th class="text-left px-4 py-2">状态</th>
                    <th class="text-right px-4 py-2">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($allRelayNodes as $n)
                    @php($st = $statusByLabel[$n->label] ?? null)
                    <tr class="border-t border-gray-100 dark:border-gray-800 {{ $n->enabled ? '' : 'opacity-55' }}">
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $n->priority }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $n->label }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $n->node_host }}:{{ $n->node_port }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $n->ssh_user }}@{{ $n->ssh_host }}:{{ $n->ssh_port }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs">{{ $relayLines !== null ? $relayLines.' 线路' : '—' }}</td>
                        <td class="px-4 py-2">
                            @if (! $n->enabled)
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">停用</span>
                            @elseif ($st === null)
                                <span class="text-xs text-gray-400 dark:text-gray-500">点「刷新状态」探测</span>
                            @elseif ($st['reachable'] && $st['active'] && $st['listening'])
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">🟢 在线</span>
                            @elseif (! $st['reachable'])
                                <span class="text-xs bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-full px-2 py-1">🔴 离线</span>
                            @else
                                <span class="text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 rounded-full px-2 py-1">⚠️ {{ ! $st['active'] ? '服务未运行' : '未监听' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                            <button type="button" wire:click="showRelayInstall({{ $n->id }})" class="text-xs text-teal-600 dark:text-teal-400 hover:underline">接入脚本</button>
                            <button type="button" wire:click="editRelayNode({{ $n->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:underline">编辑</button>
                            <button type="button" wire:click="toggleRelayNode({{ $n->id }})" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">{{ $n->enabled ? '停用' : '启用' }}</button>
                            @if ($n->enabled)
                                <button type="button" wire:click="restartRelayNode('{{ $n->label }}')" wire:confirm="确定重启「{{ $n->label }}」的中转服务？" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">重启</button>
                                <button type="button" wire:click="viewRelayLog('{{ $n->label }}')" class="text-xs text-gray-600 dark:text-gray-400 hover:underline">日志</button>
                            @endif
                            <button type="button" wire:click="deleteRelayNode({{ $n->id }})" wire:confirm="删除中转节点「{{ $n->label }}」？下次同步不再推送它。" class="text-xs text-red-600 dark:text-red-400 hover:underline">删除</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有中转节点。点右上「+ 新增中转节点」添加第一个。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 中转错误日志（点某台的「日志」后显示）--}}
    @if ($relayLog !== '')
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $relayLogNode }}中转 错误日志（最近 20 行）</span>
                <button type="button" wire:click="closeRelayLog" class="text-xs text-gray-400 hover:text-gray-600">收起</button>
            </div>
            <pre class="text-xs bg-gray-900 text-gray-100 rounded-lg p-2 overflow-x-auto whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $relayLog }}</pre>
        </div>
    @endif

    {{-- 新增 / 编辑中转节点表单（点「新增」或「编辑」后独立显示）--}}
    @if ($showNodeForm)
        <div class="border border-teal-200 dark:border-teal-800 rounded-xl p-5 space-y-3 bg-teal-50/40 dark:bg-teal-500/5">
            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $editingNodeId ? '编辑中转节点' : '新增中转节点' }}</div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">名称</label>
                    <input type="text" wire:model="nf_label" placeholder="主力 / 备用 / 节点3" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('nf_label') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">优先级（小=先试）</label>
                    <input type="number" wire:model="nf_priority" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 pb-2">
                        <input type="checkbox" wire:model="nf_enabled"> 启用
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">对外地址（客户连的）</label>
                    <input type="text" wire:model="nf_node_host" placeholder="203.0.113.10" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    @error('nf_node_host') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">中转端口</label>
                    <input type="number" wire:model="nf_node_port" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SSH 地址</label>
                    <input type="text" wire:model="nf_ssh_host" placeholder="203.0.113.10" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    @error('nf_ssh_host') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SSH 端口</label>
                    <input type="number" wire:model="nf_ssh_port" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SSH 用户</label>
                    <input type="text" wire:model="nf_ssh_user" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SSH 密码{{ $editingNodeId ? '（留空=不改）' : '（留空走密钥）' }}</label>
                    <input type="password" wire:model="nf_ssh_password" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">或 SSH 私钥路径（面板机上）</label>
                    <input type="text" wire:model="nf_ssh_key_path" placeholder="/var/www/panel/storage/app/relay_ssh_key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" wire:click="saveRelayNode" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存节点</button>
                <button type="button" wire:click="$set('showNodeForm', false)" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500">保存后到该节点点「接入脚本」在新中转机装好服务，再「重新同步全部」把配置推过去。</p>
        </div>
    @endif

    {{-- 一键接入脚本（点某节点「接入脚本」后显示）--}}
    @if ($relayInstallScript !== '')
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm text-gray-700 dark:text-gray-300">中转「{{ $relayInstallLabel }}」一键接入脚本（在新中转机 root 粘贴执行）</span>
                <button type="button" wire:click="closeRelayInstall" class="text-xs text-gray-400 hover:text-gray-600">收起</button>
            </div>
            <pre class="text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap max-h-96 overflow-y-auto">{{ $relayInstallScript }}</pre>
        </div>
    @endif

    {{-- Reality 身份（所有中转节点共用；点「Reality 身份」展开）--}}
    @if ($showRelayConfig)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 space-y-3 bg-white dark:bg-gray-900">
            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">Reality 身份（所有中转节点共用）</div>
            <p class="text-xs text-gray-400 dark:text-gray-500">每个中转节点必须用同一套 Reality 密钥，客户 uuid 才能在节点间无缝切换。第一次配好后别再改。</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SNI</label>
                    <input type="text" wire:model="relay_reality_sni" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Short ID</label>
                    <input type="text" wire:model="relay_reality_short_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">公钥 / 私钥</label>
                <input type="text" wire:model="relay_reality_public_key" placeholder="public key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono mb-2">
                <input type="password" wire:model="relay_reality_private_key" placeholder="private key" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
            </div>
            <div class="flex gap-2">
                <button type="button" wire:click="saveRealityIdentity" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">保存 Reality 身份</button>
                <button type="button" wire:click="generateRelayKeypair" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">生成新密钥对</button>
            </div>
            <p class="text-xs text-amber-600 dark:text-amber-400">改密钥后所有中转节点都要重新同步，否则老客户连不上。</p>
        </div>
    @endif
</div>
