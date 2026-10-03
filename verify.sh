#!/usr/bin/env bash
# =============================================================================
# 交付验证脚本：
#   1) PHPUnit 特性测试（标签唯一 / 预检证据不可覆盖 / 重复赔付拦截 / 自审拦截…）
#   2) 在独立验证库上启动真实 HTTP 服务，端到端冒烟核心保证
#
#   ./verify.sh
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
LOCAL_DIR="$HOME/.local/opt"

if command -v php >/dev/null 2>&1 && php -m 2>/dev/null | grep -q pdo_sqlite; then
    PHP="$(command -v php)"
else
    PHP="$LOCAL_DIR/php"
fi
if command -v composer >/dev/null 2>&1; then COMPOSER="$(command -v composer)"; else COMPOSER="$PHP $LOCAL_DIR/composer.phar"; fi

PASS=0; FAIL=0
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; PASS=$((PASS+1)); }
bad()  { printf '  \033[1;31m✗\033[0m %s\n' "$*"; FAIL=$((FAIL+1)); }
step() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }

cd "$BACKEND"

# ---------------------------------------------------------------------------
# 第一部分：PHPUnit 特性测试
# ---------------------------------------------------------------------------
step "1/2 运行后端特性测试（PHPUnit）"
if $PHP vendor/bin/phpunit --testdox 2>&1 | tee /tmp/verify-phpunit.log; then
    ok "PHPUnit 全部通过"
else
    bad "PHPUnit 存在失败"
    tail -30 /tmp/verify-phpunit.log
    exit 1
fi

# ---------------------------------------------------------------------------
# 第二部分：真实 HTTP 端到端冒烟
# ---------------------------------------------------------------------------
step "2/2 真实 HTTP 服务端到端验证"

VERIFY_DB="$BACKEND/database/verify.sqlite"
VERIFY_PORT=8123
SERVER_PHP="$BACKEND/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"

# 注意：不能用 `artisan serve`——它派生 php -S 子进程时不透传 DB_DATABASE，
# 会导致验证流量误写主库。直接启动 PHP 内置服务器 + Laravel 路由脚本。
rm -f "$VERIFY_DB"
touch "$VERIFY_DB"

export DB_DATABASE="$VERIFY_DB"
$PHP artisan migrate:fresh --seed --force >/dev/null 2>&1

# server.php 以 getcwd() 作为 public 根，因此必须在 backend/public 内启动
(
    cd "$BACKEND/public"
    env DB_DATABASE="$VERIFY_DB" PHP_CLI_SERVER_WORKERS=1 \
        $PHP -S "127.0.0.1:$VERIFY_PORT" "$SERVER_PHP"
) >/tmp/verify-serve.log 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    ps -eo pid,args | grep "[s]erver.php" | grep "$VERIFY_PORT" \
        | awk '{print $1}' | while read -r pid; do kill "$pid" 2>/dev/null || true; done
    rm -f "$VERIFY_DB"
}
trap cleanup EXIT

# 等待服务就绪
for i in $(seq 1 30); do
    curl -sf -o /dev/null "http://127.0.0.1:$VERIFY_PORT/up" && break
    sleep 0.5
done

BASE="http://127.0.0.1:$VERIFY_PORT"

# --- 小工具：HTTP 状态码 / JSON 字段提取 ---
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
json() { node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const v=JSON.parse(s);console.log(eval("v."+process.argv[1]))}catch(e){console.log("")}})' "$1"; }

# 生成一张测试 JPEG
JPG=/tmp/verify-photo.jpg
$PHP -r '$im=imagecreatetruecolor(200,150);imagefill($im,0,0,imagecolorallocate($im,210,210,210));imagestring($im,5,10,10,"VERIFY",imagecolorallocate($im,0,0,0));imagejpeg($im,"'"$JPG"'");'

# 登录
CLERK_TOKEN=$(curl -s -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"email":"clerk@dryclean.local","password":"password"}' | json 'token')
WASHER_TOKEN=$(curl -s -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"email":"washer@dryclean.local","password":"password"}' | json 'token')
MANAGER_TOKEN=$(curl -s -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"email":"manager@dryclean.local","password":"password"}' | json 'token')
[ -n "$CLERK_TOKEN" ] && ok "三种角色登录成功" || bad "登录失败"

# 未带令牌 → 401
[ "$(code "$BASE/api/dashboard/intake-stats")" = "401" ] && ok "未登录访问被拒绝（401）" || bad "鉴权失效"

# 收件台同时返回订单数和衣物件数
STATS=$(curl -s "$BASE/api/dashboard/intake-stats" -H "Authorization: Bearer $CLERK_TOKEN")
echo "$STATS" | grep -q '"orders_total"' && echo "$STATS" | grep -q '"garments_total"' \
    && ok "收件台同时展示订单数与实际衣物件数" || bad "收件台统计字段缺失"

# --- 保证一：衣物标签唯一 ---
ORD=$(curl -s -X POST "$BASE/api/orders" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' \
    -d '{"customer_name":"验证客"}')
OID=$(echo "$ORD" | json 'order.id || id')
curl -s -X POST "$BASE/api/orders/$OID/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-UNIQ","care_labels":[]}' >/dev/null
C1=$(code -X POST "$BASE/api/orders/$OID/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-UNIQ","care_labels":[]}')
[ "$C1" = "409" ] && ok "保证① 衣物标签唯一：重复编号入库被拦截（409）" || bad "标签重复未拦截（$C1）"

# 禁止水洗标签自动暂停
ORD2=$(curl -s -X POST "$BASE/api/orders" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' -d '{"customer_name":"标签验证"}')
OID2=$(echo "$ORD2" | json 'order.id || id')
curl -s -X POST "$BASE/api/orders/$OID2/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-NOWASH","care_labels":["no_wash"]}' >/dev/null
PAUSED=$(curl -s "$BASE/api/orders/$OID2" -H "Authorization: Bearer $CLERK_TOKEN" | json 'order.is_paused')
[ "$PAUSED" = "true" ] && ok "护理标签禁止水洗 → 订单自动暂停" || bad "禁水洗未触发暂停"

# 口袋物品 → 暂停
ORD3=$(curl -s -X POST "$BASE/api/orders" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' -d '{"customer_name":"口袋验证"}')
OID3=$(echo "$ORD3" | json 'order.id || id')
G3=$(curl -s -X POST "$BASE/api/orders/$OID3/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-PKT","care_labels":[]}')
GID3=$(echo "$G3" | json 'garment.id')
curl -s -X POST "$BASE/api/garments/$GID3/photos" -H "Authorization: Bearer $CLERK_TOKEN" \
    -F "photo=@$JPG;type=image/jpeg" -F stage=intake >/dev/null
curl -s -X POST "$BASE/api/garments/$GID3/pocket-items" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"description":"现金50元"}' >/dev/null
[ "$(curl -s "$BASE/api/orders/$OID3" -H "Authorization: Bearer $CLERK_TOKEN" | json 'order.is_paused')" = "true" ] \
    && ok "发现口袋物品 → 订单暂停并提示店员" || bad "口袋物品未触发暂停"

# --- 保证二：预检证据不可覆盖（完整走一遍确认流程）---
ORD4=$(curl -s -X POST "$BASE/api/orders" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' -d '{"customer_name":"证据验证"}')
OID4=$(echo "$ORD4" | json 'order.id || id')
G4=$(curl -s -X POST "$BASE/api/orders/$OID4/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-LOCK","care_labels":[]}')
GID4=$(echo "$G4" | json 'garment.id')
UP1=$(curl -s -X POST "$BASE/api/garments/$GID4/photos" -H "Authorization: Bearer $CLERK_TOKEN" \
    -F "photo=@$JPG;type=image/jpeg" -F stage=intake)
PHOTO_URL=$(echo "$UP1" | json 'photo.url')
echo "$UP1" | grep -q '"checksum_valid":true' && ok "预检照片落盘且 sha256 校验一致" || bad "照片校验异常"

curl -s -X POST "$BASE/api/orders/$OID4/submit-confirmation" -H "Authorization: Bearer $CLERK_TOKEN" >/dev/null
curl -s -X POST "$BASE/api/orders/$OID4/confirm" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"signature_name":"证据验证"}' >/dev/null

C2=$(code -X POST "$BASE/api/garments/$GID4/photos" -H "Authorization: Bearer $CLERK_TOKEN" \
    -F "photo=@$JPG;type=image/jpeg" -F stage=intake)
[ "$C2" = "409" ] && ok "保证② 顾客确认后预检照片不可追加/替换（409）" || bad "锁定后仍可上传预检照片（$C2）"

# 证据文件只读访问 + 校验和响应头
HDR=$(curl -s -D - -o /dev/null "$BASE$PHOTO_URL?token=$CLERK_TOKEN")
echo "$HDR" | grep -qi 'X-Evidence-Checksum: [a-f0-9]\{64\}' \
    && ok "证据文件可只读调阅并返回 sha256 指纹" || bad "证据文件调阅异常"

# --- 保证三：重复赔付拦截（全流程：洗护→完检→争议→复检→赔付→重复付款→重复回执）---
ORD5=$(curl -s -X POST "$BASE/api/orders" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' -d '{"customer_name":"赔付验证"}')
OID5=$(echo "$ORD5" | json 'order.id || id')
G5=$(curl -s -X POST "$BASE/api/orders/$OID5/garments" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"tag_no":"G-VERIFY-PAY","care_labels":[]}')
GID5=$(echo "$G5" | json 'garment.id')
curl -s -X POST "$BASE/api/garments/$GID5/photos" -H "Authorization: Bearer $CLERK_TOKEN" -F "photo=@$JPG;type=image/jpeg" -F stage=intake >/dev/null
curl -s -X POST "$BASE/api/orders/$OID5/submit-confirmation" -H "Authorization: Bearer $CLERK_TOKEN" >/dev/null
curl -s -X POST "$BASE/api/orders/$OID5/confirm" -H "Authorization: Bearer $CLERK_TOKEN" -H 'Content-Type: application/json' -d '{"signature_name":"赔付验证"}' >/dev/null
curl -s -X POST "$BASE/api/garments/$GID5/wash/start" -H "Authorization: Bearer $WASHER_TOKEN" >/dev/null
curl -s -X POST "$BASE/api/garments/$GID5/wash/finish" -H "Authorization: Bearer $WASHER_TOKEN" >/dev/null
curl -s -X POST "$BASE/api/garments/$GID5/photos" -H "Authorization: Bearer $CLERK_TOKEN" -F "photo=@$JPG;type=image/jpeg" -F stage=completion >/dev/null
DID=$(curl -s -X POST "$BASE/api/garments/$GID5/disputes" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"type":"new_damage","description":"新增勾破","requested_amount":6000}' | json 'dispute.id')
curl -s -X POST "$BASE/api/disputes/$DID/recheck" -H "Authorization: Bearer $MANAGER_TOKEN" >/dev/null
curl -s -X POST "$BASE/api/disputes/$DID/reviews" -H "Authorization: Bearer $MANAGER_TOKEN" \
    -H 'Content-Type: application/json' -d '{"result":"confirmed","finding":"确认差异"}' >/dev/null

curl -s -X POST "$BASE/api/disputes/$DID/compensation" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"amount":3000}' >/dev/null
C3=$(code -X POST "$BASE/api/disputes/$DID/compensation" -H "Authorization: Bearer $CLERK_TOKEN" \
    -H 'Content-Type: application/json' -d '{"amount":9999}')
[ "$C3" = "409" ] && ok "保证③ 同一争议重复赔付请求被拦截（409）" || bad "重复赔付未拦截（$C3）"

CID=$(curl -s "$BASE/api/disputes/$DID" -H "Authorization: Bearer $CLERK_TOKEN" | json 'dispute.compensation.id')
# 洗护责任人本人（ washer 角色）不能审批
C4=$(code -X POST "$BASE/api/compensations/$CID/approve" -H "Authorization: Bearer $WASHER_TOKEN" \
    -H 'Content-Type: application/json' -d '{"approved":true}')
[ "$C4" = "403" ] && ok "洗护责任人无权审批赔付（角色隔离 403）" || bad "洗护人员竟可审批（$C4）"

# 经理（非责任人）批准并支付
curl -s -X POST "$BASE/api/compensations/$CID/approve" -H "Authorization: Bearer $MANAGER_TOKEN" \
    -H 'Content-Type: application/json' -d '{"approved":true,"note":"同意"}' >/dev/null
PAID=$(curl -s -X POST "$BASE/api/compensations/$CID/pay" -H "Authorization: Bearer $CLERK_TOKEN")
echo "$PAID" | grep -q '"status":"paid"' && ok "赔付支付成功并生成模拟网关回执" || bad "赔付支付失败"

# 重复付款 → 409
C5=$(code -X POST "$BASE/api/compensations/$CID/pay" -H "Authorization: Bearer $CLERK_TOKEN")
[ "$C5" = "409" ] && ok "重复付款请求被拦截（409，无二次付款）" || bad "重复付款未拦截（$C5）"

# 重复回执 → 409 duplicate_callback
RCPT_NO=$(echo "$PAID" | json 'compensation.receipt.receipt_no')
IDEMP=$(echo "$PAID" | json 'compensation.receipt.idempotency_key')
SIGN=$($PHP -r 'echo hash_hmac("sha256",http_build_query(["idempotency_key"=>$argv[1],"receipt_no"=>$argv[2],"status"=>"success"]),"mock-gateway-shared-secret");' "$IDEMP" "$RCPT_NO")
CB_BODY="{\"receipt_no\":\"$RCPT_NO\",\"idempotency_key\":\"$IDEMP\",\"status\":\"success\",\"signature\":\"$SIGN\"}"
# 首次回执正常受理
C6A=$(code -X POST "$BASE/api/mock-gateway/callback" -H 'Content-Type: application/json' -d "$CB_BODY")
# 相同回执再发一次 → 409，绝不二次入账
C6=$(code -X POST "$BASE/api/mock-gateway/callback" -H 'Content-Type: application/json' -d "$CB_BODY")
[ "$C6A" = "200" ] && [ "$C6" = "409" ] && ok "模拟网关首次回执受理、重复回执被识别并忽略（无二次入账）" || bad "重复回执处理异常（首:$C6A 重:$C6）"

# 伪造签名回执 → 401
C7=$(code -X POST "$BASE/api/mock-gateway/callback" -H 'Content-Type: application/json' \
    -d "{\"receipt_no\":\"$RCPT_NO\",\"idempotency_key\":\"$IDEMP\",\"status\":\"success\",\"signature\":\"$(printf '0%.0s' {1..64})\"}")
[ "$C7" = "401" ] && ok "伪造签名的赔付回执被拒绝（401）" || bad "回执签名校验失效（$C7）"

# ---------------------------------------------------------------------------
printf '\n==================================================\n'
if [ "$FAIL" -eq 0 ]; then
    printf '\033[1;32m交付验证全部通过：%d 项检查 ✓\033[0m\n' "$PASS"
    printf '  · 衣物标签全局唯一，不能重复入库\n'
    printf '  · 预检证据顾客确认后不可覆盖/追加/删除\n'
    printf '  · 重复赔付、重复付款、重复回执均被拦截\n'
    printf '  · 洗护人员不能审批自己责任范围内的赔付\n'
    printf '==================================================\n'
    exit 0
else
    printf '\033[1;31m验证失败：%d 通过 / %d 失败\033[0m\n' "$PASS" "$FAIL"
    printf '==================================================\n'
    exit 1
fi
