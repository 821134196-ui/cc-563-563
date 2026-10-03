<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationReceipt extends Model
{
    protected $fillable = [
        'compensation_id', 'receipt_no', 'idempotency_key',
        'gateway', 'gateway_status', 'payload', 'recorded_by',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(Compensation::class);
    }
}
