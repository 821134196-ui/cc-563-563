<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutReceipt extends Model
{
    protected $fillable = [
        'compensation_id',
        'receipt_no',
        'idempotency_key',
        'amount_cents',
        'gateway',
        'status',
        'response_payload',
        'created_by',
        'paid_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(CompensationRequest::class, 'compensation_id');
    }
}
