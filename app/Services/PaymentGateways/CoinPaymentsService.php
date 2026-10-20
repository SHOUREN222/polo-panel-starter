<?php

namespace App\Services\PaymentGateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * CoinPayments.net classic HTTP API —
 * https://www.coinpayments.net/apidoc-create-transaction. Config expected
 * on the Payment row: public_key, private_key (for signing API requests),
 * ipn_secret (a *separate* secret configured in the CoinPayments merchant
 * panel specifically for verifying IPN callbacks — not the same as
 * private_key, per their own docs).
 */
class CoinPaymentsService
{
    private const API_URL = 'https://www.coinpayments.net/api.php';

    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];

        return ! empty($config['public_key']) && ! empty($config['private_key']) && ! empty($config['ipn_secret']);
    }

    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $amount = number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', '');

        $params = [
            'version' => 1,
            'cmd' => 'create_transaction',
            'key' => $config['public_key'],
            'amount' => $amount,
            'currency1' => $config['currency1'] ?? 'USD',
            'currency2' => $config['currency2'] ?? 'BTC',
            'item_name' => "订单 {$order->trade_no}",
            'custom' => $order->trade_no,
            'ipn_url' => url("/api/payment/{$payment->id}/notify"),
        ];

        $body = http_build_query($params);
        $hmac = hash_hmac('sha512', $body, $config['private_key']);

        $response = Http::withHeaders(['HMAC' => $hmac])
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->post(self::API_URL);

        $response->throw();
        $result = $response->json();

        if (($result['error'] ?? '') !== 'ok') {
            throw new \RuntimeException('CoinPayments API error: '.($result['error'] ?? 'unknown'));
        }

        return $result['result']['checkout_url'] ?? $result['result']['status_url'];
    }

    public function verifyIpnSignature(string $rawBody, ?string $hmacHeader, string $ipnSecret): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $ipnSecret);

        return hash_equals($expected, $hmacHeader);
    }

    /**
     * CoinPayments IPN status codes: >= 100 (or == 2) means complete,
     * 0/1 mean pending, negative means cancelled/timed out/error.
     */
    public function isCompletedStatus(int $status): bool
    {
        return $status >= 100 || $status === 2;
    }
}
