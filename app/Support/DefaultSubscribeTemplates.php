<?php

namespace App\Support;

use App\Models\SubscribeTemplate;

/**
 * The built-in, fully-functional subscription config templates shown in the
 * editor and used when a client's 订阅模板 is saved. Kept here (not inside
 * the Livewire settings component) so the editor default and any seeding
 * share one source of truth.
 *
 * Each template is a complete client config — proxies + a selector/auto
 * group + China-direct routing — not just a bare proxy list, so a client
 * that consumes it can actually route traffic. Placeholders (see
 * ClientConfigRenderer): {{proxies}} spliceable proxy entries,
 * {{proxy_names}} comma-joined node names for the groups, {{subs_link}} and
 * {{subscribe_info}} for Surge's managed-config header/panel.
 */
class DefaultSubscribeTemplates
{
    public static function for(string $client): string
    {
        return match ($client) {
            SubscribeTemplate::CLIENT_SINGBOX => self::singbox(),
            SubscribeTemplate::CLIENT_CLASH,
            SubscribeTemplate::CLIENT_CLASH_META,
            SubscribeTemplate::CLIENT_STASH => self::clash(),
            SubscribeTemplate::CLIENT_SURGE,
            SubscribeTemplate::CLIENT_SURFBOARD => self::surge(),
            default => '',
        };
    }

    /**
     * Clash dialect (Clash / Clash Meta / Stash all consume this). China
     * traffic goes DIRECT via the built-in GEOIP mmdb, everything else
     * through the selected node — no external rule-set download needed.
     */
    private static function clash(): string
    {
        return <<<'YAML'
        mixed-port: 7890
        allow-lan: false
        mode: rule
        log-level: info
        ipv6: false
        dns:
          enable: true
          nameserver:
            - 223.5.5.5
            - 119.29.29.29
          fallback:
            - 8.8.8.8
            - 1.1.1.1
        proxies:
        {{proxies}}
        proxy-groups:
          - name: 节点选择
            type: select
            proxies: [自动选择, DIRECT, {{proxy_names}}]
          - name: 自动选择
            type: url-test
            url: http://www.gstatic.com/generate_204
            interval: 300
            tolerance: 50
            proxies: [{{proxy_names}}]
        rules:
          - GEOIP,CN,DIRECT
          - MATCH,节点选择
        YAML;
    }

    /**
     * sing-box config (1.11+). Deliberately omits a custom `dns` block —
     * that's the most version-fragile part of a sing-box config (the server
     * format changed incompatibly in 1.12), and leaving it out lets sing-box
     * use its own default resolver, keeping this config valid across a wider
     * range of client versions. China domains/IPs still go DIRECT via the
     * official geoip-cn / geosite-cn rule-sets, fetched through the proxy
     * (download_detour) so the GitHub-hosted download isn't itself blocked by
     * the censorship the proxy exists to bypass. Domain matching needs the
     * sniff route action, hence the leading rule.
     */
    private static function singbox(): string
    {
        return <<<'JSON'
        {
          "log": { "level": "warn" },
          "inbounds": [
            { "type": "mixed", "tag": "mixed-in", "listen": "127.0.0.1", "listen_port": 2080 }
          ],
          "outbounds": [
            { "type": "selector", "tag": "节点选择", "outbounds": ["自动选择", {{proxy_names}}], "default": "自动选择" },
            { "type": "urltest", "tag": "自动选择", "outbounds": [{{proxy_names}}], "url": "https://www.gstatic.com/generate_204", "interval": "5m" },
            {{proxies}},
            { "type": "direct", "tag": "direct" }
          ],
          "route": {
            "rules": [
              { "action": "sniff" },
              { "ip_is_private": true, "outbound": "direct" },
              { "rule_set": ["geoip-cn", "geosite-cn"], "outbound": "direct" }
            ],
            "rule_set": [
              { "type": "remote", "tag": "geoip-cn", "format": "binary", "url": "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs", "download_detour": "节点选择" },
              { "type": "remote", "tag": "geosite-cn", "format": "binary", "url": "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs", "download_detour": "节点选择" }
            ],
            "final": "节点选择",
            "auto_detect_interface": true
          }
        }
        JSON;
    }

    /**
     * Surge / Surfboard managed-config dialect. GEOIP,CN,DIRECT is built in,
     * no rule-set download needed. (Surge/Surfboard don't support VLESS, so
     * only shadowsocks/socks nodes are usable there — a client limitation,
     * not a template one.)
     */
    private static function surge(): string
    {
        return <<<'CONF'
        #!MANAGED-CONFIG {{subs_link}} interval=43200 strict=false

        [General]
        loglevel = notify
        dns-server = 223.5.5.5, 119.29.29.29
        skip-proxy = 127.0.0.1, 192.168.0.0/16, 10.0.0.0/8, 172.16.0.0/12, localhost, *.local

        [Panel]
        SubscribeInfo = {{subscribe_info}}, style=info

        [Proxy]
        {{proxies}}

        [Proxy Group]
        节点选择 = select, 自动选择, DIRECT, {{proxy_names}}
        自动选择 = url-test, {{proxy_names}}, url=http://www.gstatic.com/generate_204, interval=300

        [Rule]
        GEOIP,CN,DIRECT
        FINAL,节点选择
        CONF;
    }
}
