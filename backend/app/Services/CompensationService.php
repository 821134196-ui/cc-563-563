<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\CompensationRequest;
use App\Models\Dispute;
use App\Models\Garment;
use App\Models\PayoutReceipt;
use App\Models\User;
use App\Services\Payout\MockPayoutGateway;
use Illuminate\Support\Facades\DB;

class CompensationService
{
    public function __construct(private readonly MockPayoutGateway $gateway)
    {
    }

    /**
     * 发起赔付申请（赔偿协商的一部分）。
     */
    public function request(Dispute $dispute, int $amountCents, string $reason, User $requester): CompensationRequest
    {
        if ($amountCents <= 0) {
            throw new DomainRuleException('invalid_amount', '赔付金额必须大于 0');
        }
        if (in_array($dispute->status, [Dispute::STATUS_PAID, Dispute::STATUS_CLOSED], true)) {
            throw new DomainRuleException('dispute_settled', '该争议已结案，不能再发起赔付', 409);
        }

        $existing = $dispute->compensation()->first();
        if ($existing !== null) {
            // 同一争议只允许一份赔付申请；重复提交不再产生新单
            throw new DomainRuleException(
                'duplicate_compensation_request',
                '该争议已存在赔付申请，不能重复发起',
                409,
                ['compensation_id' => $existing->id, 'status' => $existing->status],
            );
        }

        $request = DB::transaction(function () use ($dispute, $amountCents, $reason, $requester) {
            $comp = CompensationRequest::create([
                'dispute_id' => $dispute->id,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'status' => CompensationRequest::STATUS_PENDING,
                'requested_by' => $requester->id,
            ]);
            $dispute->update(['status' => Dispute::STATUS_NEGOTIATING]);
            AuditLog::record('compensation.requested', $requester->id, null, $dispute->garment_id, [
                'dispute_id' => $dispute->id,
                'amount_cents' => $amountCents,
            ]);
            return $comp;
        });

        return $request->fresh();
    }

    /**
     * 审批赔付。
     * 职责分离：洗护人员不能审批自己责任范围内的赔付
     *  —— 即 washer 角色一律无审批权（其本人经手的衣物争议必属其责任范围），
     *     且店长也不能审批被判定为自己经手洗护的衣物（此处仅店长可审批，
     *     审批人还不得是该衣物的登记洗护人，形成双重隔离）。
     */
    public function decide(CompensationRequest $compensation, bool $approved, string $note, User $decider): CompensationRequest
    {
        if (!$decider->isManager()) {
            throw new DomainRuleException(
                'forbidden_role',
                '洗护人员/收件店员无权审批赔付，必须由店长审批',
                403,
            );
        }

        $garment = $compensation->dispute->garment;
        if ($garment->washer_id !== null && $garment->washer_id === $decider->id) {
            throw new DomainRuleException(
                'segregation_of_duty',
                '审批人不得是该衣物的经手洗护人员（职责分离）',
                403,
            );
        }

        if ($compensation->status !== CompensationRequest::STATUS_PENDING) {
            throw new DomainRuleException(
                'already_decided',
                "该赔付申请已处理（当前状态：{$compensation->status}），不能重复审批",
                409,
            );
        }

        return DB::transaction(function () use ($compensation, $approved, $note, $decider) {
            $compensation->update([
                'status' => $approved ? CompensationRequest::STATUS_APPROVED : CompensationRequest::STATUS_REJECTED,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $dispute = $compensation->dispute;
            $dispute->update([
                'status' => $approved ? Dispute::STATUS_APPROVED : Dispute::STATUS_REJECTED,
                'resolution' => $approved ? $dispute->resolution : ($note ?: '赔付申请被驳回'),
            ]);
            AuditLog::record(
                $approved ? 'compensation.approved' : 'compensation.rejected',
                $decider->id,
                null,
                $dispute->garment_id,
                ['compensation_id' => $compensation->id, 'note' => $note],
            );
            return $compensation->fresh();
        });
    }

    /**
     * 向模拟网关发起付款并落回执。
     * 三重防重：
     *   1) payout_receipts.compensation_id 唯一：一笔赔付最多一张回执；
     *   2) payout_receipts.idempotency_key 唯一 + 固定键值：重复请求复用同一回执；
     *   3) 状态机：只有 approved 才能付款，已付款直接返回原回执（绝不二次出款）。
     */
    public function pay(CompensationRequest $compensation, User $operator, ?string $idempotencyKey = null): PayoutReceipt
    {
        return DB::transaction(function () use ($compensation, $operator, $idempotencyKey) {
            // 锁行，防止并发的两个付款请求同时通过检查
            $locked = CompensationRequest::query()->whereKey($compensation->id)->lockForUpdate()->firstOrFail();

            // 幂等键对同一赔付固定；客户端自带键也必须与赔付绑定
            $stableKey = 'payout:' . $locked->id;
            if ($idempotencyKey !== null && $idempotencyKey !== '' && $idempotencyKey !== $stableKey) {
                throw new DomainRuleException(
                    'idempotency_key_mismatch',
                    '幂等键与该赔付单不匹配',
                    409,
                );
            }

            $existing = PayoutReceipt::where('compensation_id', $locked->id)->first();
            if ($existing !== null) {
                // 重复的赔付请求/回执：原样返回已有回执，不产生第二笔付款
                return $existing;
            }

            if ($locked->status !== CompensationRequest::STATUS_APPROVED) {
                throw new DomainRuleException(
                    'not_payable',
                    "只有审批通过的赔付才能付款（当前状态：{$locked->status}）",
                    409,
                );
            }

            // 幂等键对同一赔付固定；客户端自带键也必须与赔付绑定
            $stableKey = 'payout:' . $locked->id;
            if ($idempotencyKey !== null && $idempotencyKey !== '' && $idempotencyKey !== $stableKey) {
                throw new DomainRuleException(
                    'idempotency_key_mismatch',
                    '幂等键与该赔付单不匹配',
                    409,
                );
            }

            $dispute = $locked->dispute;
            $response = $this->gateway->pay($stableKey, $locked->amount_cents, "dispute:{$dispute->id}");

            try {
                $receipt = PayoutReceipt::create([
                    'compensation_id' => $locked->id,
                    'receipt_no' => $response['receipt_no'],
                    'idempotency_key' => $stableKey,
                    'amount_cents' => $response['amount_cents'],
                    'gateway' => 'mock',
                    'status' => 'paid',
                    'response_payload' => $response,
                    'created_by' => $operator->id,
                    'paid_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // 并发兜底：唯一约束命中则返回既有回执
                $race = PayoutReceipt::where('compensation_id', $locked->id)->first();
                if ($race !== null) {
                    return $race;
                }
                throw $e;
            }

            $locked->update(['status' => CompensationRequest::STATUS_PAID]);
            $dispute->update(['status' => Dispute::STATUS_PAID, 'resolution' => '已赔付，回执号 ' . $receipt->receipt_no]);

            $garmentId = $dispute->garment_id;
            AuditLog::record('compensation.paid', $operator->id, null, $garmentId, [
                'compensation_id' => $locked->id,
                'receipt_no' => $receipt->receipt_no,
                'amount_cents' => $receipt->amount_cents,
            ]);

            return $receipt;
        });
    }
}
