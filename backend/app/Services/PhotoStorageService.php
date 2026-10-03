<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Garment;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoStorageService
{
    public const DISK = 'local';

    /**
     * 保存一张照片。内容寻址（文件名取 sha256），同一内容只落一份文件；
     * 预检照片写入后设为只读，任何接口都不存在替换/删除它的路径。
     */
    public function store(UploadedFile $file, Garment $garment, string $stage, ?User $uploader): Photo
    {
        if (!in_array($stage, [Photo::STAGE_INTAKE, Photo::STAGE_POSTWASH, Photo::STAGE_RECHECK], true)) {
            throw new DomainRuleException('invalid_photo_stage', '未知的照片阶段');
        }

        if ($stage === Photo::STAGE_INTAKE && $garment->intake_locked_at !== null) {
            // 顾客已确认 → 原始预检证据冻结
            throw new DomainRuleException(
                'intake_evidence_locked',
                '顾客已确认，原始预检照片不得再追加或替换',
                409,
            );
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            $ext = 'bin';
        }
        $relative = sprintf('photos/%d/%s/%s.%s', $garment->id, $stage, $hash, $ext);

        $disk = Storage::disk(self::DISK);
        if (!$disk->exists($relative)) {
            $disk->put($relative, file_get_contents($file->getRealPath()));
            if ($stage === Photo::STAGE_INTAKE) {
                // 只读是第二道防线：即便绕过应用层，原始文件也无法被覆盖
                @chmod($disk->path($relative), 0444);
            }
        }

        // 幂等：同件衣物、同一阶段、同一内容只保留一条照片记录（防止双击/重试产生重复证据）
        $existing = Photo::where('garment_id', $garment->id)
            ->where('stage', $stage)
            ->where('sha256', $hash)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Photo::create([
                'garment_id' => $garment->id,
                'stage' => $stage,
                'disk_path' => $relative,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
                'sha256' => $hash,
                'bytes' => $disk->size($relative),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'uploaded_by' => $uploader?->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // photos_intake_hash_unique：同一张照片不能用于第二件衣物入库
            if ($stage === Photo::STAGE_INTAKE && $this->isUniqueViolation($e)) {
                throw new DomainRuleException(
                    'duplicate_intake_photo',
                    '该照片已用于其他衣物的入库预检，同一件衣物不能重复入库',
                    409,
                );
            }
            throw $e;
        }
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'photos_intake_hash_unique')
            || str_contains($e->getMessage(), 'UNIQUE constraint');
    }
}
