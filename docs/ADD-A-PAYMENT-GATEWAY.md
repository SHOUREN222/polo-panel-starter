# 如何接入一家新的支付渠道

支付渠道是"加法式"扩展的：每一家 = 一个网关标识 + 一个 Service 类，面板按每条
`payments` 记录上的 `gateway` 字段分发到对应 Service。已有 6 家真实渠道
（EPay 支付宝 / EPay 微信 / AlipayF2F / BTCPay / Coinbase / CoinPayments）都是
这么长出来的，另有一个只给本地测试用的 `mock`。

下面以接入 **PayPal**（示例代号 `paypal`）为例，走完整流程。整套改动动 **5 处**，
其中 4 处是照抄现有渠道的样板。**路由不用改**——`/pay/{payment}/{order}` 和
`/api/payment/{payment}/notify` 是通用的，`{payment}` 已经带了是哪条渠道。

---

## 1. 注册网关标识 — `app/Models/Payment.php`

加一个常量，并在 `GATEWAY_LABELS` 里加一行：

```php
public const GATEWAY_PAYPAL = 'paypal';

public const GATEWAY_LABELS = [
    // ...现有的...
    self::GATEWAY_PAYPAL => 'PayPal',
];
```

> ⚠️ 加进 `GATEWAY_LABELS` 就够了 —— 客户结账页读的是
> `Payment::checkoutGateways()`（= `array_keys(GATEWAY_LABELS)`），新渠道会**自动**
> 出现在付款按钮里，不用再改 plans/orders 页面。`mock` 故意不在 `GATEWAY_LABELS`
> 里，所以它永远不会作为收银按钮露出。

## 2. 写 Service — `app/Services/PaymentGateways/PaypalService.php`

照抄 `CoinbaseCommerceService.php`（最简单的"托管跳转 + HMAC 回调"样板）。
这是事实上的契约（没有正式 interface，鸭子类型），必须实现这三件事：

```php
namespace App\Services\PaymentGateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PaypalService
{
    // (1) 判断这条渠道的 config 里该填的密钥都填了没
    public function isConfigured(Payment $payment): bool
    {
        $config = $payment->config ?? [];
        return ! empty($config['client_id']) && ! empty($config['client_secret']);
    }

    // (2) 调上游 API 建订单，返回收银台跳转 URL。
    //     金额务必走 amountWithFee(payableAmount())，这样余额抵扣后的
    //     剩余金额 + 该渠道自己的手续费都算对。
    public function buildPaymentUrl(Order $order, Payment $payment): string
    {
        $config = $payment->config ?? [];
        $amount = number_format($payment->amountWithFee($order->payableAmount()) / 100, 2, '.', '');

        $response = Http::withToken($this->accessToken($config))->post('https://api-m.paypal.com/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $order->trade_no,                       // ← 回调靠它认单
                'amount' => ['currency_code' => $config['currency'] ?? 'USD', 'value' => $amount],
            ]],
            'application_context' => [
                'return_url' => url('/orders'),
                'cancel_url' => url('/orders'),
            ],
        ]);
        $response->throw();

        // 从返回里取 approve 链接
        return collect($response->json('links'))->firstWhere('rel', 'approve')['href'];
    }

    // (3) 验签 + 解析回调，认出"哪一单、成没成功"
    public function verifyAndParse(string $rawBody, array $headers, Payment $payment): array
    {
        // ...按对方文档验签（PayPal 是 verify-webhook-signature 接口或证书验签）...
        $payload = json_decode($rawBody, true);
        return [
            'ok' => true,
            'paid' => ($payload['event_type'] ?? '') === 'CHECKOUT.ORDER.APPROVED',
            'orderTradeNo' => $payload['resource']['purchase_units'][0]['custom_id'] ?? null,
        ];
    }
}
```

**关键点**
- 金额一律用 `$payment->amountWithFee($order->payableAmount())`（分），别用
  `total_amount`——那样会漏掉余额抵扣和渠道手续费。
- 建单时把 `$order->trade_no` 塞进对方的 `custom_id` / `metadata` / `out_trade_no`，
  回调时才能反查是哪一单。
- 回调里**必须验签**，验不过返回 4xx 并 `Log::warning`（照现有渠道）。

## 3. 接进分发器 — `app/Http/Controllers/GatewayPaymentController.php`

三步：

```php
// (a) 构造函数注入
public function __construct(
    // ...现有的...
    private readonly PaypalService $paypal,
) {}

// (b) pay() 的 match 里加一条
Payment::GATEWAY_PAYPAL => $this->guardConfigured($this->paypal, $payment)->buildPaymentUrl($order, $payment),

// (c) notify() 的 match 里加一条，并写对应的 notifyPaypal() 私有方法
Payment::GATEWAY_PAYPAL => $this->notifyPaypal($request, $payment, $orderService),
```

`notifyPaypal()` 照抄 `notifyCoinbase()`：验签 → 判断是否支付成功 →
`markOrderPaid($tradeNo, $payment, $orderService)`（这个共用助手会幂等地把订单标记
已支付并触发发货/开通，重复回调不会重复发货）。

## 4. 后台配置表单 — `resources/views/components/admin/⚡payments.blade.php`

让管理员能在「支付配置」里填这家的密钥。四小步：

1. `configKeysFor()` 映射里加一行，列出该渠道要存的 config 字段名：
   ```php
   Payment::GATEWAY_PAYPAL => ['client_id', 'client_secret', 'webhook_id', 'currency'],
   ```
2. 每个新字段加一个 `public string $cfg_xxx = '';` 属性（已有的字段名如
   `cfg_currency` 能复用就复用）。
3. `resetForm()` 里把新增的 `cfg_xxx` 一并清空。
4. 下拉框加 `<option value="{{ Payment::GATEWAY_PAYPAL }}">PayPal</option>`，
   再加一段 `@elseif ($gateway === Payment::GATEWAY_PAYPAL)` 的输入框区块。

> 保存逻辑是通用的：它按 `configKeysFor()` 把对应的 `cfg_*` 收进 `config` JSON，
> 所以你只要把字段声明齐，存取就自动通了。

## 5. 测试 — `tests/Feature/`

照抄现有的网关测试（如 Coinbase/BTCPay 的），用 `Http::fake()` 断言：
- `buildPaymentUrl` 发出的请求参数正确（金额、trade_no、币种）、返回了跳转 URL；
- 回调验签：签名对 → 订单变已支付；签名错 → 返回 4xx 且订单不动；
- 未配置时 `isConfigured` 为 false、`pay()` 返回 503。

---

## 检查清单

- [ ] `Payment.php`：常量 + `GATEWAY_LABELS` 一行
- [ ] `app/Services/PaymentGateways/XxxService.php`：`isConfigured` / `buildPaymentUrl` / 验签解析
- [ ] `GatewayPaymentController.php`：构造函数注入 + `pay()` arm + `notify()` arm + `notifyXxx()`
- [ ] `admin/⚡payments.blade.php`：`configKeysFor` + `cfg_*` 属性 + `resetForm` + option + 输入框区块
- [ ] `tests/Feature/`：建单 + 回调验签 + 未配置 三类用例
- [ ] `php artisan test` 全绿；后台「支付配置」新增该渠道并填密钥；结账页确认按钮出现

**不需要改**：路由、插件管理页（读 `GATEWAY_LABELS` 自动出现）、客户结账页
（读 `Payment::checkoutGateways()` 自动出现）。
