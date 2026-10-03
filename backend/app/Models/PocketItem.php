<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PocketItem extends Model
{
    public const STATUS_FOUND = 'found';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'garment_id', 'description', 'status', 'note',
        'found_by', 'returned_by', 'returned_at',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }
}
