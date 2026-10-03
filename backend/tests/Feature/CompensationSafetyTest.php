<?php

namespace Tests\Feature;

use App\Models\Compensation;
use App\Models\CompensationReceipt;
use App\Models\MockGatewayTransaction;
use App\Models\User;
use App\Services\MockPaymentGateway;

/**
 * 交付验证 3：重复赔付被拦截。
 *  - 一件争议只能存在一张赔付单；
 *  - 洗护人员不能审批自己责任范围内的赔付（经理是责任人同样拦截）；
 *  - 已赔付后再次付款被拦截，不产生第二笔网关交易；
 *  - 模拟网关重复回执不造成二次付款/重复入账。
 */
class CompensationSafetyTest extends DryCleanTestCase
{
    private function createNegotiation(): array
    {
        $clerk = $this->user(User::ROLE_CLERK, '收件员');
        $washer = $this->user(User::ROLE_WASHER, '洗护员');
        $manager = $this->user(User::ROLE_MANAGER, '经理');

        $ready = $this->makeReadyForInspectionGarment($clerk, $washer, 'G-PAY-1');
        $disputeId = $this->openConfirmedDispute($ready['garment'], $clerk, $manager);

        return compact('clerk', 'washer', 'manager', 'disputeId') + $ready;
    }

    public function test_only_one_compensation_per_dispute(): void
    {
        $ctx = $this->createNegotiation();

        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 3000], $this->headers($ctx['clerk']))
            ->assertCreated();

        // 第二次提交赔付 → 409 拦截
        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 5000], $this->headers($ctx['clerk']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_compensation');

        $this->assertSame(1, Compensation::where('dispute_id', $ctx['disputeId'])->count());
    }

    public function test_washer_cannot_approve_their_own_responsibility_compensation(): void
    {
        $ctx = $this->createNegotiation();

        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 3000], $this->headers($ctx['clerk']))->assertCreated();
        $compId = Compensation::where('dispute_id', $ctx['disputeId'])->value('id');

        // 普通洗护人员连审批接口都进不了（角色限制 403）
        $this->postJson("/api/compensations/{$compId}/approve",
            ['approved' => true, 'note' => '我自己批'], $this->headers($ctx['washer']))
            ->assertStatus(403);

        // 关键：经理若恰好是洗护责任人，服务层同样拒绝（自审拦截）
        // 模拟：让经理成为另一件衣物的洗护责任人并审批其赔付
        $clerk2 = $this->user(User::ROLE_CLERK, '收件员二');
        $managerWhoWashed = $this->user(User::ROLE_MANAGER, '会洗护的经理');
        $ready2 = $this->makeReadyForInspectionGarment($clerk2, $managerWhoWashed, 'G-PAY-SELF');
        $disputeId2 = $this->openConfirmedDispute($ready2['garment'], $clerk2, $this->user(User::ROLE_MANAGER, '复核经理'));

        $this->postJson("/api/disputes/{$disputeId2}/compensation",
            ['amount' => 4000], $this->headers($clerk2))->assertCreated();
        $compId2 = Compensation::where('dispute_id', $disputeId2)->value('id');

        $this->postJson("/api/compensations/{$compId2}/approve",
            ['approved' => true, 'note' => '自己批自己'], $this->headers($managerWhoWashed))
            ->assertStatus(403)
            ->assertJsonPath('code', 'self_approval_forbidden');

        // 赔付单仍是待审批，没有被批准
        $this->assertSame(Compensation::STATUS_REQUESTED, Compensation::find($compId2)->status);
    }

    public function test_independent_manager_can_approve_and_pay_once(): void
    {
        $ctx = $this->createNegotiation();

        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 3000], $this->headers($ctx['clerk']))->assertCreated();
        $compId = Compensation::where('dispute_id', $ctx['disputeId'])->value('id');

        // 与责任无关的经理可以批准
        $this->postJson("/api/compensations/{$compId}/approve",
            ['approved' => true, 'note' => '同意'], $this->headers($ctx['manager']))
            ->assertOk();

        // 付款成功
        $this->postJson("/api/compensations/{$compId}/pay", [], $this->headers($ctx['clerk']))
            ->assertOk()
            ->assertJsonPath('compensation.status', Compensation::STATUS_PAID);

        // 网关只有一笔交易、业务只有一张回执
        $this->assertSame(1, MockGatewayTransaction::where('payment_ref', 'COMP-'.str_pad((string) $compId, 8, '0', STR_PAD_LEFT))->count());
        $this->assertSame(1, CompensationReceipt::where('compensation_id', $compId)->count());
    }

    public function test_double_pay_is_blocked_without_second_gateway_transaction(): void
    {
        $ctx = $this->createNegotiation();

        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 3000], $this->headers($ctx['clerk']))->assertCreated();
        $compId = Compensation::where('dispute_id', $ctx['disputeId'])->value('id');

        $this->postJson("/api/compensations/{$compId}/approve",
            ['approved' => true], $this->headers($ctx['manager']))->assertOk();

        $this->postJson("/api/compensations/{$compId}/pay", [], $this->headers($ctx['clerk']))->assertOk();

        // 重复付款请求 → 409
        $this->postJson("/api/compensations/{$compId}/pay", [], $this->headers($ctx['clerk']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_paid');

        // 网关交易仍是 1 笔，没有二次付款
        $this->assertSame(1, MockGatewayTransaction::count());
        $this->assertSame(1, CompensationReceipt::count());
    }

    public function test_duplicate_gateway_callback_does_not_double_settle(): void
    {
        $ctx = $this->createNegotiation();

        $this->postJson("/api/disputes/{$ctx['disputeId']}/compensation",
            ['amount' => 3000], $this->headers($ctx['clerk']))->assertCreated();
        $compId = Compensation::where('dispute_id', $ctx['disputeId'])->value('id');
        $this->postJson("/api/compensations/{$compId}/approve",
            ['approved' => true], $this->headers($ctx['manager']))->assertOk();
        $paid = $this->postJson("/api/compensations/{$compId}/pay", [], $this->headers($ctx['clerk']))
            ->json('compensation');

        $receiptNo = $paid['receipt']['receipt_no'];
        $idempotencyKey = $paid['receipt']['idempotency_key'];

        $callbackPayload = [
            'receipt_no' => $receiptNo,
            'idempotency_key' => $idempotencyKey,
            'status' => 'success',
        ];
        $signature = MockPaymentGateway::sign($callbackPayload);

        // 第一次回执（pay() 已内部记账，这里模拟网关再次回调）
        $first = $this->postJson('/api/mock-gateway/callback',
            $callbackPayload + ['signature' => $signature]);
        $this->assertContains($first->status(), [200, 409]);

        // 第二次相同回执 → 明确被识别为重复
        $second = $this->postJson('/api/mock-gateway/callback',
            $callbackPayload + ['signature' => $signature]);
        $second->assertStatus(409)->assertJsonPath('code', 'duplicate_callback');

        // 网关回调计数增加，但赔付单/回执/交易数量不变，未二次付款
        $txn = MockGatewayTransaction::where('idempotency_key', $idempotencyKey)->first();
        $this->assertGreaterThanOrEqual(1, $txn->callback_count);
        $this->assertSame(1, Compensation::where('dispute_id', $ctx['disputeId'])->count());
        $this->assertSame(1, CompensationReceipt::where('compensation_id', $compId)->count());
        $this->assertSame(Compensation::STATUS_PAID, Compensation::find($compId)->status);
    }

    public function test_callback_with_bad_signature_is_rejected(): void
    {
        $this->postJson('/api/mock-gateway/callback', [
            'receipt_no' => 'TXN-FAKE',
            'idempotency_key' => 'comp-x',
            'status' => 'success',
            'signature' => str_repeat('0', 64),
        ])->assertStatus(401);
    }
}
