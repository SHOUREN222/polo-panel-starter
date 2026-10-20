<?php

namespace App\Services\PaymentGateways;

use App\Models\Order;
use App\Models\Payment;

/**
 * Alipay's own Open Platform API (支付宝开放平台, alipay.trade.page.pay) —
 * a direct merchant integration, distinct from a third-party aggregator
 * like EPay. Requires a real app_id + RSA2 key pair issued by Alipay to a
 * verified merchant account. Sign algorithm per Alipay's official spec:
 * sort params (excluding sign/sign_type/empty) by key ASCII ascending,
 * concatenate as k=v&k=v (unescaped), sign with the merchant's RSA2
 * private key (SHA256withRSA), base64-encode.
 *
 * Config expected on the Payment row: app_id, private_key (merchant's,
 * PEM without header/footer), alipay_public_key (Alipay's, for verifying
 * notify callbacks).
 */
class AlipayF2FService
{
    private const GATEWAY_URL = 'https://openapi.alipay.com/gateway.do';

    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];

        return ! empty($config['app_id']) && ! empty($config['private_key']) && ! empty($config['alipay_public_key']);
    }

    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $amount = number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', '');

        $bizContent = json_encode([
            'out_trade_no' => $order->trade_no,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'total_amount' => $amount,
            'subject' => "订单 {$order->trade_no}",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $params = [
            'app_id' => $config['app_id'],
            'method' => 'alipay.trade.page.pay',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => url("/api/payment/{$payment->id}/notify"),
            'return_url' => url('/orders'),
            'biz_content' => $bizContent,
        ];

        $params['sign'] = $this->sign($params, $config['private_key']);

        return self::GATEWAY_URL.'?'.http_build_query($params);
    }

    public function verifyNotify(array $params, Payment $payment): bool
    {
        $config = $payment->config ?? [];
        if (empty($config['alipay_public_key'])) {
            return false;
        }

        $sign = $params['sign'] ?? '';
        unset($params['sign'], $params['sign_type']);

        $signContent = $this->buildSignContent($params);
        $publicKey = $this->wrapPem($config['alipay_public_key'], 'PUBLIC KEY');

        return openssl_verify($signContent, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function sign(array $params, string $privateKey): string
    {
        $signContent = $this->buildSignContent($params);
        $key = $this->wrapPem($privateKey, 'PRIVATE KEY');

        openssl_sign($signContent, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function buildSignContent(array $params): string
    {
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');
        ksort($params);

        return collect($params)->map(fn ($value, $key) => "{$key}={$value}")->implode('&');
    }

    /**
     * Alipay merchant consoles hand out RSA keys as bare base64 without PEM
     * headers — wrap them so openssl's PEM parser accepts them.
     */
    private function wrapPem(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $chunks = trim(chunk_split($key, 64, "\n"));

        return "-----BEGIN {$type}-----\n{$chunks}\n-----END {$type}-----";
    }
}
