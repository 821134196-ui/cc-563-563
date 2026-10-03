<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Garment extends Model
{
    public const STATUS_INTAKE = 'intake';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WASHING = 'washing';
    public const STATUS_READY_INSPECTION = 'ready_inspection';
    public const STATUS_INSPECTION_PASSED = 'inspection_passed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_RECHECKING = 'rechecking';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'order_id', 'tag_no', 'category', 'color', 'brand',
        'care_labels', 'description', 'status',
        'washer_id', 'wash_started_at', 'inspected_at', 'inspected_by',
        'intake_locked_at',
    ];

    protected $casts = [
        'care_labels' => 'array',
        'wash_started_at' => 'datetime',
        'inspected_at' => 'datetime',
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

    public function marks(): HasMany
    {
        return $this->hasMany(GarmentMark::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(GarmentPhoto::class);
    }

    public function intakePhotos(): HasMany
    {
        return $this->photos()->where('stage', GarmentPhoto::STAGE_INTAKE);
    }

    public function completionPhotos(): HasMany
    {
        return $this->photos()->where('stage', GarmentPhoto::STAGE_COMPLETION);
    }

    public function pocketItems(): HasMany
    {
        return $this->hasMany(PocketItem::class);
    }

    public function openPocketItems(): HasMany
    {
        return $this->pocketItems()->where('status', PocketItem::STATUS_FOUND);
    }

    public function isIntakeLocked(): bool
    {
        return $this->intake_locked_at !== null;
    }

    public function hasNoWashLabel(): bool
    {
        return in_array('no_wash', $this->care_labels ?? [], true);
    }
}
