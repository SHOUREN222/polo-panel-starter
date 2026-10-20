<?php

namespace App\Services\PaymentGateways;

use App\Models\Order;
use App\Models\Payment;
use App\Support\PublicHttpUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BTCPay Server (self-hosted Bitcoin/Lightning processor) via its
 * Greenfield REST API — https://docs.btcpayserver.org/API/Greenfield/v1/.
 * Config expected on the Payment row: server_url (the merchant's own
 * BTCPay instance), api_key, store_id, webhook_secret.
 */
class BtcPayService
{
    public function __construct(private readonly PublicHttpUrl $publicHttpUrl) {}

    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];

        return ! empty($config['server_url']) && ! empty($config['api_key']) && ! empty($config['store_id']);
    }

    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $amount = number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', '');
        $target = $this->publicHttpUrl->resolve((string) $config['server_url']);
        $storeId = rawurlencode((string) $config['store_id']);

        $response = $this->client($target, (string) $config['api_key'])
            ->post("{$target['url']}/api/v1/stores/{$storeId}/invoices", [
                'amount' => $amount,
                'currency' => $config['currency'] ?? 'USD',
                'metadata' => ['orderId' => $order->trade_no],
                'checkout' => [
                    'redirectURL' => url('/orders'),
                ],
            ]);

        $response->throw();

        return $response->json('checkoutLink');
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool
    {
        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * BTCPay's webhook payload only carries the invoiceId + event type, not
     * our own order reference — a real Greenfield follow-up GET call
     * against the invoice resolves the `metadata.orderId` we set at
     * creation time.
     */
    public function fetchOrderTradeNo(string $invoiceId, Payment $payment): ?string
    {
        $config = $payment->config ?? [];
        $target = $this->publicHttpUrl->resolve((string) $config['server_url']);
        $storeId = rawurlencode((string) $config['store_id']);
        $invoiceId = rawurlencode($invoiceId);

        $response = $this->client($target, (string) $config['api_key'])
            ->get("{$target['url']}/api/v1/stores/{$storeId}/invoices/{$invoiceId}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json('metadata.orderId');
    }

    /**
     * Pin the request to the public IP we just validated. Re-resolving on every
     * call plus CURLOPT_RESOLVE closes the DNS-rebinding gap between checking a
     * hostname and connecting to it. Redirects stay off so a public host cannot
     * bounce the server into an internal network target.
     *
     * @param  array{url: string, host: string, port: int, ip: string}  $target
     */
    private function client(array $target, string $apiKey): PendingRequest
    {
        $options = [];

        if ($target['host'] !== $target['ip']) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new \RuntimeException('Secure BTCPay DNS pinning requires the PHP cURL extension.');
            }

            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"],
            ];
        }

        return Http::withHeaders(['Authorization' => 'token '.$apiKey])
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(15)
            ->withOptions($options);
    }
}
