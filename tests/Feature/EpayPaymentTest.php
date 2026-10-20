<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\EpayService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create();

        return app(OrderService::class)->createOrder($user, $this->makePlan(), 'month');
    }

    private function makeEpayPayment(string $gateway = Payment::GATEWAY_EPAY_ALIPAY): Payment
    {
        return Payment::create([
            'name' => '支付宝支付',
            'gateway' => $gateway,
            'enable' => true,
            'config' => [
                'gateway_url' => 'https://pay.example.com',
                'pid' => '1001',
                'key' => 'test-merchant-key',
            ],
        ]);
    }

    /** Mirrors EpayService's private sign() so tests can build valid notify payloads independently. */
    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return md5(implode('&', $pairs).'test-merchant-key');
    }

    public function test_is_configured_reflects_whether_all_three_config_fields_are_present(): void
    {
        $unconfigured = Payment::create(['name' => 'x', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY]);
        $this->assertFalse(app(EpayService::class)->isConfigured($unconfigured));

        $configured = $this->makeEpayPayment();
        $this->assertTrue(app(EpayService::class)->isConfigured($configured));
    }

    public function test_build_payment_url_points_at_gateway_with_a_valid_signature(): void
    {
        $payment = $this->makeEpayPayment(Payment::GATEWAY_EPAY_ALIPAY);
        $order = $this->makeOrder();

        $url = app(EpayService::class)->buildPaymentUrl($order, $payment);

        $this->assertStringStartsWith('https://pay.example.com/submit.php?', $url);
        $this->assertStringContainsString('pid=1001', $url);
        $this->assertStringContainsString('type=alipay', $url);
        $this->assertStringContainsString('out_trade_no='.$order->trade_no, $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertTrue(app(EpayService::class)->verifyNotify($params, $payment));
    }

    public function test_build_payment_url_uses_wxpay_type_for_the_wxpay_gateway(): void
    {
        $payment = $this->makeEpayPayment(Payment::GATEWAY_EPAY_WXPAY);
        $order = $this->makeOrder();

        $url = app(EpayService::class)->buildPaymentUrl($order, $payment);

        $this->assertStringContainsString('type=wxpay', $url);
    }

    public function test_pay_redirects_to_gateway_for_the_orders_owner(): void
    {
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();

        $response = $this->actingAs($order->user)->get("/pay/{$payment->id}/{$order->id}");

        $response->assertRedirect();
        $this->assertStringStartsWith('https://pay.example.com/submit.php?', $response->headers->get('Location'));
    }

    public function test_pay_rejects_another_users_order(): void
    {
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/pay/{$payment->id}/{$order->id}")->assertForbidden();
    }

    public function test_pay_returns_service_unavailable_when_gateway_not_configured(): void
    {
        $payment = Payment::create(['name' => '支付宝支付', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY, 'enable' => true]);
        $order = $this->makeOrder();

        $this->actingAs($order->user)->get("/pay/{$payment->id}/{$order->id}")->assertStatus(503);
    }

    public function test_pay_returns_service_unavailable_when_the_specific_payment_row_is_disabled(): void
    {
        $payment = Payment::create([
            'name' => '支付宝支付', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY, 'enable' => false,
            'config' => ['gateway_url' => 'https://pay.example.com', 'pid' => '1001', 'key' => 'test-merchant-key'],
        ]);
        $order = $this->makeOrder();

        $this->actingAs($order->user)->get("/pay/{$payment->id}/{$order->id}")->assertStatus(503);
    }

    public function test_notify_with_valid_signature_and_trade_success_marks_order_paid(): void
    {
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();

        $params = [
            'pid' => '1001',
            'trade_no' => 'GATEWAY123',
            'out_trade_no' => $order->trade_no,
            'type' => 'alipay',
            'name' => $order->plan->name,
            'money' => '15.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson("/api/payment/{$payment->id}/notify", $params)
            ->assertOk()
            ->assertSee('success');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_notify_reporting_less_than_the_order_amount_is_rejected(): void
    {
        // Even with a valid signature and TRADE_SUCCESS, a callback that only
        // collected ¥1 for a ¥15 order must NOT provision it.
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();

        $params = [
            'pid' => '1001',
            'trade_no' => 'GATEWAY123',
            'out_trade_no' => $order->trade_no,
            'type' => 'alipay',
            'money' => '1.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson("/api/payment/{$payment->id}/notify", $params)->assertStatus(404);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_notify_with_bad_signature_is_rejected_and_does_not_pay(): void
    {
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();

        $this->postJson("/api/payment/{$payment->id}/notify", [
            'pid' => '1001',
            'out_trade_no' => $order->trade_no,
            'trade_status' => 'TRADE_SUCCESS',
            'sign' => 'not-a-real-signature',
        ])->assertStatus(400);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_notify_for_unknown_trade_no_is_rejected(): void
    {
        $payment = $this->makeEpayPayment();

        $params = [
            'pid' => '1001',
            'out_trade_no' => 'DOES-NOT-EXIST',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson("/api/payment/{$payment->id}/notify", $params)->assertStatus(404);
    }

    public function test_notify_sets_payment_id_when_a_matching_payment_row_exists(): void
    {
        $payment = $this->makeEpayPayment();
        $order = $this->makeOrder();

        $params = [
            'pid' => '1001',
            'out_trade_no' => $order->trade_no,
            'type' => 'alipay',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson("/api/payment/{$payment->id}/notify", $params)->assertOk();

        $this->assertSame($payment->id, $order->fresh()->payment_id);
    }

    public function test_legacy_notify_url_still_works_for_alipay(): void
    {
        $payment = $this->makeEpayPayment(Payment::GATEWAY_EPAY_ALIPAY);
        $order = $this->makeOrder();

        $params = [
            'pid' => '1001',
            'out_trade_no' => $order->trade_no,
            'type' => 'alipay',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson('/api/epay/notify', $params)
            ->assertOk()
            ->assertSee('success');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_legacy_notify_url_routes_wxpay_to_the_wxpay_payment_row(): void
    {
        $this->makeEpayPayment(Payment::GATEWAY_EPAY_ALIPAY);
        $wxpay = $this->makeEpayPayment(Payment::GATEWAY_EPAY_WXPAY);
        $order = $this->makeOrder();

        $params = [
            'pid' => '1001',
            'out_trade_no' => $order->trade_no,
            'type' => 'wxpay',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params);

        $this->postJson('/api/epay/notify', $params)->assertOk();

        $this->assertSame($wxpay->id, $order->fresh()->payment_id);
    }

    public function test_legacy_notify_url_404s_when_no_matching_payment_row_exists(): void
    {
        $this->postJson('/api/epay/notify', ['type' => 'alipay'])->assertStatus(404);
    }

    public function test_customer_orders_page_shows_real_gateway_buttons_when_configured(): void
    {
        $this->makeEpayPayment();
        $user = User::factory()->create();
        $plan = $this->makePlan();
        app(OrderService::class)->createOrder($user, $plan, 'month');

        \Livewire\Livewire::actingAs($user)->test('plans')
            ->assertSee('支付宝支付')
            ->assertDontSee('模拟支付'); // real gateway configured → no mock button
    }

    public function test_customer_orders_page_falls_back_to_mock_pay_when_not_configured(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();
        app(OrderService::class)->createOrder($user, $plan, 'month');

        \Livewire\Livewire::actingAs($user)->test('plans')
            ->assertSee('模拟支付')
            ->assertDontSee('支付宝支付');
    }

    public function test_admin_can_create_an_epay_alipay_payment_with_config(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', '支付宝')
            ->set('gateway', Payment::GATEWAY_EPAY_ALIPAY)
            ->set('cfg_gateway_url', 'https://niuniuapi.example.cn')
            ->set('cfg_pid', '1175')
            ->set('cfg_key', 'real-merchant-key')
            ->call('save');

        $this->assertDatabaseHas('payments', ['name' => '支付宝', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY]);
        $payment = Payment::where('gateway', Payment::GATEWAY_EPAY_ALIPAY)->first();
        $this->assertSame('https://niuniuapi.example.cn', $payment->config['gateway_url']);
        $this->assertSame('1175', $payment->config['pid']);
        $this->assertSame('real-merchant-key', $payment->config['key']);
    }
}
