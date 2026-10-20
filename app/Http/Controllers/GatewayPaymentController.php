<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\EpayService;
use App\Services\OrderService;
use App\Services\PaymentGateways\AlipayF2FService;
use App\Services\PaymentGateways\BtcPayService;
use App\Services\PaymentGateways\CoinbaseCommerceService;
use App\Services\PaymentGateways\CoinPaymentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Single dispatch point for every gateway type (EPay/AlipayF2F/BTCPay/
 * Coinbase/CoinPayments) — each Payment row carries its own credentials in
 * `config`, so the whole payments setup lives in one place (支付配置)
 * instead of EPay's credentials living in a separate global Settings tab.
 */
class GatewayPaymentController extends Controller
{
    public function __construct(
        private readonly EpayService $epay,
        private readonly AlipayF2FService $alipayF2F,
        private readonly BtcPayService $btcPay,
        private readonly CoinbaseCommerceService $coinbase,
        private readonly CoinPaymentsService $coinPayments,
    ) {
    }

    public function pay(Request $request, Payment $payment, Order $order)
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($order->status === Order::STATUS_PENDING, 400, '订单状态不允许支付');
        abort_unless($payment->enable, 503, '该支付方式已停用');

        $url = match ($payment->gateway) {
            Payment::GATEWAY_EPAY_ALIPAY, Payment::GATEWAY_EPAY_WXPAY => $this->guardConfigured($this->epay, $payment)->buildPaymentUrl($order, $payment),
            Payment::GATEWAY_ALIPAYF2F => $this->guardConfigured($this->alipayF2F, $payment)->buildPaymentUrl($order, $payment),
            Payment::GATEWAY_BTCPAY => $this->guardConfigured($this->btcPay, $payment)->buildPaymentUrl($order, $payment),
            Payment::GATEWAY_COINBASE => $this->guardConfigured($this->coinbase, $payment)->buildPaymentUrl($order, $payment),
            Payment::GATEWAY_COINPAYMENTS => $this->guardConfigured($this->coinPayments, $payment)->buildPaymentUrl($order, $payment),
            default => abort(404),
        };

        return redirect()->away($url);
    }

    private function guardConfigured(object $service, Payment $payment): object
    {
        abort_unless($service->isConfigured($payment), 503, '支付网关未配置');

        return $service;
    }

    public function notify(Request $request, string $payment, OrderService $orderService)
    {
        // Payment + Order are per-tenant, and this webhook is on the api group
        // (not tenancy-aware). The checkout ran on the 分站 subdomain, so its
        // notify_url carries that host — resolve the tenant from it and run the
        // handler in that context so the order/gateway resolve in the right DB
        // (and wholesale settles). A central-domain host → 主站, unchanged.
        $handle = fn () => $this->dispatchNotify($request, (int) $payment, $orderService);

        return ($tenant = $this->resolveTenant($request->getHost()))
            ? $tenant->run($handle)
            : $handle();
    }

    private function dispatchNotify(Request $request, int $paymentId, OrderService $orderService)
    {
        $payment = Payment::find($paymentId);
        if (! $payment) {
            return response('fail', 404);
        }

        return match ($payment->gateway) {
            Payment::GATEWAY_EPAY_ALIPAY, Payment::GATEWAY_EPAY_WXPAY => $this->notifyEpay($request, $payment, $orderService),
            Payment::GATEWAY_ALIPAYF2F => $this->notifyAlipayF2F($request, $payment, $orderService),
            Payment::GATEWAY_BTCPAY => $this->notifyBtcPay($request, $payment, $orderService),
            Payment::GATEWAY_COINBASE => $this->notifyCoinbase($request, $payment, $orderService),
            Payment::GATEWAY_COINPAYMENTS => $this->notifyCoinPayments($request, $payment, $orderService),
            default => response('fail', 404),
        };
    }

    /** Resolve a 分站 from the webhook host; null for the central 主站. */
    private function resolveTenant(string $host): ?\App\Models\Tenant
    {
        if (in_array($host, config('tenancy.central_domains'), true)) {
            return null;
        }
        $subdomain = explode('.', $host)[0] ?? '';

        return \Stancl\Tenancy\Database\Models\Domain::where('domain', $subdomain)->first()?->tenant;
    }

    /**
     * Transitional compat shim for the old `/api/epay/notify` URL (no
     * {payment} in the path) — any checkout session started before this
     * per-Payment routing existed still has that URL baked into its
     * notify_url, so the gateway will keep POSTing here for those orders
     * until they're all settled. Safe to remove once confirmed unused.
     */
    public function legacyEpayNotify(Request $request, OrderService $orderService)
    {
        $gateway = $request->input('type') === EpayService::TYPE_WXPAY
            ? Payment::GATEWAY_EPAY_WXPAY
            : Payment::GATEWAY_EPAY_ALIPAY;

        $payment = Payment::where('gateway', $gateway)->first();
        if (! $payment) {
            return response('fail', 404);
        }

        return $this->notifyEpay($request, $payment, $orderService);
    }

    private function notifyEpay(Request $request, Payment $payment, OrderService $orderService)
    {
        $params = $request->all();

        if (! $this->epay->verifyNotify($params, $payment)) {
            Log::warning('Epay notify signature mismatch', ['payment_id' => $payment->id]);

            return response('fail', 400);
        }

        if (($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') {
            return response('success');
        }

        return $this->markOrderPaid($params['out_trade_no'] ?? null, $payment, $orderService, isset($params['money']) ? (float) $params['money'] : null)
            ? response('success')
            : response('fail', 404);
    }

    private function notifyAlipayF2F(Request $request, Payment $payment, OrderService $orderService)
    {
        $params = $request->all();

        if (! $this->alipayF2F->verifyNotify($params, $payment)) {
            Log::warning('AlipayF2F notify signature mismatch', ['payment_id' => $payment->id]);

            return response('fail', 400);
        }

        if (($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') {
            return response('success');
        }

        return $this->markOrderPaid($params['out_trade_no'] ?? null, $payment, $orderService, isset($params['total_amount']) ? (float) $params['total_amount'] : null)
            ? response('success')
            : response('fail', 404);
    }

    private function notifyBtcPay(Request $request, Payment $payment, OrderService $orderService)
    {
        $secret = $payment->config['webhook_secret'] ?? '';
        $rawBody = $request->getContent();

        if (! $this->btcPay->verifyWebhookSignature($rawBody, $request->header('BTCPay-Sig'), $secret)) {
            Log::warning('BTCPay webhook signature mismatch', ['payment_id' => $payment->id]);

            return response('fail', 400);
        }

        $payload = $request->json()->all();
        if (($payload['type'] ?? '') !== 'InvoiceSettled') {
            return response('ok');
        }

        $tradeNo = $this->btcPay->fetchOrderTradeNo($payload['invoiceId'] ?? '', $payment);

        return $this->markOrderPaid($tradeNo, $payment, $orderService)
            ? response('ok')
            : response('fail', 404);
    }

    private function notifyCoinbase(Request $request, Payment $payment, OrderService $orderService)
    {
        $secret = $payment->config['webhook_secret'] ?? '';
        $rawBody = $request->getContent();

        if (! $this->coinbase->verifyWebhookSignature($rawBody, $request->header('X-CC-Webhook-Signature'), $secret)) {
            Log::warning('Coinbase webhook signature mismatch', ['payment_id' => $payment->id]);

            return response('fail', 400);
        }

        $event = $this->coinbase->parseWebhookEvent($request->json()->all());
        if ($event['type'] !== 'charge:confirmed') {
            return response('ok');
        }

        return $this->markOrderPaid($event['orderTradeNo'], $payment, $orderService)
            ? response('ok')
            : response('fail', 404);
    }

    private function notifyCoinPayments(Request $request, Payment $payment, OrderService $orderService)
    {
        $secret = $payment->config['ipn_secret'] ?? '';
        $rawBody = $request->getContent();

        if (! $this->coinPayments->verifyIpnSignature($rawBody, $request->header('HMAC'), $secret)) {
            Log::warning('CoinPayments IPN signature mismatch', ['payment_id' => $payment->id]);

            return response('fail', 400);
        }

        $params = $request->all();
        if (! $this->coinPayments->isCompletedStatus((int) ($params['status'] ?? 0))) {
            return response('IPN OK');
        }

        return $this->markOrderPaid($params['custom'] ?? null, $payment, $orderService, isset($params['amount1']) ? (float) $params['amount1'] : null)
            ? response('IPN OK')
            : response('fail', 404);
    }

    /**
     * $paidYuan is the amount the gateway reports it actually collected (fiat,
     * yuan). When provided it must cover what we asked the gateway to charge —
     * otherwise a signature-valid notify for a smaller amount could provision a
     * full order ("pay ¥1, get a ¥100 line"). It's null only for crypto-invoice
     * gateways (BTCPay / Coinbase) whose settled/confirmed webhook fires solely
     * on full payment, so the status itself is the amount guarantee.
     */
    private function markOrderPaid(?string $tradeNo, Payment $payment, OrderService $orderService, ?float $paidYuan = null): bool
    {
        if (! $tradeNo) {
            return false;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (! $order) {
            Log::warning('Payment notify for unknown trade_no', ['trade_no' => $tradeNo, 'payment_id' => $payment->id]);

            return false;
        }

        if ($paidYuan !== null) {
            $paidCents = (int) round($paidYuan * 100);
            $expectedCents = $payment->amountWithFee($order->payableAmount());
            if ($paidCents < $expectedCents) {
                Log::warning('Payment notify amount too low, rejecting', [
                    'trade_no' => $tradeNo, 'payment_id' => $payment->id,
                    'paid_cents' => $paidCents, 'expected_cents' => $expectedCents,
                ]);

                return false;
            }
        }

        if (! $order->payment_id) {
            $order->update(['payment_id' => $payment->id]);
        }

        $orderService->markPaid($order);

        return true;
    }
}
