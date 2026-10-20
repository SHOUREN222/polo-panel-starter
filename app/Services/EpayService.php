<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;

/**
 * 易支付-protocol aggregator (submit.php GET-redirect checkout). Unlike
 * Alipay/Coinbase/etc this one account can power two separate Payment rows
 * (支付宝/微信), so gateway_url/pid/key live in each row's own `config` —
 * same convention as every other gateway in this file's directory — rather
 * than a single global Setting, so it's all managed from one place (支付配置).
 */
class EpayService
{
    public const TYPE_ALIPAY = 'alipay';
    public const TYPE_WXPAY = 'wxpay';

    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];

        return ! empty($config['gateway_url']) && ! empty($config['pid']) && ! empty($config['key']);
    }

    /**
     * Build the URL that redirects the browser to the gateway's hosted
     * checkout page (GET to submit.php, the standard 易支付-protocol flow).
     */
    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $type = $payment->gateway === Payment::GATEWAY_EPAY_WXPAY ? self::TYPE_WXPAY : self::TYPE_ALIPAY;

        $params = [
            'pid' => $config['pid'],
            'type' => $type,
            'out_trade_no' => $order->trade_no,
            'notify_url' => url("/api/payment/{$payment->id}/notify"),
            'return_url' => url('/orders'),
            'name' => $order->plan->name,
            'money' => number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', ''),
            'sitename' => Setting::get('site_name', config('app.name')),
        ];
        $params['sign'] = $this->sign($params, $config['key']);
        $params['sign_type'] = 'MD5';

        return rtrim($config['gateway_url'], '/').'/submit.php?'.http_build_query($params);
    }

    public function verifyNotify(array $params, Payment $payment): bool
    {
        $config = $payment->config ?? [];
        if (empty($config['key'])) {
            return false;
        }

        $sign = $params['sign'] ?? '';

        return $sign !== '' && hash_equals($this->sign($params, $config['key']), $sign);
    }

    /**
     * Standard 易支付-protocol MD5 sign: drop sign/sign_type/empty values,
     * sort remaining params by key, concatenate as "k=v&k=v...", then
     * append the merchant key directly (no separator) and hash.
     */
    private function sign(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }

        return md5(implode('&', $pairs).$key);
    }
}
