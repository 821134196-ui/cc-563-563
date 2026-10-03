#!/usr/bin/env bash
# ============================================================
# 干洗门店衣物污渍预检与赔偿系统 —— 一条命令启动全部服务
#
# 用法:
#   ./start.sh            生产模式：构建前端，单端口提供 API + 页面（默认 8000）
#   PORT=8123 ./start.sh  指定端口
#   ./start.sh dev        开发模式：额外启动 Vite 前端热更新（5173）
#
# 演示账号（密码统一 password）:
#   cashier@store.local 收件店员 / washer@store.local 洗护人员 / manager@store.local 店长
# ============================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT="${PORT:-8000}"
MODE="${1:-serve}"

export PATH="$HOME/.local/bin:$PATH"

echo "== [1/5] 检查运行环境（PHP / Composer）"
PHP_BIN="$(bash "$ROOT/scripts/ensure-php.sh" | tail -1)"
echo "    PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"
COMPOSER="composer"
command -v composer >/dev/null 2>&1 || COMPOSER="$PHP_BIN $HOME/.local/bin/composer.phar"

echo "== [2/5] 安装后端依赖"
cd "$ROOT/backend"
if [ ! -d vendor ]; then
  $COMPOSER install --no-interaction --prefer-dist
fi

# 首次运行（或 clone 后）：.env 被版本库忽略时自动生成
if [ ! -f "$ROOT/backend/.env" ]; then
  echo "    生成 .env（SQLite）"
  cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
  "$PHP_BIN" artisan key:generate
fi
grep -q '^DB_CONNECTION=sqlite' "$ROOT/backend/.env" || cat >> "$ROOT/backend/.env" <<'ENVEOF'

DB_CONNECTION=sqlite
ENVEOF

echo "== [3/5] 准备数据库（SQLite 迁移与演示账号）"
touch "$ROOT/backend/database/database.sqlite"
"$PHP_BIN" artisan migrate --force

if [ "$MODE" = "fresh" ]; then
  echo "    --fresh: 重建数据库并填充演示数据"
  "$PHP_BIN" artisan migrate:fresh --seed --force
else
  "$PHP_BIN" artisan db:seed --force --class=StaffSeeder >/dev/null 2>&1 || true
fi

if [ "$MODE" = "dev" ]; then
  echo "== [4/5] 开发模式：安装前端依赖（不打包，使用 Vite dev server）"
  cd "$ROOT/frontend"
  [ -d node_modules ] || npm install --no-audit --no-fund
  echo "== [5/5] 启动服务"
  echo "    后端 API:  http://127.0.0.1:8765"
  echo "    前端页面:  http://127.0.0.1:5173  （/api 自动代理到 8765）"
  # 后端
  "$PHP_BIN" "$ROOT/backend/artisan" serve --host=127.0.0.1 --port=8765 &
  API_PID=$!
  # 前端
  npm run dev -- --host=127.0.0.1 --port=5173 &
  WEB_PID=$!
  trap 'echo; echo ">> 正在停止服务…"; kill $API_PID $WEB_PID 2>/dev/null || true' INT TERM EXIT
  wait
else
  echo "== [4/5] 构建并发布前端"
  bash "$ROOT/scripts/build-frontend.sh"

  echo "== [5/5] 启动单端口服务"
  echo "    ┌──────────────────────────────────────────────┐"
  echo "    │  系统地址:  http://127.0.0.1:${PORT:-$PORT}            │"
  echo "    │  健康检查:  http://127.0.0.1:${PORT}/up             │"
  echo "    └──────────────────────────────────────────────┘"
  exec "$PHP_BIN" "$ROOT/backend/artisan" serve --host=127.0.0.1 --port="$PORT"
fi
