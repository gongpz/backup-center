#!/bin/sh
# 卸载 Backup Agent
set -eu

if [ "$(id -u)" != "0" ]; then
    echo "请使用 root 或 sudo 执行" >&2
    exit 1
fi

echo "[uninstall] 停止服务"
systemctl stop backup-agent.service 2>/dev/null || true
systemctl disable backup-agent.service 2>/dev/null || true

echo "[uninstall] 删除文件"
rm -f /etc/systemd/system/backup-agent.service
rm -rf /opt/backup-agent
rm -rf /etc/backup-agent
# 日志目录保留，若要清理请手动 rm -rf /var/log/backup-agent

systemctl daemon-reload

echo "✅ Backup Agent 已卸载（日志目录 /var/log/backup-agent 保留）"
