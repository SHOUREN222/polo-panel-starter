# 全新空白站部署指南

本文面向没有旧服务器、节点供应商和支付渠道的新部署。完成后会得到一个空白管理站：只有你创建的管理员，没有客户、订单、套餐、节点、供应商或支付方式。

新站与仓库维护者正在运营的网站完全隔离。数据库、域名、密钥和外部服务都必须由新站运营者自己创建，不能从公开仓库恢复任何现网业务数据。

## 1. 准备资源

- 一台 Ubuntu 24.04 LTS VPS，建议至少 2 核 CPU、4GB 内存和 40GB 磁盘。
- 一个域名，并将主站域名的 A/AAAA 记录指向 VPS。
- 本机安装 Git、Docker Engine、Docker Compose v2 和 Node.js 22 LTS。
- 防火墙只对公网开放 SSH、HTTP 和 HTTPS。

面板服务器只运行网站、数据库和后台任务。代理流量节点应使用单独服务器，不要与面板混用。

## 2. 获取代码

```bash
git clone https://github.com/SHOUREN222/polo-panel-starter.git
cd polo-panel-starter
cp .env.docker.example .env
```

仓库不包含现站数据库、`.env`、供应商密钥、支付密钥、客户文件或节点私钥。

## 3. 配置环境

编辑 `.env`，至少修改：

```dotenv
APP_NAME="你的站点名称"
APP_URL=https://panel.example.com
ADMIN_PANEL_PATH=换成一段随机路径

DB_PASSWORD=生成一个长随机密码
DB_ROOT_PASSWORD=再生成一个不同的长随机密码
```

单站部署保持以下两项为空：

```dotenv
TENANCY_PARENT_DOMAIN=
TENANCY_CENTRAL_DOMAINS=
```

需要代理分站时再配置：

```dotenv
TENANCY_PARENT_DOMAIN=example.com
TENANCY_CENTRAL_DOMAINS=panel.example.com,example.com
```

不要手工填写 `APP_KEY`。容器首次启动时会生成并写回 `.env`。此后必须备份它，丢失后数据库中的加密配置无法恢复。

## 4. 启动空站

```bash
npm ci
npm run build
docker compose up -d --build
docker compose ps
```

如果 `DB_PASSWORD` 或 `DB_ROOT_PASSWORD` 仍为空，Compose 会直接拒绝启动。不要通过删除这项检查来绕过密码配置。

首次启动会自动执行数据库迁移、创建存储链接并缓存配置。查看启动日志：

```bash
docker compose logs -f app
```

## 5. 创建管理员

```bash
docker compose exec app php artisan app:create-admin admin@example.com --name="管理员"
```

密码会以隐藏方式输入，至少 12 位。不要运行 `DemoSeeder`，它只用于本地演示。

## 6. 配置 HTTPS

在 Docker 的 `web` 服务前配置 Caddy、Nginx 或云负载均衡，将请求反向代理到 `${WEB_PORT}`。确认：

- `APP_URL` 与浏览器实际访问地址完全一致；
- HTTPS 证书有效；
- `/livewire-*/update`、`/api/*` 和支付回调路径没有被静态缓存；
- 代理正确传递 `Host`、`X-Forwarded-Proto` 和客户端 IP。

## 7. 空站上线前设置

登录 `https://panel.example.com/<ADMIN_PANEL_PATH>` 后：

1. 在个人中心启用管理员双重验证。
2. 设置站点名称、网址、联系信息和邮件服务。
3. 在插件管理中关闭“开放注册”“静态 IP 自动开通”“Telegram Bot 集成”和暂时不用的营销功能。
4. 支付配置保持空白，不要添加模拟支付方式。
5. 套餐管理保持空白，或者将测试套餐设为隐藏且不可购买。
6. 未接入真实节点前，不要向客户开放订阅端点。

没有供应商和支付渠道时，网站可用于后台配置和页面验收，但不能向客户交付可用线路，也不能自动完成收款。

## 8. 后续接入顺序

1. 按 [添加节点](ADD-A-NODE.md) 接入共享节点，或按 [添加静态 IP 供应商](ADD-A-STATIC-IP-PROVIDER.md) 开发供应商适配器。
2. 建立不可购买的测试套餐，验证订阅生成、流量上报和停复机。
3. 按 [添加支付渠道](ADD-A-PAYMENT-GATEWAY.md) 配置支付，并在沙箱环境验证签名、金额和回调幂等。
4. 完成真实小额支付、自动开通、续费和退款演练后，再开放注册及套餐销售。

## 9. 备份与更新

必须备份：

- `.env`，特别是 `APP_KEY`；
- 数据库卷；
- `storage/app` 中的客户文件、客户端安装包和节点私钥。

不需要备份日志、缓存和编译视图。更新代码前先备份，再执行：

```bash
git pull --ff-only
npm ci
npm run build
docker compose up -d --build
```

## 10. 验收清单

- 管理员登录、退出、密码重置和 2FA 正常。
- 网站使用 HTTPS，`APP_DEBUG=false`。
- 后台路径、数据库密码和管理员密码均已更换。
- 仓库中没有 `.env`、SQLite 文件、私钥或生产配置。
- 队列、scheduler 容器持续运行且无重复失败。
- 未配置供应商和支付前，没有任何可购买套餐。
