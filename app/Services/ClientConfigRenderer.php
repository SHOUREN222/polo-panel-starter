<?php

namespace App\Services;

use App\Models\SubscribeTemplate;
use App\Models\SubscriptionInstance;
use App\Models\User;

/**
 * Renders one of the six 订阅模板 client config templates for a user by
 * substituting these tokens into the admin-edited template text:
 *   {{proxies}}        the proxy entries in that client's own dialect —
 *                      spliceable elements (not a wrapped array/list), so a
 *                      full config can put them inside a bigger outbounds
 *                      array / proxies block alongside groups and rules.
 *   {{proxy_names}}    just the node names, comma-joined, quoted for
 *                      YAML/JSON dialects and bare for Surge — for use in a
 *                      proxy-group / selector that references every node.
 *   {{subs_link}}      the subscription URL.
 *   {{subscribe_info}} the Subscription-Userinfo string.
 * Uses the admin's saved template if present, otherwise the built-in default
 * (App\Support\DefaultSubscribeTemplates) so every supported client renders
 * its proper config out of the box. Only returns null (→ plain link-list
 * body) for an unrecognised client with no default, or when the user has no
 * nodes to render.
 */
class ClientConfigRenderer
{
    public function __construct(
        private readonly SubscriptionLinkService $subscriptionLinkService,
    ) {
    }

    public function render(string $client, User $user): ?string
    {
        return $this->renderNodes($client, $this->subscriptionLinkService->buildNodeDataForUser($user), $user, $user->subscriptionUrl());
    }

    /**
     * Same rendering, scoped to only the user's shared-plan nodes — used by
     * the merged "/shared" subscription endpoint so Clash-type clients get
     * a proper YAML config for that link too, not just the plain link list.
     */
    public function renderShared(string $client, User $user): ?string
    {
        return $this->renderNodes($client, $this->subscriptionLinkService->buildSharedOnlyNodeDataForUser($user), $user, $user->sharedSubscriptionUrl());
    }

    /**
     * Same rendering, scoped to a single dedicated-ISP instance — lets that
     * instance's own subscription endpoint offer Clash-type YAML too.
     */
    public function renderInstance(string $client, SubscriptionInstance $instance, User $user): ?string
    {
        return $this->renderNodes($client, $this->subscriptionLinkService->buildInstanceOnlyNodeDataForUser($instance), $user, $user->instanceSubscriptionUrl($instance));
    }

    private function renderNodes(string $client, array $nodes, User $user, string $subsLink): ?string
    {
        // Use the admin's saved template if there is one; otherwise fall back
        // to the built-in default so every supported client gets its proper
        // config out of the box (sing-box especially can't use the plain
        // link-list). Only genuinely unknown clients (no default) return null.
        $template = SubscribeTemplate::where('client', $client)->first();
        $content = ($template && trim($template->content) !== '')
            ? $template->content
            : \App\Support\DefaultSubscribeTemplates::for($client);

        if (trim($content) === '') {
            return null;
        }

        $userinfo = $this->subscriptionLinkService->buildUserinfo($user);

        $proxies = in_array($client, SubscribeTemplate::YAML_CLIENTS, true)
            ? $this->renderYamlProxies($nodes)
            : match ($client) {
                SubscribeTemplate::CLIENT_SINGBOX => $this->renderSingboxOutbounds($nodes),
                SubscribeTemplate::CLIENT_SURGE, SubscribeTemplate::CLIENT_SURFBOARD => $this->renderSurgeLines($nodes),
                default => '',
            };

        $subscribeInfo = "upload={$userinfo['upload']}; download={$userinfo['download']}; total={$userinfo['total']}"
            .($userinfo['expire'] !== null ? "; expire={$userinfo['expire']}" : '');

        return strtr($content, [
            '{{proxies}}' => $proxies,
            '{{proxy_names}}' => $this->renderProxyNames($client, $nodes),
            '{{subs_link}}' => $subsLink,
            '{{subscribe_info}}' => $subscribeInfo,
        ]);
    }

    /**
     * Node names as a comma-joined list for a proxy-group / selector.
     * Surge/Surfboard use bare names in their group syntax; YAML (Clash) and
     * JSON (sing-box) both accept a double-quoted JSON string, so json_encode
     * gives a value that's valid in both dialects and safely escapes any
     * quote/special char in a node name.
     *
     * @param  list<array{name:string,...}>  $nodes
     */
    private function renderProxyNames(string $client, array $nodes): string
    {
        $names = array_map(fn ($node) => $node['name'], $nodes);

        if (in_array($client, [SubscribeTemplate::CLIENT_SURGE, SubscribeTemplate::CLIENT_SURFBOARD], true)) {
            return implode(', ', $names);
        }

        return implode(', ', array_map(
            fn ($name) => json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $names,
        ));
    }

    /**
     * Clash's own YAML proxy-list dialect — Clash Meta and Stash both
     * consume this same format for their `proxies:` section.
     */
    private function renderYamlProxies(array $nodes): string
    {
        $lines = [];
        foreach ($nodes as $node) {
            if ($node['protocol'] === 'vless') {
                $config = $node['config'] ?? [];
                $lines[] = sprintf(
                    "  - { name: %s, type: vless, server: %s, port: %d, uuid: %s, network: tcp, tls: true, udp: true, flow: %s, servername: %s, reality-opts: { public-key: %s, short-id: %s }, client-fingerprint: chrome }",
                    $this->yamlString($node['name']), $node['host'], $node['port'], $node['credential'],
                    $config['flow'] ?? 'xtls-rprx-vision', $this->yamlString($config['sni'] ?? ''),
                    $this->yamlString($config['public_key'] ?? ''), $this->yamlString($config['short_id'] ?? ''),
                );
            } elseif ($node['protocol'] === 'socks5') {
                $config = $node['config'] ?? [];
                $lines[] = sprintf(
                    "  - { name: %s, type: socks5, server: %s, port: %d, username: %s, password: %s }",
                    $this->yamlString($node['name']), $node['host'], $node['port'],
                    $this->yamlString($node['credential']), $this->yamlString($config['password'] ?? ''),
                );
            } elseif ($node['protocol'] === 'vmess') {
                $lines[] = sprintf(
                    "  - { name: %s, type: vmess, server: %s, port: %d, uuid: %s, alterId: 0, cipher: auto }",
                    $this->yamlString($node['name']), $node['host'], $node['port'], $node['credential'],
                );
            } else {
                $lines[] = sprintf(
                    "  - { name: %s, type: ss, server: %s, port: %d, cipher: %s, password: %s }",
                    $this->yamlString($node['name']), $node['host'], $node['port'],
                    $node['method'] ?? 'aes-256-gcm', $this->yamlString($node['credential']),
                );
            }
        }

        return implode("\n", $lines);
    }

    private function renderSingboxOutbounds(array $nodes): string
    {
        $outbounds = [];
        foreach ($nodes as $node) {
            if ($node['protocol'] === 'vless') {
                $config = $node['config'] ?? [];
                $outbounds[] = [
                    'type' => 'vless',
                    'tag' => $node['name'],
                    'server' => $node['host'],
                    'server_port' => $node['port'],
                    'uuid' => $node['credential'],
                    'flow' => $config['flow'] ?? 'xtls-rprx-vision',
                    'tls' => [
                        'enabled' => true,
                        'server_name' => $config['sni'] ?? '',
                        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                        'reality' => [
                            'enabled' => true,
                            'public_key' => $config['public_key'] ?? '',
                            'short_id' => $config['short_id'] ?? '',
                        ],
                    ],
                ];
            } elseif ($node['protocol'] === 'socks5') {
                $config = $node['config'] ?? [];
                $outbounds[] = [
                    'type' => 'socks',
                    'tag' => $node['name'],
                    'server' => $node['host'],
                    'server_port' => $node['port'],
                    'username' => $node['credential'],
                    'password' => $config['password'] ?? '',
                ];
            } elseif ($node['protocol'] === 'vmess') {
                $outbounds[] = [
                    'type' => 'vmess',
                    'tag' => $node['name'],
                    'server' => $node['host'],
                    'server_port' => $node['port'],
                    'uuid' => $node['credential'],
                    'alter_id' => 0,
                    'security' => 'auto',
                ];
            } else {
                $outbounds[] = [
                    'type' => 'shadowsocks',
                    'tag' => $node['name'],
                    'server' => $node['host'],
                    'server_port' => $node['port'],
                    'method' => $node['method'] ?? 'aes-256-gcm',
                    'password' => $node['credential'],
                ];
            }
        }

        // Bracket-less: comma-joined objects, so a full-config template can
        // splice them into a bigger "outbounds": [ … ] array next to the
        // selector/urltest/direct outbounds. Callers that want a standalone
        // array wrap the token themselves: "outbounds": [{{proxies}}].
        return implode(",\n", array_map(
            fn ($o) => json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $outbounds,
        ));
    }

    /**
     * Surge and Surfboard share the same `Name = ss, host, port,
     * encrypt-method=X, password=Y` proxy-line syntax.
     */
    private function renderSurgeLines(array $nodes): string
    {
        $lines = [];
        foreach ($nodes as $node) {
            if ($node['protocol'] === 'vless') {
                $config = $node['config'] ?? [];
                $lines[] = sprintf(
                    '%s = vless, %s, %d, username=%s, tls=true, sni=%s, reality-public-key=%s, reality-short-id=%s, client-fingerprint=chrome',
                    $node['name'], $node['host'], $node['port'], $node['credential'],
                    $config['sni'] ?? '', $config['public_key'] ?? '', $config['short_id'] ?? '',
                );
            } elseif ($node['protocol'] === 'socks5') {
                $config = $node['config'] ?? [];
                $lines[] = sprintf(
                    '%s = socks5, %s, %d, %s, %s',
                    $node['name'], $node['host'], $node['port'], $node['credential'], $config['password'] ?? '',
                );
            } elseif ($node['protocol'] === 'vmess') {
                $lines[] = sprintf('%s = vmess, %s, %d, username=%s', $node['name'], $node['host'], $node['port'], $node['credential']);
            } else {
                $lines[] = sprintf(
                    '%s = ss, %s, %d, encrypt-method=%s, password=%s',
                    $node['name'], $node['host'], $node['port'], $node['method'] ?? 'aes-256-gcm', $node['credential'],
                );
            }
        }

        return implode("\n", $lines);
    }

    private function yamlString(string $value): string
    {
        return '"'.str_replace('"', '\\"', $value).'"';
    }
}
