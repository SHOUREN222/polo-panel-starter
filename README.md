# Polo 代理订阅面板

基于 Laravel 13 / Livewire 4 的代理订阅管理系统：账号、套餐、下单支付、机房共享节点分配、静态 ISP 专属线路开通、邀请返佣、工单、流量统计、邮件与 Telegram 通知及多种支付网关。

不依赖 Xboard 或任何第三方面板，全部自主代码。

## 环境要求

- PHP 8.3+（需要 `sqlite3`、`mbstring`、`openssl` 扩展；生产环境建议 MySQL/PostgreSQL，把 `DB_CONNECTION` 改掉即可，代码本身不绑定 SQLite）
- Composer 2.x
- Node.js 22 LTS / npm

> 从一台空服务器开始部署，请直接阅读 [全新空白站部署指南](docs/FRESH-INSTALL.md)。该流程不会导入本项目运营方的客户、订单、节点、供应商或支付数据。

## 仓库交付边界

这个仓库用于交付可重新部署的程序，不是现有网站的服务器镜像。克隆后得到的是独立空站：

- 包含：应用源码、数据库结构、安全示例配置、Docker 部署文件和测试。
- 不包含：现网数据库、客户账号、订单、套餐、节点、供应商账号、支付渠道、`.env`、私钥或上传文件。
- 新站不会自动连接原站，也不会复用原站的域名、管理员、余额或线路。
- 品牌、域名、节点、供应商和支付渠道均由新站运营者自行配置。

## 快速开始（本地/开发环境）

```bash
git clone <你的仓库地址>
cd <项目目录>

composer setup   # 安装依赖、生成 .env/APP_KEY、创建 SQLite、迁移并构建前端
php artisan app:create-admin your@email.example --name="站长"
php artisan db:seed       # 可选：只导入帮助中心草稿，不创建账号、套餐或节点
```

需要演示账号、示例套餐和保留地址节点时，仅在本地执行 `php artisan db:seed --class=DemoSeeder`。演示账号固定为 `admin@example.com / password123`，严禁用于公网环境。

启动开发服务器（会同时起 web、队列监听、日志、前端热更新四个进程）：

```bash
composer dev
```

默认访问 `http://localhost:8000`。

## 一键部署

### 方式 A：Docker Compose（全新空白站首选）

需要装好 Docker + Docker Compose，宿主机还需要 Node.js 22 构建前端，然后：

```bash
cp .env.docker.example .env      # 改 APP_URL、ADMIN_PANEL_PATH、DB_PASSWORD
make up                          # = npm run build 构建前端 + docker compose up -d --build
```

没有 `make` 的话就两条命令：`npm ci && npm run build` 然后 `docker compose up -d --build`。会拉起 app(php-fpm)+web(nginx)+db(mariadb)+queue+scheduler+telegram 六个容器。

- 首次启动会自动生成 `APP_KEY`、跑数据库迁移、缓存配置/路由/视图。
- 访问 `http://localhost:8080`（端口用 `WEB_PORT` 改），后台在 `/<ADMIN_PANEL_PATH>`。
- 队列 worker、定时任务（ISP 断流 / 到期提醒 / 客户端同步）、Telegram 机器人轮询各有独立容器，无需另配 cron/supervisor。Telegram 容器在你还没配 token 时会静默待命，配好后自动开始工作。
- 队列/缓存/会话都走数据库驱动，**不需要 Redis**。
- 新建管理员：`docker compose exec app php artisan app:create-admin your@email.example --name="管理员"`。

生产上想要 HTTPS，在 `web` 前面再挂一层 Caddy/Nginx 反代到 `${WEB_PORT}` 即可。

### 方式 B：`deploy.sh`（给已有服务器一键推更新，日常最省事）

已经有一台配好 nginx + PHP-FPM 的服务器时，用它把当前代码推上去：

```bash
cp .deploy.env.example .deploy.env   # 填一次：SSH_HOST / REMOTE_PATH / 服务名 / HEALTH_URL
./deploy.sh                          # rsync 源码 → composer install → npm build → migrate → 清缓存 → reload fpm → 重启队列
./deploy.sh --dry-run                # 只预览会同步哪些文件，不改服务器
```

`.deploy.env` 已被 gitignore（真实主机地址不进仓库）。脚本会先备份服务器上的 `public/build`（回滚：`mv public/build.prev public/build`），结束时对 `HEALTH_URL` 做一次健康检查。

### 新建管理员账号

```bash
php artisan app:create-admin your-real-email@example.com --name="管理员"

# Docker 部署
docker compose exec app php artisan app:create-admin your-real-email@example.com --name="管理员"
```

命令会隐藏输入密码并要求二次确认，不会把密码写入 shell 历史。

## 空站与外部服务

仓库只包含程序和安全的示例配置。新安装默认没有真实节点、IP 供应商和支付渠道：

- 没有节点或供应商时，不要创建可售套餐；网站可以先完成品牌、文档和后台配置。
- 没有支付渠道时，客户不能自动完成在线付款；生产环境不会显示测试支付按钮。
- `.env`、数据库、客户上传、节点私钥和支付密钥永远不应提交到 Git。
- 多租户分站是可选功能；单站部署将 `TENANCY_PARENT_DOMAIN` 和 `TENANCY_CENTRAL_DOMAINS` 留空即可。

## 关键配置

### `.env` 里必须改的几项

| 变量 | 说明 |
|---|---|
| `APP_URL` | 必须带上真实可访问的域名/端口，邮件链接、支付回调地址都靠它拼出来，配错会导致密码重置邮件、支付回调全部失效 |
| `APP_KEY` | `composer setup` 会自动生成，不要复用别人的 |
| `ADMIN_PANEL_PATH` | 后台入口路径，默认 `admin`，**必须改成自己的随机字符串**（例如 `ctrl-9x3k2q`）——这是唯一一层"路径不公开"的防护，用默认值等于没防护。只写在 `.env` 里，不要提交进仓库 |
| `DB_CONNECTION` 等 | 默认 SQLite，生产环境建议换 MySQL/PostgreSQL |
| `MAIL_*` | 真实 SMTP 账号，用于密码重置、到期/流量提醒、工单回复通知邮件 |
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_USERNAME` | 找 @BotFather 申请，用于账号绑定、通知推送、机器人指令查询 |

### 后台"系统配置"页面里配的（不在 .env，改了立刻生效不用重启）

- 站点名称、描述、网址（改这个就能整站换名字，不用碰代码）
- 邀请返佣比例、佣金确认周期、每人邀请码上限
- Telegram 讨论组链接
- **支付网关（易支付协议）**：网关地址、商户 ID、商户密钥。配好后去"支付配置"页新增一条支付方式，`支付接口` 填 `epay_alipay` 或 `epay_wxpay`，前台下单页就会出现对应的真实支付按钮

## 需要常驻运行的进程（生产环境部署清单）

跟普通的纯网页应用不一样，这个系统有三类任务需要长期在后台跑，缺一个对应功能就不工作：

1. **队列 worker**（`php artisan queue:work`）——静态 ISP 专属线路的"开通任务"走的是队列，不跑 worker 的话线路会一直卡在"开通中"
2. **定时任务调度**（服务器 crontab 加一行 `* * * * * php artisan schedule:run`）——到期/流量提醒邮件靠这个每天定点发
3. **Telegram 机器人监听**（`php artisan telegram:poll`，需要长期运行的进程，比如用 `supervisor` 托管）——不跑的话账号绑定、机器人指令查询都收不到消息。本地开发环境没有公网 IP 时用不了 webhook，只能靠这个长轮询

## nginx + PHP-FPM 部署踩坑记录（真实遇到过，不是理论）

1. **非标准端口（还没配域名/HTTPS，先用 `IP:端口` 访问）时，Debian/Ubuntu 自带的 `/etc/nginx/fastcgi_params` 会把生成的链接端口号吃掉**——Debian 给这个文件打了个安全补丁，把 `HTTP_HOST` 从标准的 `$http_host`（含端口）改成了 `$host`（不含端口）。后果是 Livewire 的 JS 资源链接、所有 `url()`/`route()` 生成的绝对地址都会丢掉端口号，请求会打到 80/443 端口而不是你实际用的端口。解决办法：**不要改系统级的 `fastcgi_params`**（可能影响这台机器上别的站点），而是在自己站点的 nginx 配置里，`include fastcgi_params;` **之后**（顺序很重要，写在前面会被 include 覆盖掉）单独加一行：
   ```
   fastcgi_param HTTP_HOST $http_host;
   ```
2. **`rsync` 部署代码时一定要把 `public/hot` 也排除掉**（不只是 `public/build`）——这个文件是本地 `npm run dev` 时自动生成的，只要它存在，Laravel 就会认为 Vite 开发服务器还在跑，尝试从 `http://127.0.0.1:5173` 加载前端资源，生产环境上没有这个东西，会导致页面所有交互（登录、注册、所有 Livewire 组件）完全没反应，且没有任何后端报错——因为请求根本没到后端，是浏览器自己请求 5173 失败了。

## 支付回调注意事项

支付网关的异步通知（`/api/epay/notify`）需要网关服务器能直接访问到你的 `APP_URL`——本地开发环境（`localhost`）网关连不进来，这部分只能部署到有公网域名之后才能完整跑通。本地能验证的只有：按钮渲染、跳转链接签名是否正确、`/api/epay/notify` 接口本身的签名校验逻辑（可以自己构造签名请求测试）。

## 运行测试

```bash
php artisan test
```

## 安全提醒

- `.env` 和 SQLite 数据库文件（`database/*.sqlite`）已经在 `.gitignore` 里排除，不会被提交进版本控制——但如果你把整个项目文件夹打包发给别人，记得手动排除这两样，里面可能有真实的邮箱密码、Telegram token、支付商户密钥
- `ADMIN_PANEL_PATH` 和管理员密码，上线前一定要改成自己的
