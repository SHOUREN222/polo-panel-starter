<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\NodeRelayService;

/**
 * Turns a server (or a provisioned dedicated-ISP instance) plus a user's
 * credential into a URI string real proxy clients (v2rayN, Shadowrocket,
 * Clash, ...) can import. This only produces the *string* — whether a real
 * proxy is actually listening at that host:port is a separate, later
 * concern (matches how Xboard itself separates the panel from node agents).
 */
class SubscriptionLinkService
{
    public function __construct(
        private readonly SharedNodeService $sharedNodeService,
        private readonly NodeRelayService $nodeRelay,
    )
    {
    }

    /**
     * onip ISO3 country code → Chinese name, for customer-facing node names.
     * Covers the countries we sell; unknown codes fall back to the raw code
     * (see countryLabel()).
     */
    private const COUNTRY_LABELS = [
        'USA' => '美国', 'GBR' => '英国', 'DEU' => '德国', 'FRA' => '法国',
        'ITA' => '意大利', 'ESP' => '西班牙', 'NLD' => '荷兰', 'CAN' => '加拿大',
        'AUS' => '澳大利亚', 'ARE' => '阿联酋', 'ZAF' => '南非', 'IDN' => '印尼',
        'MYS' => '马来西亚', 'PHL' => '菲律宾', 'IND' => '印度', 'CHN' => '中国',
        'KOR' => '韩国', 'SGP' => '新加坡', 'VNM' => '越南', 'JPN' => '日本',
        'HKG' => '香港', 'TWN' => '台湾', 'THA' => '泰国', 'BRA' => '巴西',
        'TUR' => '土耳其', 'RUS' => '俄罗斯', 'MEX' => '墨西哥', 'ARG' => '阿根廷',
        'SAU' => '沙特', 'EGY' => '埃及', 'NGA' => '尼日利亚', 'PAK' => '巴基斯坦',
    ];

    /**
     * "美国" for a known ISO3 code, the raw code for an unknown one, or null
     * when no country is set (caller then uses its own fallback).
     */
    private function countryLabel(?string $iso3): ?string
    {
        if ($iso3 === null || $iso3 === '') {
            return null;
        }

        return self::COUNTRY_LABELS[strtoupper($iso3)] ?? strtoupper($iso3);
    }

    /**
     * All subscription lines (shared nodes + active dedicated instances)
     * for a user, newline-joined and base64-encoded — the conventional
     * "subscription URL body" format most clients expect.
     */
    public function buildSubscriptionBody(User $user): string
    {
        return base64_encode(implode("\n", $this->buildLinksForUser($user)));
    }

    /**
     * Just the shared-plan nodes, none of the user's dedicated-ISP
     * instances — the dashboard's "我的订阅" card for a shared plan links
     * here so a customer who only has that one plan gets a single real
     * subscription URL covering every node in it, instead of one link per
     * node. buildSubscriptionBody() stays "everything" for accounts that
     * also have dedicated instances.
     */
    public function buildSharedOnlySubscriptionBody(User $user): string
    {
        $links = $this->sharedNodeService->getAvailableServers($user)
            ->map(fn ($server) => $this->buildServerLink($server, $user->uuid, $server->name))
            ->all();

        return base64_encode(implode("\n", $links));
    }

    /**
     * Structured-data counterpart to buildSharedOnlySubscriptionBody() —
     * shared-plan nodes only, none of the user's dedicated-ISP instances.
     * buildNodeDataForUser() extends this with the dedicated instances for
     * the "everything" endpoint.
     *
     * @return list<array{name:string,protocol:string,host:string,port:int,credential:string,method:?string,config:?array}>
     */
    public function buildSharedOnlyNodeDataForUser(User $user): array
    {
        $nodes = [];

        foreach ($this->sharedNodeService->getAvailableServers($user) as $server) {
            $nodes[] = [
                'name' => $this->decorateName($server->name, $server->protocol),
                'protocol' => $server->protocol,
                'host' => $server->host,
                'port' => (int) $server->port,
                'credential' => $user->uuid,
                'method' => $server->method,
                'config' => $server->config,
            ];
        }

        return $nodes;
    }

    /**
     * A single dedicated-ISP instance's own subscription body — lets that
     * one instance card offer a fetchable HTTP URL (for one-click-import
     * deep links) instead of just its raw share-link string.
     */
    public function buildInstanceOnlySubscriptionBody(SubscriptionInstance $instance): string
    {
        return base64_encode($this->buildInstanceLink($instance) ?? '');
    }

    /**
     * Structured-data counterpart to buildInstanceOnlySubscriptionBody().
     *
     * @return list<array{name:string,protocol:string,host:string,port:int,credential:string,method:?string,config:?array}>
     */
    public function buildInstanceOnlyNodeDataForUser(SubscriptionInstance $instance): array
    {
        return $this->instanceNodes($instance);
    }

    /**
     * The node data a customer's client should use for one dedicated-ISP
     * instance. For onip instances with the relay configured, this is the
     * 主力 VLESS-Reality relay endpoint (onip's whitelist rejects a direct
     * connection to the raw residential IP); otherwise it falls back to the
     * instance's own meta (raw ip/port/protocol). Returns null when the
     * instance has no usable connection info.
     */
    /**
     * @return list<array{name:string,protocol:string,host:string,port:int,credential:string,method:?string,config:?array}>
     */
    private function instanceNodes(SubscriptionInstance $instance): array
    {
        $meta = $instance->meta ?? [];
        $name = $this->decorateName($instance->plan->name ?? '专属线路', 'vless');

        if (($meta['provider'] ?? null) === 'onip') {
            // Name reads "🇺🇸 美国 · 主力 · 198.51.100.3": country (from the
            // instance's own onip country code) + which relay + the static IP,
            // so a customer with several ISP lines can tell them apart at a
            // glance. Falls back to the plan name when the country is unknown.
            $prefix = $this->countryLabel($meta['country'] ?? null) ?? ($instance->plan->name ?? '静态IP');
            $suffix = (string) ($meta['ip'] ?? '');
            $relayNodes = $this->nodeRelay->customerNodes($instance, $prefix, $suffix);
            if ($relayNodes !== []) {
                return $relayNodes;
            }
        }

        if (empty($meta['ip']) || empty($meta['port']) || empty($meta['protocol'])) {
            return [];
        }

        return [[
            'name' => $this->decorateName($instance->plan->name ?? '专属线路', $meta['protocol']),
            'protocol' => $meta['protocol'],
            'host' => $meta['ip'],
            'port' => (int) $meta['port'],
            'credential' => $meta['username'] ?? $instance->uuid,
            'method' => $meta['method'] ?? null,
            'config' => $this->metaConfig($meta),
        ]];
    }

    /**
     * @return list<string>
     */
    public function buildLinksForUser(User $user): array
    {
        $links = [];

        foreach ($this->sharedNodeService->getAvailableServers($user) as $server) {
            $links[] = $this->buildServerLink($server, $user->uuid, $server->name);
        }

        foreach ($user->subscriptionInstances()->with('plan')->get() as $instance) {
            if (! $instance->isAvailable()) {
                continue;
            }

            $link = $this->buildInstanceLink($instance);
            if ($link !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * Same node set as buildLinksForUser(), but as structured data instead
     * of pre-built URI strings — ClientConfigRenderer needs the individual
     * fields (host/port/method/credential) to serialize into each client's
     * own config dialect (Clash YAML, sing-box JSON, Surge/Surfboard lines).
     *
     * @return list<array{name:string,protocol:string,host:string,port:int,credential:string,method:?string,config:?array}>
     */
    public function buildNodeDataForUser(User $user): array
    {
        $nodes = $this->buildSharedOnlyNodeDataForUser($user);

        foreach ($user->subscriptionInstances()->with('plan')->get() as $instance) {
            if (! $instance->isAvailable()) {
                continue;
            }

            foreach ($this->instanceNodes($instance) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Aggregate quota info across the user's active dedicated-ISP instances
     * plus their shared plan (if any), for the standard "Subscription-Userinfo"
     * response header most proxy clients read to show usage/expiry in their
     * own UI. Shared-node quota is real and enforced (see
     * SharedNodeService::hasExceededSharedQuota()), so it belongs in this
     * total same as dedicated instances.
     *
     * @return array{upload:int,download:int,total:int,expire:?int}
     */
    public function buildUserinfo(User $user): array
    {
        $instances = $user->subscriptionInstances()
            ->where('status', SubscriptionInstance::STATUS_ACTIVE)
            ->get();

        $upload = (int) $instances->sum('u');
        $download = (int) $instances->sum('d');
        $total = (int) $instances->sum(fn (SubscriptionInstance $i) => $i->effectiveQuotaBytes());
        $expire = $instances->max(fn (SubscriptionInstance $i) => $i->expired_at?->timestamp);

        if ($user->hasActiveSharedPlan()) {
            // Shared usage isn't split into upload/download separately
            // anywhere in this system, so it's folded entirely into
            // "download" — same convention XrayR/V2bX-style panels use when
            // a plan doesn't track the two directions apart.
            $download += $this->sharedNodeService->sharedTrafficUsedBytes($user);
            $total += $this->sharedNodeService->effectiveSharedQuotaBytes($user);
            $expire = max($expire ?? 0, $user->plan_expired_at->timestamp);
        }

        return [
            'upload' => $upload,
            'download' => $download,
            'total' => $total,
            'expire' => $expire,
        ];
    }

    public function buildServerLink(Server $server, string $credential, string $name): string
    {
        return $this->buildLink($server->protocol, $server->host, $server->port, $credential, $this->decorateName($name, $server->protocol), $server->method, $server->config);
    }

    public function buildInstanceLink(SubscriptionInstance $instance): ?string
    {
        $nodes = $this->instanceNodes($instance);
        if ($nodes === []) {
            return null;
        }

        // One share-link per relay endpoint (主力 + 备用), newline-joined so a
        // single instance's subscription body carries both failover nodes.
        $links = array_map(fn ($node) => $this->buildLink(
            $node['protocol'],
            $node['host'],
            $node['port'],
            $node['credential'],
            $node['name'],
            $node['method'] ?? null,
            $node['config'] ?? null,
        ), $nodes);

        return implode("\n", $links);
    }

    /**
     * meta['config'] already carries protocol-specific extras for vless
     * (Reality params). socks5 has no persisted config sub-array of its
     * own — its one extra field (password) gets folded in here so
     * buildLink()'s $config parameter stays the single place protocol
     * builders look for "everything beyond host/port/credential".
     */
    private function metaConfig(array $meta): ?array
    {
        if (($meta['protocol'] ?? null) === 'socks5') {
            return ['password' => $meta['password'] ?? ''];
        }

        return $meta['config'] ?? null;
    }

    private function decorateName(string $name, string $protocol): string
    {
        if (! Setting::getBool('subscribe_show_protocol_name', false)) {
            return $name;
        }

        return '[' . strtoupper($protocol) . ']' . $name;
    }

    private function buildLink(string $protocol, string $host, int $port, string $credential, string $name, ?string $method = null, ?array $config = null): string
    {
        return match ($protocol) {
            'vmess' => $this->buildVmess($host, $port, $credential, $name),
            'vless' => $this->buildVless($host, $port, $credential, $name, $config ?? []),
            'socks5' => $this->buildSocks5($host, $port, $credential, $config['password'] ?? '', $name),
            default => $this->buildShadowsocks($host, $port, $credential, $method ?? 'aes-256-gcm', $name),
        };
    }

    private function buildShadowsocks(string $host, int $port, string $credential, string $method, string $name): string
    {
        $userInfo = base64_encode("{$method}:{$credential}");

        return "ss://{$userInfo}@{$host}:{$port}#" . rawurlencode($name);
    }

    private function buildVmess(string $host, int $port, string $uuid, string $name): string
    {
        $config = [
            'v' => '2',
            'ps' => $name,
            'add' => $host,
            'port' => (string) $port,
            'id' => $uuid,
            'aid' => '0',
            'scy' => 'auto',
            'net' => 'tcp',
            'type' => 'none',
            'tls' => '',
        ];

        return 'vmess://' . base64_encode(json_encode($config, JSON_UNESCAPED_SLASHES));
    }

    /**
     * VLESS+Reality only — this project doesn't build plain/TLS VLESS links
     * since Reality is the only VLESS security mode any node actually runs.
     * $config carries the Reality params set on the Server/instance row:
     * public_key, short_id, sni, flow (defaults to xtls-rprx-vision).
     */
    private function buildVless(string $host, int $port, string $uuid, string $name, array $config): string
    {
        $query = http_build_query([
            'encryption' => 'none',
            'security' => 'reality',
            'pbk' => $config['public_key'] ?? '',
            'fp' => 'chrome',
            'sni' => $config['sni'] ?? '',
            'sid' => $config['short_id'] ?? '',
            'type' => 'tcp',
            'flow' => $config['flow'] ?? 'xtls-rprx-vision',
        ]);

        return "vless://{$uuid}@{$host}:{$port}?{$query}#" . rawurlencode($name);
    }

    private function buildSocks5(string $host, int $port, string $username, string $password, string $name): string
    {
        $userInfo = rawurlencode($username).':'.rawurlencode($password);

        return "socks5://{$userInfo}@{$host}:{$port}#" . rawurlencode($name);
    }
}
