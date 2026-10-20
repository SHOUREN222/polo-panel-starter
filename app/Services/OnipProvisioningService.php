<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Client for the real static-proxy vendor's OpenAPI V2
 * (https://api-ip.onip.cc/openapi/v2 — "静态代理服务管理系统"). Signing
 * scheme, endpoints, and field names are taken directly from the vendor's
 * own docs, not guessed.
 *
 * The vendor hands back a fully working SOCKS5+HTTP proxy per instance
 * (ip/port/username/password) — there's no separate "assign an IP, then
 * configure a proxy server ourselves" step; the vendor's own box is the
 * proxy server.
 */
class OnipProvisioningService
{
    public function isConfigured(): bool
    {
        return Setting::get('onip_app_id', '') !== '' && Setting::get('onip_app_secret', '') !== '';
    }

    public function getAccount(): array
    {
        return $this->request('GET', '/account');
    }

    /**
     * @param  array{countryCode?:string,ispType?:int,businessTypeCode?:string,tag?:string}  $query
     */
    public function getAvailableLocations(array $query = []): array
    {
        return $this->request('GET', '/static/lines/locations/available', [], $query);
    }

    /**
     * @param  array{countryCode?:string,cityCode?:string,businessType?:string,tag?:string,ispType?:int,lineId?:int,current?:int,size?:int}  $params
     */
    public function searchLines(array $params = []): array
    {
        return $this->request('POST', '/static/lines', $params);
    }

    /**
     * Server-side authoritative price cache for a line, keyed by its onip id.
     * The whole line-pick UI lives in client-controllable Livewire state, so
     * the sell price (derived from the line's onip cost) must NOT be taken from
     * anything the client sends. searchLines() — which fetches real onip data
     * server-side — records each sellable line here, and OrderService reads the
     * cost back from here at checkout instead of trusting the request.
     */
    public function cacheLine(array $record): void
    {
        if (empty($record['id'])) {
            return;
        }

        Cache::put(self::lineCacheKey((string) $record['id']), [
            'countryCode' => $record['countryCode'] ?? null,
            'cityCode' => $record['cityCode'] ?? null,
            'businessTypeCode' => $record['businessTypeCode'] ?? null,
            'ispType' => $record['ispType'] ?? null,
            'price' => $record['price'] ?? null,
            'currency' => $record['currency'] ?? null,
        ], now()->addMinutes(60));
    }

    public function cachedLine(string $lineId): ?array
    {
        return Cache::get(self::lineCacheKey($lineId));
    }

    public static function lineCacheKey(string $lineId): string
    {
        return 'onip_line:'.$lineId;
    }

    /**
     * @param  array{status?:int,orderNo?:string,externalOrderNo?:string,createdAfter?:string,createdBefore?:string,current?:int,size?:int}  $params
     */
    public function searchOrders(array $params = []): array
    {
        return $this->request('POST', '/static/orders', $params);
    }

    /**
     * @param  array{lineId?:int,countryCode?:string,cityCode?:string,businessType?:string,ispType?:int,tag?:string,cidr?:string,quantity:int,days:int,currency?:string,orderNo?:string,sync?:bool}  $params
     */
    public function createOrder(array $params): array
    {
        return $this->request('POST', '/static/orders/create', $params);
    }

    public function renewInstance(string $proxyId, int $days, ?string $orderNo = null): array
    {
        return $this->request('POST', '/static/instances/renew', array_filter([
            'proxyIds' => [$proxyId],
            'days' => $days,
            'orderNo' => $orderNo,
        ]));
    }

    public function changeIp(string $proxyId): array
    {
        return $this->request('POST', '/static/instances/change-ip', ['proxyIds' => [$proxyId]]);
    }

    /**
     * $query params are sent as a real query string but deliberately
     * excluded from the signed string — the vendor's own spec is explicit
     * that "URI" in the signature means "请求路径（不含域名和查询参数）".
     */
    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $appId = Setting::get('onip_app_id', '');
        $appSecret = Setting::get('onip_app_secret', '');
        $baseUrl = rtrim(Setting::get('onip_base_url', 'https://api-ip.onip.cc'), '/');
        $uri = '/openapi/v2'.$path;

        $timestamp = (string) time();
        $nonce = Str::random(16);
        $bodyStr = $method === 'GET' ? '' : json_encode((object) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signString = strtoupper($method).$uri.$timestamp.$nonce.$bodyStr;
        $signature = hash_hmac('sha256', $signString, $appSecret);

        $request = Http::withHeaders([
            'X-API-AppId' => $appId,
            'X-API-Timestamp' => $timestamp,
            'X-API-Nonce' => $nonce,
            'X-API-Signature' => $signature,
        ]);

        $response = $method === 'GET'
            ? $request->get($baseUrl.$uri, $query)
            : $request->withBody($bodyStr, 'application/json')->post($baseUrl.$uri);

        $json = $response->json() ?? [];

        if (! ($json['success'] ?? false)) {
            throw new \RuntimeException('onip API error: '.($json['code'] ?? 'UNKNOWN').' - '.($json['message'] ?? $response->body()));
        }

        return $json['data'] ?? [];
    }
}
