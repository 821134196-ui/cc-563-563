<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\Order;
use App\Models\User;
use App\Support\EventLog;
use Illuminate\Support\Facades\DB;

class WashService
{
    /**
     * 洗护人员认领并开始洗护一件衣物（记录洗护责任人）。
     */
    public function startWashing(Garment $garment, User $washer): Garment
    {
        if ($garment->order->isPaused()) {
            throw new DomainException('订单暂停中，不能开始洗护', 'order_paused', 409);
        }
        if ($garment->status !== Garment::STATUS_CONFIRMED) {
            throw new DomainException('只有已确认（预检锁定）的衣物才能开始洗护', 'garment_not_confirmed', 409);
        }

        $garment->update([
            'status' => Garment::STATUS_WASHING,
            'washer_id' => $washer->id,
            'wash_started_at' => now(),
        ]);

        EventLog::record('garment.wash_started', $garment->order_id, $garment->id, null, $washer->id);

        return $garment;
    }

    /**
     * 洗护完成，衣物进入待完检。
     */
    public function markWashed(Garment $garment, User $washer): Garment
    {
        if ($garment->status !== Garment::STATUS_WASHING || (int) $garment->washer_id !== (int) $washer->id) {
            throw new DomainException('只有该衣物的洗护责任人可以标记洗护完成', 'not_washer', 403);
        }

        $garment->update(['status' => Garment::STATUS_READY_INSPECTION]);
        EventLog::record('garment.washed', $garment->order_id, $garment->id, null, $washer->id);

        return $garment;
    }

    /**
     * 完检通过（原始照片与当前照片核对无差异后）。可由店员或经理执行。
     */
    public function passInspection(Garment $garment, User $inspector): Garment
    {
        if ($garment->order->isPaused()) {
            throw new DomainException('订单暂停中', 'order_paused', 409);
        }
        if ($garment->status !== Garment::STATUS_READY_INSPECTION) {
            throw new DomainException('只有待完检衣物可以执行完检', 'not_ready_for_inspection', 409);
        }

        $hasCompletionPhoto = GarmentPhoto::where('garment_id', $garment->id)
            ->where('stage', GarmentPhoto::STAGE_COMPLETION)
            ->exists();
        if (! $hasCompletionPhoto) {
            throw new DomainException('请先拍摄完检照片，与原始照片并排核对', 'completion_photo_required', 422);
        }

        $garment->update([
            'status' => Garment::STATUS_INSPECTION_PASSED,
            'inspected_at' => now(),
            'inspected_by' => $inspector->id,
        ]);

        EventLog::record('garment.inspection_passed', $garment->order_id, $garment->id, null, $inspector->id);

        return $garment;
    }

    /**
     * 完检通过的衣物交付顾客；全部交付后订单完成。
     */
    public function handOver(Garment $garment, User $clerk): Garment
    {
        if ($garment->status !== Garment::STATUS_INSPECTION_PASSED) {
            throw new DomainException('只有完检通过的衣物可以交付', 'not_passed', 409);
        }

        DB::transaction(function () use ($garment, $clerk) {
            $garment->update(['status' => Garment::STATUS_COMPLETED]);
            EventLog::record('garment.handed_over', $garment->order_id, $garment->id, null, $clerk->id);

            $order = Order::with('garments')->find($garment->order_id);
            $pending = $order->garments->reject(fn (Garment $g) =>
                in_array($g->status, [Garment::STATUS_COMPLETED], true)
                || $g->id === $garment->id)->count();

            $activeDisputes = $order->disputes()
                ->whereIn('status', [\App\Models\Dispute::STATUS_OPEN, \App\Models\Dispute::STATUS_RECHECKING, \App\Models\Dispute::STATUS_NEGOTIATING])
                ->exists();

            if ($pending === 0 && ! $activeDisputes) {
                $order->update(['status' => Order::STATUS_COMPLETED, 'completed_at' => now()]);
                EventLog::record('order.completed', $order->id, null, null, $clerk->id);
            }
        });

        return $garment->fresh();
    }
}
