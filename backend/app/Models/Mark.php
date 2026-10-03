<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends Model
{
    public const KIND_STAIN = 'stain';
    public const KIND_DAMAGE = 'damage';

    public const STAGE_INTAKE = 'intake';
    public const STAGE_POSTWASH = 'postwash';
    public const STAGE_RECHECK = 'recheck';

    protected $fillable = [
        'garment_id',
        'kind',
        'location',
        'description',
        'stage',
        'created_by',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }
}
