<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Garment;
use App\Models\GarmentMark;
use App\Models\Order;
use App\Models\PocketItem;
use App\Models\User;
use App\Support\EventLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntakeService
{
    public function createOrder(array $attrs, User $clerk): Order
    {
        $order = Order::create([
            'order_no' => $this->generateOrderNo(),
            'customer_name' => $attrs['customer_name'],
            'customer_phone' => $attrs['customer_phone'] ?? null,
            'status' => Order::STATUS_INTAKE,
        ]);

        EventLog::record('order.created', $order->id, null, null, $clerk->id, [
            'order_no' => $order->order_no,
            'customer_name' => $order->customer_name,
        ]);

        return $order;
    }

    private function generateOrderNo(): string
    {
        // 形如 DRY261003-AB12
        return 'DRY'.now()->format('ymd').'-'.strtoupper(Str::random(5));
    }

    /**
     * 衣物入库（单件编号）。标签全局唯一，重复编号直接拦截。
     */
    public function registerGarment(Order $order, array $attrs, User $clerk): Garment
    {
        if ($order->isPaused()) {
            throw new DomainException('订单已暂停，需先处理暂停原因后再录入衣物', 'order_paused', 409);
        }
        if (! in_array($order->status, [Order::STATUS_INTAKE, Order::STATUS_AWAITING_CONFIRMATION], true)) {
            throw new DomainException('当前订单状态不能再录入衣物', 'order_not_intake', 409);
        }

        $tagNo = trim($attrs['tag_no']);
        if ($tagNo === '') {
            throw new DomainException('衣物编号不能为空', 'tag_required');
        }

        // 同一件衣物不能重复入库（唯一索引兜底 + 友好提示）
        if (Garment::where('tag_no', $tagNo)->exists()) {
            throw new DomainException("衣物编号 {$tagNo} 已存在，同一衣物不能重复入库", 'tag_not_unique', 409);
        }

        return DB::transaction(function () use ($order, $attrs, $clerk, $tagNo) {
            $garment = new Garment([
                'tag_no' => $tagNo,
                'category' => $attrs['category'] ?? null,
                'color' => $attrs['color'] ?? null,
                'brand' => $attrs['brand'] ?? null,
                'care_labels' => $attrs['care_labels'] ?? [],
                'description' => $attrs['description'] ?? null,
                'status' => Garment::STATUS_INTAKE,
            ]);
            $garment->order_id = $order->id;

            try {
                $garment->save();
            } catch (\Illuminate\Database\QueryException $e) {
                throw new DomainException("衣物编号 {$tagNo} 已存在，同一衣物不能重复入库", 'tag_not_unique', 409);
            }

            // 护理标签禁止水洗 → 自动暂停订单
            if ($garment->hasNoWashLabel()) {
                $this->pauseOrder($order, Order::PAUSE_NO_WASH_LABEL,
                    "衣物 {$garment->tag_no} 护理标签标注“不可水洗”，需与顾客确认洗护方式", $clerk);
            }

            EventLog::record('garment.registered', $order->id, $garment->id, null, $clerk->id, [
                'tag_no' => $garment->tag_no,
                'category' => $garment->category,
                'care_labels' => $garment->care_labels,
            ]);

            return $garment->fresh();
        });
    }

    public function addMark(Garment $garment, array $attrs, User $clerk): GarmentMark
    {
        $this->assertIntakeEditable($garment);

        $mark = GarmentMark::create([
            'garment_id' => $garment->id,
            'kind' => $attrs['kind'],
            'label' => $attrs['label'] ?? null,
            'x_permille' => $attrs['x_permille'] ?? 0,
            'y_permille' => $attrs['y_permille'] ?? 0,
            'description' => $attrs['description'] ?? null,
            'created_by' => $clerk->id,
        ]);

        EventLog::record('garment.mark_added', $garment->order_id, $garment->id, null, $clerk->id, [
            'kind' => $mark->kind,
            'label' => $mark->label,
        ]);

        return $mark;
    }

    /**
     * 发现口袋物品：登记并暂停订单，提示店员处理。
     */
    public function reportPocketItem(Garment $garment, array $attrs, User $clerk): PocketItem
    {
        $this->assertIntakeEditable($garment);

        return DB::transaction(function () use ($garment, $attrs, $clerk) {
            $item = PocketItem::create([
                'garment_id' => $garment->id,
                'description' => $attrs['description'],
                'note' => $attrs['note'] ?? null,
                'found_by' => $clerk->id,
                'status' => PocketItem::STATUS_FOUND,
            ]);

            $this->pauseOrder(
                $garment->order,
                Order::PAUSE_POCKET_ITEM,
                "衣物 {$garment->tag_no} 口袋发现物品：{$item->description}，需取出并与顾客确认",
                $clerk,
            );

            EventLog::record('garment.pocket_item_found', $garment->order_id, $garment->id, null, $clerk->id, [
                'item' => $item->description,
            ]);

            return $item;
        });
    }

    public function returnPocketItem(PocketItem $item, User $clerk): PocketItem
    {
        if ($item->status !== PocketItem::STATUS_FOUND) {
            throw new DomainException('该物品已登记归还', 'pocket_item_already_returned', 409);
        }

        $item->update([
            'status' => PocketItem::STATUS_RETURNED,
            'returned_by' => $clerk->id,
            'returned_at' => now(),
        ]);

        EventLog::record('garment.pocket_item_returned', $item->garment->order_id, $item->garment_id, null, $clerk->id, [
            'item' => $item->description,
        ]);

        // 订单内所有口袋物品均已归还才允许恢复
        $order = $item->garment->order;
        if ($order->isPaused() && $order->pause_reason === Order::PAUSE_POCKET_ITEM) {
            $stillOpen = PocketItem::whereIn('garment_id', $order->garments()->pluck('id'))
                ->where('status', PocketItem::STATUS_FOUND)
                ->exists();
            if (! $stillOpen) {
                $this->resumeOrder($order, '口袋物品均已处理', $clerk);
            }
        }

        return $item;
    }

    /**
     * 顾客拒签：暂停订单等待店员处理。
     */
    public function refuseSignature(Order $order, ?string $note, User $clerk): Order
    {
        if ($order->status !== Order::STATUS_AWAITING_CONFIRMATION) {
            throw new DomainException('只有待顾客确认的订单可以登记拒签', 'not_awaiting_confirmation', 409);
        }

        $this->pauseOrder($order, Order::PAUSE_REFUSED_SIGNATURE, $note ?? '顾客拒绝确认预检结果', $clerk);

        return $order;
    }

    public function pauseOrder(Order $order, string $reason, string $note, User $actor): Order
    {
        if (! array_key_exists($reason, Order::PAUSE_REASONS)) {
            throw new DomainException('未知暂停原因', 'unknown_pause_reason');
        }

        DB::transaction(function () use ($order, $reason, $note, $actor) {
            if ($order->status === Order::STATUS_PAUSED) {
                return; // 已暂停（保留首个暂停原因）
            }
            $order->update([
                'status' => Order::STATUS_PAUSED,
                'status_before_pause' => $order->status,
                'pause_reason' => $reason,
                'pause_note' => $note,
                'paused_by' => $actor->id,
                'paused_at' => now(),
            ]);

            EventLog::record('order.paused', $order->id, null, null, $actor->id, [
                'reason' => $reason,
                'note' => $note,
            ]);
        });

        return $order->fresh();
    }

    /**
     * 处理完暂停事项后恢复订单。
     */
    public function resumeOrder(Order $order, string $resolutionNote, User $actor): Order
    {
        if (! $order->isPaused()) {
            throw new DomainException('订单未处于暂停状态', 'order_not_paused', 409);
        }

        if ($order->pause_reason === Order::PAUSE_POCKET_ITEM) {
            $openItems = PocketItem::whereIn('garment_id', $order->garments()->pluck('id'))
                ->where('status', PocketItem::STATUS_FOUND)
                ->exists();
            if ($openItems) {
                throw new DomainException('仍有口袋物品未处理/归还，不能恢复订单', 'pocket_items_open', 409);
            }
        }

        return DB::transaction(function () use ($order, $resolutionNote, $actor) {
            $previous = $order->status_before_pause ?? Order::STATUS_INTAKE;
            $order->update([
                'status' => $previous,
                'status_before_pause' => null,
                'pause_reason' => null,
                'pause_note' => null,
                'paused_by' => null,
                'paused_at' => null,
                'resolved_at' => now(),
            ]);

            EventLog::record('order.resumed', $order->id, null, null, $actor->id, [
                'note' => $resolutionNote,
            ]);

            return $order;
        })->fresh();
    }

    /**
     * 预检完成，提交给顾客确认（至少一件衣物 + 每件至少一张预检照片）。
     */
    public function submitForConfirmation(Order $order, User $clerk): Order
    {
        if ($order->isPaused()) {
            throw new DomainException('订单暂停中，无法提交确认', 'order_paused', 409);
        }
        if ($order->status !== Order::STATUS_INTAKE) {
            throw new DomainException('当前订单状态不允许提交确认', 'bad_order_status', 409);
        }

        $garments = $order->garments()->withCount(['photos as intake_photos_count' => fn ($q) =>
            $q->where('stage', \App\Models\GarmentPhoto::STAGE_INTAKE)])->get();

        if ($garments->isEmpty()) {
            throw new DomainException('订单内还没有衣物，无法提交确认', 'no_garments');
        }
        foreach ($garments as $g) {
            if ($g->intake_photos_count === 0) {
                throw new DomainException("衣物 {$g->tag_no} 缺少预检照片", 'intake_photo_required', 422, );
            }
        }

        $order->update(['status' => Order::STATUS_AWAITING_CONFIRMATION]);
        EventLog::record('order.submitted_for_confirmation', $order->id, null, null, $clerk->id);

        return $order->fresh();
    }

    /**
     * 顾客确认：冻结全部预检证据（照片不得替换/追加/删除），订单进入洗护。
     */
    public function confirmByCustomer(Order $order, string $signatureName, User $clerk): Order
    {
        if ($order->status !== Order::STATUS_AWAITING_CONFIRMATION) {
            throw new DomainException('只有待确认订单可以确认', 'not_awaiting_confirmation', 409);
        }
        $signatureName = trim($signatureName);
        if ($signatureName === '') {
            throw new DomainException('请填写顾客签名（姓名）以完成确认', 'signature_required');
        }

        return DB::transaction(function () use ($order, $signatureName, $clerk) {
            $now = now();
            $order->update([
                'status' => Order::STATUS_IN_WASH,
                'signature_name' => $signatureName,
                'confirmed_at' => $now,
                'confirmed_by' => $clerk->id,
            ]);

            $garmentIds = $order->garments()->pluck('id');
            Garment::whereIn('id', $garmentIds)->update([
                'status' => Garment::STATUS_CONFIRMED,
                'intake_locked_at' => $now,
            ]);

            EventLog::record('order.confirmed', $order->id, null, null, $clerk->id, [
                'signature_name' => $signatureName,
                'garment_count' => count($garmentIds),
            ]);

            return $order;
        })->fresh();
    }

    private function assertIntakeEditable(Garment $garment): void
    {
        if ($garment->order->isPaused()) {
            throw new DomainException('订单暂停中，请先处理暂停事项', 'order_paused', 409);
        }
        if ($garment->isIntakeLocked()) {
            throw new DomainException('预检已确认锁定，证据不可修改', 'intake_evidence_locked', 409);
        }
        if ($garment->status !== Garment::STATUS_INTAKE) {
            throw new DomainException('该衣物已离开收件环节，不能修改预检信息', 'garment_not_intake', 409);
        }
    }
}
