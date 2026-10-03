#!/usr/bin/env bash
# ============================================================
# 交付验收：一键验证三项核心业务规则
#   1. 衣物标签全店唯一（同件衣物不能重复入库）
#   2. 原始预检照片在顾客确认后不可覆盖/追加
#   3. 重复赔付请求 / 回执不会造成二次付款
#      （另含：洗护人员不能审批自己责任范围内的赔付）
# ============================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="$HOME/.local/bin:$PATH"
PHP_BIN="$(command -v php || echo "$HOME/.local/bin/php")"

echo "########################################################"
echo "# 干洗预检与赔偿系统 · 交付验收"
echo "########################################################"
cd "$ROOT/backend"

"$PHP_BIN" artisan test \
  --filter='IntakeVerificationTest|CompensationVerificationTest'

echo
echo "########################################################"
echo "# 验收结论对照"
echo "#  ✓ garment tag must be unique …           → 标签全店唯一"
echo "#  ✓ same photo cannot check in two garments → 同件衣物不可重复入库"
echo "#  ✓ intake evidence is locked …            → 预检证据不可覆盖"
echo "#  ✓ blocking conditions pause order …      → 拒签/禁水洗/口袋物品先暂停"
echo "#  ✓ washer cannot approve compensation     → 职责分离（洗护不能审批）"
echo "#  ✓ duplicate compensation request …       → 重复赔付申请被拦截"
echo "#  ✓ duplicate payout … pays once           → 重复回执不二次付款"
echo "########################################################"
