#!/usr/bin/env bash
# 确保 PHP 可用：优先使用系统 php；没有则下载静态构建到 ~/.local/bin（无需 root）。
set -euo pipefail

PHP_BIN=""
if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [ -x "$HOME/.local/bin/php" ]; then
  PHP_BIN="$HOME/.local/bin/php"
else
  echo ">> 未发现 PHP，下载静态 PHP 8.3（用户目录，免 root）…"
  mkdir -p "$HOME/.local/bin" /tmp/php-install
  curl -fsSL "https://dl.static-php.dev/static-php-cli/common/php-8.3.32-cli-linux-x86_64.tar.gz" -o /tmp/php-install/php.tar.gz
  tar xzf /tmp/php-install/php.tar.gz -C /tmp/php-install
  mv /tmp/php-install/php "$HOME/.local/bin/php"
  chmod +x "$HOME/.local/bin/php"
  PHP_BIN="$HOME/.local/bin/php"
fi

# Composer
if ! command -v composer >/dev/null 2>&1 && [ ! -x "$HOME/.local/bin/composer" ]; then
  echo ">> 安装 Composer …"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-installer.php
  "$PHP_BIN" /tmp/composer-installer.php --install-dir="$HOME/.local/bin" --filename=composer.phar
  cat > "$HOME/.local/bin/composer" <<'WRAPPER'
#!/bin/bash
exec "$HOME/.local/bin/php" "$HOME/.local/bin/composer.phar" "$@"
WRAPPER
  chmod +x "$HOME/.local/bin/composer"
fi

echo "$PHP_BIN"
