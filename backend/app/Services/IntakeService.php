<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Garment;
use App\Models\Inspection;
use App\Models\Mark;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntakeService
{
    public const CARE_LABELS = [
        'no_water_wash',    // 禁止水洗
        'no_dry_clean',
        'no_bleach',
        'hand_wash_only',
        'low_temp_iron',
        'shade_dry',
    ];

    public function createOrder(array $data, User $cashier): Order
    {
        $data = validator($data, [
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
        ])->validate();

        return DB::transaction(function () use ($data, $cashier) {
            $order = Order::create([
                'code' => $this->generateOrderCode(),
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? '',
                'status' => Order::STATUS_INTAKE,
                'created_by' => $cashier->id,
            ]);
            AuditLog::record('order.created', $cashier->id, $order->id, null, ['code' => $order->code]);
            return $order;
        });
    }

    public function addGarment(Order $order, array $data): Garment
    {
        if ($order->status !== Order::STATUS_INTAKE) {
            throw new DomainRuleException(
                'order_not_intake',
                '订单已离开收件阶段，不能再添加衣物',
                409,
            );
        }

        $data = validator($data, [
            'tag' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'category' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'care_labels' => ['array'],
            'care_labels.*' => [Rule::in(self::CARE_LABELS)],
            'pocket_items_found' => ['boolean'],
            'pocket_items_note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        // 标签号全店唯一：每件衣物单独编号，重复编号被数据库+应用双层拦截
        if (Garment::where('tag', $data['tag'])->exists()) {
            throw new DomainRuleException(
                'duplicate_tag',
                "衣物标签号 {$data['tag']} 已存在，标签必须全店唯一",
                409,
            );
        }

        return DB::transaction(function () use ($order, $data) {
            $garment = $order->garments()->create([
                'tag' => $data['tag'],
                'category' => $data['category'] ?? '',
                'color' => $data['color'] ?? '',
                'description' => $data['description'] ?? '',
                'care_labels' => $data['care_labels'] ?? [],
                'pocket_items_found' => $data['pocket_items_found'] ?? false,
                'pocket_items_note' => $data['pocket_items_note'] ?? null,
                'status' => Garment::STATUS_RECORDED,
            ]);
            AuditLog::record('garment.created', null, $order->id, $garment->id, ['tag' => $garment->tag]);
            return $garment;
        });
    }

    /**
     * 暂停订单：顾客拒签 / 护理标签禁止水洗 / 发现口袋物品。
     * 三种情形都必须先暂停并提示店员处理，禁止直接进入洗护。
     */
    public function pauseOrder(Order $order, string $reason, ?string $note, User $actor): Order
    {
        $validReasons = [
            Order::PAUSE_CUSTOMER_REFUSED,
            Order::PAUSE_NO_WATER_WASH,
            Order::PAUSE_POCKET_ITEMS,
        ];
        if (!in_array($reason, $validReasons, true)) {
            throw new DomainRuleException('invalid_pause_reason', '未知的暂停原因');
        }
        if ($order->status === Order::STATUS_PAUSED) {
            throw new DomainRuleException('already_paused', '订单已处于暂停状态', 409);
        }
        if (!in_array($order->status, [Order::STATUS_INTAKE], true)) {
            throw new DomainRuleException('order_not_pausable', '订单已进入洗护流程，不能暂停', 409);
        }

        return DB::transaction(function () use ($order, $reason, $note, $actor) {
            $order->update([
                'status' => Order::STATUS_PAUSED,
                'pause_reason' => $reason,
                'pause_note' => $note,
                'paused_at' => now(),
            ]);
            AuditLog::record('order.paused', $actor->id, $order->id, null, [
                'reason' => $reason,
                'note' => $note,
            ]);
            return $order->fresh();
        });
    }

    public function resumeOrder(Order $order, string $note, User $actor): Order
    {
        if ($order->status !== Order::STATUS_PAUSED) {
            throw new DomainRuleException('not_paused', '只有暂停中的订单可以恢复', 409);
        }

        return DB::transaction(function () use ($order, $note, $actor) {
            $order->update([
                'status' => Order::STATUS_INTAKE,
                'resume_note' => $note,
                'pause_reason' => null,
                'pause_note' => null,
                'paused_at' => null,
            ]);
            AuditLog::record('order.resumed', $actor->id, $order->id, null, ['note' => $note]);
            return $order->fresh();
        });
    }

    /**
     * 顾客确认。确认前会强制检查暂停情形；确认后锁定全部原始预检证据。
     */
    public function confirmByCustomer(Order $order, User $actor): Order
    {
        if ($order->status === Order::STATUS_PAUSED) {
            throw new DomainRuleException(
                'order_paused',
                '订单处于暂停状态（' . $this->pauseReasonText($order->pause_reason) . '），店员处理完毕并恢复后才能让顾客确认',
                409,
            );
        }
        if ($order->status !== Order::STATUS_INTAKE) {
            throw new DomainRuleException('order_not_intake', '订单不在收件阶段，无需确认', 409);
        }

        $garments = $order->garments()->with('intakePhotos')->get();
        if ($garments->isEmpty()) {
            throw new DomainRuleException('no_garments', '订单没有任何衣物，不能确认');
        }
        foreach ($garments as $garment) {
            if ($garment->intakePhotos->isEmpty()) {
                throw new DomainRuleException(
                    'missing_intake_photo',
                    "衣物 {$garment->tag} 还没有原始预检照片，不能确认",
                );
            }
            // 确认前最后一道自动排查：禁止水洗标签 / 口袋物品必须先走暂停流程
            if (in_array('no_water_wash', $garment->care_labels ?? [], true)) {
                throw new DomainRuleException(
                    'care_label_blocks_confirm',
                    "衣物 {$garment->tag} 的护理标签禁止水洗，请先暂停订单处理",
                    409,
                );
            }
            if ($garment->pocket_items_found) {
                throw new DomainRuleException(
                    'pocket_items_block_confirm',
                    "衣物 {$garment->tag} 发现口袋物品，请先暂停订单处理",
                    409,
                );
            }
        }

        return DB::transaction(function () use ($order, $actor, $garments) {
            $now = now();
            foreach ($garments as $garment) {
                $garment->update([
                    'status' => Garment::STATUS_CONFIRMED,
                    'intake_locked_at' => $now,
                ]);
            }
            $order->update(['status' => Order::STATUS_IN_CARE, 'confirmed_at' => $now]);
            AuditLog::record('order.confirmed_by_customer', $actor->id, $order->id, null, [
                'garment_count' => $garments->count(),
            ]);
            return $order->fresh();
        });
    }

    private function generateOrderCode(): string
    {
        return 'OD' . now()->format('YmdHis') . str_pad(random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    private function pauseReasonText(?string $reason): string
    {
        return match ($reason) {
            Order::PAUSE_CUSTOMER_REFUSED => '顾客拒签',
            Order::PAUSE_NO_WATER_WASH => '护理标签禁止水洗',
            Order::PAUSE_POCKET_ITEMS => '发现口袋物品',
            default => '待处理事项',
        };
    }
}
