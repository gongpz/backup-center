#!/usr/bin/env bash
# Backup Center · 构建 bc-deploy.tar.gz
#
# 产物结构（install.sh 会把它解压到 /opt/bc）：
#   backend/                        Laravel 后端源码（composer 依赖在镜像构建时装）
#   deploy/                         docker-compose + nginx 模板
#   deploy/frontend-dist/           前端构建产物
#   deploy/agent-dist/              Agent 二进制（linux amd64 / arm64）
#
# 本地用法：
#   bash scripts/build-release.sh
#   SKIP_AGENT=1 bash scripts/build-release.sh   # 跳过 Agent 编译，复用已有 deploy/agent-dist
#
# CI 用法（GitHub Actions）会直接调用本脚本，见 .github/workflows/release.yml
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BC="$ROOT/backup-center"
OUT="${OUT:-$ROOT/bc-deploy.tar.gz}"
SKIP_AGENT="${SKIP_AGENT:-0}"

C_CYAN='\033[1;36m'
C_GRN='\033[1;32m'
C_RED='\033[1;31m'
C_RST='\033[0m'
log() { printf "${C_CYAN}[build]${C_RST} %s\n" "$*"; }
ok()  { printf "${C_GRN}  ✓${C_RST} %s\n" "$*"; }
die() { printf "${C_RED}[错误]${C_RST} %s\n" "$*"; exit 1; }

command -v node >/dev/null 2>&1 || die "需要 Node.js（用于构建前端）"
command -v npm  >/dev/null 2>&1 || die "需要 npm（用于构建前端）"

# ---------- 1) 前端 ----------
log "1/3 构建前端"
if [ -d "$BC/frontend/node_modules" ]; then
    ( cd "$BC/frontend" && npm run build )
else
    ( cd "$BC/frontend" && npm ci --no-audit --no-fund && npm run build )
fi
rm -rf "$BC/deploy/frontend-dist"
cp -a "$BC/frontend/dist" "$BC/deploy/frontend-dist"
ok "frontend-dist"

# ---------- 2) Agent ----------
if [ "$SKIP_AGENT" = "1" ]; then
    log "2/3 跳过 Agent 编译（SKIP_AGENT=1）"
    [ -d "$BC/deploy/agent-dist" ] || die "SKIP_AGENT=1 但 deploy/agent-dist 不存在"
else
    command -v go >/dev/null 2>&1 || die "需要 Go 1.22+ 编译 Agent；或设 SKIP_AGENT=1 复用已有 deploy/agent-dist"
    log "2/3 交叉编译 Agent（linux amd64 / arm64）"
    mkdir -p "$BC/deploy/agent-dist"
    for arch in amd64 arm64; do
        ( cd "$BC/agent" && CGO_ENABLED=0 GOOS=linux GOARCH="$arch" \
            go build -trimpath -ldflags "-s -w" \
            -o "$BC/deploy/agent-dist/backup-agent-linux-$arch" . )
        ok "backup-agent-linux-$arch"
    done
fi

# ---------- 3) 打包 ----------
log "3/3 打包 $OUT"
rm -f "$OUT"
tar -czf "$OUT" -C "$BC" \
    --exclude='backend/.env' \
    --exclude='backend/.env.*' \
    --exclude='backend/database/database.sqlite' \
    --exclude='backend/storage/logs/*' \
    --exclude='backend/storage/framework/cache/*' \
    --exclude='backend/storage/framework/sessions/*' \
    --exclude='backend/storage/framework/views/*' \
    --exclude='backend/storage/framework/testing/*' \
    --exclude='backend/node_modules' \
    --exclude='deploy/ssl' \
    --exclude='deploy/.env' \
    --exclude='.DS_Store' \
    backend deploy

[ -s "$OUT" ] || die "产物为空"
ok "$(du -h "$OUT" | cut -f1) → $OUT"
