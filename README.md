# 干洗门店衣物污渍预检与赔偿系统

收件时让店员和顾客确认衣物原状，洗护完成后用**同一份记录**核对；出现破损争议时可查到完整证据与责任处理过程。

- **后端**：PHP 8.3 + Laravel 12，Eloquent 读写 **SQLite**
- **前端**：React 19 + Vite，单页应用
- **照片**：本地存储（内容寻址、SHA-256 校验、确认后只读）
- **赔付**：模拟回执接口（幂等，绝不二次付款）
- **启动**：**一条命令** 同时构建前端、迁移数据库并在单端口提供页面与 API

---

## 一条命令启动

```bash
./start.sh            # 默认 http://127.0.0.1:8000
PORT=8123 ./start.sh  # 指定端口
./start.sh fresh      # 清空重建数据库并填充演示账号
./start.sh dev        # 开发模式（Laravel 8765 + Vite 5173 热更新）
```

脚本会在本机没有 PHP/Composer 时，自动把**静态 PHP 8.3 与 Composer 安装到用户目录**（`~/.local/bin`，无需 root、不污染系统）。

演示账号（密码统一 `password`）：

| 角色 | 账号 | 能做什么 |
|---|---|---|
| 收件店员 王收件 | `cashier@store.local` | 建单、逐件预检拍照标注、暂停/恢复、顾客确认 |
| 洗护人员 李洗护 | `washer@store.local` | 开始/完成洗护、上传完工照、登记核对差异、发起赔付申请 |
| 店长 赵店长 | `manager@store.local` | **审批赔付**、执行模拟付款、查看全部争议 |

---

## 业务流程

```
新建订单（收件台同时显示“订单数 / 实际衣物件数”）
   │
   ├─ 逐件：生成全店唯一标签号 → 拍原始照片 → 标注已有污渍/破损 → 勾护理标签
   │      ├─ 顾客拒签 / 标签禁止水洗 / 发现口袋物品 → 订单【暂停】，店员处理+留痕后恢复
   │      └─ 顾客与店员逐件确认 → 原始证据【锁定】，进入洗护
   │
   ├─ 洗护人员开始洗护 → 登记洗护完成
   │
   ├─ 完工核对：原始照片 ⟷ 当前照片【并排展示】，对照预检标注
   │      ├─ 一致 → 衣物完结
   │      └─ 差异（必填新污渍/破损位置描述）→ 自动开启争议
   │
   └─ 争议处理：发起复检（补复检照片/结论）→ 协商记录 → 发起赔付申请
              → 店长审批（洗护人员被隔离）→ 模拟付款拿回执
              → 全程审计时间线可查
```

## 核心规则的实现位置

| 规则 | 实现 |
|---|---|
| 每件衣物单独编号、标签全店唯一 | `garments.tag` 数据库唯一索引 + `IntakeService::addGarment()` 双层校验 |
| 同件衣物不能重复入库 | `photos` 上 `WHERE stage='intake'` 的 **SHA-256 部分唯一索引**；相同照片用于第二件衣物直接 409 |
| 原始预检照片不得事后替换 | 顾客确认后 `intake_locked_at` 置位：上传接口 409、无更新/删除路由、证据文件 `chmod 0444` 只读、预检标注同步冻结 |
| 顾客拒签/禁水洗/口袋物品 | 三类暂停原因，`confirmByCustomer()` 强制前置检查；暂停中禁止确认 |
| 收件台显示订单数与实际件数 | `GET /api/orders` 的 `meta.stats`（`order_count` / `garment_count` 等） |
| 前后照片并排核对 | `GarmentPage` 的 compare 布局；原始侧带 🔒 锁定标识 |
| 洗护人员不能审批自己责任范围赔付 | 路由层 `role:manager` 拒绝非店长；服务层再校验审批人不得是该衣物 `washer_id`（双重职责分离） |
| 重复赔付申请被拦截 | `compensation_requests.dispute_id` 唯一 + 服务层 409 `duplicate_compensation_request` |
| 重复赔付/回执不二次付款 | 固定幂等键 `payout:{id}`、行级锁 `lockForUpdate`、`payout_receipts` 双唯一约束、已付款重放返回原回执；网关回执号由幂等键确定性生成 |
| 责任处理过程可查 | 所有关键动作写 `order_events` 审计表，订单页展示时间线 |

## 交付验收（三项必验 + 附加规则）

```bash
./scripts/verify.sh
```

12 个自动化用例全部通过，其中直接对应交付要求：

- `garment tag must be unique across orders` — **衣物标签唯一**
- `intake evidence is locked after customer confirmation` — **预检证据不可覆盖**
- `same photo cannot check in two garments` — 同件衣物不可重复入库
- `duplicate payout returns same receipt and pays once` — **重复赔付被拦截，只出一次款**
- `washer cannot approve compensation` / `manager who washed cannot approve` — 职责分离
- `duplicate_compensation_request_is_rejected`、`cannot pay before approval`、`foreign idempotency key is rejected`、`blocking conditions pause order…`

## 目录结构

```
start.sh                     一条命令启动（含环境自检/前端构建/迁移/起服）
scripts/
  ensure-php.sh              免 root 准备静态 PHP + Composer
  build-frontend.sh          构建 React 并发布到 backend/public
  verify.sh                  交付验收测试入口
backend/                     Laravel 12
  app/Models/                Order/Garment/Photo/Mark/Inspection/Dispute/Compensation…
  app/Services/              IntakeService / InspectionService / CompensationService / PhotoStorageService
  app/Services/Payout/       MockPayoutGateway（模拟赔付回执）
  app/Http/Controllers/      API 控制器
  database/migrations/       表结构（含唯一/部分唯一索引）
  tests/Feature/             验收自动化测试
frontend/                    React（收件台 / 订单预检 / 洗护看板 / 衣物并排核对 / 争议赔偿）
```

## 常用手动检查

```bash
cd backend && php artisan test          # 跑全部测试
php artisan migrate:fresh --seed        # 重置数据库
```

照片为本地文件，存于 `backend/storage/app/private/photos/{garmentId}/{stage}/`，文件名即 SHA-256；前端通过 `/api/photos/{id}/content?token=…` 读取（响应头回传 `X-Evidence-SHA256`）。
