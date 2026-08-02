<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalProfileMention extends Model
{
    protected $fillable = [
        'profile_id',
        'ai_enrichment_task_id',
        'source_type',
        'title',
        'url',
        'external_rating',
        'external_reviews_count',
        'sentiment',
        'topic',
        'summary',
        'confidence_score',
        'status',
        'raw_payload',
    ];

    protected $casts = [
        'external_rating' => 'decimal:2',
        'external_reviews_count' => 'integer',
        'confidence_score' => 'integer',
        'raw_payload' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AiEnrichmentTask::class, 'ai_enrichment_task_id');
    }
}
