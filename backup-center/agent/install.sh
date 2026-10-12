#!/bin/sh
# Backup Center Agent 安装脚本
# 用法：
#   curl -fsSL https://<center>/agent/install.sh | BC_URL="..." BC_TOKEN="..." sh
set -eu

# ---------- 检查参数 ----------
: "${BC_URL:?环境变量 BC_URL 必填 (例如 https://bak.example.com)}"
: "${BC_TOKEN:?环境变量 BC_TOKEN 必填 (Agent Token, 形如 bca_...)}"

if [ "$(id -u)" != "0" ]; then
    echo "请使用 root 或 sudo 执行" >&2
    exit 1
fi

# ---------- 检测架构 ----------
UNAME_M=$(uname -m)
case "$UNAME_M" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) echo "不支持的架构: $UNAME_M" >&2; exit 1 ;;
esac

# ---------- 检查依赖 ----------
if ! command -v curl >/dev/null 2>&1; then
    echo "缺少 curl。请先安装 curl。" >&2
    exit 1
fi

# ---------- 装 rclone（Agent v0.3+ 需要）----------
if ! command -v rclone >/dev/null 2>&1; then
    echo "[install] 未检测到 rclone，正在安装..."
    curl -fsSL https://rclone.org/install.sh | bash >/dev/null 2>&1 || {
        # 官方脚本失败时用 apt/yum 兜底
        if command -v apt-get >/dev/null 2>&1; then
            apt-get install -y -qq rclone >/dev/null 2>&1
        elif command -v yum >/dev/null 2>&1; then
            yum install -y -q rclone >/dev/null 2>&1
        fi
    }
    if ! command -v rclone >/dev/null 2>&1; then
        echo "[install] rclone 安装失败，请手动装后重跑" >&2
        exit 1
    fi
fi
echo "[install] rclone 版本: $(rclone version 2>/dev/null | head -1)"

# ---------- 目录 / 用户 ----------
INSTALL_DIR="/opt/backup-agent"
CONFIG_DIR="/etc/backup-agent"
LOG_DIR="/var/log/backup-agent"
UNIT_FILE="/etc/systemd/system/backup-agent.service"

mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$LOG_DIR"

# ---------- 下载二进制 ----------
# BC_MIRROR 优先（国内加速）；失败自动回退 Center 源
if [ -n "${BC_MIRROR:-}" ]; then
    BINARY_URL="${BC_MIRROR%/}/backup-agent-linux-${ARCH}"
    echo "[install] 下载 Agent (mirror): $BINARY_URL"
    if ! curl -fsSL --max-time 1800 "$BINARY_URL" -o "$INSTALL_DIR/backup-agent.tmp"; then
        echo "[install] mirror 下载失败，回退到 Center 源..."
        BINARY_URL="${BC_URL%/}/agent/backup-agent-linux-${ARCH}"
        echo "[install] 下载 Agent: $BINARY_URL"
        curl -fsSL --max-time 1800 "$BINARY_URL" -o "$INSTALL_DIR/backup-agent.tmp"
    fi
else
    BINARY_URL="${BC_URL%/}/agent/backup-agent-linux-${ARCH}"
    echo "[install] 下载 Agent: $BINARY_URL"
    curl -fsSL --max-time 1800 "$BINARY_URL" -o "$INSTALL_DIR/backup-agent.tmp"
fi
chmod +x "$INSTALL_DIR/backup-agent.tmp"

# 简单验证：能返回 version
if ! "$INSTALL_DIR/backup-agent.tmp" version >/dev/null 2>&1; then
    echo "[install] 下载的二进制无法运行，中止" >&2
    rm -f "$INSTALL_DIR/backup-agent.tmp"
    exit 1
fi
mv "$INSTALL_DIR/backup-agent.tmp" "$INSTALL_DIR/backup-agent"
echo "[install] 二进制版本: $($INSTALL_DIR/backup-agent version)"

# ---------- 写配置文件 ----------
# BC_MIRROR 可选：Agent 二进制 CDN 镜像 URL 前缀。设置后 update 时优先从这里下载
MIRROR_LINE=""
if [ -n "${BC_MIRROR:-}" ]; then
    MIRROR_LINE=",
  \"mirror_url\": \"${BC_MIRROR%/}\""
fi
cat > "$CONFIG_DIR/config.json" <<EOF
{
  "center_url": "${BC_URL%/}",
  "agent_token": "${BC_TOKEN}",
  "heartbeat_interval": 60${MIRROR_LINE}
}
EOF
chmod 600 "$CONFIG_DIR/config.json"
echo "[install] 已写配置文件: $CONFIG_DIR/config.json"

# ---------- 连接测试 ----------
echo "[install] 测试连接 Center..."
if ! "$INSTALL_DIR/backup-agent" -config "$CONFIG_DIR/config.json" test; then
    echo "[install] 连接测试失败，请检查 URL / Token 是否正确" >&2
    exit 1
fi

# ---------- 写 systemd unit ----------
cat > "$UNIT_FILE" <<'EOF'
[Unit]
Description=Backup Center Agent
Documentation=https://github.com/gongpz/backup-center
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/opt/backup-agent/backup-agent -config /etc/backup-agent/config.json run
Restart=always
RestartSec=10
User=root
StandardOutput=journal
StandardError=journal
SyslogIdentifier=backup-agent

PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/opt/backup-agent /etc/backup-agent /var/log/backup-agent /tmp
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF
echo "[install] 已写 systemd unit: $UNIT_FILE"

# ---------- 启用 + 启动 ----------
systemctl daemon-reload
systemctl enable backup-agent.service >/dev/null 2>&1 || true
systemctl restart backup-agent.service

sleep 2

if systemctl is-active --quiet backup-agent.service; then
    echo ""
    echo "✅ Backup Agent 安装成功并已启动"
    echo ""
    echo "查看状态:  systemctl status backup-agent"
    echo "查看日志:  journalctl -u backup-agent -f"
    echo "停止:      systemctl stop backup-agent"
else
    echo ""
    echo "❌ Backup Agent 启动失败，请查看日志：" >&2
    echo "   journalctl -u backup-agent -n 50" >&2
    exit 1
fi
