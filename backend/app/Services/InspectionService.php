<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Dispute;
use App\Models\Garment;
use App\Models\Inspection;
use App\Models\Mark;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InspectionService
{
    /**
     * 洗护人员开始洗护一件衣物。
     */
    public function startWashing(Garment $garment, User $washer): Garment
    {
        if ($garment->status !== Garment::STATUS_CONFIRMED) {
            throw new DomainRuleException(
                'garment_not_confirmed',
                '衣物未经顾客确认，不能进入洗护',
                409,
            );
        }

        return DB::transaction(function () use ($garment, $washer) {
            $garment->update(['status' => Garment::STATUS_WASHING, 'washer_id' => $washer->id]);
            AuditLog::record('garment.washing_started', $washer->id, $garment->order_id, $garment->id);
            return $garment->fresh();
        });
    }

    /**
     * 洗护完成：记录洗护人员、进入待检状态。
     */
    public function finishWashing(Garment $garment, User $washer): Garment
    {
        if ($garment->status !== Garment::STATUS_WASHING) {
            throw new DomainRuleException('garment_not_washing', '衣物不在洗护中', 409);
        }
        if ($garment->washer_id !== $washer->id) {
            throw new DomainRuleException('not_assigned_washer', '只有负责该衣物的洗护人员可以登记完工', 403);
        }

        return DB::transaction(function () use ($garment) {
            $garment->update(['status' => Garment::STATUS_WASHED]);
            AuditLog::record('garment.washing_finished', $garment->washer_id, $garment->order_id, $garment->id);
            return $garment->fresh();
        });
    }

    /**
     * 完工检查：上传当前照片后给出核对结论。
     * result=discrepancy 时必须描述新发现的痕迹，并自动开启争议。
     */
    public function inspect(
        Garment $garment,
        string $result,
        string $notes,
        ?array $newMarks,
        string $kind,
        User $inspector,
    ): Inspection {
        if (!in_array($garment->status, [Garment::STATUS_WASHED, Garment::STATUS_DISPUTED], true)) {
            throw new DomainRuleException('garment_not_inspectable', '衣物当前状态不能进行检查', 409);
        }
        if (!in_array($result, [Inspection::RESULT_CONSISTENT, Inspection::RESULT_DISCREPANCY], true)) {
            throw new DomainRuleException('invalid_result', '检查结论无效');
        }
        if (!in_array($kind, [Inspection::KIND_POSTWASH, Inspection::KIND_RECHECK], true)) {
            throw new DomainRuleException('invalid_kind', '检查类型无效');
        }
        if (!$garment->photos()->where('stage', Photo::STAGE_POSTWASH)->exists()
            && !$garment->photos()->where('stage', Photo::STAGE_RECHECK)->exists()) {
            throw new DomainRuleException('missing_current_photo', '请先上传当前照片再核对');
        }

        if ($result === Inspection::RESULT_DISCREPANCY && empty($newMarks) && !$garment->disputes()->exists()) {
            throw new DomainRuleException(
                'discrepancy_requires_detail',
                '发现差异时必须记录新污渍/破损的位置与描述，才能发起复检与赔偿协商',
            );
        }

        return DB::transaction(function () use ($garment, $result, $notes, $newMarks, $kind, $inspector) {
            $inspection = Inspection::create([
                'garment_id' => $garment->id,
                'kind' => $kind,
                'inspector_id' => $inspector->id,
                'result' => $result,
                'notes' => $notes,
            ]);

            $markStage = $kind === Inspection::KIND_RECHECK ? Mark::STAGE_RECHECK : Mark::STAGE_POSTWASH;
            foreach ($newMarks ?? [] as $mark) {
                Mark::create([
                    'garment_id' => $garment->id,
                    'kind' => $mark['kind'] === Mark::KIND_DAMAGE ? Mark::KIND_DAMAGE : Mark::KIND_STAIN,
                    'location' => $mark['location'] ?? '',
                    'description' => $mark['description'] ?? '',
                    'stage' => $markStage,
                    'created_by' => $inspector->id,
                ]);
            }

            if ($result === Inspection::RESULT_DISCREPANCY) {
                $garment->update(['status' => Garment::STATUS_DISPUTED]);

                // 复检再次确认差异时复用原争议，避免一件衣物出现多张争议单
                $dispute = null;
                if ($kind === Inspection::KIND_RECHECK) {
                    $dispute = $garment->disputes()
                        ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_RECHECKING, Dispute::STATUS_NEGOTIATING])
                        ->latest('id')->first();
                }

                if ($dispute) {
                    $dispute->update([
                        'inspection_id' => $inspection->id,
                        'status' => Dispute::STATUS_NEGOTIATING,
                        'resolution' => null,
                    ]);
                    AuditLog::record('dispute.recheck_confirmed_discrepancy', $inspector->id, $garment->order_id, $garment->id, [
                        'dispute_id' => $dispute->id,
                    ]);
                } else {
                    $dispute = $garment->disputes()->create([
                        'inspection_id' => $inspection->id,
                        'opened_by' => $inspector->id,
                        'description' => $notes ?: '完工核对发现与预检记录不一致',
                        'suspected_responsibility' => Dispute::RESP_UNKNOWN,
                        'status' => Dispute::STATUS_OPEN,
                    ]);
                    AuditLog::record('dispute.opened', $inspector->id, $garment->order_id, $garment->id, [
                        'dispute_id' => $dispute->id,
                        'kind' => $kind,
                    ]);
                }
            } elseif ($kind === Inspection::KIND_RECHECK) {
                // 复检结论为一致：若没有进行中的赔付，了结争议
                $openDispute = $garment->disputes()
                    ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_RECHECKING, Dispute::STATUS_NEGOTIATING])
                    ->latest('id')->first();
                if ($openDispute) {
                    $openDispute->update(['status' => Dispute::STATUS_CLOSED, 'resolution' => '复检确认无差异，争议关闭']);
                }
                if (!$garment->disputes()->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_NEGOTIATING, Dispute::STATUS_APPROVED, Dispute::STATUS_PAID])->exists()) {
                    $garment->update(['status' => Garment::STATUS_DONE]);
                }
            } else {
                $garment->update(['status' => Garment::STATUS_DONE]);
                AuditLog::record('garment.checked_consistent', $inspector->id, $garment->order_id, $garment->id);
            }

            return $inspection;
        });
    }

    /**
     * 发起复检（对已有争议补充复核照片与结论）。
     */
    public function requestRecheck(Dispute $dispute, User $actor): Dispute
    {
        if (!in_array($dispute->status, [Dispute::STATUS_OPEN, Dispute::STATUS_NEGOTIATING], true)) {
            throw new DomainRuleException('dispute_not_recheckable', '该争议当前状态不能发起复检', 409);
        }

        return DB::transaction(function () use ($dispute, $actor) {
            $dispute->update(['status' => Dispute::STATUS_RECHECKING]);
            AuditLog::record('dispute.recheck_requested', $actor->id, null, $dispute->garment_id, [
                'dispute_id' => $dispute->id,
            ]);
            return $dispute->fresh();
        });
    }

    public function addNote(Dispute $dispute, string $body, User $author): Dispute
    {
        if ($dispute->status === Dispute::STATUS_CLOSED || $dispute->status === Dispute::STATUS_PAID) {
            throw new DomainRuleException('dispute_settled', '争议已结案，不能再添加记录', 409);
        }
        $dispute->notes()->create(['author_id' => $author->id, 'body' => $body]);
        AuditLog::record('dispute.note_added', $author->id, null, $dispute->garment_id, ['dispute_id' => $dispute->id]);
        return $dispute->fresh();
    }
}
