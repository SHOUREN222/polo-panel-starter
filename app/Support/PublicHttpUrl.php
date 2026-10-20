<?php

namespace App\Support;

use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Resolve an HTTPS URL to a public IP that can be pinned for one request. */
class PublicHttpUrl
{
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     */
    public function resolve(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('请输入有效的 BTCPay 服务器地址');
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('BTCPay 服务器必须使用 HTTPS');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('BTCPay 服务器地址不能包含账号、查询参数或片段');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw new InvalidArgumentException('BTCPay 服务器域名无效');
        }

        // URL clients may interpret decimal/octal/hexadecimal host notation as
        // an IP even when FILTER_VALIDATE_IP does not. Reject those ambiguous
        // forms instead of letting cURL reinterpret one as loopback later.
        if (! filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host)) {
            throw new InvalidArgumentException('BTCPay 服务器域名无效');
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('BTCPay 服务器端口无效');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveHostname($host);

        if ($ips === []) {
            throw new InvalidArgumentException('BTCPay 服务器域名无法解析');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new InvalidArgumentException('BTCPay 服务器不能指向本机、内网或保留地址');
            }
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'ip' => $ips[0],
        ];
    }

    /** @return array<int, string> */
    private function resolveHostname(string $host): array
    {
        if ($this->resolver) {
            return array_values(array_unique(($this->resolver)($host)));
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return ! IpUtils::checkIp($ip, self::BLOCKED_CIDRS)
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
    }
}
