# 多租户分站 · 合并前 review 清单

> `feat/multitenant-p1` → `main` 前过一眼。数据全部从 `git diff main...feat/multitenant-p1` 实测得出，不是回忆。
> 规模：**19 个提交，55 个文件，+3207 / −72**，全套 **639 测试绿**。

## 一句话结论

**30 个纯新增文件（零风险）+ 25 个改动文件**；改动里绝大多数是加注释/加 trait，真正改了行为的只有 5 个文件，且主站路径都靠"短路/ordinal 0"保号。**最强证据：0 个既有测试被修改或删除**——607 个既有测试原样全绿。

---

## 1. 纯新增：30 个文件（对主站零风险）

不存在的文件不会影响正在跑的代码。分四类：

| 类 | 文件 |
|---|---|
| 多租户内核 | `config/tenancy.php`、`app/Models/Tenant.php`、`app/Providers/TenancyServiceProvider.php`、`routes/tenant.php`、2 张 central 表迁移（tenants/domains） |
| 分站功能 | `TenantSetting`、`TenantWholesale`、`WholesaleService`、`NodeUserId`、`⚡tenants.blade.php`、`⚡branding.blade.php`、`tenant_wholesale` 迁移 |
| 租户库表 | `database/migrations/tenant/` 下 6 个迁移（只作用于分站库，central 的 `migrate` 不递归子目录，**碰不到主库**） |
| 部署/测试/文档 | `CaddyOnDemandController`、`ops/Caddyfile.multitenant.snippet`、`docs/AGENT-MULTITENANT-DEPLOY.md`、8 个测试文件（32 个新测试） |

## 2. 改动的 25 个文件，按风险分档

### 档 A — 注释 / trait-only，**0 行为改变**（7 个）

`Plan` `Server` `AppClient` `EmailTemplate` `KnowledgeArticle` `SubscribeTemplate`：只加了一行 `use CentralConnection;`（+注释），**零删除**。作用：分站上下文里也从 central 读平台共享数据。主站上下文本就读 central → 无变化。

`Payment`：**只加了 3 行注释**，无代码改动。（P2 曾给它加 central pin，P4 又摘掉 → 净效果 vs main = 0。）主站上下文默认连接=central 主库，与 main 完全一致。

### 档 B — 包了一层，主站短路成 no-op（3 个）

| 文件 | 改法 | 主站为何不受影响 |
|---|---|---|
| `OrderService` (+11/−1) | `markPaid` 前后各插一句 `assertCanSettle` / `settle` | 两者第一行都是 `if (! ($tenant = tenant())) return;` → 主站直接返回，事务体一字未改 |
| `Setting` (+35/−1) | 品牌 key 白名单走租户覆盖 | `tenant()` 为 null 时永远走 central 原路径 |
| `User` (+27/−0) | `canAccessAdminRoute` 多一个 `if (tenant())` 分支 | 主站 `tenant()` 为 null → 走原逻辑，**零删除** |

### 档 C — 真重构，主站路径靠 "ordinal 0" 保号（5 个）⚠️ 重点看这档

| 文件 | 改法 | 证据 |
|---|---|---|
| `UniProxyController` (+56/−21) | `/user` 聚合 central+各租户；`/push` 按全局 ID 解码 | 删掉的 21 行 = 旧的内联体，被**原样抽成 `recordTraffic()` helper**；主站 = ordinal 0 → `encode(0,id)==id`，**协议逐字节不变** |
| `TrafficReportController` (+74/−33) | 拆成 reportServer/reportInstance + `onCentralThenTenants()` | central **先匹配**，主站路径优先命中 |
| `SharedNodeService` (+13/−1) | **唯一被改写语义的查询**：`whereHas('plan',…)` → 先取 planIds 再 `whereIn('plan_id',…)` | 因 whereHas 跨不了连接。等价性由既有 `UniProxyTest`/`SharedNodeServiceQuotaTest` **原样全绿**背书 |
| `NodeRelayService` (+24/−1) | 新增 `allActiveOnipInstances()` 聚合各租户 onip 线路 | uuid/token 全局唯一 → 无碰撞 |
| `GatewayPaymentController` (+31/−1) | ⚠️ **动了收款回调**：`notify(…, Payment $payment,…)` 路由模型绑定 → `string $payment` + 按 host 手工解析租户 | 主站 host 命中 `central_domains` → `resolveTenant` 返回 null → 直接 dispatch，等价旧路径。既有支付测试**原样全绿** |

### 档 D — 全局中间件 ⚠️ **全分支最高风险单点**

`bootstrap/app.php` (+8/−0)：把 `InitializeTenancyBySubdomain` **prepend 到 `web` 组** → **它会跑在每一个主站请求上**。

缓解：标记为 `universal`（空中间件组），central 域名走 `$onFail` continue-as-central。**这是整个分支唯一一处"主站请求也会执行新代码"的地方**，合并后第一优先验证主站登录/购买/后台。

### 档 E — 运维 / 配置（10 个）

`deploy.sh`(+3) 加 `tenants:migrate --force`；`ops/backup-panel-db.sh`(+43/−11) 加租户库快照（0 分站时**空跑，行为同旧**，已本地实测）；`routes/web.php`(+6) 加 2 条后台路由；`routes/api.php`(+5) 加 ask 端点；`admin-nav`(+4) 加导航；`.gitignore`(+3) 忽略租户库；`composer.json`(+2/−1) 加 `stancl/tenancy ^3.10`；`composer.lock`(+227)；`bootstrap/providers.php`(+2)。

---

## 3. 主站零改动的证据点

1. **0 个既有测试被修改或删除** —— `git diff main...HEAD --diff-filter=MD -- tests/` 为空。607 个既有测试**一个字没动**、全绿。这是最强的一条：任何主站行为漂移都会打破它们。
2. **639 = 607 既有 + 32 新增**，没有测试被悄悄丢掉。
3. **ordinal 0 = 原始 ID**：主站节点协议逐字节不变（`encode(0, id) == id`）。
4. **`tenant()` 为 null 即短路**：批发结算、品牌覆盖、两级后台三处都是主站直接走原路径。
5. **租户迁移隔离**：Laravel `migrate` 不递归子目录 → `migrations/tenant/` 永远碰不到主库。
6. 档 A 的 7 个文件**零删除行**。

## 4. 合并后必须立刻验的 3 件事（按此顺序）

1. **主站登录 / 购买 / 后台**（档 D：中间件跑在每个主站请求上）
2. **真实客户节点供货正常**（档 C：UniProxy 重构过；主站应完全如常）
3. **主站收款回调**（档 C：`GatewayPaymentController` 签名变了 —— 跑一笔真实小额支付确认回调落单）

回滚：`git checkout main` + `./deploy.sh`，`tenants`/`domains` 空表留着无害，不动任何分站库文件。

## 5. 上线顺序

合并 → `./deploy.sh` → 验上面 3 件事 → 泛域名 DNS → Caddy（见 `ops/Caddyfile.multitenant.snippet`）→ 开一个 demo 分站 → **在真实节点上冒烟一次**（本地测跑不了真 V2bX 二进制）→ 才对外放代理进来。

**红线**：S-UI 节点机全程不碰。
