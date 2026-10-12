# Backup Center

自托管的多服务器备份编排系统。一台 Center 统一管理多台机器上的网站备份，自动扫描、自动上传、集中查看。

- **Center**：Laravel 12 后端 + Vue 3 前端，Docker Compose 一键起
- **Agent**：Go 写的轻量代理，装在每台被备份的机器上，负责扫描目录并上传
- **存储**：S3 兼容 / SFTP / FTP-FTPS / WebDAV，可同时挂多个

---

## 特性

- **多协议存储** —— S3 兼容（COS、OSS、R2、MinIO）、SFTP、FTP/FTPS、WebDAV
- **强制备份** —— 某个存储标记为「强制」后，所有网站自动向它推送一份
- **时间戳归组** —— 备份按 `<website>/YYYYMMDD/` 分目录存放，干净可追溯
- **两种同步方式** —— 自动检测（每 2 分钟）或定时（每天指定时刻）
- **Agent 远程升级** —— 网页点一下，Agent 从镜像源拉新二进制自更新
- **资源池** —— 集中管理多家对象存储凭证，可选作 Agent 下载镜像
- **系统自备份** —— Backup Center 自己也能定时打包上传到远端存储
- **页脚可配置** —— 版权、ICP 备案、统计、联系方式、自定义 HTML

---

## 快速开始

### 方式一 · 一键部署（推荐）

在一台干净的 VPS 上（KVM / XEN 虚拟化，root 权限）跑：

```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | sudo bash
```

脚本会交互式询问访问地址、证书方式、端口，然后自动完成：虚拟化兼容检查 → 装 Docker → 拉部署包 → 签证书 → 起容器 → 配置续期钩子 → 冒烟测试。

想跳过交互（自动化场景）：

```bash
curl -fsSL .../bc-install.sh | BC_ACCESS=bak.example.com BC_EMAIL=you@example.com sudo -E bash
```

常用环境变量：

| 变量 | 说明 |
|---|---|
| `BC_ACCESS` | 访问地址（域名或 IP） |
| `BC_EMAIL` | Let's Encrypt 通知邮箱 |
| `BC_HTTP_ONLY=1` | 强制 HTTP-only |
| `BC_HTTP_PORT` / `BC_HTTPS_PORT` | 自定义端口（LXC 等绑不了 80/443 时用） |
| `BC_DEPLOY_URL` | 覆盖部署包下载源 |
| `BC_FORCE=1` | 覆盖已有安装 |

> ⚠️ **VPS 兼容性**：OpenVZ 6/7 不支持 Docker，脚本会拒绝安装。LXC 若无法绑特权端口，脚本自动切到 8080/8443。

### 方式二 · 手动部署

```bash
git clone https://github.com/gongpz/backup-center.git
cd backup-center/backup-center

# 后端
cd backend && composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
cd ..

# 前端
cd frontend && npm install && npm run build
cd ..
```

然后用 `deploy/docker-compose.yml` 起服务。

### 首次登录

部署完访问你的地址。系统检测到还没有任何用户时，会引导你**创建第一个管理员账号**（密码至少 8 位）。创建后该入口自动关闭。

---

## 部署 Agent

在 Center 的「服务器」页面添加一台服务器，复制生成的安装命令，到目标机器上执行：

```bash
curl -fsSL https://<your-center>/agent/install.sh | BC_URL="https://<your-center>" BC_TOKEN="<token>" sh
```

Agent 会以 systemd 服务运行，定期向 Center 心跳上报，并接收扫描 / 上传 / 升级指令。

---

## 项目结构

```
backup-center/
├── backend/     Laravel 12 后端（PHP 8.2+）
├── frontend/    Vue 3 + Element Plus 前端
├── agent/       Go 备份代理（Go 1.23+）
└── deploy/      docker-compose + nginx 模板
install-scripts/ 一键部署 / 卸载脚本
docs/            产品与部署文档
```

---

## 开发

环境要求：Node.js ≥ 20、PHP ≥ 8.2 + Composer 2、Go ≥ 1.22、Docker + Docker Compose。

```bash
cd backup-center/frontend && npm run dev     # 前端开发模式
cd backup-center/backend && php artisan serve # 后端
cd backup-center/agent && GOOS=linux GOARCH=amd64 go build -o backup-agent ./
```

---

## 构建部署包 / 发布

一键部署脚本从 GitHub Release 拉取 `bc-deploy.tar.gz`，包内结构如下（解压到 `/opt/bc`）：

```
backend/                    Laravel 后端源码
deploy/                     docker-compose + nginx 模板
deploy/frontend-dist/       前端构建产物
deploy/agent-dist/          Agent 二进制（linux amd64 / arm64）
```

**推荐走 GitHub Actions** —— 打 tag 即自动构建并挂到 Release：

```bash
git tag v1.0.0 && git push origin v1.0.0
```

也可以本地构建（需 Node + Go）：

```bash
bash scripts/build-release.sh          # 产出 ./bc-deploy.tar.gz
SKIP_AGENT=1 bash scripts/build-release.sh   # 不重编 Agent，复用已有 deploy/agent-dist
```

然后手动上传到 Release 页面即可。

---

## 卸载

```bash
curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-uninstall.sh | sudo bash
```

交互式逐项确认，默认保留数据库、SSL 证书等资产，不会动 Docker 本身。

---

## 许可

本项目采用 **GNU Affero General Public License v3.0 (AGPL-3.0)** —— 详见 [LICENSE](./LICENSE)。

简而言之：你可以自由使用、修改和分发本项目，但**如果你将修改后的版本作为网络服务对外提供，必须同样公开你的完整源代码**。
