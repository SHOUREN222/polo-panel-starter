<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\OnipProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnipProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        Setting::set('onip_app_id', 'APP_TEST_ID');
        Setting::set('onip_app_secret', 'test-secret');
        Setting::set('onip_base_url', 'https://api-ip.onip.cc');
    }

    public function test_get_account_sends_a_correctly_signed_request(): void
    {
        $this->configure();

        Http::fake(function ($request) {
            $method = $request->method();
            $uri = '/openapi/v2/account';
            $timestamp = $request->header('X-API-Timestamp')[0];
            $nonce = $request->header('X-API-Nonce')[0];
            $signature = $request->header('X-API-Signature')[0];

            $expectedSignString = strtoupper($method).$uri.$timestamp.$nonce.'';
            $expectedSignature = hash_hmac('sha256', $expectedSignString, 'test-secret');

            $this->assertSame($expectedSignature, $signature);
            $this->assertSame('APP_TEST_ID', $request->header('X-API-AppId')[0]);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'message' => '操作成功',
                'data' => ['userId' => 1, 'username' => 'partner_a', 'balance' => 100.0, 'currency' => 'CNY'],
            ]);
        });

        $account = app(OnipProvisioningService::class)->getAccount();

        $this->assertSame('partner_a', $account['username']);
    }

    public function test_create_order_signs_the_body_correctly(): void
    {
        $this->configure();

        Http::fake(function ($request) {
            $method = $request->method();
            $uri = '/openapi/v2/static/orders/create';
            $timestamp = $request->header('X-API-Timestamp')[0];
            $nonce = $request->header('X-API-Nonce')[0];
            $signature = $request->header('X-API-Signature')[0];
            $body = $request->body();

            $expectedSignString = strtoupper($method).$uri.$timestamp.$nonce.$body;
            $expectedSignature = hash_hmac('sha256', $expectedSignString, 'test-secret');

            $this->assertSame($expectedSignature, $signature);
            $this->assertSame('POST', $method);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'message' => '操作成功',
                'data' => [
                    'orderNo' => 'ORD1', 'status' => 3, 'instances' => [
                        ['proxyId' => 'SI1', 'ip' => '192.168.1.1', 'port' => 8080, 'username' => 'u1', 'password' => 'p1'],
                    ],
                ],
            ]);
        });

        $order = app(OnipProvisioningService::class)->createOrder([
            'countryCode' => 'USA', 'businessType' => 'RESIDENTIAL', 'ispType' => 1,
            'quantity' => 1, 'days' => 30, 'sync' => true,
        ]);

        $this->assertSame('SI1', $order['instances'][0]['proxyId']);
    }

    public function test_throws_when_vendor_returns_a_failure_response(): void
    {
        $this->configure();

        Http::fake(fn () => Http::response([
            'success' => false, 'code' => 'ACCOUNT_BALANCE_INSUFFICIENT', 'message' => '余额不足', 'data' => null,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ACCOUNT_BALANCE_INSUFFICIENT/');

        app(OnipProvisioningService::class)->getAccount();
    }

    public function test_get_available_locations_excludes_query_params_from_the_signed_string_but_sends_them_on_the_wire(): void
    {
        $this->configure();

        Http::fake(function ($request) {
            $method = $request->method();
            $uri = '/openapi/v2/static/lines/locations/available'; // no query string
            $timestamp = $request->header('X-API-Timestamp')[0];
            $nonce = $request->header('X-API-Nonce')[0];
            $signature = $request->header('X-API-Signature')[0];

            $expectedSignString = strtoupper($method).$uri.$timestamp.$nonce.'';
            $expectedSignature = hash_hmac('sha256', $expectedSignString, 'test-secret');

            $this->assertSame($expectedSignature, $signature, 'signature must exclude query params per vendor spec');
            $this->assertStringContainsString('countryCode=USA', (string) $request->url());
            $this->assertStringContainsString('ispType=2', (string) $request->url());

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    ['code' => 'NYC', 'name' => '纽约', 'nameEn' => 'New York', 'type' => 'city', 'parentCode' => 'USA', 'active' => true],
                ],
            ]);
        });

        $locations = app(OnipProvisioningService::class)->getAvailableLocations(['countryCode' => 'USA', 'ispType' => 2]);

        $this->assertSame('NYC', $locations[0]['code']);
    }

    public function test_is_configured_reflects_whether_credentials_are_set(): void
    {
        $this->assertFalse(app(OnipProvisioningService::class)->isConfigured());

        $this->configure();

        $this->assertTrue(app(OnipProvisioningService::class)->isConfigured());
    }
}
