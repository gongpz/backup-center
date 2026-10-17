#!/usr/bin/env bash
# Backup Center · 一键交互式部署脚本
#
# 使用（一条命令，自动交互式提问）：
#   curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | sudo bash
#
# 也可用环境变量跳过交互（用于自动化）：
#   curl -fsSL ... | BC_ACCESS=xxx BC_EMAIL=xxx BC_HTTP_ONLY=1 sudo -E bash
#
# 想用自建镜像源拉部署包：
#   curl -fsSL ... | BC_DEPLOY_URL=https://mirror.example.com/bc-deploy.tar.gz sudo -E bash
#
# 部署包渠道（默认 release 正式版；要装 main 分支最新未发布功能用 main）：
#   curl -fsSL ... | BC_CHANNEL=main sudo -E bash
#
# 备用源可选（主源失败时才尝试，默认关闭）：
#   curl -fsSL ... | BC_DEPLOY_URL_FALLBACK=https://mirror2.example.com/bc-deploy.tar.gz sudo -E bash
#
set -euo pipefail

# ---------- 配置 ----------
# 部署包渠道：
#   release = 正式发布（默认；当前钉到 v1.2.7：releases/download/v1.2.7，
#            避开 GitHub 的 latest 标志未自动翻新的问题；发布新版时请把这里的版本号同步上调）
#   main    = 滚动构建（releases/download/bc-rolling，含 main 分支最新未发布功能，用于提前验证）
BC_CHANNEL="${BC_CHANNEL:-release}"
if [ "$BC_CHANNEL" = "main" ]; then
    DEPLOY_URL_PRIMARY="${BC_DEPLOY_URL:-https://github.com/gongpz/backup-center/releases/download/bc-rolling/bc-deploy.tar.gz}"
else
    DEPLOY_URL_PRIMARY="${BC_DEPLOY_URL:-https://github.com/gongpz/backup-center/releases/download/v1.2.7/bc-deploy.tar.gz}"
fi
DEPLOY_URL_FALLBACK="${BC_DEPLOY_URL_FALLBACK:-}"
TARGET_DIR="/opt/bc"

# 端口默认值（必须在这里初始化：LXC 兼容检测会引用，set -u 下未定义会直接退出）
BC_HTTP_PORT="${BC_HTTP_PORT:-}"
BC_HTTPS_PORT="${BC_HTTPS_PORT:-}"

C_CYAN='\033[1;36m'
C_YEL='\033[1;33m'
C_RED='\033[1;31m'
C_GRN='\033[1;32m'
C_DIM='\033[0;90m'
C_RST='\033[0m'
log()  { printf "${C_CYAN}[bc]${C_RST} %s\n" "$*"; }
warn() { printf "${C_YEL}[bc]${C_RST} %s\n" "$*" >&2; }
die()  { printf "${C_RED}[bc]${C_RST} %s\n" "$*" >&2; exit 1; }

# 兼容 tty 输入：curl | bash 时 stdin 被占用，用 /dev/tty 读用户
_read_tty() {
    local __var="$1"; local __prompt="$2"; local __default="${3:-}"
    if [ -e /dev/tty ]; then
        if [ -n "$__default" ]; then
            printf "${C_CYAN}?${C_RST} %s [${C_DIM}%s${C_RST}]: " "$__prompt" "$__default" > /dev/tty
        else
            printf "${C_CYAN}?${C_RST} %s: " "$__prompt" > /dev/tty
        fi
        # shellcheck disable=SC2229
        read -r "$__var" < /dev/tty || true
        local __val
        __val="$(eval echo "\$$__var")"
        if [ -z "$__val" ] && [ -n "$__default" ]; then
            eval "$__var=\"\$__default\""
        fi
    else
        die "无 /dev/tty，非交互环境请用环境变量跳过（BC_ACCESS/BC_EMAIL/BC_HTTP_ONLY）"
    fi
}

# ---------- 基本检查 ----------
[ "$(id -u)" -eq 0 ] || die "请用 root 或 sudo 执行"

echo ""
printf "${C_GRN}================================================\n${C_RST}"
printf "${C_GRN}   Backup Center · 一键部署（交互式）${C_RST}\n"
printf "${C_GRN}================================================${C_RST}\n"
echo ""

# 追踪是否有交互（用户至少答过一个问题）→ 全自动就跳过最终确认
INTERACTED=0

# ---------- 1) 访问地址 ----------
BC_ACCESS="${BC_ACCESS:-${BC_DOMAIN:-}}"
if [ -z "$BC_ACCESS" ]; then
    echo "第 1 步：访问地址"
    # 先探测本机公网 IP，让用户选择「用 IP 还是域名」；选 IP 默认用探测到的本机 IP
    PUB_IP="$(curl -fsS -m 5 https://api.ipify.org 2>/dev/null || curl -fsS -m 5 https://ifconfig.me 2>/dev/null || true)"
    if [ -n "$PUB_IP" ]; then
        echo "  · 检测到本机公网 IP：$PUB_IP"
        echo "  1) 使用 IP 访问    （默认使用本机公网 IP）"
        echo "  2) 使用域名访问    （需要 A 记录已指到本机）"
        while :; do
            ACC_CHOICE=""
            _read_tty ACC_CHOICE "选择 (1/2)" "1"
            INTERACTED=1
            case "$ACC_CHOICE" in
                1|"")
                    BC_ACCESS="$PUB_IP"
                    echo "  → 使用本机公网 IP：$BC_ACCESS"
                    break;;
                2)
                    while :; do
                        DOM_INPUT=""
                        _read_tty DOM_INPUT "输入你的域名"
                        if [ -n "$DOM_INPUT" ]; then
                            BC_ACCESS="$DOM_INPUT"
                            break 2
                        fi
                        warn "域名不能为空"
                    done;;
                *)
                    warn "输入 1 或 2";;
            esac
        done
    else
        echo "  · 域名示例：bak.example.com（需要 A 记录先指到本机）"
        echo "  · IP 示例：1.2.3.4"
        echo "  （未能自动探测公网 IP，请手动输入）"
        _read_tty BC_ACCESS "输入你的域名或 IP"
        INTERACTED=1
    fi
fi
[ -n "$BC_ACCESS" ] || die "访问地址不能为空"

# 识别 IP or 域名
IS_IP=0
if echo "$BC_ACCESS" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
    IS_IP=1
    echo "  → 识别为 IP 地址"
else
    echo "  → 识别为域名"
fi
echo ""

# ---------- 2) 证书模式 ----------
# 优先环境变量；没有则问用户
MODE=""
if [ -n "${BC_HTTP_ONLY:-}" ] && [ "$BC_HTTP_ONLY" = "1" ]; then
    MODE="http"
elif [ "$IS_IP" = "1" ]; then
    # IP 模式
    echo "第 2 步：SSL 证书方式（IP 访问）"
    echo "  1) 自签名 HTTPS   （数据加密传输，浏览器首次访问会提示证书警告，点'高级 → 继续'即可）"
    echo "  2) HTTP-only      （无加密，只适合内网/临时/自用）"
    while :; do
        SSL_CHOICE=""
        _read_tty SSL_CHOICE "选择 (1/2)" "1"
        INTERACTED=1
        case "$SSL_CHOICE" in
            1|"") MODE="https-selfsigned"; break;;
            2)   MODE="http";              break;;
            *)   warn "输入 1 或 2";;
        esac
    done
else
    # 域名模式
    echo "第 2 步：SSL 证书方式（域名访问）"
    echo "  1) Let's Encrypt 免费 HTTPS  （推荐；证书自动续期，浏览器绿锁）"
    echo "  2) HTTP-only                 （无加密，只适合内网/临时/自用）"
    while :; do
        SSL_CHOICE=""
        _read_tty SSL_CHOICE "选择 (1/2)" "1"
        INTERACTED=1
        case "$SSL_CHOICE" in
            1|"") MODE="https-le";  break;;
            2)   MODE="http";       break;;
            *)   warn "输入 1 或 2";;
        esac
    done
fi
echo "  → 证书模式：$MODE"
echo ""

# ---------- 3) 端口自定义 ----------
if [ -z "$BC_HTTP_PORT" ] && [ -z "$BC_HTTPS_PORT" ]; then
    DEFAULT_PORT=$([ "$MODE" = "http" ] && echo "80" || echo "443")
    PORT_LABEL=$([ "$MODE" = "http" ] && echo "HTTP" || echo "HTTPS")
    echo "第 3 步：$PORT_LABEL 端口"
    echo "  · 默认 $DEFAULT_PORT（标准端口，直接访问 http(s)://地址/）"
    echo "  · 某些 LXC / OpenVZ VPS 不能绑 <1024 特权端口 —— 需要改成 8080 / 8443 之类"
    PORT_INPUT=""
    _read_tty PORT_INPUT "输入端口" "$DEFAULT_PORT"
    if echo "$PORT_INPUT" | grep -qE '^[0-9]+$' && [ "$PORT_INPUT" -ge 1 ] && [ "$PORT_INPUT" -le 65535 ]; then
        INTERACTED=1
        if [ "$MODE" = "http" ]; then
            BC_HTTP_PORT="$PORT_INPUT"
        else
            BC_HTTPS_PORT="$PORT_INPUT"
        fi
    else
        warn "端口无效，用默认 $DEFAULT_PORT"
    fi
    echo ""
fi

# ---------- 3.5) LE 邮箱（仅 LE 模式）----------
if [ "$MODE" = "https-le" ] && [ -z "${BC_EMAIL:-}" ]; then
    echo "第 3 步：Let's Encrypt 通知邮箱"
    echo "  · 用来接收证书过期通知（LE 强制要求；不发广告）"
    while :; do
        BC_EMAIL=""
        _read_tty BC_EMAIL "输入邮箱"
        INTERACTED=1
        if echo "$BC_EMAIL" | grep -qE '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then
            break
        else
            warn "邮箱格式不对"
        fi
    done
    echo ""
fi

# ---------- 汇总确认 ----------
SCHEME=$([ "$MODE" = "http" ] && echo http || echo https)
printf "${C_GRN}────────────────  即将部署 ────────────────${C_RST}\n"
echo "  访问地址：$SCHEME://$BC_ACCESS/"
echo "  证书模式：$MODE"
[ "$MODE" = "https-le" ] && echo "  证书邮箱：$BC_EMAIL"
echo "  部署包源：$DEPLOY_URL_PRIMARY"
echo "  安装路径：$TARGET_DIR"
printf "${C_GRN}────────────────────────────────────────${C_RST}\n"

# 用户答过任何一个问题 → 让他最终确认；全环境变量走完 → 直接开跑
if [ "$INTERACTED" = "1" ]; then
    CONFIRM=""
    _read_tty CONFIRM "确认开始？(Y/n)" "Y"
    case "$CONFIRM" in
        Y|y|YES|yes|"") ;;
        *) die "已取消";;
    esac
fi
echo ""

# ---------- 已装检测 ----------
FRESH_INSTALL=0
if [ -d "$TARGET_DIR" ] && [ -z "${BC_FORCE:-}" ]; then
    warn "$TARGET_DIR 已存在"
    echo "  1) 卸载后全新安装  （清空 $TARGET_DIR：容器、数据库、证书、配置全部重置，从零开始）"
    echo "  2) 覆盖安装        （保留 .env / 数据库 / 证书，仅更新程序文件）"
    echo "  3) 退出"
    OW=""
    _read_tty OW "选择处理方式 (1/2/3)" "2"
    case "$OW" in
        1)
            FRESH_INSTALL=1
            log "  全新安装：清空 $TARGET_DIR（数据库将重置，证书需重新生成）"
            rm -rf "$TARGET_DIR"
            ;;
        2) BC_FORCE=1;;
        *) die "已取消。如需手动处理：cd /opt/bc/deploy && docker compose down -v && rm -rf /opt/bc";;
    esac
fi

# ---------- DNS 校验（域名模式） ----------
if [ "$IS_IP" = "0" ]; then
    DNS_IP="$(getent hosts "$BC_ACCESS" 2>/dev/null | awk '{print $1}' | head -1 || true)"
    PUB_IP="$(curl -fsS -m 5 https://api.ipify.org 2>/dev/null || curl -fsS -m 5 https://ifconfig.me 2>/dev/null || true)"
    if [ -n "$DNS_IP" ] && [ -n "$PUB_IP" ] && [ "$DNS_IP" != "$PUB_IP" ]; then
        warn "$BC_ACCESS 解析到 $DNS_IP，本机公网 IP 是 $PUB_IP"
        if [ "$MODE" = "https-le" ]; then
            warn "  → LE 证书大概率签失败，20 秒后继续（Ctrl+C 中止）"
            sleep 20
        fi
    fi
fi

# ---------- 0) VPS 兼容性预检 ----------
log "0/7 VPS 兼容性检查"
VIRT_TYPE="unknown"
if command -v systemd-detect-virt >/dev/null 2>&1; then
    VIRT_TYPE=$(systemd-detect-virt 2>/dev/null || echo "unknown")
fi
IS_OPENVZ=0
IS_LXC=0
[ -d /proc/vz ] && IS_OPENVZ=1
[ "$VIRT_TYPE" = "openvz" ] && IS_OPENVZ=1
[ "$VIRT_TYPE" = "lxc" ] && IS_LXC=1
[ "$VIRT_TYPE" = "lxc-libvirt" ] && IS_LXC=1

log "  虚拟化类型：$VIRT_TYPE"
if [ "$IS_OPENVZ" = "1" ]; then
    warn ""
    warn "═════════════════════════════════════════════════════════"
    warn "  ⚠ 检测到 OpenVZ 虚拟化 —— Docker 官方不支持！"
    warn "═════════════════════════════════════════════════════════"
    warn "  已知问题："
    warn "    · Docker daemon 可能起不来（内核限制）"
    warn "    · 无法修改 sysctl（绑定 80/443 失败）"
    warn "    · 存储驱动退化为 vfs（极慢）"
    warn "    · iptables 常被宿主锁死，容器网络挂"
    warn ""
    warn "  建议：换 KVM / XEN 虚拟化的 VPS（Vultr / Hetzner / RackNerd KVM 套餐等）"
    warn ""
    if [ -z "${BC_FORCE_OPENVZ:-}" ]; then
        die "OpenVZ 不支持部署。要强行试可传 BC_FORCE_OPENVZ=1（大概率失败）"
    else
        warn "  BC_FORCE_OPENVZ=1 已设，硬着头皮继续（自负后果）"
        sleep 5
    fi
fi

if [ "$IS_LXC" = "1" ]; then
    # 试探能不能改 sysctl
    if ! echo 0 2>/dev/null | tee /proc/sys/net/ipv4/ip_unprivileged_port_start >/dev/null 2>&1; then
        warn "  ⚠ 检测到 LXC 虚拟化 + 无法修改 sysctl"
        warn "     Docker 装 <1024 特权端口会失败（80/443）"
        if [ "${BC_HTTP_PORT:-}" = "80" ] && [ "${BC_HTTPS_PORT:-}" = "443" ]; then
            warn "     → 已自动切换到高端口：HTTP 8080 + HTTPS 8443"
            BC_HTTP_PORT=8080
            BC_HTTPS_PORT=8443
        else
            warn "     ✓ 你已指定 BC_HTTP_PORT=${BC_HTTP_PORT:-默认} / BC_HTTPS_PORT=${BC_HTTPS_PORT:-默认}，可以继续"
        fi
        sleep 3
    fi
fi

# ---------- 端口默认值兜底 ----------
BC_HTTP_PORT="${BC_HTTP_PORT:-80}"
BC_HTTPS_PORT="${BC_HTTPS_PORT:-443}"

# ---------- 1) 端口冲突 ----------
log "1/7 检查端口（$BC_HTTP_PORT / $BC_HTTPS_PORT）"
if [ "$MODE" = "http" ]; then
    PORTS_PATTERN=":$BC_HTTP_PORT\s"
    DOCKER_PORTS_PATTERN=":$BC_HTTP_PORT->"
else
    PORTS_PATTERN=":$BC_HTTP_PORT\s|:$BC_HTTPS_PORT\s"
    DOCKER_PORTS_PATTERN=":$BC_HTTP_PORT->|:$BC_HTTPS_PORT->"
fi

# 端口是否仍被占用
bc_ports_busy() { ss -tlnp 2>/dev/null | grep -E "($PORTS_PATTERN)"; }

# 覆盖/重装时端口常被上一次的 bc-web 容器占着（docker-proxy）。
# 只处理本项目自己的容器（bc-* / compose 项目 deploy）；外部服务不碰。
bc_release_ports() {
    command -v docker >/dev/null 2>&1 || return 1
    timeout 10 docker info >/dev/null 2>&1 || return 1
    local freed=0 cname owners
    # 1) 上一次的 compose 栈还在跑 → down（不加 -v，覆盖安装保留数据卷）
    #    判定用 docker ps（compose ps -q 在部分环境会返回空，不可靠）
    if [ -f "$TARGET_DIR/deploy/docker-compose.yml" ] \
       && docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^bc-'; then
        log "  停止上一次的 Backup Center 容器栈，释放端口…"
        (cd "$TARGET_DIR/deploy" && docker compose down --remove-orphans >/dev/null 2>&1)
        freed=1
    fi
    # 2) 兜底：仍占着端口的 docker 容器，属于本项目才强删
    owners="$(docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -E "($DOCKER_PORTS_PATTERN)" | awk '{print $1}')"
    for cname in $owners; do
        case "$cname" in
            bc-*)
                log "  停止旧容器 $cname 以释放端口…"
                docker rm -f "$cname" >/dev/null 2>&1 && freed=1;;
            *)
                warn "  端口被容器 $cname 占用（非 Backup Center），不自动处理";;
        esac
    done
    [ "$freed" = "1" ] || return 1
    # 等端口真正释放
    for _ in $(seq 1 10); do
        bc_ports_busy >/dev/null || return 0
        sleep 1
    done
    bc_ports_busy >/dev/null && return 1
    return 0
}

if bc_ports_busy >/dev/null; then
    if bc_ports_busy | grep -q 'docker-proxy'; then
        if bc_release_ports; then
            log "  ✓ 已自动释放端口（停止了旧的 Backup Center 容器）"
        fi
    fi
    if bc_ports_busy >/dev/null; then
        warn "端口已被占用："
        bc_ports_busy || true
        die "请先释放这些端口。若是 nginx/apache 等外部服务：停掉它，或用 BC_HTTP_PORT / BC_HTTPS_PORT 换端口重跑；若是 Backup Center 旧容器：cd $TARGET_DIR/deploy && docker compose down"
    fi
fi

# ---------- 2) Docker ----------
log "2/7 检查 Docker"
if ! command -v docker >/dev/null 2>&1; then
    log "  安装 Docker（1-2 分钟）"
    curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    sh /tmp/get-docker.sh
    rm -f /tmp/get-docker.sh
fi
docker --version >/dev/null || die "Docker 安装失败"
docker compose version >/dev/null 2>&1 || die "Docker Compose 插件缺失"

# 二进制存在 ≠ 守护进程在跑（docker --version / compose version 都不需要 daemon）。
# 历史上这里只查二进制，导致 daemon 没起的机器下载解压全走完、第 7 步构建才崩。
# 必须确认 daemon 真的活着；起不来就早死，别浪费用户时间。
if ! timeout 10 docker info >/dev/null 2>&1; then
    log "  Docker 已安装但守护进程未运行，尝试启动..."
    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable docker >/dev/null 2>&1 || true
        systemctl start docker  >/dev/null 2>&1 || true
    fi
    if command -v service >/dev/null 2>&1; then
        service docker start >/dev/null 2>&1 || true
    fi
    for _ in $(seq 1 10); do
        timeout 10 docker info >/dev/null 2>&1 && break
        sleep 2
    done
fi
timeout 10 docker info >/dev/null 2>&1 || die "Docker 守护进程无法连接。请先手动修复：systemctl status docker；journalctl -xe | grep docker。常见原因：内核不支持 / cgroup 异常 / 磁盘满 / systemd 未激活。修好后重跑本脚本。"
log "  ✓ Docker 就绪"

# 全新安装：清掉上一次安装遗留的容器和数据卷（rm -rf 删了 compose 文件，
# 容器不会自动消失；不清则后面 up -d 会撞名 'container name already in use'）
if [ "${FRESH_INSTALL:-0}" = "1" ]; then
    docker rm -f bc-app bc-web >/dev/null 2>&1 || true
    docker volume rm deploy_bc-database >/dev/null 2>&1 || true
    log "  ✓ 已清理旧容器与数据卷"
fi

# ---------- 3) 拉部署包 ----------
log "3/7 拉部署包"
mkdir -p /tmp/bc-download
got_pkg=0
for u in "$DEPLOY_URL_PRIMARY" ${DEPLOY_URL_FALLBACK:+"$DEPLOY_URL_FALLBACK"}; do
    if curl -fsSL --max-time 90 "$u" -o /tmp/bc-download/bc-deploy.tar.gz && [ -s /tmp/bc-download/bc-deploy.tar.gz ]; then
        log "  ✓ 下载 OK: $u"
        got_pkg=1
        break
    fi
    log "  ✗ 失败: $u"
done
if [ "$got_pkg" != "1" ]; then
    die "拉不到部署包（源：$DEPLOY_URL_PRIMARY${DEPLOY_URL_FALLBACK:+ / $DEPLOY_URL_FALLBACK}）。可用 BC_DEPLOY_URL 指定自建镜像源。"
fi

# ---------- 4) 证书 ----------
mkdir -p "$TARGET_DIR/deploy/ssl"
case "$MODE" in
    "https-le")
        log "4/7 签发 Let's Encrypt 证书"
        mkdir -p /etc/letsencrypt /var/lib/letsencrypt
        if [ ! -f "/etc/letsencrypt/live/$BC_ACCESS/fullchain.pem" ]; then
            docker run --rm -p 80:80 \
                -v /etc/letsencrypt:/etc/letsencrypt \
                -v /var/lib/letsencrypt:/var/lib/letsencrypt \
                certbot/certbot certonly --standalone \
                -d "$BC_ACCESS" --non-interactive --agree-tos -m "$BC_EMAIL"
        fi
        [ -f "/etc/letsencrypt/live/$BC_ACCESS/fullchain.pem" ] || die "LE 签发失败"
        cp -L "/etc/letsencrypt/live/$BC_ACCESS/fullchain.pem" "$TARGET_DIR/deploy/ssl/bundle.crt"
        cp -L "/etc/letsencrypt/live/$BC_ACCESS/privkey.pem"   "$TARGET_DIR/deploy/ssl/private.key"
        ;;
    "https-selfsigned")
        log "4/7 生成自签名证书（SAN=IP:$BC_ACCESS，10 年有效）"
        SSL_TMP=$(mktemp -d)
        cat > "$SSL_TMP/openssl.cnf" <<EOF
[req]
default_bits = 2048
distinguished_name = req_dn
req_extensions = v3_req
prompt = no
[req_dn]
CN = $BC_ACCESS
[v3_req]
subjectAltName = IP:$BC_ACCESS
extendedKeyUsage = serverAuth
EOF
        openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
            -keyout "$SSL_TMP/private.key" -out "$SSL_TMP/bundle.crt" \
            -config "$SSL_TMP/openssl.cnf" -extensions v3_req >/dev/null 2>&1
        mv "$SSL_TMP/bundle.crt"  "$TARGET_DIR/deploy/ssl/bundle.crt"
        mv "$SSL_TMP/private.key" "$TARGET_DIR/deploy/ssl/private.key"
        rm -rf "$SSL_TMP"
        ;;
    "http")
        log "4/7 HTTP-only 模式，跳过证书"
        ;;
esac
[ "$MODE" != "http" ] && chmod 600 "$TARGET_DIR/deploy/ssl/private.key"

# ---------- 5) 展开 ----------
log "5/7 展开部署文件到 $TARGET_DIR"
mkdir -p "$TARGET_DIR"
tar -xzf /tmp/bc-download/bc-deploy.tar.gz -C "$TARGET_DIR"
rm -rf /tmp/bc-download

# 版本信息（构建时写入 backend/VERSION）：让安装者一眼看到装的是哪个版本，
# 避免"装了旧版却不知道"——之前线上 latest 停在 v1.1.0、main 已领先 61 个提交却装不到就是教训。
if [ -f "$TARGET_DIR/backend/VERSION" ]; then
    BC_INSTALLED_VERSION="$( . "$TARGET_DIR/backend/VERSION"; echo "${BC_VERSION:-未知}" )"
    log "部署包版本：$BC_INSTALLED_VERSION（渠道：$BC_CHANNEL）"
else
    warn "未找到版本文件 backend/VERSION，无法显示具体版本"
fi

if [ "$MODE" = "http" ]; then
    cp "$TARGET_DIR/deploy/nginx.http.conf" "$TARGET_DIR/deploy/nginx.conf"
else
    cp "$TARGET_DIR/deploy/nginx.https.conf" "$TARGET_DIR/deploy/nginx.conf"
fi

# ---------- 6) .env + APP_URL + 端口 ----------
log "6/7 生成 APP_KEY"
if [ ! -f "$TARGET_DIR/deploy/.env" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    printf 'APP_KEY=%s\n' "$APP_KEY" > "$TARGET_DIR/deploy/.env"
    chmod 600 "$TARGET_DIR/deploy/.env"
fi
# 端口自定义（部分 LXC / OpenVZ VPS 不给容器绑 <1024 特权端口，需要走高端口）
if [ "$BC_HTTP_PORT" != "80" ]; then
    sed -i "s|\"80:80\"|\"$BC_HTTP_PORT:80\"|" "$TARGET_DIR/deploy/docker-compose.yml"
    URL_PORT_HTTP=":$BC_HTTP_PORT"
else
    URL_PORT_HTTP=""
fi
if [ "$BC_HTTPS_PORT" != "443" ]; then
    sed -i "s|\"443:443\"|\"$BC_HTTPS_PORT:443\"|" "$TARGET_DIR/deploy/docker-compose.yml"
    URL_PORT_HTTPS=":$BC_HTTPS_PORT"
else
    URL_PORT_HTTPS=""
fi
URL_PORT=$([ "$MODE" = "http" ] && echo "$URL_PORT_HTTP" || echo "$URL_PORT_HTTPS")
sed -i "s|APP_URL:.*|APP_URL: \"$SCHEME://$BC_ACCESS$URL_PORT\"|" "$TARGET_DIR/deploy/docker-compose.yml"
if [ "$MODE" = "http" ]; then
    sed -i "/- \"$BC_HTTPS_PORT:443\"/d" "$TARGET_DIR/deploy/docker-compose.yml"
    sed -i '/- "443:443"/d' "$TARGET_DIR/deploy/docker-compose.yml"
fi

# ---------- 7) 启动 ----------
log "7/7 构建镜像 + 启动容器（首次约 2-3 分钟）"
cd "$TARGET_DIR/deploy"
if ! docker compose build app >/tmp/bc-build.log 2>&1; then
    tail -20 /tmp/bc-build.log
    die "镜像构建失败，完整日志：/tmp/bc-build.log"
fi
docker compose up -d || die "容器启动失败。排查：cd $TARGET_DIR/deploy && docker compose logs --tail=50"
sleep 12

# ---------- LE 续期钩子 ----------
if [ "$MODE" = "https-le" ]; then
    log "安装 certbot + 续期钩子"
    if ! command -v certbot >/dev/null 2>&1; then
        apt-get update -qq 2>&1 | tail -1 || true
        apt-get install -y certbot >/dev/null 2>&1 || true
    fi
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > "/etc/letsencrypt/renewal-hooks/deploy/bc-$BC_ACCESS.sh" <<HOOK
#!/bin/sh
set -e
if echo "\$RENEWED_LINEAGE" | grep -q "$BC_ACCESS"; then
    cp -L /etc/letsencrypt/live/$BC_ACCESS/fullchain.pem $TARGET_DIR/deploy/ssl/bundle.crt
    cp -L /etc/letsencrypt/live/$BC_ACCESS/privkey.pem   $TARGET_DIR/deploy/ssl/private.key
    chmod 600 $TARGET_DIR/deploy/ssl/private.key
    docker exec bc-web nginx -s reload
fi
HOOK
    chmod +x "/etc/letsencrypt/renewal-hooks/deploy/bc-$BC_ACCESS.sh"
fi

# ---------- 冒烟 ----------
sleep 3
SMOKE_OK=0
if [ "$MODE" = "http" ]; then
    curl -fs --max-time 8 "http://127.0.0.1/" -o /dev/null -w '%{http_code}\n' 2>/dev/null | grep -qE '^(200|301|302)$' && SMOKE_OK=1
else
    curl -fsk --max-time 8 "https://127.0.0.1/" -o /dev/null -w '%{http_code}\n' 2>/dev/null | grep -qE '^(200|301|302)$' && SMOKE_OK=1
fi

echo ""
printf "${C_GRN}══════════════════════════════════════════════════${C_RST}\n"
printf "${C_GRN}  ✓ Backup Center 部署完成${C_RST}\n"
printf "${C_GRN}══════════════════════════════════════════════════${C_RST}\n"
echo "  访问地址：$SCHEME://$BC_ACCESS$URL_PORT/"
[ "$MODE" = "https-selfsigned" ] && echo "  ⚠  自签名证书：浏览器首次访问会警告，点'高级 → 继续访问'"
echo "  首次访问：输入你要用的用户名 + 密码创建管理员"
echo ""
echo "  升级方式：重新跑本脚本（BC_FORCE=1）；要装最新未发布功能加 BC_CHANNEL=main"
echo ""
echo "  容器状态：docker ps | grep bc-"
echo "  应用日志：docker logs -f bc-app"
echo "  数据目录：$TARGET_DIR/"
[ "$MODE" = "https-le" ] && echo "  LE 续期：已配置 /etc/letsencrypt/renewal-hooks/"
echo "  冒烟测试：$([ "$SMOKE_OK" = "1" ] && echo '✓ 通过' || echo '⚠ 未通过，请查 docker logs bc-web')"
echo ""
