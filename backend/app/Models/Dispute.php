<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dispute extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RECHECKING = 'rechecking';
    public const STATUS_NEGOTIATING = 'negotiating';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';
    public const STATUS_CLOSED = 'closed';

    public const RESP_WASHER = 'washer';
    public const RESP_CUSTOMER = 'customer';
    public const RESP_STORE = 'store';
    public const RESP_UNKNOWN = 'unknown';

    protected $fillable = [
        'garment_id',
        'inspection_id',
        'opened_by',
        'description',
        'suspected_responsibility',
        'status',
        'resolution',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisputeNote::class);
    }

    public function compensation(): HasOne
    {
        return $this->hasOne(CompensationRequest::class);
    }
}
