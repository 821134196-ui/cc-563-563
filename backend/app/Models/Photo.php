<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    public const STAGE_INTAKE = 'intake';
    public const STAGE_POSTWASH = 'postwash';
    public const STAGE_RECHECK = 'recheck';

    protected $fillable = [
        'garment_id',
        'stage',
        'disk_path',
        'original_name',
        'sha256',
        'bytes',
        'mime',
        'uploaded_by',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }

    /**
     * 原始预检照片是否已锁定：顾客确认后任何写入接口都不得替换/删除它。
     */
    public function isIntakeLocked(): bool
    {
        return $this->stage === self::STAGE_INTAKE
            && $this->garment?->intake_locked_at !== null;
    }
}
