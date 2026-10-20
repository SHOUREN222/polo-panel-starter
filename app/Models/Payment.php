<?php

namespace App\Models;

use App\Casts\EncryptedConfigArray;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    // Per-tenant: each 分站 configures its OWN gateway (agent's EPay pid/key),
    // so checkout money goes to the agent. In central context this reads the
    // 主站's own payments — no CentralConnection pin.
    public const GATEWAY_MOCK = 'mock';

    public const GATEWAY_EPAY_ALIPAY = 'epay_alipay';

    public const GATEWAY_EPAY_WXPAY = 'epay_wxpay';

    public const GATEWAY_ALIPAYF2F = 'alipayf2f';

    public const GATEWAY_BTCPAY = 'btcpay';

    public const GATEWAY_COINBASE = 'coinbase';

    public const GATEWAY_COINPAYMENTS = 'coinpayments';

    public const GATEWAY_LABELS = [
        self::GATEWAY_EPAY_ALIPAY => 'EPay（支付宝）',
        self::GATEWAY_EPAY_WXPAY => 'EPay（微信）',
        self::GATEWAY_ALIPAYF2F => 'AlipayF2F',
        self::GATEWAY_BTCPAY => 'BTCPay',
        self::GATEWAY_COINBASE => 'Coinbase',
        self::GATEWAY_COINPAYMENTS => 'CoinPayments',
    ];

    /**
     * The real, customer-facing gateway identifiers — every gateway in
     * GATEWAY_LABELS (which deliberately excludes GATEWAY_MOCK, so the mock
     * channel never shows up as a checkout button). Adding a new gateway to
     * GATEWAY_LABELS is enough to surface it on the checkout page; callers
     * should filter enabled Payments through this instead of hardcoding a
     * list, so there is exactly one place to update.
     *
     * @return list<string>
     */
    public static function checkoutGateways(): array
    {
        return array_keys(self::GATEWAY_LABELS);
    }

    protected $fillable = ['name', 'gateway', 'enable', 'icon_url', 'notify_domain', 'fee_percent', 'fee_fixed', 'config'];

    protected $casts = [
        'enable' => 'boolean',
        'fee_percent' => 'decimal:2',
        'fee_fixed' => 'integer',
        'config' => EncryptedConfigArray::class,
    ];

    /**
     * Total amount (cents) after this payment method's own fee is applied
     * on top of the order's price — some gateways (crypto especially) pass
     * their processing fee to the customer rather than absorbing it.
     */
    public function amountWithFee(int $baseAmount): int
    {
        $percentFee = (int) round($baseAmount * ((float) $this->fee_percent) / 100);

        return $baseAmount + $percentFee + $this->fee_fixed;
    }
}
