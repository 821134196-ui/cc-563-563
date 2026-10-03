<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentMark extends Model
{
    public const KIND_STAIN = 'stain';
    public const KIND_DAMAGE = 'damage';

    protected $fillable = [
        'garment_id', 'kind', 'label', 'x_permille', 'y_permille',
        'description', 'created_by',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }
}
