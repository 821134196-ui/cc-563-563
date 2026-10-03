<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_INTAKE = 'intake';
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_IN_WASH = 'in_wash';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAUSE_REFUSED_SIGNATURE = 'refused_signature';
    public const PAUSE_NO_WASH_LABEL = 'no_wash_label';
    public const PAUSE_POCKET_ITEM = 'pocket_item';

    public const PAUSE_REASONS = [
        self::PAUSE_REFUSED_SIGNATURE => '顾客拒签',
        self::PAUSE_NO_WASH_LABEL => '护理标签禁止水洗',
        self::PAUSE_POCKET_ITEM => '发现口袋物品',
    ];

    protected $fillable = [
        'order_no', 'customer_name', 'customer_phone', 'status', 'status_before_pause',
        'pause_reason', 'pause_note', 'paused_by', 'paused_at', 'resolved_at',
        'signature_name', 'confirmed_at', 'confirmed_by', 'completed_at',
    ];

    protected $casts = [
        'paused_at' => 'datetime',
        'resolved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function garments(): HasMany
    {
        return $this->hasMany(Garment::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }
}
