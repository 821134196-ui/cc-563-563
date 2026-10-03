<?php

namespace Tests\Feature;

use App\Models\CompensationRequest;
use App\Models\Dispute;
use App\Models\Garment;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompensationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'tok-' . $user->id . '-' . uniqid();
        $user->update(['api_token' => hash('sha256', $token)]);
        return ['Authorization' => "Bearer $token"];
    }

    /**
     * 构造一条已进入争议、带赔付申请的衣物：
     * cashier 收件确认 → washer 洗护 → 上传完工照 → 登记差异 → washer 发起赔付
     *
     * @return array{dispute: Dispute, washer: User, manager: User, cashier: User}
     */
    private function scenario(): array
    {
        Storage::fake('local');
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $washer = User::factory()->create(['role' => User::ROLE_WASHER]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $order = Order::create([
            'code' => 'ODT' . uniqid(),
            'customer_name' => '争议顾客',
            'status' => Order::STATUS_INTAKE,
            'created_by' => $cashier->id,
        ]);
        $garment = Garment::create([
            'order_id' => $order->id,
            'tag' => 'DISP-' . uniqid(),
            'category' => '外套',
            'status' => Garment::STATUS_WASHED,
            'washer_id' => $washer->id,
            'intake_locked_at' => now(),
        ]);
        Photo::create([
            'garment_id' => $garment->id,
            'stage' => Photo::STAGE_INTAKE,
            'disk_path' => 'fake/intake.png',
            'sha256' => 'intake' . uniqid(),
            'mime' => 'image/png',
        ]);
        Photo::create([
            'garment_id' => $garment->id,
            'stage' => Photo::STAGE_POSTWASH,
            'disk_path' => 'fake/post.png',
            'sha256' => 'post' . uniqid(),
            'mime' => 'image/png',
        ]);

        $dispute = Dispute::create([
            'garment_id' => $garment->id,
            'opened_by' => $washer->id,
            'description' => '右肩裂口',
            'suspected_responsibility' => Dispute::RESP_WASHER,
            'status' => Dispute::STATUS_OPEN,
        ]);

        $this->postJson("/api/disputes/{$dispute->id}/compensation",
            ['amount_cents' => 5000, 'reason' => '洗护致损'],
            $this->headers($washer),
        )->assertCreated();

        return compact('dispute', 'washer', 'manager', 'cashier');
    }

    /**
     * 验证 3a：洗护人员不能审批自己责任范围内的赔付 → 403。
     */
    public function test_washer_cannot_approve_compensation(): void
    {
        ['dispute' => $dispute, 'washer' => $washer] = $this->scenario();

        $this->postJson("/api/disputes/{$dispute->id}/compensation/decide",
            ['approved' => true, 'note' => '我自己批'],
            $this->headers($washer),
        )->assertStatus(403)->assertJsonPath('error_code', 'forbidden_role');

        // 收件店员同样无权审批
        $cashier = User::where('role', User::ROLE_CASHIER)->first();
        $this->postJson("/api/disputes/{$dispute->id}/compensation/decide",
            ['approved' => true],
            $this->headers($cashier),
        )->assertStatus(403)->assertJsonPath('error_code', 'forbidden_role');

        $this->assertSame(CompensationRequest::STATUS_PENDING, $dispute->compensation->first()->status);
    }

    /**
     * 店长不能审批自己经手洗护的衣物（双重职责分离）。
     */
    public function test_manager_who_washed_cannot_approve(): void
    {
        Storage::fake('local');
        $managerWhoWashed = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $order = Order::create(['code' => 'ODM' . uniqid(), 'customer_name' => 'x', 'status' => 'in_care']);
        $garment = Garment::create([
            'order_id' => $order->id, 'tag' => 'SELF-' . uniqid(), 'status' => 'washed',
            'washer_id' => $managerWhoWashed->id, 'intake_locked_at' => now(),
        ]);
        $dispute = Dispute::create([
            'garment_id' => $garment->id, 'opened_by' => $managerWhoWashed->id,
            'description' => '破损', 'status' => 'open',
        ]);
        CompensationRequest::create([
            'dispute_id' => $dispute->id, 'amount_cents' => 1000,
            'status' => 'pending', 'requested_by' => $managerWhoWashed->id,
        ]);

        $this->postJson("/api/disputes/{$dispute->id}/compensation/decide",
            ['approved' => true],
            $this->headers($managerWhoWashed),
        )->assertStatus(403)->assertJsonPath('error_code', 'segregation_of_duty');
    }

    /**
     * 重复赔付申请被拦截（同一争议只允许一份申请）。
     */
    public function test_duplicate_compensation_request_is_rejected(): void
    {
        ['dispute' => $dispute, 'washer' => $washer] = $this->scenario();

        $this->postJson("/api/disputes/{$dispute->id}/compensation",
            ['amount_cents' => 5000, 'reason' => '再来一次'],
            $this->headers($washer),
        )->assertStatus(409)->assertJsonPath('error_code', 'duplicate_compensation_request');

        $this->assertSame(1, CompensationRequest::where('dispute_id', $dispute->id)->count());
    }

    /**
     * 验证 3b：店长批准后，重复付款请求 / 回执重发不会造成二次付款。
     */
    public function test_duplicate_payout_returns_same_receipt_and_pays_once(): void
    {
        ['dispute' => $dispute, 'manager' => $manager] = $this->scenario();
        $headers = $this->headers($manager);

        $this->postJson("/api/disputes/{$dispute->id}/compensation/decide",
            ['approved' => true, 'note' => '同意赔付'], $headers)->assertOk();

        // 第一次付款 → 201
        $first = $this->postJson("/api/disputes/{$dispute->id}/compensation/pay", [], $headers)
            ->assertCreated()->json('data');

        // 紧接着的重复请求 → 返回同一张回执（200/201 均可，但 receipt_no 必须相同）
        $second = $this->postJson("/api/disputes/{$dispute->id}/compensation/pay", [], $headers)
            ->assertOk()->json('data');
        $third = $this->postJson("/api/disputes/{$dispute->id}/compensation/pay", [], $headers)
            ->assertOk()->json('data');

        $this->assertSame($first['receipt_no'], $second['receipt_no']);
        $this->assertSame($first['receipt_no'], $third['receipt_no']);
        $this->assertSame($first['id'], $second['id']);

        // 数据库中只有一张回执、一条付款；赔付状态为 paid
        $this->assertSame(1, \App\Models\PayoutReceipt::where('compensation_id', $first['compensation_id'])->count());
        $this->assertSame('paid', CompensationRequest::find($first['compensation_id'])->status);
        $this->assertSame(1, \App\Models\PayoutReceipt::count());
    }

    /**
     * 未审批通过不能付款。
     */
    public function test_cannot_pay_before_approval(): void
    {
        ['dispute' => $dispute, 'manager' => $manager] = $this->scenario();

        $this->postJson("/api/disputes/{$dispute->id}/compensation/pay", [], $this->headers($manager))
            ->assertStatus(409)->assertJsonPath('error_code', 'not_payable');

        $this->assertSame(0, \App\Models\PayoutReceipt::count());
    }

    /**
     * 幂等键与赔付单不匹配时拒绝，防止用他人回执冲正。
     */
    public function test_foreign_idempotency_key_is_rejected_after_payment(): void
    {
        ['dispute' => $dispute, 'manager' => $manager] = $this->scenario();
        $headers = $this->headers($manager);
        $this->postJson("/api/disputes/{$dispute->id}/compensation/decide", ['approved' => true], $headers)->assertOk();
        $this->postJson("/api/disputes/{$dispute->id}/compensation/pay", [], $headers)->assertCreated();

        $this->postJson("/api/disputes/{$dispute->id}/compensation/pay",
            ['idempotency_key' => 'payout:999999'], $headers)
            ->assertStatus(409)->assertJsonPath('error_code', 'idempotency_key_mismatch');

        $this->assertSame(1, \App\Models\PayoutReceipt::count());
    }
}
