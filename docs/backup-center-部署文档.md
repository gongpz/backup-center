# Backup Center · 网站备份管理中心

# 一键部署文档

---

## 📖 简介

Backup Center 是一个自托管的多服务器备份编排系统 —— 让分散在各地的服务器的备份文件，按你设定的规则，一次配置永久生效地推送到你指定的所有存储上（S3 兼容 / SFTP / WebDAV / FTP）。

- **后端**：Laravel 12 + SQLite（PHP-FPM 8.2 Alpine 容器）
- **前端**：Vue 3 + Element Plus
- **Agent**：Go 单二进制（5 MB，跑在被监控服务器上）
- **底层**：rclone（协议兼容层）
- **部署**：Docker Compose 一键起

---

## 🖥️ 系统要求

| 项 | 最低 | 推荐 |
|---|---|---|
| CPU | 1 核 | 2 核 |
| 内存 | 1 GB | 2 GB |
| 磁盘 | 15 GB | 30 GB |
| 系统 | Debian 10+ / Ubuntu 20.04+ / 大多 Linux | Debian 11 / Ubuntu 22.04 |
| 端口 | 80 或 80+443 | 空着 |
| 权限 | root 或 sudo | root |

**外部依赖**：无。全程自托管，不依赖任何 SaaS/云 API。

---

## 🚀 一条命令部署

**SSH 到你的 VPS，跑这一条**：

```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | sudo bash
```

脚本会**交互式引导你**做 3 个选择，然后自动完成一切：

### 第 1 步 · 访问地址
```
? 输入你的域名或 IP: 
```
- 有域名的填 `bak.example.com`
- 只有 IP 的填 `1.2.3.4`
- 脚本自动识别是域名还是 IP

### 第 2 步 · SSL 证书方式

**如果第 1 步填了域名**：
```
? 选择 (1/2):
  1) Let's Encrypt 免费 HTTPS  （推荐；证书自动续期，浏览器绿锁）
  2) HTTP-only                 （无加密，只适合内网/临时/自用）
```

**如果第 1 步填了 IP**：
```
? 选择 (1/2):
  1) 自签名 HTTPS   （数据加密传输，浏览器首次访问会提示证书警告，点"高级 → 继续"即可）
  2) HTTP-only      （无加密，只适合内网/临时/自用）
```

### 第 3 步 · LE 邮箱（仅选 LE 时）
```
? 输入邮箱:
```
LE 强制要求的联系方式，用来发证书过期通知（不发广告）。随便一个能收信的邮箱即可。

### 汇总确认
脚本会打印一份汇总让你最后确认一次：
```
──────────  即将部署  ──────────
  访问地址：https://bak.example.com/
  证书模式：https-le
  证书邮箱：you@example.com
  安装路径：/opt/bc
  部署源  ：...
──────────────────────────────
? 确认开始？(Y/n):
```

按回车（默认 Y）后自动执行安装，约 **3-6 分钟**完成。

---

## 📋 部署前检查清单

**通用**：
- [ ] SSH 到你的 VPS 拿到 root（或 sudo）
- [ ] 端口 80 空着（HTTPS 模式还需 443 空）
  ```bash
  ss -tlnp | grep -E ':(80|443)\s'   # 应无输出
  ```
- [ ] 系统时间正确：`date` 显示的时间不是 1970 年

**如果选域名 + LE 模式**：
- [ ] DNS A 记录已经解析到 VPS IP（脚本会自动比对，不对 warn 20 秒才继续）
- [ ] 云厂商安全组放行 **80 端口入站流量**（LE HTTP-01 校验走 80）
- [ ] 有一个能收信的邮箱

**如果选 IP 模式**：
- [ ] 知道 VPS 公网 IP：`curl -4 ifconfig.me`

---

## ⚡ 非交互式部署（自动化用）

需要在脚本里跑（比如 Ansible / Terraform），可以直接传环境变量跳过所有提问：

### 域名 + LE
```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | \
  BC_ACCESS=bak.example.com BC_EMAIL=you@example.com sudo -E bash
```

### IP + 自签名 HTTPS
```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | \
  BC_ACCESS=1.2.3.4 sudo -E bash
```

### IP + HTTP-only
```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | \
  BC_ACCESS=1.2.3.4 BC_HTTP_ONLY=1 sudo -E bash
```

### 完整环境变量清单

| 变量 | 说明 | 默认 |
|---|---|---|
| `BC_ACCESS` | 访问地址（域名或 IP） | 交互问 |
| `BC_EMAIL` | LE 通知邮箱（域名+LE 必填） | 交互问 |
| `BC_HTTP_ONLY` | 设为 `1` 强制 HTTP-only | 未设 → 依 IP/域名决定 |
| `BC_DEPLOY_URL` | 部署包下载 URL（自建镜像用） | GitHub Release |
| `BC_FORCE` | 设为 `1` 覆盖已有安装（保留数据） | 未设 → 存在就报错 |

---

## ⏱️ 安装过程

```
0/7 VPS 虚拟化兼容性检查（OpenVZ 拒绝，LXC 自动切高端口）
1/7 检查端口占用
2/7 装 Docker（get.docker.com 官方脚本）
3/7 拉部署包（GitHub Release，失败切备用源）
4/7 生成证书（LE / 自签名 / 跳过，视你的选择）
5/7 展开到 /opt/bc/，用对应的 nginx 模板
6/7 生成 APP_KEY + 写 docker-compose 环境变量
7/7 docker compose build + up
   装 certbot 续期钩子（仅 LE 模式）+ 冒烟测试
```

---

## 🎉 部署完成后

### 访问

- **域名 + LE**：https://bak.example.com/（浏览器绿锁）
- **IP + 自签名**：https://1.2.3.4/（⚠️ 浏览器警告 → 点"高级 → 继续访问 1.2.3.4"，之后浏览器会记住 → 后续访问无警告）
- **HTTP-only**：http://1.2.3.4/

### 创建管理员

首次访问时，系统检测到还没有任何用户，会引导你**设置用户名 + 密码**创建管理员账号（密码至少 8 位）。创建完成后入口自动关闭。

**⚠️ 请务必记住这组凭据** —— 目前只能通过数据库重置找回。

### 后续要做的事

1. **改密码**（同上）
2. **加服务器**：侧栏 → 服务器 → 添加服务器，会给你一条 curl 命令，SSH 到目标机器跑一下就装好 Agent
3. **加存储**：侧栏 → 存储 → 添加存储，配置你的对象存储 / SFTP / WebDAV
4. **加网站**：侧栏 → 网站 → 添加网站，指定备份目录 + 推送到哪几家存储

---

## 🛠️ 常用运维命令

**查看容器状态**：
```bash
sudo docker ps
```

**查看日志**：
```bash
sudo docker logs -f bc-app       # PHP 后端
sudo docker logs -f bc-web       # Nginx 前端
```

**重启服务**：
```bash
cd /opt/bc/deploy && sudo docker compose restart
```

**停机**：
```bash
cd /opt/bc/deploy && sudo docker compose down
```

**重新拉起**（不重建镜像）：
```bash
cd /opt/bc/deploy && sudo docker compose up -d
```

**完全清理**（会删数据）：
```bash
cd /opt/bc/deploy && sudo docker compose down -v && sudo rm -rf /opt/bc
```

---

## 🔄 升级到新版本

> 生产环境（bf.imask.cc）的完整升级作业书见 **[生产升级步骤-v1.0.0.md](./生产升级步骤-v1.0.0.md)**，其中包含备份、精确覆盖清单、验证与回滚。
> 下面是简版流程（适合全新安装的自托管实例）。

```bash
# 1) 备份数据库（保险）
sudo docker cp bc-app:/var/www/html/database/data/database.sqlite \
    /root/database-$(date +%Y%m%d-%H%M).sqlite.bak

# 2) 拉最新部署包
curl -fsSL https://github.com/gongpz/backup-center/releases/latest/download/bc-deploy.tar.gz \
    -o /tmp/bc-new.tar.gz

# 3) 解开到临时目录（不要直接覆盖 deploy/，会冲掉 .env 与 ssl/）
mkdir -p /tmp/bc-new && tar -xzf /tmp/bc-new.tar.gz -C /tmp/bc-new

# 4) 精确覆盖：只换代码与前端产物
sudo rm -rf /opt/bc/backend && sudo cp -a /tmp/bc-new/backend /opt/bc/backend
sudo rm -rf /opt/bc/deploy/frontend-dist && sudo cp -a /tmp/bc-new/deploy/frontend-dist /opt/bc/deploy/frontend-dist

# 5) 重新构建镜像 + 启动
cd /opt/bc/deploy && sudo docker compose build app && sudo docker compose up -d --force-recreate app

# 6) 迁移 + 清缓存
sudo docker exec bc-app php artisan migrate --force
sudo docker exec bc-app php artisan optimize:clear
```

> ⚠️ 两个文件**永远不要**用新包覆盖：`deploy/.env`（APP_KEY 丢了则已加密的存储凭证全废）和 `deploy/nginx.conf`（含你的真实域名与证书路径，新包里只是 `server_name _` 模板）。

---

## 🌐 换镜像源（可选）

部署包默认从本项目的 **GitHub Release** 拉取（`releases/latest/download/bc-deploy.tar.gz`，由 Actions 在推 tag 时自动构建上传）。

想用自建镜像源（比如对象存储）加速，用 `BC_DEPLOY_URL` 覆盖：

```bash
R2_URL="https://pub-你的hash.r2.dev"
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh \
  | BC_DEPLOY_URL="$R2_URL/bc-deploy.tar.gz" sudo -E bash
```

（交互式提问部分仍然会走，只是部署包下载源换了）

---

## 🐛 常见问题

### Q1: 一键命令报"80/443 已被占用"

有别的服务占了端口。查是谁：
```bash
sudo ss -tlnp | grep -E ':(80|443)\s'
```
先停掉那个服务（比如 apache/nginx），或者交互时选 HTTP-only 模式（跳过 443）。

### Q2: LE 签发证书失败

- **检查 DNS**：`dig +short 你的域名` 必须返回本机公网 IP
- **检查防火墙**：云厂商安全组必须放行 80 端口的**入站流量**（LE 校验走 80）
- **检查限流**：LE 有每周签发次数上限，短时间反复试会被 blocked（等 1 小时或换域名）

### Q3: 自签名 HTTPS 首次访问浏览器警告

正常，点"**高级 → 继续访问**"即可。浏览器会记住选择，后续访问不再警告。企业环境要免警告，把 `/opt/bc/deploy/ssl/bundle.crt` 下载后加到系统信任根（Windows：`certmgr.msc` 导入到"受信任的根证书颁发机构"；macOS：Keychain → System → 拖入 → 双击 → Always Trust）。

### Q4: bc-app 一直重启

一般是权限或 APP_KEY 问题：
```bash
sudo docker logs bc-app --tail 50
```
需要重新跑一遍安装：
```bash
sudo BC_FORCE=1 bash <(curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh)
```

### Q5: 内存不够，Docker 构建时被 kill

1 GB 小机可能构建期间 OOM。检查 swap：
```bash
free -m
```
如果 swap = 0，脚本应该自动加，没加成功就手动：
```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && \
sudo mkswap /swapfile && sudo swapon /swapfile && \
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### Q6: 想换域名/换 IP 怎么办

原地重装即可，数据库保留：
```bash
# 备份数据库到 /tmp
sudo docker exec bc-app cp /var/www/html/database/data/database.sqlite /tmp/backup.sqlite
sudo docker cp bc-app:/tmp/backup.sqlite ~/bc-backup.sqlite

# 清干净重装
cd /opt/bc/deploy && sudo docker compose down -v && sudo rm -rf /opt/bc

# 重新跑一键脚本（走交互，填新的地址）
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | sudo bash

# 恢复数据库
sudo docker cp ~/bc-backup.sqlite bc-app:/var/www/html/database/data/database.sqlite
sudo docker restart bc-app
```

### Q7: `.env` 里的 APP_KEY 丢了怎么办

**存储的密码解不开了**。这就是为什么系统备份必须包含 `.env`。如果确实丢了 APP_KEY：
1. 生成新 APP_KEY，写入 `/opt/bc/deploy/.env`
2. 所有存储的密码全部作废（config 字段的密文无法解密）
3. 手动在网页里重新填一遍每家存储的密码

**教训**：登录后马上到 **设置 → 系统备份 → 立即备份**，把 SQLite + .env 打包到强制备份存储里存一份。

### Q8: 一键命令没有弹交互提示

如果你用 `curl | bash` 但没看到 `? 输入你的域名...` 提示，可能你在一个非交互 shell 里（比如 `screen` / 某些 CI）。**解决**：改用 `bash <(curl ...)` 或先下载再跑：
```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh -o /tmp/bc-install.sh
sudo bash /tmp/bc-install.sh
```

### Q9: 备份加密是什么，解决什么问题

**让云端只留密文。** 开启后上传链路多包一层 rclone crypt：文件在离开 Agent 主机前加密、落到第三方存储的是密文，下载恢复时同一套配置自动解密——对备份记录、恢复流程、保留期清理**全部透明**，不需要改任何现有数据模型。

不开加密时：备份产物（tar.gz / .sql.gz）原样上传，文件名还带着站点名和日期（`<站点>-web-<时间戳>.tar.gz`）。云盘账号被盗、rclone 凭据泄露、存储供应商内部越权 = 备份内容全泄，还能从文件名读出业务规模与站点结构。

开了之后可选两档：

| 选项 | 效果 | 代价 |
|---|---|---|
| 文件名加密 `standard` | 云端文件名变密文 | 云端侧排障只能依赖 Center 里的记录 |
| 目录名加密（默认开） | 连目录一起加密 | 同上，且只能在 Center 侧看真实路径 |

**教训**：这只是把"云端静态数据"从明文变密文的一层，别把它当成万能盾——见 Q10。

### Q10: 开了加密就绝对安全了吗（威胁模型边界）

不是。**先划清楚防什么、不防什么**：

- **防得住**：第三方存储服务商/云账号被攻破、rclone 凭据（token / 密码）泄露、传输链路被动窃听（配合 HTTPS 后）。
- **防不住**：
  - **Agent 主机被攻破** —— `/etc/backup-agent/rclone.conf` 里就有密钥，和没加密时"主机上就有存储密码"是同一个信任域；
  - **Center 服务器被攻破** —— 默认方案（T1）里加密密码托管在 Center 数据库，和存储凭据、数据库密码一起在库里；
  - 有云控制台权限的人做整桶删除（防泄不防删）。
- Center 的 SQLite 库里**仍然存着明文文件名和路径**给 UI 展示用，加密不改变这一点。

所以顺序是：**先保证主机与 Center 自身安全，再谈加密**。加密是边际收益最大、代价最小的一层，但它替代不了主机加固。

### Q11: T1 中心托管 和 T2 Agent 本地密钥有什么区别，怎么启用

在 **存储 → 编辑 → 加密** 里开关，两种密钥托管方式：

| | T1 中心托管（`center`）默认 | T2 Agent 本地密钥（`agent_local`） |
|---|---|---|
| 密码存在哪 | Center 数据库（随 storages.config 整块加密） | 只有 Agent 主机 `/etc/backup-agent/backup.key` |
| Center 是否知情 | 是，随 `websites()` 下发给 Agent 内存 | **否**，中心侧永远为空、不下发 |
| Center 沦陷后果 | 存储凭据 + 加密密钥同泄 | 攻击者拿不到密钥，云端仍是密文 |
| 保留期清理谁执行 | Center 侧跑 rclone 删 | **Agent 侧删**（新增 `cleanup_retention` 指令，由 03:30 的调度统一派发+对账） |
| 适合谁 | 绝大多数场景，零额外运维 | 有合规要求 / 不想让 Center 持有密钥 |

启用步骤（两种方式通用）：
1. 存储页打开「加密」→ 填密码（编辑时留空或 `••••••••` = 不改）→ 保存
2. 点「连接测试」：**中心托管模式会真的写一个小文件上去、再下载回来比对内容**，密码不对直接测试失败（这一步是故意的，它证明密码可用）；**Agent 本地密钥模式**中心没有密码，只能验证底层存储能连通，界面会提示"未验证密码正确性"
3. 建议跑一次备份，直接登录云端控制台确认看到的是乱码文件名/乱码目录

注意三点：
- **加密是存储级开关**：关掉后新备份回明文、旧的加密备份照常可恢复，新旧混存互不干扰。
- **无法原地换密钥**：rotation 等于按新密钥重传全量（或双 Section 过渡：先写新、删旧）。
- **T2 下某站点没有 Agent 时**，加密的文件中心删不掉，清理任务会把它们列进"需人工处理"清单——别把 Agent 摘了不管。

### Q12: 加密密码丢了怎么办

**这部分备份就全废了。** rclone crypt 没有找回机制，密码丢了云端那些密文永远解不开。和 APP_KEY（Q7）是同一级别的东西。

所以：
1. **必须纳入系统备份**：设置 → 系统备份 → 立即备份，打包 SQLite + `.env` 存到强制备份存储；
2. T1 的加密密码在 `storages.config` 里，跟着 SQLite 一起备份；
3. T2 的 `backup.key` 在 Agent 主机上，不在 Center 的备份范围内——**自己单独保管一份**；
4. 万不得已：重设密码 = 之前的加密备份全部作废，只能从头重传。

### Q13: Agent 和 Center 之间为什么必须 HTTPS，有没有逃生开关

Center 接口用 `X-Agent-Token` 做认证，**token 本身不加密**；`websites()` 会下发存储的解密后配置（含存储密码、T1 的加密密码）和网站的数据库密码。走明文 HTTP 等于把整套凭据放在链路上给人捡。

**强制要求**：Agent 配置里的 `center_url` 必须是 `https://`。证书用 LE 签发（部署脚本默认）或内网自签都行。

逃生开关 `BC_ALLOW_HTTP=1`（纯内网、Agent 与 Center 同机房且链路可信时才考虑）：设置后 Agent 才允许用 http 启动，否则直接拒绝运行并提示。**开之前先确认链路真的可信**——这个开关只省掉"申请证书"这一步，不省掉风险。

> 注：HTTPS 强制校验与 `BC_ALLOW_HTTP` 由 Agent 侧实现；纯内网裸奔前请再确认一遍你的链路。

---

## 📞 支持

- 部署问题：把 `sudo docker logs bc-app --tail 80` + `sudo docker logs bc-web --tail 40` 的输出发过来
- 功能咨询：见系统内 **帮助中心** tab

---

## 📄 文件位置速查

| 路径 | 内容 |
|---|---|
| `/opt/bc/` | 代码 + Docker 配置 |
| `/opt/bc/deploy/docker-compose.yml` | 容器编排 |
| `/opt/bc/deploy/.env` | APP_KEY（**必备份**） |
| `/opt/bc/deploy/nginx.conf` | Nginx 配置 |
| `/opt/bc/deploy/ssl/` | SSL 证书 |
| Docker 卷 `deploy_bc-database` | SQLite 数据库（**必备份**） |
| Docker 卷 `deploy_bc-storage` | Laravel storage 目录 |
| `/etc/letsencrypt/live/<domain>/` | LE 证书原文件（仅 LE 模式） |
