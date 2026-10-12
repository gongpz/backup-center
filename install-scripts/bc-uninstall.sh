#!/usr/bin/env bash
# Backup Center · 一键卸载脚本
#
# 使用：
#   curl -fsSL https://raw.githubusercontent.com/imask/backup-center/main/install-scripts/bc-uninstall.sh | sudo bash
#
# 环境变量：
#   BC_KEEP_DATA=1       保留数据库 + storage 卷（默认清除）
#   BC_REMOVE_LE=1       同时删掉 Let's Encrypt 证书（默认保留 —— 证书是资产）
#   BC_REMOVE_SWAP=1     同时删掉 install.sh 创建的 /swapfile（默认保留）
#   BC_FORCE=1           跳过所有交互确认（自动化用）
#   BC_TARGET_DIR=/opt/bc  自定义安装路径（默认 /opt/bc）

set -euo pipefail

TARGET_DIR="${BC_TARGET_DIR:-/opt/bc}"

C_CYAN='\033[1;36m'
C_YEL='\033[1;33m'
C_RED='\033[1;31m'
C_GRN='\033[1;32m'
C_DIM='\033[0;90m'
C_RST='\033[0m'
log()  { printf "${C_CYAN}[bc-uninstall]${C_RST} %s\n" "$*"; }
warn() { printf "${C_YEL}[bc-uninstall]${C_RST} %s\n" "$*" >&2; }
die()  { printf "${C_RED}[bc-uninstall]${C_RST} %s\n" "$*" >&2; exit 1; }
ok()   { printf "${C_GRN}[bc-uninstall]${C_RST} %s\n" "$*"; }

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
        local __val; __val="$(eval echo "\$$__var")"
        [ -z "$__val" ] && [ -n "$__default" ] && eval "$__var=\"\$__default\""
    else
        die "无 /dev/tty，非交互环境请传 BC_FORCE=1"
    fi
}

[ "$(id -u)" -eq 0 ] || die "请用 root 或 sudo 执行"

echo ""
printf "${C_RED}══════════════════════════════════════════════════${C_RST}\n"
printf "${C_RED}  Backup Center · 一键卸载${C_RST}\n"
printf "${C_RED}══════════════════════════════════════════════════${C_RST}\n"
echo ""

# ---------- 检测当前状态 ----------
log "检测已安装组件"
HAS_DIR=0
HAS_CONTAINERS=0
HAS_VOLUMES=0
HAS_IMAGE=0
HAS_NETWORK=0
HAS_SYSTEMD=0
HAS_LE=0
HAS_SWAP=0

[ -d "$TARGET_DIR" ] && HAS_DIR=1
docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qE '^bc-(app|web|demo-app|demo-web)$' && HAS_CONTAINERS=1
docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qE '^deploy_bc-' && HAS_VOLUMES=1
docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -q '^backup-center-app:latest$' && HAS_IMAGE=1
docker network ls --format '{{.Name}}' 2>/dev/null | grep -qE '^deploy_bc(demo)?net$' && HAS_NETWORK=1
[ -f /etc/systemd/system/bc-update.service ] && HAS_SYSTEMD=1

# 尝试从 nginx.conf 里提取域名（推 LE 证书路径）
DOMAIN=""
if [ -f "$TARGET_DIR/deploy/nginx.conf" ]; then
    DOMAIN=$(grep -oP 'server_name \K[^;]+' "$TARGET_DIR/deploy/nginx.conf" | head -1 | tr -d ' ')
fi
if [ -n "$DOMAIN" ] && [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then
    HAS_LE=1
fi

[ -f /swapfile ] && grep -q '^/swapfile' /etc/fstab 2>/dev/null && HAS_SWAP=1

echo ""
echo "  ${C_DIM}检测结果：${C_RST}"
[ "$HAS_DIR" = "1" ]        && echo "    ✓ 安装目录：$TARGET_DIR"
[ "$HAS_CONTAINERS" = "1" ] && echo "    ✓ Docker 容器：$(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^bc-(app|web|demo-app|demo-web)$' | tr '\n' ' ')"
[ "$HAS_IMAGE" = "1" ]      && echo "    ✓ Docker 镜像：backup-center-app:latest"
[ "$HAS_VOLUMES" = "1" ]    && echo "    ✓ Docker 卷（数据库+storage）：$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '^deploy_bc-' | tr '\n' ' ')"
[ "$HAS_NETWORK" = "1" ]    && echo "    ✓ Docker 网络：$(docker network ls --format '{{.Name}}' 2>/dev/null | grep -E '^deploy_bc' | tr '\n' ' ')"
[ "$HAS_SYSTEMD" = "1" ]    && echo "    ✓ systemd 单元：bc-update.service + bc-update.timer"
[ "$HAS_LE" = "1" ]         && echo "    ✓ Let's Encrypt 证书（域名 $DOMAIN）"
[ "$HAS_SWAP" = "1" ]       && echo "    ✓ /swapfile（可能是 install.sh 创建的）"

if [ "$HAS_DIR" = "0" ] && [ "$HAS_CONTAINERS" = "0" ] && [ "$HAS_VOLUMES" = "0" ]; then
    ok "看起来 Backup Center 没装在这台机器上（或已卸载）"
    exit 0
fi

# ---------- 卸载选项 ----------
KEEP_DATA="${BC_KEEP_DATA:-}"
REMOVE_LE="${BC_REMOVE_LE:-}"
REMOVE_SWAP="${BC_REMOVE_SWAP:-}"

if [ -z "${BC_FORCE:-}" ]; then
    echo ""
    echo "${C_YEL}⚠ 卸载将删除以上所有 Backup Center 相关组件。${C_RST}"
    echo ""

    # 数据库 + storage 是否保留
    if [ "$HAS_VOLUMES" = "1" ] && [ -z "$KEEP_DATA" ]; then
        echo "  ${C_RED}数据库 + storage 会一起清掉（含所有备份记录、账号、存储配置）${C_RST}"
        ans=""
        _read_tty ans "保留数据库？(y/N，选 y 后想恢复只需重跑一键部署)" "N"
        case "$ans" in Y|y|yes) KEEP_DATA=1;; *) KEEP_DATA=0;; esac
    fi

    # LE 证书
    if [ "$HAS_LE" = "1" ] && [ -z "$REMOVE_LE" ]; then
        ans=""
        _read_tty ans "同时删除 Let's Encrypt 证书 $DOMAIN? (y/N，默认保留)" "N"
        case "$ans" in Y|y|yes) REMOVE_LE=1;; *) REMOVE_LE=0;; esac
    fi

    # swap
    if [ "$HAS_SWAP" = "1" ] && [ -z "$REMOVE_SWAP" ]; then
        ans=""
        _read_tty ans "同时删除 /swapfile? (y/N，如果被别的应用用着请选 N)" "N"
        case "$ans" in Y|y|yes) REMOVE_SWAP=1;; *) REMOVE_SWAP=0;; esac
    fi

    echo ""
    echo "  即将执行："
    echo "    ✗ 停止 + 删除容器（bc-app / bc-web / bc-demo-* 如果存在）"
    echo "    ✗ 删除 Docker 网络 + 镜像"
    [ "$KEEP_DATA" = "1" ] && echo "    → 保留 Docker 卷（数据库 + storage）" || echo "    ✗ 删除 Docker 卷（数据库 + storage）"
    [ "$HAS_SYSTEMD" = "1" ] && echo "    ✗ 停用 + 删除 systemd 单元（bc-update.timer + .service）"
    echo "    ✗ 删除安装目录 $TARGET_DIR"
    [ "$REMOVE_LE" = "1" ]  && echo "    ✗ 删除 LE 证书 $DOMAIN + 续期钩子"
    [ "$REMOVE_SWAP" = "1" ] && echo "    ✗ 删除 /swapfile + 移除 fstab 挂载"
    echo ""

    ans=""
    _read_tty ans "确认卸载？(y/N)" "N"
    case "$ans" in Y|y|yes) ;; *) die "已取消";; esac
fi

echo ""
log "开始卸载..."

# ---------- 1) 停容器 + 移除 docker compose 栈 ----------
if [ "$HAS_CONTAINERS" = "1" ] || [ "$HAS_NETWORK" = "1" ]; then
    log "1) 停 + 删 Docker 栈"
    if [ -f "$TARGET_DIR/deploy/docker-compose.yml" ]; then
        cd "$TARGET_DIR/deploy"
        if [ "${KEEP_DATA:-0}" = "1" ]; then
            docker compose down 2>&1 | tail -3 || true    # 保 volumes
        else
            docker compose down -v 2>&1 | tail -3 || true  # 连 volumes 一起
        fi
    else
        # 没 compose 文件也要清（可能只是残留）
        for c in bc-app bc-web bc-demo-app bc-demo-web komari; do
            docker stop "$c" 2>/dev/null || true
            docker rm -f "$c" 2>/dev/null || true
        done
    fi
    ok "  Docker 栈已停"
fi

# ---------- 2) 删 volumes（防止 compose down 没删干净）----------
if [ "${KEEP_DATA:-0}" != "1" ]; then
    log "2) 清理 Docker 卷"
    for v in $(docker volume ls --format '{{.Name}}' 2>/dev/null | grep -E '^deploy_bc-' || true); do
        docker volume rm -f "$v" 2>/dev/null && ok "  ✗ $v"
    done
else
    log "2) 保留 Docker 卷（BC_KEEP_DATA=1 / 用户选择）"
fi

# ---------- 3) 删镜像 ----------
if [ "$HAS_IMAGE" = "1" ]; then
    log "3) 删镜像 backup-center-app:latest"
    docker rmi backup-center-app:latest 2>/dev/null || warn "  镜像删除失败（可能被别的容器引用）"
fi

# ---------- 4) 删网络（如果 compose down 没清）----------
for n in deploy_bcnet deploy_bcdemonet; do
    docker network rm "$n" 2>/dev/null && ok "  ✗ 网络 $n"
done

# ---------- 5) systemd 单元 ----------
if [ "$HAS_SYSTEMD" = "1" ]; then
    log "4) 停用 + 删 systemd 单元"
    systemctl disable --now bc-update.timer 2>/dev/null || true
    systemctl disable --now bc-update.service 2>/dev/null || true
    rm -f /etc/systemd/system/bc-update.timer /etc/systemd/system/bc-update.service
    rm -f /etc/systemd/system/timers.target.wants/bc-update.timer
    systemctl daemon-reload
    ok "  ✗ bc-update.timer + .service"
fi

# ---------- 6) LE 证书（可选）----------
if [ "$HAS_LE" = "1" ] && [ "${REMOVE_LE:-0}" = "1" ]; then
    log "5) 删 LE 证书 $DOMAIN"
    if command -v certbot >/dev/null 2>&1; then
        certbot delete --cert-name "$DOMAIN" --non-interactive 2>&1 | tail -3 || true
    else
        rm -rf "/etc/letsencrypt/live/$DOMAIN" "/etc/letsencrypt/archive/$DOMAIN" "/etc/letsencrypt/renewal/$DOMAIN.conf"
    fi
    rm -f "/etc/letsencrypt/renewal-hooks/deploy/bc-$DOMAIN.sh"
    ok "  ✗ LE 证书 + 续期钩子"
fi

# ---------- 7) 安装目录 ----------
if [ "$HAS_DIR" = "1" ]; then
    log "6) 删安装目录 $TARGET_DIR"
    rm -rf "$TARGET_DIR"
    ok "  ✗ $TARGET_DIR"
fi

# ---------- 8) swap（可选）----------
if [ "$HAS_SWAP" = "1" ] && [ "${REMOVE_SWAP:-0}" = "1" ]; then
    log "7) 删 /swapfile"
    swapoff /swapfile 2>/dev/null || true
    sed -i '\|^/swapfile |d' /etc/fstab
    rm -f /swapfile
    ok "  ✗ /swapfile"
fi

echo ""
printf "${C_GRN}══════════════════════════════════════════════════${C_RST}\n"
printf "${C_GRN}  ✓ Backup Center 卸载完成${C_RST}\n"
printf "${C_GRN}══════════════════════════════════════════════════${C_RST}\n"
echo ""
[ "${KEEP_DATA:-0}" = "1" ] && echo "  数据卷已保留：$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '^deploy_bc-' | tr '\n' ' ')"
[ "${REMOVE_LE:-0}" = "1" ]  || [ "$HAS_LE" = "1" ] && echo "  LE 证书保留在 /etc/letsencrypt/live/$DOMAIN/"
echo "  想重装：curl -fsSL https://raw.githubusercontent.com/imask/backup-center/main/install-scripts/bc-install.sh | sudo bash"
echo ""
echo "  Docker 本身没卸载（可能你别的东西还在用）。要清 Docker："
echo "    apt purge -y docker-ce docker-ce-cli containerd.io  # Debian/Ubuntu"
echo "    rm -rf /var/lib/docker /etc/docker"
echo ""
