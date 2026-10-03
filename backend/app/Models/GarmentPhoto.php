<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentPhoto extends Model
{
    public const STAGE_INTAKE = 'intake';
    public const STAGE_COMPLETION = 'completion';
    public const STAGE_DISPUTE = 'dispute';

    public const STAGES = [
        self::STAGE_INTAKE => '预检照片',
        self::STAGE_COMPLETION => '完检照片',
        self::STAGE_DISPUTE => '争议照片',
    ];

    protected $fillable = [
        'garment_id', 'stage', 'path', 'filename', 'checksum',
        'size_bytes', 'mime_type', 'uploaded_by',
    ];

    protected $hidden = ['path'];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
