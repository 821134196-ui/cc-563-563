<?php

namespace App\Http\Resources;

use App\Models\Compensation;
use App\Models\Dispute;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\PhotoStorageService;

class Presenter
{
    public static function order(Order $order): array
    {
        if (! $order->relationLoaded('garments')) {
            $order->load('garments.marks', 'garments.photos', 'pausedByUser');
        }

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'status' => $order->status,
            'status_label' => self::orderStatusLabel($order->status),
            'is_paused' => $order->isPaused(),
            'pause' => $order->isPaused() ? [
                'reason' => $order->pause_reason,
                'reason_label' => Order::PAUSE_REASONS[$order->pause_reason] ?? $order->pause_reason,
                'note' => $order->pause_note,
                'paused_at' => $order->paused_at?->toIso8601String(),
                'paused_by' => $order->pausedByUser?->name,
            ] : null,
            'signature_name' => $order->signature_name,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'garment_count' => $order->garments->count(),
            'garments' => $order->garments->map(fn (Garment $g) => self::garment($g))->values(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    public static function orderStatusLabel(string $status): string
    {
        return match ($status) {
            Order::STATUS_INTAKE => '收件预检中',
            Order::STATUS_AWAITING_CONFIRMATION => '待顾客确认',
            Order::STATUS_PAUSED => '已暂停',
            Order::STATUS_IN_WASH => '洗护中',
            Order::STATUS_READY => '待完检',
            Order::STATUS_COMPLETED => '已完成',
            Order::STATUS_CANCELLED => '已取消',
            default => $status,
        };
    }

    public static function garment(Garment $garment): array
    {
        if (! $garment->relationLoaded('photos')) {
            $garment->load('photos', 'marks', 'washer', 'pocketItems');
        }

        return [
            'id' => $garment->id,
            'order_id' => $garment->order_id,
            'tag_no' => $garment->tag_no,
            'category' => $garment->category,
            'color' => $garment->color,
            'brand' => $garment->brand,
            'care_labels' => $garment->care_labels ?? [],
            'description' => $garment->description,
            'status' => $garment->status,
            'status_label' => self::garmentStatusLabel($garment->status),
            'intake_locked' => $garment->isIntakeLocked(),
            'intake_locked_at' => $garment->intake_locked_at?->toIso8601String(),
            'washer' => $garment->washer ? ['id' => $garment->washer->id, 'name' => $garment->washer->name] : null,
            'marks' => $garment->marks->map(fn ($m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'kind_label' => $m->kind === 'stain' ? '污渍' : '破损',
                'label' => $m->label,
                'x' => $m->x_permille,
                'y' => $m->y_permille,
                'description' => $m->description,
            ])->values(),
            'photos' => $garment->photos->map(fn (GarmentPhoto $p) => self::photo($p))->values(),
            'pocket_items' => $garment->pocketItems->map(fn ($i) => [
                'id' => $i->id,
                'description' => $i->description,
                'note' => $i->note,
                'status' => $i->status,
                'status_label' => $i->status === 'returned' ? '已归还' : '待处理',
            ])->values(),
            'open_pocket_items' => $garment->pocketItems->where('status', 'found')->count(),
            'created_at' => $garment->created_at?->toIso8601String(),
        ];
    }

    public static function garmentStatusLabel(string $status): string
    {
        return match ($status) {
            Garment::STATUS_INTAKE => '预检中',
            Garment::STATUS_CONFIRMED => '已确认待洗护',
            Garment::STATUS_WASHING => '洗护中',
            Garment::STATUS_READY_INSPECTION => '待完检',
            Garment::STATUS_INSPECTION_PASSED => '完检通过',
            Garment::STATUS_DISPUTED => '有争议',
            Garment::STATUS_RECHECKING => '复检中',
            Garment::STATUS_COMPLETED => '已交付',
            default => $status,
        };
    }

    public static function photo(GarmentPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'stage' => $photo->stage,
            'stage_label' => GarmentPhoto::STAGES[$photo->stage] ?? $photo->stage,
            'filename' => $photo->filename,
            'checksum' => $photo->checksum,
            'size_bytes' => $photo->size_bytes,
            'mime_type' => $photo->mime_type,
            'url' => '/api/photos/'.$photo->id,
            'checksum_valid' => PhotoStorageService::verifyFileChecksum($photo),
            'uploaded_at' => $photo->created_at?->toIso8601String(),
        ];
    }

    public static function dispute(Dispute $dispute): array
    {
        $dispute->loadMissing(['reviews.reviewer', 'compensation.receipt', 'garment', 'opener']);

        return [
            'id' => $dispute->id,
            'order_id' => $dispute->order_id,
            'order_no' => $dispute->order->order_no,
            'garment' => [
                'id' => $dispute->garment->id,
                'tag_no' => $dispute->garment->tag_no,
                'washer' => $dispute->garment->washer ? ['id' => $dispute->garment->washer->id, 'name' => $dispute->garment->washer->name] : null,
            ],
            'type' => $dispute->type,
            'description' => $dispute->description,
            'requested_amount' => $dispute->requested_amount,
            'status' => $dispute->status,
            'opened_by' => $dispute->opener?->name,
            'closed_at' => $dispute->closed_at?->toIso8601String(),
            'resolution_note' => $dispute->resolution_note,
            'reviews' => $dispute->reviews->map(fn ($r) => [
                'id' => $r->id,
                'reviewer' => $r->reviewer?->name,
                'result' => $r->result,
                'result_label' => match ($r->result) {
                    'confirmed' => '确认存在差异',
                    'not_found' => '未见差异',
                    'inconclusive' => '存疑',
                    default => $r->result,
                },
                'finding' => $r->finding,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'compensation' => $dispute->compensation ? self::compensation($dispute->compensation) : null,
            'created_at' => $dispute->created_at?->toIso8601String(),
        ];
    }

    public static function compensation(Compensation $comp): array
    {
        $comp->loadMissing('receipt');

        return [
            'id' => $comp->id,
            'amount' => $comp->amount,
            'currency' => $comp->currency,
            'status' => $comp->status,
            'status_label' => match ($comp->status) {
                Compensation::STATUS_REQUESTED => '待审批',
                Compensation::STATUS_APPROVED => '已批准待付',
                Compensation::STATUS_REJECTED => '已驳回',
                Compensation::STATUS_PAID => '已赔付',
                Compensation::STATUS_FAILED => '支付失败',
                default => $comp->status,
            },
            'approved_by_name' => User::find($comp->approved_by)?->name,
            'approval_note' => $comp->approval_note,
            'approved_at' => $comp->approved_at?->toIso8601String(),
            'paid_at' => $comp->paid_at?->toIso8601String(),
            'receipt' => $comp->receipt ? [
                'id' => $comp->receipt->id,
                'receipt_no' => $comp->receipt->receipt_no,
                'idempotency_key' => $comp->receipt->idempotency_key,
                'gateway_status' => $comp->receipt->gateway_status,
            ] : null,
        ];
    }

    public static function event(OrderEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'actor' => $event->actor?->name,
            'garment_id' => $event->garment_id,
            'garment_tag' => $event->garment?->tag_no,
            'payload' => $event->payload,
            'at' => $event->created_at?->toIso8601String(),
        ];
    }
}
