<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeReview extends Model
{
    public const RESULT_CONFIRMED = 'confirmed';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_INCONCLUSIVE = 'inconclusive';

    protected $fillable = [
        'dispute_id', 'reviewer_id', 'result', 'finding', 'evidence_photo_ids',
    ];

    protected $casts = [
        'evidence_photo_ids' => 'array',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
