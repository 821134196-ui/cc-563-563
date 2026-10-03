<?php

namespace Database\Seeders;

use App\Models\Compensation;
use App\Models\CompensationReceipt;
use App\Models\Dispute;
use App\Models\DisputeReview;
use App\Models\Garment;
use App\Models\GarmentMark;
use App\Models\GarmentPhoto;
use App\Models\Order;
use App\Models\PocketItem;
use App\Models\User;
use App\Services\IntakeService;
use App\Services\PhotoStorageService;
use App\Services\WashService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $clerk = User::create([
            'name' => '王小柜', 'email' => 'clerk@dryclean.local',
            'password' => Hash::make('password'), 'role' => User::ROLE_CLERK,
            'api_token' => hash('sha256', 'clerk-token'),
        ]);
        $washer = User::create([
            'name' => '李洗护', 'email' => 'washer@dryclean.local',
            'password' => Hash::make('password'), 'role' => User::ROLE_WASHER,
            'api_token' => hash('sha256', 'washer-token'),
        ]);
        $manager = User::create([
            'name' => '赵经理', 'email' => 'manager@dryclean.local',
            'password' => Hash::make('password'), 'role' => User::ROLE_MANAGER,
            'api_token' => hash('sha256', 'manager-token'),
        ]);

        $intake = app(IntakeService::class);
        $photos = app(PhotoStorageService::class);
        $wash = app(WashService::class);

        // ---------- 订单 1：收件预检中 ----------
        $o1 = $intake->createOrder(['customer_name' => '陈女士', 'customer_phone' => '138****0001'], $clerk);
        $g1 = $intake->registerGarment($o1, [
            'tag_no' => 'G-0001', 'category' => '真丝衬衫', 'color' => '米白', 'brand' => '之禾',
            'care_labels' => ['hand_wash', 'no_bleach'], 'description' => '顾客称领口有旧渍',
        ], $clerk);
        $photos->store($g1, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 1', 480, 360, [245, 240, 230]), $clerk);
        $intake->addMark($g1, ['kind' => 'stain', 'label' => '领口旧渍', 'x_permille' => 500, 'y_permille' => 120,
            'description' => '收件时已存在'], $clerk);

        // ---------- 订单 2：发现口袋物品 → 暂停 ----------
        $o2 = $intake->createOrder(['customer_name' => '孙先生', 'customer_phone' => '139****0002'], $clerk);
        $g2 = $intake->registerGarment($o2, [
            'tag_no' => 'G-0002', 'category' => '西装外套', 'color' => '深灰',
            'care_labels' => ['dry_clean_only'],
        ], $clerk);
        $photos->store($g2, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 2', 480, 360, [80, 85, 95]), $clerk);
        $intake->reportPocketItem($g2, ['description' => 'U盘一个', 'note' => '内袋'], $clerk);

        // ---------- 订单 3：已确认，洗护中 ----------
        $o3 = $intake->createOrder(['customer_name' => '周女士', 'customer_phone' => '137****0003'], $clerk);
        $g3 = $intake->registerGarment($o3, [
            'tag_no' => 'G-0003', 'category' => '羊毛大衣', 'color' => '驼色',
            'care_labels' => ['dry_clean_only', 'low_heat'],
        ], $clerk);
        $photos->store($g3, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 3', 480, 360, [210, 180, 140]), $clerk);
        $g3b = $intake->registerGarment($o3, [
            'tag_no' => 'G-0004', 'category' => '羊绒围巾', 'color' => '酒红',
            'care_labels' => ['hand_wash'],
        ], $clerk);
        $photos->store($g3b, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 4', 480, 360, [150, 40, 50]), $clerk);
        $intake->submitForConfirmation($o3, $clerk);
        $o3 = $intake->confirmByCustomer($o3->fresh(), '周女士', $clerk);
        $wash->startWashing($g3->fresh(), $washer);

        // ---------- 订单 4：完检无差异，通过 ----------
        $o4 = $intake->createOrder(['customer_name' => '吴先生', 'customer_phone' => '136****0004'], $clerk);
        $g4 = $intake->registerGarment($o4, [
            'tag_no' => 'G-0005', 'category' => '羽绒服', 'color' => '黑色',
            'care_labels' => ['no_dryer'],
        ], $clerk);
        $photos->store($g4, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 5', 480, 360, [40, 40, 45]), $clerk);
        $intake->submitForConfirmation($o4, $clerk);
        $intake->confirmByCustomer($o4->fresh(), '吴先生', $clerk);
        $wash->startWashing($g4->fresh(), $washer);
        $wash->markWashed($g4->fresh(), $washer);
        $photos->store($g4->fresh(), GarmentPhoto::STAGE_COMPLETION, $this->fakeImage('done 5', 480, 360, [50, 50, 55]), $clerk);
        $wash->passInspection($g4->fresh(), $clerk);

        // ---------- 订单 5：新增破损争议 → 复检确认 → 经理审批 → 已赔付 ----------
        $o5 = $intake->createOrder(['customer_name' => '郑女士', 'customer_phone' => '135****0005'], $clerk);
        $g5 = $intake->registerGarment($o5, [
            'tag_no' => 'G-0006', 'category' => '风衣', 'color' => '卡其',
            'care_labels' => ['dry_clean_only'],
        ], $clerk);
        $photos->store($g5, GarmentPhoto::STAGE_INTAKE, $this->fakeImage('intake 6', 480, 360, [200, 185, 150]), $clerk);
        $intake->addMark($g5, ['kind' => 'stain', 'label' => '下摆泥点', 'x_permille' => 450, 'y_permille' => 900], $clerk);
        $intake->submitForConfirmation($o5, $clerk);
        $intake->confirmByCustomer($o5->fresh(), '郑女士', $clerk);
        $wash->startWashing($g5->fresh(), $washer);
        $wash->markWashed($g5->fresh(), $washer);
        $photos->store($g5->fresh(), GarmentPhoto::STAGE_COMPLETION, $this->fakeImage('done 6 - tear?', 480, 360, [195, 180, 145]), $clerk);

        $dispute = Dispute::create([
            'order_id' => $o5->id, 'garment_id' => $g5->id,
            'type' => Dispute::TYPE_NEW_DAMAGE,
            'description' => '对比预检照片，左袖内衬新增长约 3cm 勾破，预检时无此破损。',
            'requested_amount' => 8000, 'status' => Dispute::STATUS_RECHECKING,
            'opened_by' => $clerk->id,
        ]);
        $g5->fresh()->update(['status' => Garment::STATUS_RECHECKING]);
        DisputeReview::create([
            'dispute_id' => $dispute->id, 'reviewer_id' => $manager->id,
            'result' => DisputeReview::RESULT_CONFIRMED,
            'finding' => '复检比对原始照片，确认左袖内衬勾破为洗护后新增，门店承担责任。',
        ]);
        $dispute->update(['status' => Dispute::STATUS_NEGOTIATING]);

        $comp = Compensation::create([
            'dispute_id' => $dispute->id, 'garment_id' => $g5->id, 'order_id' => $o5->id,
            'amount' => 5000, 'status' => Compensation::STATUS_PAID,
            'requested_by' => $clerk->id, 'approved_by' => $manager->id,
            'approved_at' => now(), 'approval_note' => '与顾客协商按 ¥50 赔付',
            'paid_by' => $clerk->id, 'paid_at' => now(),
        ]);
        CompensationReceipt::create([
            'compensation_id' => $comp->id,
            'receipt_no' => 'TXNSEED000001',
            'idempotency_key' => 'comp-'.$comp->id,
            'gateway' => 'mock-gateway', 'gateway_status' => 'success',
            'payload' => ['payment_ref' => 'COMP-'.str_pad((string) $comp->id, 8, '0', STR_PAD_LEFT), 'amount' => 5000, 'seed' => true],
        ]);
        $dispute->update([
            'status' => Dispute::STATUS_RESOLVED, 'closed_by' => $manager->id,
            'closed_at' => now(), 'resolution_note' => '已赔付 5000 分，回执号 TXNSEED000001',
        ]);
        $g5->fresh()->update(['status' => Garment::STATUS_INSPECTION_PASSED, 'inspected_at' => now(), 'inspected_by' => $manager->id]);
    }

    /**
     * 用 GD 生成一张占位证据图（本地文件，无需联网）。
     */
    private function fakeImage(string $label, int $w, int $h, array $bg): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        $bgc = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
        imagefill($img, 0, 0, $bgc);
        // 模拟衣物轮廓
        $shape = imagecolorallocatealpha($img, 255, 255, 255, 110);
        imagefilledrectangle($img, (int) ($w * 0.25), (int) ($h * 0.12), (int) ($w * 0.75), (int) ($h * 0.9), $shape);
        imagerectangle($img, (int) ($w * 0.25), (int) ($h * 0.12), (int) ($w * 0.75), (int) ($h * 0.9), $shape);
        $txt = imagecolorallocate($img, 30, 30, 30);
        imagestring($img, 5, 12, 12, $label, $txt);
        imagestring($img, 3, 12, 34, now()->format('Y-m-d H:i:s'), $txt);

        $dir = sys_get_temp_dir().'/seed-img';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.Str::random(12).'.jpg';
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }
}
