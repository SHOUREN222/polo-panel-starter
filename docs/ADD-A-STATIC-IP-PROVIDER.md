# 如何接入第二家（或更多）静态 IP 供应商

静态 ISP 线路的"开通渠道"是可插拔的：每家供应商 = 一个实现 `ProvisionerInterface` 的类，
面板按后台「系统设置 → 静态IP开通渠道」里选的 `provisioner_driver` 值来决定用哪家。

目前只有一家真实供应商 **onip.cc**（外加一个只给测试用的 `stub`）。下面是接入新一家（示例代号 `acme`）的完整步骤。

---

## 1. 写供应商的 API 客户端（Service）

照抄 `app/Services/OnipProvisioningService.php`，新建 `app/Services/AcmeProvisioningService.php`。
它负责：读取自己的配置、鉴权、调对方 API 下单/查询。配置从 `Setting` 读，键名自己起（见第 4 步）：

```php
// app/Services/AcmeProvisioningService.php
class AcmeProvisioningService
{
    public function isConfigured(): bool
    {
        return Setting::get('acme_api_key', '') !== '';
    }

    public function placeOrder(array $params): array { /* 调对方 API，返回 IP 等 */ }
}
```

## 2. 写 Provisioner（实现接口）

照抄 `app/Services/Provisioners/OnipProvisioner.php`，新建
`app/Services/Provisioners/AcmeProvisioner.php`。接口只有一个方法：

```php
// app/Services/Provisioners/AcmeProvisioner.php
class AcmeProvisioner implements ProvisionerInterface
{
    public function __construct(private readonly AcmeProvisioningService $acme) {}

    /** 失败必须抛异常；成功返回结果数组（至少含 ip / provider）。 */
    public function provision(ProvisionTask $task): array
    {
        $profile = $task->payload['resource_profile'] ?? [];
        $period  = $task->payload['period'] ?? 'month';

        $result = $this->acme->placeOrder([/* ...从 $profile 取 lineId/地区等... */]);

        return [
            'ip'       => $result['ip'],
            'provider' => 'acme',
            // 需要在续费/回调里用到的字段都塞进来（onip 是塞了 lineId）
        ];
    }
}
```

> `resource_profile` 是套餐里配的线路参数（管理员在「套餐管理」里选的线路快照）。
> 每家供应商的 profile 字段可以不一样，Provisioner 自己解释。

## 3. 在容器绑定里加一个分支

`app/Providers/AppServiceProvider.php` 现在是：

```php
$this->app->bind(ProvisionerInterface::class, function ($app) {
    return Setting::get('provisioner_driver', 'stub') === 'onip'
        ? $app->make(OnipProvisioner::class)
        : $app->make(StubProvisioner::class);
});
```

改成 `match`，加上 acme：

```php
$this->app->bind(ProvisionerInterface::class, function ($app) {
    return match (Setting::get('provisioner_driver', 'stub')) {
        'onip'  => $app->make(OnipProvisioner::class),
        'acme'  => $app->make(AcmeProvisioner::class),
        default => $app->make(StubProvisioner::class), // 'stub'，仅测试用
    };
});
```

## 4. 后台加它的配置字段 + 注册到下拉

在 `resources/views/components/admin/⚡system.blade.php`：

1. **注册表加一行**（`$provisionDrivers` 数组）——加完"开通方式"会自动从固定项变成下拉框：

```php
public array $provisionDrivers = [
    'onip' => 'onip.cc 真实静态代理',
    'acme' => 'Acme 静态代理',   // ← 新增
];
```

2. **加它的配置属性**（照着 `onip_app_id` 等）：`public string $acme_api_key = '';`
   在 `mount()` 里 `$this->acme_api_key = Setting::get('acme_api_key', '');`
   在 `saveProvision()` 里 `Setting::set('acme_api_key', $this->acme_api_key);`

3. **加它的配置输入框**（照抄 onip 那几个 `<input>`），建议用 `@if ($provisioner_driver === 'acme')` 包起来，
   这样选哪家只显示哪家的字段。

## 5. 测试

照抄 `tests/Feature/OnipProvisionerTest.php`，把 `provisioner_driver` 设成 `acme`、
`Http::fake()` 掉对方 API，断言 `provision()` 返回正确的 IP/provider。跑 `php artisan test`。

---

## 就这些

核心架构（`ProvisionerInterface` + `provisioner_driver` 设置 + 容器绑定）已经就位，
加一家不是重写、是上面这 5 处照抄。后台"开通方式"会自动适配：1 家显示固定项、≥2 家变下拉。
`stub` 是保留给自动化测试的默认实现，永远不出现在后台下拉里。
