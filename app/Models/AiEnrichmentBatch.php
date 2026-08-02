<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEnrichmentBatch extends Model
{
    protected $fillable = [
        'user_id',
        'default_category_id',
        'name',
        'source_type',
        'status',
        'default_city',
        'default_country',
        'language',
        'options',
        'input_text',
        'file_path',
        'total_items',
        'drafts_created',
        'profiles_updated',
        'duplicates_found',
        'errors_count',
        'needs_review_count',
        'sources_found',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'total_items' => 'integer',
        'drafts_created' => 'integer',
        'profiles_updated' => 'integer',
        'duplicates_found' => 'integer',
        'errors_count' => 'integer',
        'needs_review_count' => 'integer',
        'sources_found' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AiEnrichmentTask::class, 'batch_id');
    }

    public function getTotalRowsAttribute(): int
    {
        return (int) $this->total_items;
    }

    public function getCreatedProfilesAttribute(): int
    {
        return (int) $this->drafts_created;
    }

    public function getUpdatedProfilesAttribute(): int
    {
        return (int) $this->profiles_updated;
    }

    public function getNeedsReviewAttribute(): int
    {
        return (int) $this->needs_review_count;
    }
}
