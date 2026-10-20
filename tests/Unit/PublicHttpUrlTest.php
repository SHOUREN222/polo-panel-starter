<?php

namespace Tests\Unit;

use App\Support\PublicHttpUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicHttpUrlTest extends TestCase
{
    public function test_public_https_ip_is_allowed(): void
    {
        $target = (new PublicHttpUrl)->resolve('https://93.184.216.34:8443/btcpay/');

        $this->assertSame('https://93.184.216.34:8443/btcpay', $target['url']);
        $this->assertSame('93.184.216.34', $target['ip']);
        $this->assertSame(8443, $target['port']);
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PublicHttpUrl)->resolve($url);
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrlProvider(): array
    {
        return [
            'plain HTTP' => ['http://93.184.216.34'],
            'loopback' => ['https://127.0.0.1'],
            'private IPv4' => ['https://10.0.0.1'],
            'link local metadata' => ['https://169.254.169.254'],
            'carrier grade NAT' => ['https://100.64.0.1'],
            'benchmark network' => ['https://198.18.0.1'],
            'IPv6 loopback' => ['https://[::1]'],
            'IPv6 unique local' => ['https://[fd00::1]'],
            'IPv4 mapped IPv6' => ['https://[::ffff:127.0.0.1]'],
            'credentials' => ['https://user:pass@93.184.216.34'],
            'query' => ['https://93.184.216.34?next=/admin'],
            'fragment' => ['https://93.184.216.34/#/api'],
            'numeric alternate IP' => ['https://2130706433'],
            'octal alternate IP' => ['https://0177.0.0.1'],
            'hex alternate IP' => ['https://0x7f000001'],
        ];
    }

    public function test_hostname_is_rejected_if_any_dns_answer_is_private(): void
    {
        $guard = new PublicHttpUrl(fn (string $host) => ['93.184.216.34', '10.0.0.8']);

        $this->expectException(InvalidArgumentException::class);
        $guard->resolve('https://btcpay.example.com');
    }

    public function test_public_hostname_returns_an_ip_for_request_pinning(): void
    {
        $guard = new PublicHttpUrl(fn (string $host) => ['93.184.216.34']);

        $target = $guard->resolve('https://btcpay.example.com');

        $this->assertSame('btcpay.example.com', $target['host']);
        $this->assertSame('93.184.216.34', $target['ip']);
        $this->assertSame(443, $target['port']);
    }
}
