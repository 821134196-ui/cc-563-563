<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = User::ROLE_CASHIER): User
    {
        return User::factory()->create(['role' => $role, 'api_token' => null]);
    }

    private function auth(User $user): array
    {
        $token = 'test-token-' . $user->id;
        $user->update(['api_token' => hash('sha256', $token)]);
        return ['Authorization' => "Bearer $token"];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeOrder(User $cashier, string $customer = '顾客'): array
    {
        return $this->postJson('/api/orders', ['customer_name' => $customer], $this->auth($cashier))
            ->assertCreated()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function addGarment(User $cashier, int $orderId, string $tag, array $extra = []): array
    {
        $data = array_merge(['tag' => $tag, 'category' => '外套'], $extra);
        return $this->postJson("/api/orders/$orderId/garments", $data, $this->auth($cashier))
            ->assertCreated()->json('data');
    }

    /**
     * 验证 1：衣物标签号全店唯一，重复编号必须被拦截（应用层 + 数据库层）。
     */
    public function test_garment_tag_must_be_unique_across_orders(): void
    {
        $cashier = $this->staff();
        $orderA = $this->makeOrder($cashier, '甲');
        $orderB = $this->makeOrder($cashier, '乙');

        $this->postJson("/api/orders/{$orderA['id']}/garments", ['tag' => 'TAG-100'], $this->auth($cashier))
            ->assertCreated();

        // 同一标签用于另一订单 → 409
        $this->postJson("/api/orders/{$orderB['id']}/garments", ['tag' => 'TAG-100'], $this->auth($cashier))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'duplicate_tag');

        $this->assertSame(1, Garment::where('tag', 'TAG-100')->count());
    }

    /**
     * 验证 2：顾客确认后，原始预检照片不可覆盖/追加，标注也冻结。
     */
    public function test_intake_evidence_is_locked_after_customer_confirmation(): void
    {
        Storage::fake('local');
        $cashier = $this->staff();
        $order = $this->makeOrder($cashier);
        $garment = $this->addGarment($cashier, $order['id'], 'LOCK-1');

        // 确认前：可以上传原始照片
        $this->post(
            "/api/garments/{$garment['id']}/photos",
            ['stage' => 'intake', 'photo' => UploadedFile::fake()->image('a.png', 800, 600)],
            $this->auth($cashier),
        )->assertCreated();

        // 顾客确认
        $this->postJson("/api/orders/{$order['id']}/confirm", [], $this->auth($cashier))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_care');

        $garmentModel = Garment::find($garment['id']);
        $this->assertNotNull($garmentModel->intake_locked_at);

        // 确认后：再次上传原始照片 → 409，证据不可追加/替换
        $this->post(
            "/api/garments/{$garment['id']}/photos",
            ['stage' => 'intake', 'photo' => UploadedFile::fake()->image('b.png', 800, 600)],
            $this->auth($cashier),
        )->assertStatus(409)->assertJsonPath('error_code', 'intake_evidence_locked');

        // 确认后：预检标注同样冻结
        $this->postJson(
            "/api/garments/{$garment['id']}/marks",
            ['kind' => 'stain', 'location' => '领口'],
            $this->auth($cashier),
        )->assertStatus(409)->assertJsonPath('error_code', 'intake_evidence_locked');

        // 数据库中原始照片仍只有一张，且为最初那张
        $this->assertSame(1, $garmentModel->intakePhotos()->count());
    }

    /**
     * 同一张照片（相同内容）不能用于第二件衣物入库 —— 防止同一衣物重复入库。
     */
    public function test_same_photo_cannot_check_in_two_garments(): void
    {
        Storage::fake('local');
        $cashier = $this->staff();
        $o1 = $this->makeOrder($cashier, '甲');
        $o2 = $this->makeOrder($cashier, '乙');
        $g1 = $this->addGarment($cashier, $o1['id'], 'DUP-P-1');
        $g2 = $this->addGarment($cashier, $o2['id'], 'DUP-P-2');

        // 用同一份文件内容（字节完全相同）分别上传，模拟同一件衣物被重复入库
        $pngPath = tempnam(sys_get_temp_dir(), 'same');
        $im = imagecreatetruecolor(640, 480);
        imagefilledrectangle($im, 0, 0, 640, 480, imagecolorallocate($im, 120, 70, 200));
        imagepng($im, $pngPath);

        $this->post(
            "/api/garments/{$g1['id']}/photos",
            ['stage' => 'intake', 'photo' => new UploadedFile($pngPath, 'same.png', 'image/png', null, true)],
            $this->auth($cashier),
        )->assertCreated();

        $this->post(
            "/api/garments/{$g2['id']}/photos",
            ['stage' => 'intake', 'photo' => new UploadedFile($pngPath, 'same.png', 'image/png', null, true)],
            $this->auth($cashier),
        )->assertStatus(409)->assertJsonPath('error_code', 'duplicate_intake_photo');
    }

    /**
     * 护理标签禁止水洗 / 口袋物品：必须先暂停，不能直接确认。
     */
    public function test_blocking_conditions_pause_order_before_confirm(): void
    {
        $cashier = $this->staff();

        // 禁止水洗
        $order = $this->makeOrder($cashier);
        $g = $this->addGarment($cashier, $order['id'], 'CARE-1', ['care_labels' => ['no_water_wash']]);
        Storage::fake('local');
        $this->post("/api/garments/{$g['id']}/photos", ['stage' => 'intake', 'photo' => UploadedFile::fake()->image('c.png')], $this->auth($cashier))
            ->assertCreated();
        $this->postJson("/api/orders/{$order['id']}/confirm", [], $this->auth($cashier))
            ->assertStatus(409)->assertJsonPath('error_code', 'care_label_blocks_confirm');

        // 暂停后依然不能确认
        $this->postJson("/api/orders/{$order['id']}/pause",
            ['reason' => 'label_forbids_water_wash', 'note' => '联系顾客改干洗'], $this->auth($cashier))->assertOk();
        $this->postJson("/api/orders/{$order['id']}/confirm", [], $this->auth($cashier))
            ->assertStatus(409)->assertJsonPath('error_code', 'order_paused');
    }
}
