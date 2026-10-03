<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inspection extends Model
{
    public const KIND_POSTWASH = 'postwash';
    public const KIND_RECHECK = 'recheck';

    public const RESULT_CONSISTENT = 'consistent';
    public const RESULT_DISCREPANCY = 'discrepancy';

    protected $fillable = [
        'garment_id',
        'kind',
        'inspector_id',
        'result',
        'notes',
    ];

    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
