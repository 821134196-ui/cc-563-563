<?php

namespace Tests\Feature;

use App\Models\GarmentPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

abstract class DryCleanTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> userId => 原始 API 令牌 */
    private array $tokens = [];

    protected function user(string $role = User::ROLE_CLERK, string $name = '测试员'): User
    {
        $raw = User::newApiToken();
        $user = User::create([
            'name' => $name,
            'email' => $role.'-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'api_token' => hash('sha256', $raw),
        ]);
        $this->tokens[$user->id] = $raw;

        return $user;
    }

    protected function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokens[$user->id]];
    }

    protected function jpeg(string $label = 'test'): UploadedFile
    {
        $img = imagecreatetruecolor(120, 90);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        imagestring($img, 3, 5, 5, $label, imagecolorallocate($img, 0, 0, 0));
        $path = sys_get_temp_dir().'/test-'.uniqid().'.jpg';
        imagejpeg($img, $path, 80);
        imagedestroy($img);

        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }

    /**
     * 走完 收件 → 顾客确认 → 洗护 → 完检拍照 全流程，返回 ['order'=>id,'garment'=>id]。
     */
    protected function makeReadyForInspectionGarment(User $clerk, User $washer, string $tagNo = 'G-T-1'): array
    {
        $order = $this->postJson('/api/orders', ['customer_name' => '测试顾客'], $this->headers($clerk))
            ->json('order');

        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => $tagNo, 'category' => '外套', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();

        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');

        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg('intake'), 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk))->assertCreated();

        $this->postJson("/api/orders/{$order['id']}/submit-confirmation", [], $this->headers($clerk))->assertOk();
        $this->postJson("/api/orders/{$order['id']}/confirm",
            ['signature_name' => '顾客签名'], $this->headers($clerk))->assertOk();

        $this->postJson("/api/garments/{$garmentId}/wash/start", [], $this->headers($washer))->assertOk();
        $this->postJson("/api/garments/{$garmentId}/wash/finish", [], $this->headers($washer))->assertOk();
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg('completion'), 'stage' => GarmentPhoto::STAGE_COMPLETION],
            $this->headers($clerk))->assertCreated();

        return ['order' => $order['id'], 'garment' => $garmentId];
    }

    protected function openConfirmedDispute(int $garmentId, User $clerk, User $manager): int
    {
        $disputeId = $this->postJson("/api/garments/{$garmentId}/disputes", [
            'type' => 'new_damage',
            'description' => '袖口新增勾破',
            'requested_amount' => 6000,
        ], $this->headers($clerk))->json('dispute.id');

        $this->postJson("/api/disputes/{$disputeId}/recheck", [], $this->headers($manager))->assertOk();
        $this->postJson("/api/disputes/{$disputeId}/reviews", [
            'result' => 'confirmed',
            'finding' => '比对原始照片确认差异为新增',
        ], $this->headers($manager))->assertCreated();

        return $disputeId;
    }
}
