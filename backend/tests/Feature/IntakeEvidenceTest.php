<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\User;
use App\Services\PhotoStorageService;

/**
 * 交付验证 2：预检证据不可覆盖。
 *  - 顾客确认后不得再上传/追加/替换预检照片；
 *  - 磁盘文件内容寻址且只读；
 *  - 同一照片重复上传被拒绝；
 *  - 记录的 checksum 与磁盘文件始终一致。
 */
class IntakeEvidenceTest extends DryCleanTestCase
{
    public function test_intake_photos_cannot_be_added_or_replaced_after_lock(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $order = $this->postJson('/api/orders', ['customer_name' => '证据顾客'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-EV-1', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');

        $first = $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg('original'), 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk));
        $first->assertCreated();
        $originalChecksum = $first->json('photo.checksum');

        // 顾客确认 → 预检锁定
        $this->postJson("/api/orders/{$order['id']}/submit-confirmation", [], $this->headers($clerk))->assertOk();
        $this->postJson("/api/orders/{$order['id']}/confirm",
            ['signature_name' => '证据顾客'], $this->headers($clerk))->assertOk();

        // 再次上传预检照片（无论相同还是不同文件）→ 409 被拒
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg('i-want-to-replace'), 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk))
            ->assertStatus(409)
            ->assertJsonPath('code', 'intake_evidence_locked');

        // 记录未变，仍是原始那张
        $photos = GarmentPhoto::where('garment_id', $garmentId)
            ->where('stage', GarmentPhoto::STAGE_INTAKE)->get();
        $this->assertCount(1, $photos);
        $this->assertSame($originalChecksum, $photos->first()->checksum);

        // 磁盘文件只读且校验和未变
        $this->assertTrue(PhotoStorageService::verifyFileChecksum($photos->first()));
        $absolute = storage_path('app/private/'.$photos->first()->path);
        $this->assertFalse(is_writable($absolute), '预检证据文件应为只读');
    }

    public function test_same_photo_cannot_be_uploaded_twice_before_lock(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $order = $this->postJson('/api/orders', ['customer_name' => '重复照顾客'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-EV-2', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');

        // 同一张文件传两次
        $file = $this->jpeg('same-same');
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $file, 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk))->assertCreated();

        $file2 = $this->jpeg('same-same'); // 同样标签生成相同字节
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $file2, 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk))
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_photo');

        $this->assertSame(1, GarmentPhoto::where('garment_id', $garmentId)->count());
    }

    public function test_intake_photo_record_has_checksum_that_matches_disk(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $order = $this->postJson('/api/orders', ['customer_name' => '校验顾客'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-EV-3', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');

        $photo = $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg('checksum-me'), 'stage' => GarmentPhoto::STAGE_INTAKE],
            $this->headers($clerk))->json('photo');

        $this->assertTrue($photo['checksum_valid']);
        $this->assertSame(64, strlen($photo['checksum']));
    }
}
