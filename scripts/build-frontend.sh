#!/usr/bin/env bash
# 构建 React 前端并发布到后端 public/（API 与 SPA 共用一个端口）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo ">> [1/2] 构建前端（React + Vite）…"
cd "$ROOT/frontend"
if [ ! -d node_modules ]; then
  npm install --no-audit --no-fund
fi
npm run build

echo ">> [2/2] 发布到 backend/public …"
mkdir -p "$ROOT/backend/public"
# 清理上一次发布的 hashed 资源，保留 Laravel 自带文件
rm -rf "$ROOT/backend/public/assets"
cp -r "$ROOT/frontend/dist/." "$ROOT/backend/public/"
echo ">> 前端已发布到 backend/public（index.html + assets/）"
