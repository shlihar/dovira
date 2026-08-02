<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEnrichmentTask extends Model
{
    protected $fillable = [
        'batch_id',
        'profile_id',
        'category_id',
        'row_number',
        'raw_name',
        'raw_city',
        'raw_phone',
        'raw_website',
        'raw_source_url',
        'raw_payload',
        'status',
        'confidence_score',
        'suggested_data',
        'duplicate_candidates',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'raw_payload' => 'array',
        'confidence_score' => 'integer',
        'suggested_data' => 'array',
        'duplicate_candidates' => 'array',
        'processed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiEnrichmentBatch::class, 'batch_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function dataSources(): HasMany
    {
        return $this->hasMany(ProfileDataSource::class);
    }

    public function externalMentions(): HasMany
    {
        return $this->hasMany(ExternalProfileMention::class);
    }
}
