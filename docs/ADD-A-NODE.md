# 加一台新机房 / 节点

两种角色，可各自独立，也可同一台机器身兼两职：

- **机房共享线路（V2bX 节点）** — 机房共享套餐的客户直连它（shadowsocks / VLESS-Reality）。
- **静态ISP中转（xray-relay 节点）** — 独享静态IP客户的流量经它中转到 onip 的住宅IP出口（onip 只认中转机，客户连不了裸 onip IP）。

---

## 一、机房共享线路（V2bX）

面板已内置一键接入，几乎全自动。

1. **后台 → 服务器管理 → 新增服务器**：填名称、权限组（group）、对外 host、端口、协议。VLESS 的话点「生成 Reality 密钥」。保存。
2. 点该节点的 **「接入配置」**：生成一整段安装脚本，NodeID 与令牌都已填好。复制。
3. SSH 进新机器（root）：先按提示装官方 V2bX，再整段粘贴那个脚本执行（配置、启动、自检都在里面）。
4. **放行防火墙端口**（脚本末尾会提示端口号）。
5. 回「节点监控」，60 秒内看到上线、日志出现 `Added N new users` 即成。

> **IPv6 坑（已内置规避）**：Reality 的 dest 回落若走 IPv6 而机器无 IPv6 出口，握手会大面积失败（`cannot assign requested address`）。生成的脚本里节点配置已带 `DNSType: UseIPv4` 强制 IPv4，新节点不会犯。老节点若是 sing 核心，检查 `sing_origin.json` 是否有 `"strategy": "ipv4_only"`。

---

## 二、静态ISP中转（xray-relay）

半自动：面板通过 SSH 把配置推给中转机并 reload，但**中转机上的 xray + xray-relay 服务需先装好**（面板不代装系统软件）。后台已提供一键接入脚本把这步也简化了。

### 前置：Reality 身份（只做一次，全平台共享）

**后台 → 服务器管理 → 静态IP中转配置 → Reality 身份**。所有中转节点必须共用同一套 Reality 密钥 / SNI —— 这样同一个客户 uuid 才能在多个中转节点之间无缝故障转移（不换 IP）。第一次点「生成新密钥对」→「保存 Reality 身份」。**之后别再改**，改了所有中转都要重新同步、老客户会短暂连不上。

### 加一个中转节点

1. **静态IP中转配置 → 新增中转节点**：填名称、优先级（数字小的客户端先试）、对外地址+端口（客户连的）、SSH 地址+端口+用户，以及 SSH 密码**或**面板机上的私钥路径。保存。
2. 点该节点的 **「接入脚本」**，复制，在**新中转机** root 粘贴执行。脚本会：
   - 装官方 xray（已装则跳过）；
   - **关掉系统 IPv6**（同样的 Reality dest 回落坑）；
   - 写占位配置 + 建 `xray-relay.service` + 启动。
3. 让面板能 SSH 到这台机器：把**面板的公钥**加到中转机 `/root/.ssh/authorized_keys`，或在上一步表单里填 SSH 密码。
4. **放行防火墙**中转端口（默认 8446）。
5. 回中转配置页点 **「重新同步全部」**，面板会把真实客户配置推过去并 reload。
6. 点「刷新」看该节点变 🟢 在线。

### 故障转移与自愈（自动，无需干预）

- 客户订阅里每条静态线路会列出**所有启用的中转节点**（按优先级），任一挂了客户端切下一个，IP 不变。
- 面板每 3 分钟自动 `relay:sync` 核对：某中转节点配置落后（新客户/续费）就补推，SSH 不通就持续重试——**一台中转 SSH 一恢复，几分钟内自动补齐**。已是最新的节点会跳过、不 reload、不打扰在线客户。
- 手动强推：中转配置页「重新同步全部」（force，即使哈希没变也推，用于救配置漂移的节点）。

### 一台机器同时当共享节点 + 中转

完全可以：先按「一」接入 V2bX（占用 8443/其协议端口），再按「二」加中转（占用 8446）。两个服务、两个端口，互不干扰。

---

## 排查

| 现象 | 查什么 |
|---|---|
| 共享节点不上线 | 令牌/NodeID 是否对；防火墙端口；`journalctl -u V2bX` 有无 `Added N new users` |
| 中转节点客户连不上 | 该节点在中转配置列表里是否「启用」；点「重新同步」；`journalctl -u xray-relay`；Reality 身份是否全平台一致 |
| Reality 握手大量失败 `cannot assign requested address` | IPv6 未关：`sysctl net.ipv6.conf.all.disable_ipv6`；sing 核心查 `sing_origin.json` 的 `ipv4_only` |
| 中转机流量超额被机房停机 | 客户会自动转到其它中转；机房恢复后面板 3 分钟内自动补配置 |

---

## 底层参考（改代码才需要）

- 中转节点存 `relay_nodes` 表（`App\Models\RelayNode`，`ssh_password` 加密）；Reality 身份存 `Setting`（`relay_reality_*`）。
- `App\Services\NodeRelayService`：`relayNodes()` 读表、`sync()` 逐节点独立推送（哈希守卫、单节点失败不阻断其它）、`customerNodes()` 生成客户订阅的多中转端点。
- 定时自愈：`App\Console\Commands\RelaySync`（`relay:sync`），`routes/console.php` 里每 3 分钟。
