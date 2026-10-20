<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentGateways\AlipayF2FService;
use App\Services\PaymentGateways\BtcPayService;
use App\Services\PaymentGateways\CoinbaseCommerceService;
use App\Services\PaymentGateways\CoinPaymentsService;
use App\Support\PublicHttpUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create();

        return app(OrderService::class)->createOrder($user, $this->makePlan(), 'month');
    }

    /** @return array{0:string,1:string} [privateKeyPem, publicKeyPem] both bare base64, no PEM header/footer */
    private function generateTestRsaKeyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);
        $publicPem = $details['key'];

        $stripPem = fn (string $pem) => trim(preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $pem));

        return [$stripPem($privatePem), $stripPem($publicPem)];
    }

    // --- Payment::amountWithFee ---

    public function test_amount_with_fee_applies_percent_and_fixed(): void
    {
        $payment = Payment::create(['name' => 'x', 'gateway' => 'btcpay', 'fee_percent' => 5, 'fee_fixed' => 100]);

        $this->assertSame(1150, $payment->amountWithFee(1000)); // 1000 + 5% (50) + 100
    }

    public function test_amount_with_fee_is_identity_when_no_fee_configured(): void
    {
        $payment = Payment::create(['name' => 'x', 'gateway' => 'btcpay']);

        $this->assertSame(1500, $payment->amountWithFee(1500));
    }

    // --- AlipayF2F ---

    public function test_alipayf2f_sign_and_verify_round_trip_with_real_rsa_keypair(): void
    {
        [$privateKey, $publicKey] = $this->generateTestRsaKeyPair();
        $payment = Payment::create([
            'name' => '支付宝当面付', 'gateway' => Payment::GATEWAY_ALIPAYF2F, 'enable' => true,
            'config' => ['app_id' => '2021000000000000', 'private_key' => $privateKey, 'alipay_public_key' => $publicKey],
        ]);
        $order = $this->makeOrder();

        $url = app(AlipayF2FService::class)->buildPaymentUrl($order, $payment);
        $this->assertStringStartsWith('https://openapi.alipay.com/gateway.do?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame($order->trade_no, json_decode($params['biz_content'], true)['out_trade_no']);

        // Simulate a genuine Alipay notify callback, signed with the SAME
        // key pair — this fully exercises the RSA2 verify path without
        // needing a real Alipay merchant account.
        $notifyParams = [
            'out_trade_no' => $order->trade_no,
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '15.00',
        ];
        $signContent = collect($notifyParams)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        openssl_sign($signContent, $signature, "-----BEGIN PRIVATE KEY-----\n".chunk_split($privateKey, 64, "\n").'-----END PRIVATE KEY-----', OPENSSL_ALGO_SHA256);
        $notifyParams['sign'] = base64_encode($signature);
        $notifyParams['sign_type'] = 'RSA2';

        $this->assertTrue(app(AlipayF2FService::class)->verifyNotify($notifyParams, $payment));
    }

    public function test_alipayf2f_rejects_tampered_notify(): void
    {
        [$privateKey, $publicKey] = $this->generateTestRsaKeyPair();
        $payment = Payment::create([
            'name' => 'x', 'gateway' => Payment::GATEWAY_ALIPAYF2F, 'enable' => true,
            'config' => ['app_id' => 'x', 'private_key' => $privateKey, 'alipay_public_key' => $publicKey],
        ]);

        $result = app(AlipayF2FService::class)->verifyNotify([
            'out_trade_no' => 'ANY', 'trade_status' => 'TRADE_SUCCESS', 'sign' => base64_encode('forged'),
        ], $payment);

        $this->assertFalse($result);
    }

    public function test_alipayf2f_pay_flow_marks_order_paid_via_notify_endpoint(): void
    {
        [$privateKey, $publicKey] = $this->generateTestRsaKeyPair();
        $payment = Payment::create([
            'name' => '支付宝当面付', 'gateway' => Payment::GATEWAY_ALIPAYF2F, 'enable' => true,
            'config' => ['app_id' => 'x', 'private_key' => $privateKey, 'alipay_public_key' => $publicKey],
        ]);
        $order = $this->makeOrder();

        $response = $this->actingAs($order->user)->get("/pay/{$payment->id}/{$order->id}");
        $response->assertRedirect();
        $this->assertStringStartsWith('https://openapi.alipay.com/gateway.do?', $response->headers->get('Location'));

        $notifyParams = ['out_trade_no' => $order->trade_no, 'trade_status' => 'TRADE_SUCCESS'];
        $signContent = collect($notifyParams)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        openssl_sign($signContent, $signature, "-----BEGIN PRIVATE KEY-----\n".chunk_split($privateKey, 64, "\n").'-----END PRIVATE KEY-----', OPENSSL_ALGO_SHA256);
        $notifyParams['sign'] = base64_encode($signature);

        $this->post("/api/payment/{$payment->id}/notify", $notifyParams)->assertOk();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // --- BTCPay ---

    public function test_btcpay_build_payment_url_calls_greenfield_api_and_returns_checkout_link(): void
    {
        Http::fake([
            '*/api/v1/stores/*/invoices' => Http::response(['id' => 'inv123', 'checkoutLink' => 'https://btcpay.example.com/i/inv123'], 200),
        ]);
        $payment = Payment::create([
            'name' => 'BTCPay', 'gateway' => Payment::GATEWAY_BTCPAY, 'enable' => true,
            'config' => ['server_url' => 'https://93.184.216.34', 'api_key' => 'key', 'store_id' => 'store1', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();

        $url = app(BtcPayService::class)->buildPaymentUrl($order, $payment);

        $this->assertSame('https://btcpay.example.com/i/inv123', $url);
        Http::assertSent(fn ($request) => $request['metadata']['orderId'] === $order->trade_no);
    }

    public function test_btcpay_webhook_signature_verification(): void
    {
        $body = '{"invoiceId":"inv123","type":"InvoiceSettled"}';
        $secret = 'whsecret';
        $validSig = 'sha256='.hash_hmac('sha256', $body, $secret);

        $service = app(BtcPayService::class);
        $this->assertTrue($service->verifyWebhookSignature($body, $validSig, $secret));
        $this->assertFalse($service->verifyWebhookSignature($body, 'sha256=wrong', $secret));
        $this->assertFalse($service->verifyWebhookSignature($body, null, $secret));
    }

    public function test_btcpay_hostname_is_pinned_and_redirects_are_disabled(): void
    {
        $optionsSeen = null;
        Http::globalMiddleware(function (callable $handler) use (&$optionsSeen) {
            return function ($request, array $options) use ($handler, &$optionsSeen) {
                $optionsSeen = $options;

                return $handler($request, $options);
            };
        });
        Http::fake([
            '*/api/v1/stores/*/invoices' => Http::response(['checkoutLink' => 'https://btcpay.example.com/i/1']),
        ]);
        app()->instance(PublicHttpUrl::class, new PublicHttpUrl(fn (string $host) => ['93.184.216.34']));

        $payment = Payment::create([
            'name' => 'BTCPay', 'gateway' => Payment::GATEWAY_BTCPAY, 'enable' => true,
            'config' => ['server_url' => 'https://btcpay.example.com', 'api_key' => 'key', 'store_id' => 'store1'],
        ]);

        app(BtcPayService::class)->buildPaymentUrl($this->makeOrder(), $payment);

        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertSame(5, $optionsSeen['connect_timeout']);
        $this->assertSame(15, $optionsSeen['timeout']);
        $this->assertSame(
            ['btcpay.example.com:443:93.184.216.34'],
            $optionsSeen['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_btcpay_notify_marks_order_paid_after_fetching_metadata(): void
    {
        $payment = Payment::create([
            'name' => 'BTCPay', 'gateway' => Payment::GATEWAY_BTCPAY, 'enable' => true,
            'config' => ['server_url' => 'https://93.184.216.34', 'api_key' => 'key', 'store_id' => 'store1', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();

        Http::fake([
            '*/api/v1/stores/store1/invoices/inv123' => Http::response(['metadata' => ['orderId' => $order->trade_no]], 200),
        ]);

        $body = json_encode(['invoiceId' => 'inv123', 'type' => 'InvoiceSettled']);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'whsecret');

        $this->call('POST', "/api/payment/{$payment->id}/notify", [], [], [], [
            'HTTP_BTCPay-Sig' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_btcpay_invoice_id_cannot_escape_the_invoice_path(): void
    {
        Http::fake([
            '*' => Http::response(['metadata' => ['orderId' => 'ORDER-1']]),
        ]);
        $payment = Payment::create([
            'name' => 'BTCPay', 'gateway' => Payment::GATEWAY_BTCPAY, 'enable' => true,
            'config' => ['server_url' => 'https://93.184.216.34', 'api_key' => 'key', 'store_id' => 'store/one'],
        ]);

        app(BtcPayService::class)->fetchOrderTradeNo('../admin?secret=x', $payment);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, '/stores/store%2Fone/invoices/..%2Fadmin%3Fsecret%3Dx')
                && ! str_contains($url, '/../admin');
        });
    }

    // --- Coinbase Commerce ---

    public function test_coinbase_build_payment_url_calls_charges_api(): void
    {
        Http::fake([
            'api.commerce.coinbase.com/*' => Http::response(['data' => ['hosted_url' => 'https://commerce.coinbase.com/charges/abc']], 200),
        ]);
        $payment = Payment::create([
            'name' => 'Coinbase', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true,
            'config' => ['api_key' => 'key', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();

        $url = app(CoinbaseCommerceService::class)->buildPaymentUrl($order, $payment);

        $this->assertSame('https://commerce.coinbase.com/charges/abc', $url);
        Http::assertSent(fn ($request) => $request['metadata']['order_id'] === $order->trade_no);
    }

    public function test_coinbase_webhook_signature_verification(): void
    {
        $body = '{"event":{"type":"charge:confirmed"}}';
        $secret = 'whsecret';
        $validSig = hash_hmac('sha256', $body, $secret);

        $service = app(CoinbaseCommerceService::class);
        $this->assertTrue($service->verifyWebhookSignature($body, $validSig, $secret));
        $this->assertFalse($service->verifyWebhookSignature($body, 'wrong', $secret));
    }

    public function test_coinbase_notify_marks_order_paid_on_charge_confirmed(): void
    {
        $payment = Payment::create([
            'name' => 'Coinbase', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true,
            'config' => ['api_key' => 'key', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();

        $body = json_encode(['event' => ['type' => 'charge:confirmed', 'data' => ['metadata' => ['order_id' => $order->trade_no]]]]);
        $sig = hash_hmac('sha256', $body, 'whsecret');

        $this->call('POST', "/api/payment/{$payment->id}/notify", [], [], [], [
            'HTTP_X-CC-Webhook-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_coinbase_notify_rejects_bad_signature(): void
    {
        $payment = Payment::create([
            'name' => 'Coinbase', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true,
            'config' => ['api_key' => 'key', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();

        $body = json_encode(['event' => ['type' => 'charge:confirmed', 'data' => ['metadata' => ['order_id' => $order->trade_no]]]]);

        $this->call('POST', "/api/payment/{$payment->id}/notify", [], [], [], [
            'HTTP_X-CC-Webhook-Signature' => 'wrong-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    // --- CoinPayments ---

    public function test_coinpayments_build_payment_url_calls_api_with_hmac_header(): void
    {
        Http::fake([
            'www.coinpayments.net/*' => Http::response(['error' => 'ok', 'result' => ['checkout_url' => 'https://www.coinpayments.net/index.php?cmd=_pay&id=abc']], 200),
        ]);
        $payment = Payment::create([
            'name' => 'CoinPayments', 'gateway' => Payment::GATEWAY_COINPAYMENTS, 'enable' => true,
            'config' => ['public_key' => 'pub', 'private_key' => 'priv', 'ipn_secret' => 'ipnsecret'],
        ]);
        $order = $this->makeOrder();

        $url = app(CoinPaymentsService::class)->buildPaymentUrl($order, $payment);

        $this->assertSame('https://www.coinpayments.net/index.php?cmd=_pay&id=abc', $url);
        Http::assertSent(fn ($request) => $request->hasHeader('HMAC'));
    }

    public function test_coinpayments_ipn_signature_verification(): void
    {
        $body = 'custom=ABC123&status=100';
        $secret = 'ipnsecret';
        $validHmac = hash_hmac('sha512', $body, $secret);

        $service = app(CoinPaymentsService::class);
        $this->assertTrue($service->verifyIpnSignature($body, $validHmac, $secret));
        $this->assertFalse($service->verifyIpnSignature($body, 'wrong', $secret));
    }

    public function test_coinpayments_completed_status_thresholds(): void
    {
        $service = app(CoinPaymentsService::class);

        $this->assertTrue($service->isCompletedStatus(100));
        $this->assertTrue($service->isCompletedStatus(2));
        $this->assertFalse($service->isCompletedStatus(0));
        $this->assertFalse($service->isCompletedStatus(-1));
    }

    public function test_coinpayments_notify_marks_order_paid_when_status_complete(): void
    {
        $payment = Payment::create([
            'name' => 'CoinPayments', 'gateway' => Payment::GATEWAY_COINPAYMENTS, 'enable' => true,
            'config' => ['public_key' => 'pub', 'private_key' => 'priv', 'ipn_secret' => 'ipnsecret'],
        ]);
        $order = $this->makeOrder();

        $params = ['custom' => $order->trade_no, 'status' => '100'];
        $body = http_build_query($params);
        $hmac = hash_hmac('sha512', $body, 'ipnsecret');

        $this->call('POST', "/api/payment/{$payment->id}/notify", $params, [], [], [
            'HTTP_HMAC' => $hmac,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_coinpayments_notify_does_not_pay_when_status_pending(): void
    {
        $payment = Payment::create([
            'name' => 'CoinPayments', 'gateway' => Payment::GATEWAY_COINPAYMENTS, 'enable' => true,
            'config' => ['public_key' => 'pub', 'private_key' => 'priv', 'ipn_secret' => 'ipnsecret'],
        ]);
        $order = $this->makeOrder();

        $params = ['custom' => $order->trade_no, 'status' => '0'];
        $body = http_build_query($params);
        $hmac = hash_hmac('sha512', $body, 'ipnsecret');

        $this->call('POST', "/api/payment/{$payment->id}/notify", $params, [], [], [
            'HTTP_HMAC' => $hmac,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    // --- Controller-level guards ---

    public function test_btcpay_rejects_private_server_url_even_if_config_bypasses_the_admin_form(): void
    {
        Http::fake();
        $payment = Payment::create([
            'name' => 'BTCPay', 'gateway' => Payment::GATEWAY_BTCPAY, 'enable' => true,
            'config' => ['server_url' => 'https://127.0.0.1', 'api_key' => 'key', 'store_id' => 'store1', 'webhook_secret' => 'whsecret'],
        ]);

        try {
            app(BtcPayService::class)->buildPaymentUrl($this->makeOrder(), $payment);
            $this->fail('private BTCPay URL should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('内网', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_pay_rejects_another_users_order_for_new_gateways(): void
    {
        $payment = Payment::create([
            'name' => 'Coinbase', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true,
            'config' => ['api_key' => 'key', 'webhook_secret' => 'whsecret'],
        ]);
        $order = $this->makeOrder();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/pay/{$payment->id}/{$order->id}")->assertForbidden();
    }

    public function test_pay_returns_503_when_gateway_not_configured(): void
    {
        $payment = Payment::create(['name' => 'Coinbase', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);
        $order = $this->makeOrder();

        $this->actingAs($order->user)->get("/pay/{$payment->id}/{$order->id}")->assertStatus(503);
    }

    public function test_admin_can_create_a_coinbase_payment_with_config(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', 'Coinbase 支付')
            ->set('gateway', Payment::GATEWAY_COINBASE)
            ->set('cfg_api_key', 'real-key')
            ->set('cfg_webhook_secret', 'real-secret')
            ->set('fee_percent', '2.5')
            ->call('save');

        $payment = Payment::where('name', 'Coinbase 支付')->firstOrFail();
        $this->assertSame(Payment::GATEWAY_COINBASE, $payment->gateway);
        $this->assertSame('real-key', $payment->config['api_key']);
        $this->assertSame('real-secret', $payment->config['webhook_secret']);
        $this->assertSame(2.5, (float) $payment->fee_percent);
    }

    public function test_admin_cannot_save_a_btcpay_gateway_pointing_at_a_private_address(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', 'Bad BTCPay')
            ->set('gateway', Payment::GATEWAY_BTCPAY)
            ->set('cfg_server_url', 'https://169.254.169.254')
            ->set('cfg_api_key', 'key')
            ->set('cfg_store_id', 'store')
            ->set('cfg_webhook_secret', 'secret')
            ->call('save')
            ->assertHasErrors(['cfg_server_url']);

        $this->assertDatabaseMissing('payments', ['name' => 'Bad BTCPay']);
    }
}
