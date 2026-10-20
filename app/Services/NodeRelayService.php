<?php

namespace App\Services;

use App\Models\RelayNode;
use App\Models\RoutingRule;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Builds the 主力-node xray config for static-ISP "relay" delivery.
 *
 * onip's residential SOCKS5 proxies only accept connections from the
 * whitelisted 主力 node, so a customer can't connect to the raw onip IP
 * directly. Instead every active static-ISP instance becomes a user on a
 * single VLESS-Reality relay inbound on 主力, and xray routes that user's
 * traffic to their own onip SOCKS5 outbound (the dedicated residential IP).
 *
 * This service is the pure, side-effect-free half: it turns the set of
 * active onip instances into the three config fragments xray needs —
 * inbound clients, per-user route rules, and per-instance onip outbounds —
 * exactly matching the shapes the old Xboard provision worker wrote
 * (verified against the live node). The SSH push + reload lives in
 * pushConfig() (kept thin so the generation is what gets unit-tested).
 */
class NodeRelayService
{
    /**
     * The email/tag prefix that ties a relay inbound client to its route
     * rule. xray routes on the `user` field, which for a V2bX/panel client
     * is "<node-name>|<uuid>"; we mirror that so a rule can target exactly
     * one instance.
     */
    public const RELAY_NODE_NAME = 'static-relay';

    /**
     * ByteDance / Douyin domains where onip's remote SOCKS DNS path resets.
     * These stay on the customer's own ISP outbound, but the relay resolves
     * them to IPv4 first so domestic Douyin assets can connect reliably.
     */
    private const BYTEDANCE_IPV4_DOMAINS = [
        'domain:amemv.com',
        'domain:bytecdn.cn',
        'domain:byteimg.com',
        'domain:bytedance.com',
        'domain:bytedance.net',
        'domain:bytednsdoc.com',
        'domain:bytescm.com',
        'domain:douyin.com',
        'domain:douyincdn.com',
        'domain:douyinpic.com',
        'domain:douyinstatic.com',
        'domain:ixigua.com',
        'domain:pstatp.com',
        'domain:snssdk.com',
        'domain:toutiao.com',
        'domain:toutiaoimg.com',
        'domain:toutiaostatic.com',
        'domain:zijieapi.com',
    ];

    /**
     * Outbound tag for an instance — dashes to underscores so it is a valid
     * xray tag (matches the onip_XXXX tags already on the node).
     */
    public function outboundTag(string $uuid): string
    {
        return 'onip_'.str_replace('-', '_', $uuid);
    }

    private function ipv4OutboundTag(string $tag): string
    {
        return $tag.'_ipv4';
    }

    public function userField(string $uuid): string
    {
        return self::RELAY_NODE_NAME.'|'.$uuid;
    }

    /**
     * Active onip static-ISP instances that should have a live relay.
     *
     * @return Collection<int, SubscriptionInstance>
     */
    public function activeOnipInstances(): Collection
    {
        return SubscriptionInstance::query()
            ->where('status', SubscriptionInstance::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', now());
            })
            ->get()
            ->filter(fn (SubscriptionInstance $i) => ($i->meta['provider'] ?? null) === 'onip'
                && ! empty($i->meta['ip']) && ! empty($i->meta['port']))
            ->values();
    }

    /**
     * Active onip instances across the central 主站 AND every 分站. The relay
     * (主力:8446) is shared infrastructure serving all sites; each instance's
     * uuid/token is globally unique so their relay clients never collide. Only
     * loaded attributes (uuid/token/meta) are read afterwards, so the tenant
     * models stay usable once tenancy has ended.
     */
    public function allActiveOnipInstances(): Collection
    {
        $all = $this->activeOnipInstances()->all();

        foreach (Tenant::all() as $tenant) {
            $collected = [];
            $tenant->run(function () use (&$collected) {
                $collected = $this->activeOnipInstances()->all();
            });
            $all = array_merge($all, $collected);
        }

        return collect($all)->values();
    }

    /**
     * Turn a set of instances into the three xray config fragments.
     *
     * @param  iterable<SubscriptionInstance>  $instances
     * @return array{clients: list<array>, routes: list<array>, outbounds: list<array>}
     */
    public function generateConfig(iterable $instances): array
    {
        $clients = [];
        $routes = [];
        $outbounds = [];

        foreach ($instances as $instance) {
            $meta = $instance->meta ?? [];
            $uuid = $instance->uuid;
            $tag = $this->outboundTag($uuid);

            // The client email is the instance's report token, so xray's
            // per-user stats key (user>>>TOKEN>>>traffic>>>…) maps straight back
            // to the instance via /api/traffic/report's token credential.
            $email = $instance->token;

            $clients[] = [
                'id' => $uuid,
                'email' => $email,
                'flow' => 'xtls-rprx-vision',
            ];

            $routes[] = [
                'type' => 'field',
                'user' => [$email],
                'outboundTag' => $tag,
            ];

            $outbounds[] = [
                'tag' => $tag,
                'protocol' => 'socks',
                'settings' => [
                    'servers' => [[
                        'address' => $meta['ip'],
                        'port' => (int) $meta['port'],
                        'users' => [[
                            'user' => (string) ($meta['username'] ?? ''),
                            'pass' => (string) ($meta['password'] ?? ''),
                        ]],
                    ]],
                ],
            ];
        }

        return ['clients' => $clients, 'routes' => $routes, 'outbounds' => $outbounds];
    }

    /**
     * The reality identity + defaults EVERY relay node inherits. Kept shared
     * in Settings (not per-node) because all relay nodes must present the same
     * reality keys / sni, or a customer's uuid can't fail over between them.
     *
     * @return array<string,mixed>
     */
    private function sharedRealitySettings(): array
    {
        return [
            'sni' => Setting::get('relay_reality_sni', 'addons.mozilla.org'),
            'private_key' => Setting::get('relay_reality_private_key', ''),
            'public_key' => Setting::get('relay_reality_public_key', ''),
            'short_id' => Setting::get('relay_reality_short_id', ''),
        ];
    }

    /**
     * Every enabled relay node as a flat config array (each merged with the
     * shared reality identity), ordered by priority — the client tries them in
     * this order. Replaces the old fixed 主力 + optional 备用 pair; there can
     * now be any number of relay nodes (see the relay_nodes table).
     *
     * The relay runs as its OWN plain-xray process (NOT inside V2bX — V2bX's
     * dispatcher rejects inbounds it didn't create: "get limiter <tag>: not
     * found"). The panel owns each node's config file and reloads its service.
     *
     * @return list<array<string,mixed>>
     */
    public function relayNodes(): array
    {
        $shared = $this->sharedRealitySettings();

        return RelayNode::where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (RelayNode $n) => array_merge($shared, [
                'id' => $n->id,
                'label' => $n->label,
                'host' => (string) $n->node_host,
                'port' => (int) $n->node_port,
                'config_path' => (string) $n->config_path,
                'reload_cmd' => (string) $n->reload_cmd,
                'ssh_host' => (string) $n->ssh_host,
                'ssh_port' => (int) $n->ssh_port,
                'ssh_user' => (string) $n->ssh_user,
                'ssh_password' => (string) $n->ssh_password,
                'ssh_key_path' => (string) $n->ssh_key_path,
            ]))
            ->all();
    }

    /**
     * The first (highest-priority) relay node, or the shared reality identity
     * with empty node fields when none exist yet. Only a fallback default for
     * the internal SSH/build helpers, which are always called with an explicit
     * node in the real flow (sync iterates relayTargets()).
     *
     * @return array<string,mixed>
     */
    public function relaySettings(): array
    {
        return $this->relayNodes()[0] ?? array_merge($this->sharedRealitySettings(), [
            'label' => '主力',
            'host' => '',
            'port' => 8446,
            'config_path' => '/etc/xray-relay/config.json',
            'reload_cmd' => 'systemctl restart xray-relay',
            'ssh_host' => '',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_password' => '',
            'ssh_key_path' => '',
        ]);
    }

    /**
     * Every relay node the panel can push config to (has SSH creds + the
     * shared reality identity is set). One config, pushed to each.
     *
     * @return list<array<string,mixed>>
     */
    public function relayTargets(): array
    {
        return array_values(array_filter($this->relayNodes(), fn (array $s) => $this->nodePushable($s)));
    }

    private function nodePushable(array $s): bool
    {
        return $s['ssh_host'] !== '' && ($s['ssh_password'] !== '' || $s['ssh_key_path'] !== '')
            && $s['private_key'] !== '' && $s['short_id'] !== '';
    }

    public function isConfigured(): bool
    {
        return $this->relayTargets() !== [];
    }

    /**
     * True once at least one relay node can hand customers a working endpoint
     * (public reality params + a customer-facing host).
     */
    public function canDeliver(): bool
    {
        return $this->deliverableNodes() !== [];
    }

    /**
     * The node data a customer's client should use for an onip static-ISP
     * instance: a VLESS-Reality endpoint on the 主力 relay (uuid = the
     * instance's), NOT the raw onip IP (which onip's whitelist rejects).
     * Returns null when the relay isn't configured (caller then falls back).
     *
     * @return array{name:string,protocol:string,host:string,port:int,credential:string,method:?string,config:array}|null
     */
    public function customerNode(SubscriptionInstance $instance, string $prefix, string $suffix = ''): ?array
    {
        return $this->customerNodes($instance, $prefix, $suffix)[0] ?? null;
    }

    /**
     * Every relay endpoint a customer's client should try for one onip
     * instance: the 主力 relay plus the 备用 relay when it's deliverable —
     * both VLESS-Reality on the SAME uuid routing to the SAME onip IP, so a
     * client fails over between them without the static IP changing. Each node
     * name reads "{prefix} · 主力/备用 · {suffix}", e.g. "🇺🇸 美国 · 主力 · 1.2.3.4".
     *
     * @return list<array<string,mixed>>
     */
    public function customerNodes(SubscriptionInstance $instance, string $prefix, string $suffix = ''): array
    {
        $nodes = [];
        foreach ($this->deliverableNodes() as $s) {
            $label = $s['label'] ?? '主力';
            $name = $prefix.' · '.$label.($suffix !== '' ? ' · '.$suffix : '');
            $nodes[] = [
                'name' => $name,
                'protocol' => 'vless',
                'host' => $s['host'],
                'port' => $s['port'],
                'credential' => $instance->uuid,
                'method' => null,
                'config' => [
                    'sni' => $s['sni'],
                    'public_key' => $s['public_key'],
                    'short_id' => $s['short_id'],
                    'flow' => 'xtls-rprx-vision',
                ],
            ];
        }

        return $nodes;
    }

    /**
     * Relay nodes that can hand a customer a working endpoint right now (public
     * reality params + a customer-facing host), in client-preference order.
     *
     * @return list<array<string,mixed>>
     */
    private function deliverableNodes(): array
    {
        return array_values(array_filter($this->relayNodes(), fn (array $s) => $this->nodeDeliverable($s)));
    }

    private function nodeDeliverable(array $s): bool
    {
        return $s['host'] !== '' && $s['public_key'] !== '' && $s['short_id'] !== '';
    }

    /**
     * Render the complete standalone-xray config (as a JSON string) for the
     * relay process: a VLESS-Reality inbound the customers connect to, the
     * outbound set (direct default + block + one onip socks per instance),
     * and per-user routing (each user → their onip outbound; everything else
     * direct). Deliberately no per-user stats/policy, so xray's dispatcher
     * needs no limiter (which is exactly what broke when this ran inside
     * V2bX).
     *
     * @param  array{clients:list<array>,routes:list<array>,outbounds:list<array>}  $config
     * @return array{config:string}
     */
    /**
     * Admin routing rules as xray routing entries for the relay: block →
     * blackhole, direct → egress from the relay node itself (bypassing onip).
     * "proxy" is skipped — on the relay the default is already the per-user
     * onip route, so there's no distinct global "proxy" outbound to target.
     *
     * @return list<array<string,mixed>>
     */
    protected function globalRoutingRules(): array
    {
        $rules = [];
        foreach (RoutingRule::activeFor(RoutingRule::SCOPE_ISP) as $rule) {
            $tag = match ($rule->action) {
                RoutingRule::ACTION_BLOCK => 'block',
                RoutingRule::ACTION_DIRECT => 'direct',
                default => null, // proxy: no distinct global target on the relay
            };
            if ($tag === null) {
                continue;
            }
            $field = $rule->isIpRule() ? 'ip' : 'domain';
            $rules[] = ['type' => 'field', $field => [$rule->matchToken()], 'outboundTag' => $tag];
        }

        return $rules;
    }

    /**
     * Per-user ByteDance routes must run before the normal per-user route.
     *
     * @param  list<array<string,mixed>>  $routes
     * @return list<array<string,mixed>>
     */
    protected function byteDanceIpv4Routes(array $routes): array
    {
        $byteDanceRoutes = [];

        foreach ($routes as $route) {
            if (! isset($route['user'], $route['outboundTag'])) {
                continue;
            }

            $byteDanceRoutes[] = [
                'type' => 'field',
                'user' => $route['user'],
                'domain' => self::BYTEDANCE_IPV4_DOMAINS,
                'outboundTag' => $this->ipv4OutboundTag($route['outboundTag']),
            ];
        }

        return $byteDanceRoutes;
    }

    /**
     * Add a paired IPv4-resolving SOCKS outbound for each onip outbound.
     *
     * @param  list<array<string,mixed>>  $outbounds
     * @return list<array<string,mixed>>
     */
    protected function withByteDanceIpv4Outbounds(array $outbounds): array
    {
        $withIpv4 = [];

        foreach ($outbounds as $outbound) {
            $withIpv4[] = $outbound;

            if (($outbound['protocol'] ?? null) !== 'socks' || ! isset($outbound['tag'])) {
                continue;
            }

            $ipv4Outbound = $outbound;
            $ipv4Outbound['tag'] = $this->ipv4OutboundTag($outbound['tag']);
            $ipv4Outbound['targetStrategy'] = 'UseIPv4';

            $withIpv4[] = $ipv4Outbound;
        }

        return $withIpv4;
    }

    public function buildNodeFiles(array $config, ?array $s = null): array
    {
        $s ??= $this->relaySettings();

        $inbound = [
            'tag' => self::RELAY_NODE_NAME,
            'listen' => '0.0.0.0',
            'port' => $s['port'],
            'protocol' => 'vless',
            'settings' => ['clients' => $config['clients'], 'decryption' => 'none'],
            'streamSettings' => [
                'network' => 'tcp',
                'security' => 'reality',
                'realitySettings' => [
                    'show' => false,
                    'dest' => $s['sni'].':443',
                    'xver' => 0,
                    'serverNames' => [$s['sni']],
                    'privateKey' => $s['private_key'],
                    'shortIds' => [$s['short_id']],
                ],
            ],
        ];

        // direct MUST be first = the default outbound, so any traffic not
        // matched by a per-user rule egresses directly from the node.
        $outbounds = array_merge(
            [
                ['tag' => 'direct', 'protocol' => 'freedom', 'settings' => ['domainStrategy' => 'UseIPv4']],
                ['tag' => 'block', 'protocol' => 'blackhole', 'settings' => new \stdClass],
            ],
            $this->withByteDanceIpv4Outbounds($config['outbounds']),
        );

        // Local-only gRPC stats API + per-user counters, so a reporting agent
        // on the node can read each user's uplink/downlink and post it to the
        // panel. Bound to 127.0.0.1 only — never exposed off the box.
        $apiPort = (int) ($s['stats_api_port'] ?? Setting::get('relay_stats_api_port', 10085));
        $apiInbound = [
            'tag' => 'api',
            'listen' => '127.0.0.1',
            'port' => $apiPort,
            'protocol' => 'dokodemo-door',
            'settings' => ['address' => '127.0.0.1'],
        ];

        $rules = array_merge(
            // API traffic must be routed to the api handler before anything else.
            [['type' => 'field', 'inboundTag' => ['api'], 'outboundTag' => 'api']],
            [['type' => 'field', 'protocol' => ['bittorrent'], 'outboundTag' => 'block']],
            // The relay boxes only have IPv4 egress. Block IPv6 literals before
            // per-user onip routing so customers get a fast failure instead of
            // repeated upstream dial timeouts.
            [['type' => 'field', 'ip' => ['::/0'], 'outboundTag' => 'block']],
            // Admin routing rules (block/direct) run before the per-user onip
            // routes, so e.g. "block youtube" applies even to relayed users.
            $this->globalRoutingRules(),
            $this->byteDanceIpv4Routes($config['routes']),
            $config['routes'],
            [['type' => 'field', 'inboundTag' => [self::RELAY_NODE_NAME], 'outboundTag' => 'direct']],
        );

        $xray = [
            'log' => ['loglevel' => 'warning', 'error' => '/etc/xray-relay/error.log'],
            'api' => ['tag' => 'api', 'services' => ['StatsService']],
            'stats' => new \stdClass,
            // (object) cast forces `"levels":{"0":…}` — a PHP array ['0'=>…]
            // json-encodes as an array [...], which xray rejects (levels must
            // be a map keyed by level number).
            'policy' => ['levels' => (object) ['0' => ['statsUserUplink' => true, 'statsUserDownlink' => true]]],
            'inbounds' => [$apiInbound, $inbound],
            'outbounds' => $outbounds,
            'routing' => ['domainStrategy' => 'AsIs', 'rules' => $rules],
        ];

        return ['config' => json_encode($xray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /**
     * Regenerate the relay config from current DB state and push it to every
     * relay node, then reload each. Full-state (idempotent): every call writes
     * the complete config for all active onip instances, so provision / renew
     * / change-ip / expiry all just call sync().
     *
     * Each node is pushed INDEPENDENTLY: one node being unreachable (e.g. 主力
     * down for a bandwidth suspension) must NOT stop the config reaching the
     * others. Before this was a plain loop that threw on the first failed push,
     * so while 主力 was down every new provision/renewal silently failed to
     * update 备用 — new customers couldn't use the 备用 line at all even though
     * old ones (already in 备用's last-good config) could.
     *
     * Self-healing: a node is only pushed+reloaded when its config actually
     * DIFFERS from what was last successfully pushed to it (per-node content
     * hash). A node that failed while down keeps a stale/absent hash, so it is
     * retried on every call until it succeeds — which is what lets the
     * scheduled relay:sync command auto-repair 主力 the moment its SSH is
     * reachable again, WITHOUT reloading (and dropping the live connections
     * on) any node whose config is already current. Pass $force to re-push
     * every node regardless (operator "重新同步").
     *
     * @return array{pushed:bool,instances:int,nodes:array,failed:array,unchanged:array,message:string}
     */
    public function sync(bool $force = false): array
    {
        $targets = $this->relayTargets();
        if ($targets === []) {
            return ['pushed' => false, 'instances' => 0, 'nodes' => [], 'failed' => [], 'unchanged' => [], 'message' => '中转节点未配置（SSH / Reality 参数缺失）'];
        }

        // Aggregate onip lines from 主站 + all 分站 into one relay config.
        $instances = $this->allActiveOnipInstances();
        $config = $this->generateConfig($instances);

        $pushed = [];
        $failed = [];
        $unchanged = [];
        foreach ($targets as $target) {
            $label = $target['label'] ?? '主力';
            $files = $this->buildNodeFiles($config, $target);
            $hash = hash('sha256', $files['config']);
            $hashKey = 'relay_synced_hash:'.($target['ssh_host'] ?: $label);

            // Skip a node whose live config already matches what we last put
            // there — no SSH, no reload — so this is safe to run on a timer.
            if (! $force && Setting::get($hashKey) === $hash) {
                $unchanged[] = $label;

                continue;
            }

            try {
                $this->pushFiles($files, $target);
                Setting::set($hashKey, $hash); // only on a confirmed successful push+reload
                $pushed[] = $label;
            } catch (\Throwable $e) {
                $failed[] = $label;
                Log::warning('relay sync: push to one node failed, continuing to others', [
                    'node' => $label,
                    'ssh_host' => $target['ssh_host'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Only stamp last-sync when at least one node actually took the config.
        if ($pushed !== []) {
            Setting::set('relay_last_sync_at', now()->toIso8601String());
        }

        return [
            'pushed' => $pushed !== [],
            'instances' => $instances->count(),
            'nodes' => $pushed,
            'failed' => $failed,
            'unchanged' => $unchanged,
            'message' => $failed === [] ? 'ok' : ('部分中转节点推送失败：'.implode('、', $failed).'（其余已更新）'),
        ];
    }

    /**
     * Write the config to one relay node over SSH and reload it. Isolated so
     * tests can stub it; the content it pushes is what buildNodeFiles() (fully
     * unit-tested) produces. $s selects which relay node (主力 / 备用).
     */
    protected function pushFiles(array $files, ?array $s = null): void
    {
        $s ??= $this->relaySettings();

        // Content over stdin (not argv) so onip creds / reality keys never
        // land in the node's process list.
        $this->runSsh('cat > '.escapeshellarg($s['config_path']), $files['config'], $s);
        $this->runSsh($s['reload_cmd'], null, $s);
    }

    protected function runSsh(string $remoteCommand, ?string $stdin = null, ?array $s = null): void
    {
        $s ??= $this->relaySettings();
        [$cmd, $env] = $this->buildSshCommand($s, $remoteCommand);

        $process = new Process($cmd, null, $env, $stdin);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('relay SSH push failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Run a read-only command on a relay node and return its stdout (empty on
     * failure/unreachable — used by the status console, so it never throws).
     */
    protected function runSshCapture(string $remoteCommand, array $s): string
    {
        [$cmd, $env] = $this->buildSshCommand($s, $remoteCommand);

        $process = new Process($cmd, null, $env, null);
        $process->setTimeout(20);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * @return array{0:list<string>,1:?array<string,string>} [argv, env]
     */
    private function buildSshCommand(array $s, string $remoteCommand): array
    {
        $sshOpts = ['-o', 'StrictHostKeyChecking=no', '-o', 'ConnectTimeout=15', '-p', (string) $s['ssh_port']];

        if ($s['ssh_key_path'] !== '') {
            // Key-based (preferred): no secret in the DB / process env.
            return [
                array_merge(['ssh', '-i', $s['ssh_key_path'], '-o', 'BatchMode=yes'], $sshOpts, [$s['ssh_user'].'@'.$s['ssh_host'], $remoteCommand]),
                null,
            ];
        }

        // Password fallback via sshpass -e (password from env, not argv).
        return [
            array_merge(['sshpass', '-e', 'ssh'], $sshOpts, [$s['ssh_user'].'@'.$s['ssh_host'], $remoteCommand]),
            ['SSHPASS' => $s['ssh_password']],
        ];
    }

    /**
     * Live status of every configured relay node, for the admin control
     * console: whether each is reachable, its xray-relay service up, the relay
     * port listening, plus panel-side line count + last-sync time (shared).
     *
     * @return list<array<string,mixed>>
     */
    public function relayStatuses(): array
    {
        $lines = $this->activeOnipInstances()->count();
        $lastSync = Setting::get('relay_last_sync_at', '');

        return array_map(function (array $t) use ($lines, $lastSync) {
            return array_merge(
                ['label' => $t['label'] ?? '主力', 'host' => $t['host'], 'port' => (int) $t['port'], 'lines' => $lines, 'last_sync' => $lastSync],
                $this->probeNode($t),
            );
        }, $this->relayTargets());
    }

    /**
     * @return array{reachable:bool,active:bool,listening:bool}
     */
    protected function probeNode(array $s): array
    {
        $port = (int) $s['port'];
        $out = $this->runSshCapture(
            "printf 'ACT=%s LIS=%s' \"$(systemctl is-active xray-relay 2>/dev/null)\" \"$(ss -tln 2>/dev/null | grep -c :{$port})\"",
            $s,
        );

        if ($out === '') {
            return ['reachable' => false, 'active' => false, 'listening' => false];
        }

        preg_match('/ACT=(\S*)/', $out, $a);
        preg_match('/LIS=(\d+)/', $out, $l);

        return [
            'reachable' => true,
            'active' => ($a[1] ?? '') === 'active',
            'listening' => ((int) ($l[1] ?? 0)) > 0,
        ];
    }

    /** Restart the xray-relay service on one node (by 主力/备用 label). */
    public function restartNode(string $label): void
    {
        foreach ($this->relayTargets() as $t) {
            if (($t['label'] ?? '主力') === $label) {
                $this->runSsh($t['reload_cmd'], null, $t);

                return;
            }
        }
    }

    /** Last lines of one node's relay error log (by 主力/备用 label). */
    public function nodeErrorLog(string $label): string
    {
        foreach ($this->relayTargets() as $t) {
            if (($t['label'] ?? '主力') === $label) {
                $logPath = dirname($t['config_path']).'/error.log';
                $out = $this->runSshCapture('tail -n 20 '.escapeshellarg($logPath).' 2>/dev/null', $t);

                return $out !== '' ? $out : '（无日志或读取失败）';
            }
        }

        return '';
    }
}
