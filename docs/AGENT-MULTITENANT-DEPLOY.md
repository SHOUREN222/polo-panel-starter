# 多租户分站 · 上生产 + 子域 TLS 部署方案

> 规划文档，未执行。动的是**正在给真实客户供货的生产面板**，务必按序、可回滚、主站零中断。

## 0. 时机（重要，先读）

**技术上现在就能安全部署**（多租户对 central 主站零改动，619 测试全绿）。但要认清：

- 部署 P2 后，分站能**注册/登录/看仪表盘/下单/付款**，但**节点还不会给分站客户供货**——因为 **P3 节点交付（UniProxy 跨租户）还没做**。
- 也就是说，**现在部署 = 分站能收钱、但发不了货**。客户付了钱连不上,是事故。

**结论：真正面向代理开分站，应在 P3（节点交付）+ P4（收款）做完后再部署。** 现在部署只适合**给自己内部预览分站外壳**，绝不能对外放客户进来。下面步骤两种场景通用，但对外开放务必等 P3/P4。

## 1. 部署前准备

1. **全量备份**：手动跑一次 `/root/backup-panel-db.sh`（本地+两台异地都有），确认最新备份可解压、`integrity_check = ok`。
2. **选低峰时段**，准备好回滚（见 §8）。
3. **确认分支绿**：`feat/multitenant-p1` 全量测试通过、与 main 差异 review 过。
4. **DNS 现状审计**：列出 `example.com` 现有解析记录，确认没有别的 `*.example.com` 子域被占用（老 Xboard 的 `tizi` 已退役）；MX/邮件记录不受泛解析影响。

## 2. 代码上线

1. **合并**：`feat/multitenant-p1` → `main`（这是"决定上线"的动作；在此之前 main 保持随时可部署的旧状态）。
2. `./deploy.sh`（或等效）在生产执行：
   - `composer install --no-dev`（拉入 stancl/tenancy）；
   - `npm run build`（后台分站页复用现有类，但保险起见重建）；
   - **central 迁移** `php artisan migrate --force` → 新增 `tenants` / `domains` 两张表（**纯新增，不动现有表**）；
   - **租户迁移编排** `php artisan tenants:migrate --force`（首次没有租户，空跑；以后每次上线都要跑，给已存在的分站库补新迁移）；
   - `optimize:clear` + reload php-fpm。
3. 部署脚本需**永久加入** `tenants:migrate --force` 这一步（见 §7）。

## 3. DNS：子域泛解析

给面板机加一条**泛解析 A 记录**：

```
*.example.com   A   <面板机 IP>
```

- 现有 `app.example.com`（主站）保持不变——显式记录优先于泛解析。
- 泛解析让任意 `代理名.example.com` 都解析到面板机。**节点机（S-UI）是不同 IP，泛解析不影响它们。**

## 4. Caddy：子域按需 TLS（on-demand）

主站现在走 Caddy 终止 TLS → 反代 nginx:8080。给分站子域加 **on-demand TLS**（每个子域首次访问时自动签证书，无需 DNS-API）：

```caddy
{
    on_demand_tls {
        # 只给"已登记的分站子域"签证书，防止有人用随机域名刷爆你的证书额度
        ask http://127.0.0.1:8080/api/caddy-ondemand-ask
    }
}

# 主站块保持原样，不要动
app.example.com {
    reverse_proxy 127.0.0.1:8080
    # …现有配置…
}

# 分站子域：按需签证书 + 反代到同一个 nginx
*.example.com {
    tls {
        on_demand
    }
    reverse_proxy 127.0.0.1:8080
}
```

- **务必保留主站 `app.example.com` 块原样**；只新增泛子域块 + 全局 `on_demand_tls`。
- 改完 `caddy validate` 通过再 `caddy reload`（热加载，不断连）。

## 5. 面板要新增的 `ask` 端点（配合 Caddy on-demand）—— ✅ 已实现

Caddy 每次要给某域名签证书前，会先问这个端点"这个域名能签吗"。防滥用的关键。

- 路由（central，api）：`GET /api/caddy-ondemand-ask?domain=xxx` → `App\Http\Controllers\Api\CaddyOnDemandController`。
- 逻辑：`domain` 是主站域名（`central_domains` 精确命中）、或 `<label>.<主站父域>` 且 **`label` 是已登记的分站 Domain**（查 `domains` 表）→ 返回 **200**；否则 **403**（含随机子域、外域 `xxx.evil.com`）。**fail-closed**：缺参 400、限流 429 都不会签证书。
- 测试：`tests/Feature/CaddyOnDemandTest.php`（主站放行 / 已登记分站放行 / 未登记拒 / 外域拒 / 分站删除后即刻拒 / 缺参 400）。

## 6. 备份脚本更新（含租户库）—— ✅ 已实现

`ops/backup-panel-db.sh` 现在除了主库 `database.sqlite`，还对 `database/` 下**每个 `tenant*.sqlite`** 各做一次 `VACUUM INTO` 热快照：

- 主库快照文件名不变（`panel-db-<ts>.sqlite.gz`）；分站库为 `panel-tdb-tenant<id>-<ts>.sqlite.gz`。
- 主库 + 所有分站库一起推异地三地；本地/异地保留期一致（两个 `find` 分别清理两种前缀）。
- 没有分站时该段**空跑**，行为与旧的"仅主库"备份完全一致（已本地验证：0 分站→只出主库文件；2 分站→主库+2 分站快照，解压 `integrity_check = ok` 可还原）。
- **恢复流程**：主库覆盖 `database/database.sqlite`；每个分站库覆盖对应的 `database/tenant<id>.sqlite`。

## 7. 部署流程固化 —— ✅ 已实现

`deploy.sh` 远端步骤已永久加入租户迁移（顺序：central 迁移 → 租户迁移）：

```bash
php artisan migrate --force
php artisan tenants:migrate --force   # 给所有已存在的分站库补迁移；首次上线空跑
```

## 8. 验证（上线后立即做）

**A. 主站零回归（最高优先级）**
- `app.example.com` 登录/仪表盘/购买/后台，逐个点一遍，行为与上线前完全一致；
- 真实客户订阅/节点供货正常（P3 未做时，主站这条链路本就没变，应完全正常）。

**B. 分站（内部预览）**
- 后台 → 分站管理 → 建一个测试分站 `demo`；
- 浏览器打开 `https://demo.example.com` → **证书自动签发、绿锁**、能注册/登录/看仪表盘；
- 隔离核对：分站注册的用户不出现在主站，反之亦然。

## 9. 回滚方案

- **主站一旦异常**：`git checkout main`（回滚前的版本）→ `./deploy.sh` 重新部署。多租户中间件/代码随之移除，主站恢复原状；`tenants`/`domains` 两张空表留着无害。
- Caddy：删掉新增的泛子域块 + `on_demand_tls`，`reload`，主站块不受影响。
- DNS：泛解析记录可删，不影响 `app` 显式记录。
- 回滚全程**不动任何分站库文件**（保留数据）。

## 10. 风险与红线

| 项 | 说明 / 缓解 |
|---|---|
| 主站回归 | central 零改动 + 619 绿；上线后立即逐项验证；随时可回滚 |
| Caddy 配错断主站 TLS | 只新增、不动主站块；`caddy validate` 后再 reload |
| on-demand 证书被刷 | `ask` 端点只放行已登记分站域 |
| **分站发不了货**（P3 未做） | **对外开分站必须等 P3/P4**；现在只做内部预览 |
| S-UI 节点机 | **全程绝不触碰**——泛解析指向面板机、不涉及节点机；本方案不动节点任何东西 |

---

**一句话**：上线本身对主站是安全、可回滚的；但**分站要能真给客户供货得等 P3**，所以现在最多做到"内部预览分站外壳"，别对外放客户进来。
