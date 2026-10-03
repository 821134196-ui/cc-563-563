#!/usr/bin/env bash
# =============================================================================
# 洁衣通 · 干洗门店衣物预检与赔偿系统 —— 一条命令启动全部服务
#
#   ./start.sh           启动（自动完成所有准备：PHP/依赖/迁移/播种/前端构建）
#   ./start.sh --reset   清空数据库重新迁移并播种演示数据
#
# 启动后访问 http://127.0.0.1:8000
# 单端口同时提供：React 页面、/api/* 接口、证据照片、模拟赔付网关回执接口
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
LOCAL_DIR="$HOME/.local/opt"
PHP_BIN="$LOCAL_DIR/php"
COMPOSER="$LOCAL_DIR/composer.phar"
HOST="${APP_HOST:-127.0.0.1}"
PORT="${APP_PORT:-8000}"

log() { printf '\033[1;34m[start]\033[0m %s\n' "$*"; }
ok()  { printf '\033[1;32m[ ok ]\033[0m %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 1. PHP（优先系统 php，缺失时自动下载静态 PHP 8.4，含 pdo_sqlite/gd/mbstring）
# ---------------------------------------------------------------------------
if command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -q pdo_sqlite; then
    PHP="$(command -v php)"
    log "使用系统 PHP：$($PHP -v | head -1)"
else
    if [ ! -x "$PHP_BIN" ]; then
        log "未找到系统 PHP，下载静态 PHP 8.4 到 $PHP_BIN …"
        mkdir -p "$LOCAL_DIR"
        curl -fsSL --retry 3 -o /tmp/static-php.tar.gz \
            "https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz"
        tar xzf /tmp/static-php.tar.gz -C "$LOCAL_DIR"
        rm -f /tmp/static-php.tar.gz
    fi
    PHP="$PHP_BIN"
    ok "静态 PHP 就绪：$($PHP -v | head -1)"
fi

# ---------------------------------------------------------------------------
# 2. Composer
# ---------------------------------------------------------------------------
if command -v composer >/dev/null 2>&1; then
    COMPOSER_BIN="$(command -v composer)"
else
    if [ ! -f "$COMPOSER" ]; then
        log "下载 Composer …"
        curl -fsSL --retry 3 -o "$COMPOSER" https://getcomposer.org/download/latest-stable/composer.phar
    fi
    COMPOSER_BIN="$PHP $COMPOSER"
fi

# ---------------------------------------------------------------------------
# 3. 后端依赖
# ---------------------------------------------------------------------------
cd "$BACKEND"
if [ ! -d vendor ]; then
    log "安装 PHP 依赖（composer install）…"
    $COMPOSER_BIN install --no-interaction --optimize-autoloader
fi

# ---------------------------------------------------------------------------
# 4. 环境配置（SQLite）
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    log "生成 .env（SQLite）…"
    cp .env.example .env
    sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    grep -q '^DB_CONNECTION=sqlite' .env || printf '\nDB_CONNECTION=sqlite\n' >> .env
    $PHP artisan key:generate --force
fi

touch database/database.sqlite

# ---------------------------------------------------------------------------
# 5. 数据库
# ---------------------------------------------------------------------------
if [ "${1:-}" = "--reset" ]; then
    log "重置数据库（migrate:fresh --seed）…"
    $PHP artisan migrate:fresh --seed --force
else
    log "执行数据库迁移 …"
    $PHP artisan migrate --force
    log "按需播种演示数据（已有数据则跳过）…"
    $PHP artisan app:seed-demo
fi

# ---------------------------------------------------------------------------
# 6. 前端依赖 + 构建（React 产物由 Laravel 在同一端口提供）
# ---------------------------------------------------------------------------
if command -v npm >/dev/null 2>&1; then
    if [ ! -d node_modules ]; then
        log "安装前端依赖（npm install）…"
        npm install --no-audit --no-fund
    fi
    if [ ! -f public/build/manifest.json ] || [ "${1:-}" = "--reset" ]; then
        log "构建 React 前端 …"
        npm run build
    fi
else
    echo "[start] 未找到 npm，无法构建前端；请先安装 Node.js 20+。" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 7. 一条命令启动全部服务（单端口）
# ---------------------------------------------------------------------------
ok "准备完成。系统启动中 …"
cat <<EOF

============================================================
  洁衣通 · 衣物预检与赔偿系统
  访问地址:  http://$HOST:$PORT
  健康检查:  http://$HOST:$PORT/up

  演示账号（密码均为 password）:
    收件店员   clerk@dryclean.local
    洗护人员   washer@dryclean.local
    门店经理   manager@dryclean.local
============================================================

EOF

exec $PHP artisan serve --host="$HOST" --port="$PORT"
