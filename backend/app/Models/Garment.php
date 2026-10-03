<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Garment extends Model
{
    public const STATUS_RECORDED = 'recorded';   // 预检录入中
    public const STATUS_CONFIRMED = 'confirmed'; // 顾客已确认、证据锁定
    public const STATUS_WASHING = 'washing';
    public const STATUS_WASHED = 'washed';       // 洗护完成待检
    public const STATUS_DISPUTED = 'disputed';   // 有差异争议
    public const STATUS_DONE = 'done';           // 核对无误/争议已了结

    protected $fillable = [
        'order_id',
        'tag',
        'category',
        'color',
        'description',
        'care_labels',
        'pocket_items_found',
        'pocket_items_note',
        'status',
        'washer_id',
        'intake_locked_at',
    ];

    protected $casts = [
        'care_labels' => 'array',
        'pocket_items_found' => 'boolean',
        'intake_locked_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function washer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'washer_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function intakePhotos(): HasMany
    {
        return $this->photos()->where('stage', Photo::STAGE_INTAKE);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }
}
