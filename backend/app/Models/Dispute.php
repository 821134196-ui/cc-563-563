<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dispute extends Model
{
    public const TYPE_NEW_DAMAGE = 'new_damage';
    public const TYPE_NEW_STAIN = 'new_stain';
    public const TYPE_MISSING = 'missing';
    public const TYPE_OTHER = 'other';

    public const STATUS_OPEN = 'open';
    public const STATUS_RECHECKING = 'rechecking';
    public const STATUS_NEGOTIATING = 'negotiating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'order_id', 'garment_id', 'type', 'description', 'requested_amount',
        'status', 'opened_by', 'closed_by', 'closed_at', 'resolution_note',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'requested_amount' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DisputeReview::class);
    }

    public function compensation(): HasOne
    {
        return $this->hasOne(Compensation::class);
    }
}
