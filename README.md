# 洁衣通 · 干洗门店衣物预检与赔偿系统

收件时逐件编号、拍照、标注已有污渍/破损与护理标签，顾客确认后进入洗护；
洗护完成后用**同一份预检记录**与当前照片并排核对；出现破损争议时，可发起复检、
赔偿协商与赔付，全过程留痕、责任可追溯。

- 后端：PHP 8.4 + Laravel，Eloquent 读写 **SQLite**
- 前端：React 19 + Vite + Tailwind（与后端**同一端口**提供）
- 照片：本地文件存储（内容寻址、sha256 校验、落盘只读）
- 赔付回执：**模拟支付网关**接口（签名 + 幂等键）
- 一条本地命令启动全部服务

## 快速开始

```bash
./start.sh            # 自动准备依赖/迁移/播种/构建并启动
./start.sh --reset    # 清空数据库，重新迁移并播种演示数据
```

打开 <http://127.0.0.1:8000> （可用 `APP_PORT=9000 ./start.sh` 改端口）。

演示账号（密码均为 `password`）：

| 角色 | 邮箱 | 主要权限 |
| --- | --- | --- |
| 收件店员 王小柜 | `clerk@dryclean.local` | 建单、衣物预检、拍照标注、顾客确认、完检/交付 |
| 洗护人员 李洗护 | `washer@dryclean.local` | 认领洗护、洗护完成（**不能审批自己责任的赔付**） |
| 门店经理 赵经理 | `manager@dryclean.local` | 复检、赔付审批（责任人是自己时同样被拦截） |

## 交付验证

```bash
./verify.sh
```

包含两部分：

1. **PHPUnit 特性测试**（18 个，190 个断言，内存库）；
2. **真实 HTTP 端到端检查**（独立 SQLite 库，16 项断言）。

重点验证三条交付保证：

- **衣物标签唯一**：`garments.tag_no` 全局唯一索引 + 服务层预查，重复入库返回 `409 tag_not_unique`；
- **预检证据不可覆盖**：顾客确认后 `intake_locked_at` 锁定，预检照片不能再上传/替换/删除
  （`409 intake_evidence_locked`）；照片按 sha256 内容寻址、落盘 `0444` 只读，记录校验和与磁盘实时比对；
- **重复赔付被拦截**：一件争议最多一张赔付单（唯一索引），已赔付后重复付款返回
  `409 already_paid`，模拟网关重复回执返回 `409 duplicate_callback`，均不产生二次付款/入账。

同时覆盖：洗护责任人不能审批本人责任赔付（`403 self_approval_forbidden`）、
顾客拒签 / 禁水洗标签 / 口袋物品三种暂停触发与恢复、完检无完检照不能通过等。

## 业务流程

```
新建订单(收件台)
   │  逐件衣物：编号 → 护理标签 → 拍预检照 → 标注已有污渍/破损
   │   ├─ 护理标签“不可水洗” ──┐
   │   ├─ 发现口袋物品 ────────┼──▶ 订单暂停（店员处理后恢复）
   │   └─ 顾客拒签 ────────────┘
   ▼
提交顾客确认 → 顾客签名 → 预检证据永久锁定
   ▼
洗护认领(记录责任人) → 洗护完成
   ▼
完检：原始预检照片 ⇄ 当前完检照片 并排核对
   ├─ 一致 → 完检通过 → 交付顾客 → 订单完成
   └─ 差异 → 发起争议 → 复检 → 赔偿协商 → 经理审批 → 模拟网关赔付(回执/幂等)
```

## 关键安全设计

| 风险 | 防护 |
| --- | --- |
| 同一衣物重复入库 | `tag_no` 数据库唯一索引 + 服务层检查 |
| 原始预检照片被替换/删除 | 内容寻址（sha256 文件名）、`0444` 只读、确认后接口锁定、无删除/更新入口 |
| 照片被篡改 | 每条证据存 sha256，展示前与磁盘实时比对，响应头回传指纹 |
| 洗护人员自审自赔 | 审批人 == 衣物 `washer_id` 时服务层拒绝（经理也不例外） |
| 重复赔付请求 | `compensations.dispute_id` 唯一索引 + 行锁；驳回后只能在原单上修改重提 |
| 重复付款 | 赔付单行锁 + 状态机，确定性幂等键 `comp-{id}`，网关侧 `idempotency_key`/`payment_ref` 双唯一 |
| 重复回执二次入账 | 网关台账计回调次数，重复回执只计数不入账；回执需 HMAC 签名 |
| 责任无法追溯 | 全量 `order_events` 审计事件流（含被拦截的自审/重复回执尝试） |

## 主要 API（前缀 `/api`，Bearer 令牌登录）

```
POST   /auth/login
GET    /dashboard/intake-stats        收件台：订单数 + 实际衣物件数
GET    /dashboard/paused              暂停订单及待处理事项

POST   /orders                        新建订单
POST   /orders/{id}/submit-confirmation
POST   /orders/{id}/confirm           顾客签名确认（锁定预检）
POST   /orders/{id}/refuse            顾客拒签（暂停）
POST   /orders/{id}/pause | /resume

POST   /orders/{id}/garments          衣物入库（编号唯一）
POST   /garments/{id}/marks           标注污渍/破损
POST   /garments/{id}/pocket-items    登记口袋物品（自动暂停）
POST   /garments/{id}/photos          上传照片 stage=intake|completion|dispute
GET    /photos/{id}                   只读调阅证据（回 sha256 头）

POST   /garments/{id}/wash/start | /wash/finish
GET    /garments/{id}/comparison      预检/完检照片并排数据
POST   /garments/{id}/inspection/pass
POST   /garments/{id}/handover

POST   /garments/{id}/disputes        发现差异发起争议
POST   /disputes/{id}/recheck | /reviews | /compensation | /close
POST   /compensations/{id}/approve    仅经理；责任人本人禁止
POST   /compensations/{id}/pay        模拟网关赔付
POST   /mock-gateway/callback         模拟网关回执（HMAC 签名 + 幂等）
```

## 目录结构（主要部分）

```
start.sh                    一条命令启动（自举 PHP/Composer/依赖/迁移/构建）
verify.sh                   交付验证（PHPUnit + 真实 HTTP 端到端）
backend/
  app/
    Models/                 Order/Garment/GarmentPhoto/Dispute/Compensation …
    Services/
      IntakeService.php         收件、暂停/恢复、顾客确认与证据锁定
      WashService.php           洗护流转、完检、交付
      DisputeService.php        争议复检、赔付审批/支付（自审拦截+防重）
      PhotoStorageService.php   证据照片内容寻址存储
      MockPaymentGateway.php    模拟支付网关（幂等 + 回执计数）
    Http/Controllers/       API 控制器
    Http/Middleware/        令牌鉴权、角色鉴权
    Http/Resources/Presenter.php
  database/migrations/      表结构（含 tag_no、dispute_id、幂等键等唯一索引）
  database/seeders/         演示数据（GD 生成本地占位照片）
  resources/js/             React 前端（收件台/订单/洗护/完检/争议赔付）
  tests/Feature/            交付验证特性测试
```

## 说明

- 无外网/无 root 环境下，`start.sh` 会自动下载静态 PHP 8.4（内含 pdo_sqlite、gd、
  mbstring、openssl）与 Composer 到 `~/.local/opt`；系统已装 PHP 8.3+ 则优先使用。
- SQLite 数据库文件 `backend/database/database.sqlite` 与证据照片目录
  `backend/storage/app/private/evidence/` 均为运行时产物，不纳入 git。
