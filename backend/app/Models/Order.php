<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_INTAKE = 'intake';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_IN_CARE = 'in_care';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    public const PAUSE_CUSTOMER_REFUSED = 'customer_refused_sign';
    public const PAUSE_NO_WATER_WASH = 'label_forbids_water_wash';
    public const PAUSE_POCKET_ITEMS = 'pocket_items_found';

    protected $fillable = [
        'code',
        'customer_name',
        'customer_phone',
        'status',
        'pause_reason',
        'pause_note',
        'resume_note',
        'paused_at',
        'confirmed_at',
        'created_by',
    ];

    protected $casts = [
        'paused_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function garments(): HasMany
    {
        return $this->hasMany(Garment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }
}
