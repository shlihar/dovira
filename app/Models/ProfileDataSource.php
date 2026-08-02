<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileDataSource extends Model
{
    protected $fillable = [
        'profile_id',
        'ai_enrichment_task_id',
        'source_type',
        'title',
        'url',
        'found_fields',
        'confidence_score',
        'fetched_at',
        'status',
    ];

    protected $casts = [
        'found_fields' => 'array',
        'confidence_score' => 'integer',
        'fetched_at' => 'datetime',
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
