<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Compensation;
use App\Models\CompensationReceipt;
use App\Models\Dispute;
use App\Models\DisputeReview;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\Order;
use App\Models\User;
use App\Support\EventLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DisputeService
{
    public function __construct(private readonly MockPaymentGateway $gateway)
    {
    }

    /**
     * 完检时发现差异：发起争议（复检+赔偿协商入口）。
     */
    public function openDispute(Garment $garment, array $attrs, User $actor): Dispute
    {
        if (! in_array($garment->status, [Garment::STATUS_READY_INSPECTION, Garment::STATUS_DISPUTED, Garment::STATUS_RECHECKING], true)) {
            throw new DomainException('只有完检环节发现差异时才能发起争议', 'bad_status_for_dispute', 409);
        }
        if ($garment->order->isPaused()) {
            throw new DomainException('订单暂停中', 'order_paused', 409);
        }

        return DB::transaction(function () use ($garment, $attrs, $actor) {
            // 同一件衣物存在未关闭争议时，不重复开单
            $open = Dispute::where('garment_id', $garment->id)
                ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_RECHECKING, Dispute::STATUS_NEGOTIATING])
                ->first();
            if ($open) {
                throw new DomainException('该衣物已有进行中的争议，请在原争议单上处理', 'dispute_already_open', 409);
            }

            $dispute = Dispute::create([
                'order_id' => $garment->order_id,
                'garment_id' => $garment->id,
                'type' => $attrs['type'],
                'description' => $attrs['description'],
                'requested_amount' => $attrs['requested_amount'] ?? 0,
                'status' => Dispute::STATUS_OPEN,
                'opened_by' => $actor->id,
            ]);

            $garment->update(['status' => Garment::STATUS_DISPUTED]);

            EventLog::record('dispute.opened', $garment->order_id, $garment->id, $dispute->id, $actor->id, [
                'type' => $dispute->type,
                'requested_amount' => $dispute->requested_amount,
            ]);

            return $dispute;
        });
    }

    public function startRecheck(Dispute $dispute, User $reviewer): Dispute
    {
        if (! in_array($dispute->status, [Dispute::STATUS_OPEN, Dispute::STATUS_RECHECKING], true)) {
            throw new DomainException('当前争议状态不能发起复检', 'recheck_not_allowed', 409);
        }

        DB::transaction(function () use ($dispute, $reviewer) {
            $dispute->update(['status' => Dispute::STATUS_RECHECKING]);
            $dispute->garment->update(['status' => Garment::STATUS_RECHECKING]);
            EventLog::record('dispute.recheck_started', $dispute->order_id, $dispute->garment_id, $dispute->id, $reviewer->id);
        });

        return $dispute->fresh();
    }

    public function submitReview(Dispute $dispute, array $attrs, User $reviewer): DisputeReview
    {
        if ($dispute->status !== Dispute::STATUS_RECHECKING) {
            throw new DomainException('只有复检中的争议可以提交复检结论', 'not_rechecking', 409);
        }

        return DB::transaction(function () use ($dispute, $attrs, $reviewer) {
            $review = DisputeReview::create([
                'dispute_id' => $dispute->id,
                'reviewer_id' => $reviewer->id,
                'result' => $attrs['result'],
                'finding' => $attrs['finding'],
                'evidence_photo_ids' => $attrs['evidence_photo_ids'] ?? null,
            ]);

            $nextStatus = match ($attrs['result']) {
                DisputeReview::RESULT_CONFIRMED => Dispute::STATUS_NEGOTIATING,
                DisputeReview::RESULT_INCONCLUSIVE => Dispute::STATUS_NEGOTIATING,
                DisputeReview::RESULT_NOT_FOUND => Dispute::STATUS_OPEN,
            };
            $dispute->update(['status' => $nextStatus]);

            EventLog::record('dispute.review_submitted', $dispute->order_id, $dispute->garment_id, $dispute->id, $reviewer->id, [
                'result' => $review->result,
            ]);

            return $review;
        });
    }

    /**
     * 提出赔付方案（赔偿协商）。一件争议最多一条赔付——重复请求被拦截。
     */
    public function proposeCompensation(Dispute $dispute, int $amount, User $actor): Compensation
    {
        if ($amount <= 0) {
            throw new DomainException('赔付金额必须大于 0', 'amount_invalid');
        }
        if (! in_array($dispute->status, [Dispute::STATUS_NEGOTIATING, Dispute::STATUS_OPEN], true)) {
            throw new DomainException('当前争议状态不能发起赔付协商', 'compensation_not_allowed', 409);
        }

        return DB::transaction(function () use ($dispute, $amount, $actor) {
            // 行锁 + 唯一索引双重保证“一件争议一条赔付”
            $existing = Compensation::where('dispute_id', $dispute->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === Compensation::STATUS_REJECTED) {
                    throw new DomainException(
                        '赔付方案已被驳回，应由发起方在原单上修改金额重新提交，不能重复发起',
                        'compensation_rejected_use_repropose',
                        409,
                    );
                }
                throw new DomainException(
                    "该争议已存在赔付单（#{$existing->id}，状态：{$existing->status}），重复赔付请求被拦截",
                    'duplicate_compensation',
                    409,
                );
            }

            try {
                $comp = Compensation::create([
                    'dispute_id' => $dispute->id,
                    'garment_id' => $dispute->garment_id,
                    'order_id' => $dispute->order_id,
                    'amount' => $amount,
                    'currency' => 'CNY',
                    'status' => Compensation::STATUS_REQUESTED,
                    'requested_by' => $actor->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                throw new DomainException('该争议已有赔付单，重复赔付请求被拦截', 'duplicate_compensation', 409);
            }

            $dispute->update(['status' => Dispute::STATUS_NEGOTIATING]);

            EventLog::record('compensation.proposed', $dispute->order_id, $dispute->garment_id, $dispute->id, $actor->id, [
                'compensation_id' => $comp->id,
                'amount' => $amount,
            ]);

            return $comp;
        });
    }

    /**
     * 被驳回后，在原赔付单上修改金额重新提交（仍只有一张赔付单）。
     */
    public function reproposeCompensation(Compensation $compensation, int $amount, User $actor): Compensation
    {
        if ($amount <= 0) {
            throw new DomainException('赔付金额必须大于 0', 'amount_invalid');
        }
        if ($compensation->status !== Compensation::STATUS_REJECTED) {
            throw new DomainException('只有已驳回的赔付方案可以修改重新提交', 'comp_not_rejected', 409);
        }

        $compensation->update([
            'amount' => $amount,
            'status' => Compensation::STATUS_REQUESTED,
            'requested_by' => $actor->id,
            'approved_by' => null,
            'approved_at' => null,
            'approval_note' => null,
            'paid_by' => null,
            'paid_at' => null,
        ]);

        EventLog::record('compensation.reproposed', $compensation->order_id, $compensation->garment_id,
            $compensation->dispute_id, $actor->id, ['amount' => $amount, 'compensation_id' => $compensation->id]);

        return $compensation;
    }

    /**
     * 审批赔付。关键规则：
     *  - 只有经理可以审批；
     *  - 洗护人员不能审批自己责任范围内的赔付——审批人是该衣物洗护责任人时直接拒绝
     *    （经理若恰好是责任人同样受此约束）。
     */
    public function approveCompensation(Compensation $compensation, bool $approved, ?string $note, User $approver): Compensation
    {
        if ($compensation->status !== Compensation::STATUS_REQUESTED) {
            throw new DomainException("赔付单当前状态为 {$compensation->status}，不能重复审批", 'comp_not_pending', 409);
        }

        $garment = $compensation->garment;

        if ((int) $garment->washer_id === (int) $approver->id) {
            EventLog::record('compensation.self_approval_blocked', $compensation->order_id,
                $compensation->garment_id, $compensation->dispute_id, $approver->id, [
                    'compensation_id' => $compensation->id,
                    'reason' => 'approver_is_responsible_washer',
                ]);
            throw new DomainException(
                '洗护人员不能审批自己责任范围内的赔付：该衣物洗护责任人就是您，须由其他经理审批',
                'self_approval_forbidden',
                403,
            );
        }

        $compensation->update([
            'status' => $approved ? Compensation::STATUS_APPROVED : Compensation::STATUS_REJECTED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approval_note' => $note,
        ]);

        if (! $approved) {
            $compensation->dispute->update(['status' => Dispute::STATUS_NEGOTIATING]);
        }

        EventLog::record(
            $approved ? 'compensation.approved' : 'compensation.rejected',
            $compensation->order_id, $compensation->garment_id, $compensation->dispute_id, $approver->id,
            ['compensation_id' => $compensation->id, 'note' => $note],
        );

        return $compensation;
    }

    /**
     * 发起赔付付款（调用模拟网关）。多重防重：
     *  - 赔付单必须处于 approved；
     *  - 行锁防止并发双付；
     *  - 网关与回执均以幂等键去重，重复调用返回原回执，绝不二次付款。
     */
    public function pay(Compensation $compensation, User $actor): Compensation
    {
        return DB::transaction(function () use ($compensation, $actor) {
            $locked = Compensation::where('id', $compensation->id)->lockForUpdate()->first();

            if ($locked->status === Compensation::STATUS_PAID) {
                throw new DomainException(
                    "赔付单 #{$locked->id} 已付款，重复付款请求被拦截（回执号：".optional($locked->receipt)->receipt_no.'）',
                    'already_paid',
                    409,
                );
            }
            if ($locked->status !== Compensation::STATUS_APPROVED) {
                throw new DomainException("赔付单状态为 {$locked->status}，尚未批准，不能付款", 'comp_not_approved', 409);
            }

            // 确定性幂等键：同一赔付单无论发起多少次都只对应一笔网关交易
            $idempotencyKey = 'comp-'.$locked->id;
            $paymentRef = 'COMP-'.str_pad((string) $locked->id, 8, '0', STR_PAD_LEFT);

            $txn = $this->gateway->charge($paymentRef, $locked->amount, $idempotencyKey);

            // 业务侧回执表唯一键兜底
            $receipt = CompensationReceipt::where('compensation_id', $locked->id)->first();
            if (! $receipt) {
                try {
                    $receipt = CompensationReceipt::create([
                        'compensation_id' => $locked->id,
                        'receipt_no' => $txn->transaction_no,
                        'idempotency_key' => $idempotencyKey,
                        'gateway' => 'mock-gateway',
                        'gateway_status' => $txn->status,
                        'payload' => ['payment_ref' => $paymentRef, 'amount' => $locked->amount],
                        'recorded_by' => $actor->id,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    throw new DomainException('回执已存在，重复付款被拦截', 'duplicate_receipt', 409);
                }
            }

            $locked->update([
                'status' => Compensation::STATUS_PAID,
                'paid_by' => $actor->id,
                'paid_at' => now(),
            ]);

            EventLog::record('compensation.paid', $locked->order_id, $locked->garment_id,
                $locked->dispute_id, $actor->id, [
                    'compensation_id' => $locked->id,
                    'receipt_no' => $receipt->receipt_no,
                    'amount' => $locked->amount,
                ]);

            $this->settleDispute($locked, $actor, "已赔付 {$locked->amount} 分，回执号 {$receipt->receipt_no}");

            return $locked->fresh('receipt');
        });
    }

    /**
     * 网关回执回调：同一幂等键的重复回执不会造成二次付款或状态反复。
     */
    public function recordGatewayCallback(array $payload): CompensationReceipt
    {
        $result = $this->gateway->handleCallback($payload);

        if (! empty($result['unknown'])) {
            throw new DomainException('找不到对应交易的回执', 'unknown_receipt', 404);
        }

        $txn = $result['transaction'];
        $receipt = CompensationReceipt::where('idempotency_key', $txn->idempotency_key)->firstOrFail();

        if ($result['duplicate']) {
            // 重复回执：只记录，绝不再入账
            EventLog::record('compensation.duplicate_receipt_ignored',
                $receipt->compensation->order_id, $receipt->compensation->garment_id,
                $receipt->compensation->dispute_id, null, [
                    'compensation_id' => $receipt->compensation_id,
                    'receipt_no' => $receipt->receipt_no,
                    'callback_count' => $txn->callback_count,
                ]);
            throw new DomainException(
                "回执 {$receipt->receipt_no} 已处理过，重复回执被忽略，未发生二次付款（网关回调计数：{$txn->callback_count}）",
                'duplicate_callback',
                409,
            );
        }

        EventLog::record('compensation.receipt_confirmed',
            $receipt->compensation->order_id, $receipt->compensation->garment_id,
            $receipt->compensation->dispute_id, null, ['receipt_no' => $receipt->receipt_no]);

        return $receipt;
    }

    /**
     * 争议处理结束（已赔付或驳回关闭）。
     */
    public function closeDispute(Dispute $dispute, string $note, User $actor): Dispute
    {
        $unresolvedComp = $dispute->compensation
            && in_array($dispute->compensation->status, [Compensation::STATUS_REQUESTED, Compensation::STATUS_APPROVED], true);
        if ($unresolvedComp) {
            throw new DomainException('赔付单尚未了结，不能关闭争议', 'comp_open', 409);
        }

        DB::transaction(function () use ($dispute, $note, $actor) {
            $dispute->update([
                'status' => Dispute::STATUS_REJECTED,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'resolution_note' => $note,
            ]);
            // 争议关闭后衣物回到待完检，由店员核对后完检通过
            $dispute->garment->update(['status' => Garment::STATUS_READY_INSPECTION]);
            EventLog::record('dispute.closed', $dispute->order_id, $dispute->garment_id, $dispute->id, $actor->id, [
                'note' => $note,
            ]);
        });

        return $dispute->fresh();
    }

    private function settleDispute(Compensation $comp, User $actor, string $note): void
    {
        $dispute = $comp->dispute;
        $dispute->update([
            'status' => Dispute::STATUS_RESOLVED,
            'closed_by' => $actor->id,
            'closed_at' => now(),
            'resolution_note' => $note,
        ]);
        // 已赔付，衣物按完检通过处理，等待交付
        $comp->garment->update([
            'status' => Garment::STATUS_INSPECTION_PASSED,
            'inspected_at' => now(),
            'inspected_by' => $actor->id,
        ]);
        EventLog::record('dispute.resolved', $comp->order_id, $comp->garment_id, $dispute->id, $actor->id, [
            'note' => $note,
        ]);
    }
}
