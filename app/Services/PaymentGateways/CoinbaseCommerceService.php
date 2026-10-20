<?php

namespace App\Services\PaymentGateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * Coinbase Commerce API — https://docs.cloud.coinbase.com/commerce/reference.
 * Config expected on the Payment row: api_key, webhook_secret.
 */
class CoinbaseCommerceService
{
    private const API_URL = 'https://api.commerce.coinbase.com/charges';

    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];

        return ! empty($config['api_key']) && ! empty($config['webhook_secret']);
    }

    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $amount = number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', '');

        $response = Http::withHeaders([
            'X-CC-Api-Key' => $config['api_key'],
            'X-CC-Version' => '2018-03-22',
        ])->post(self::API_URL, [
            'name' => "订单 {$order->trade_no}",
            'description' => "订单 {$order->trade_no} 支付",
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => $amount,
                'currency' => $config['currency'] ?? 'USD',
            ],
            'metadata' => ['order_id' => $order->trade_no],
            'redirect_url' => url('/orders'),
            'cancel_url' => url('/orders'),
        ]);

        $response->throw();

        return $response->json('data.hosted_url');
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool
    {
        if (! $signatureHeader) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @return array{type:string,orderTradeNo:?string}
     */
    public function parseWebhookEvent(array $payload): array
    {
        return [
            'type' => $payload['event']['type'] ?? '',
            'orderTradeNo' => $payload['event']['data']['metadata']['order_id'] ?? null,
        ];
    }
}
