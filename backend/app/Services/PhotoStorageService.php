<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 证据照片存储：
 * - 内容寻址（sha256 文件名），物理上不存在“覆盖原文件”的路径；
 * - 落盘后设置为只读；
 * - 预检照片仅允许在顾客确认前写入，锁定后一律拒绝；
 * - (garment_id, stage, checksum) 唯一，重复上传同文件被拦截。
 */
class PhotoStorageService
{
    public function store(Garment $garment, string $stage, UploadedFile $file, User $uploader): GarmentPhoto
    {
        if (! array_key_exists($stage, GarmentPhoto::STAGES)) {
            throw new DomainException('未知的照片阶段', 'unknown_stage', 422);
        }

        if ($stage === GarmentPhoto::STAGE_INTAKE && $garment->isIntakeLocked()) {
            throw new DomainException(
                '预检已被顾客确认锁定，原始预检照片不得追加、替换或删除',
                'intake_evidence_locked',
                409,
            );
        }

        if ($stage === GarmentPhoto::STAGE_INTAKE
            && ! in_array($garment->status, [Garment::STATUS_INTAKE, Garment::STATUS_CONFIRMED], true)) {
            throw new DomainException('当前衣物状态不允许补充预检照片', 'intake_photo_not_allowed', 409);
        }

        if ($stage === GarmentPhoto::STAGE_COMPLETION
            && $garment->status !== Garment::STATUS_READY_INSPECTION) {
            throw new DomainException('只有待完检衣物可以上传完检照片', 'completion_photo_not_allowed', 409);
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($garment, $stage, $file, $uploader, $checksum) {
            $exists = GarmentPhoto::where('garment_id', $garment->id)
                ->where('stage', $stage)
                ->where('checksum', $checksum)
                ->exists();
            if ($exists) {
                throw new DomainException('该照片已作为证据存在，禁止重复上传', 'duplicate_photo', 409);
            }

            // 内容寻址：目录按阶段+衣物划分，文件名用校验和，写入绝不覆盖已有路径
            $relativeDir = sprintf('evidence/%s/garment-%d', $stage, $garment->id);
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            if (! preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
                $extension = 'bin';
            }
            $filename = sprintf('%s.%s', $checksum, $extension);
            $absoluteDir = storage_path('app/private/'.$relativeDir);
            if (! is_dir($absoluteDir)) {
                mkdir($absoluteDir, 0755, true);
            }
            $absolutePath = $absoluteDir.'/'.$filename;

            $clientName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();

            if (file_exists($absolutePath)) {
                // 同校验和文件已在磁盘（其它衣物的相同文件）：直接复用，不重写
                if (! hash_equals($checksum, hash_file('sha256', $absolutePath))) {
                    throw new DomainException('证据文件校验冲突', 'checksum_collision', 409);
                }
            } else {
                $file->move($absoluteDir, $filename);
                // 落盘即只读，防止事后篡改/替换
                @chmod($absolutePath, 0444);
            }

            try {
                return GarmentPhoto::create([
                    'garment_id' => $garment->id,
                    'stage' => $stage,
                    'path' => $relativeDir.'/'.$filename,
                    'filename' => $clientName,
                    'checksum' => $checksum,
                    'size_bytes' => filesize($absolutePath),
                    'mime_type' => $mimeType,
                    'uploaded_by' => $uploader->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // 并发下唯一索引兜底
                throw new DomainException('该照片已作为证据存在，禁止重复上传', 'duplicate_photo', 409);
            }
        });
    }

    public static function verifyFileChecksum(GarmentPhoto $photo): bool
    {
        $absolute = storage_path('app/private/'.$photo->path);
        return is_file($absolute) && hash_equals($photo->checksum, hash_file('sha256', $absolute));
    }
}
