<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockGatewayTransaction extends Model
{
    protected $table = 'mock_gateway_transactions';

    protected $fillable = [
        'transaction_no', 'payment_ref', 'idempotency_key',
        'amount', 'currency', 'status', 'callback_count', 'raw',
    ];

    protected $casts = [
        'raw' => 'array',
        'callback_count' => 'integer',
        'amount' => 'integer',
    ];
}
