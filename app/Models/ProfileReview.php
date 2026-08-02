<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProfileReview extends Model
{
    protected $fillable = [
        'profile_id',
        'user_id',
        'author_name',
        'author_email',
        'author_phone',
        'author_ip',
        'author_user_agent',
        'risk_score',
        'rating',
        'title',
        'body',
        'pros',
        'cons',
        'interaction_date',
        'media',
        'status',
        'is_anonymous',
        'is_suspicious',
        'is_featured',
        'verification_type',
        'moderation_reason',
        'admin_note',
        'moderation_note',
        'is_verified_purchase',
        'helpful_count',
        'like_count',
        'dislike_count',
        'published_at',
        'external_source_url',
        'external_review_hash',
        'external_source_type',
        'external_review_author',
        'external_review_author_avatar_url',
        'external_review_date',
    ];

    protected $casts = [
        'rating' => 'integer',
        'risk_score' => 'decimal:2',
        'media' => 'array',
        'interaction_date' => 'date',
        'is_anonymous' => 'boolean',
        'is_suspicious' => 'boolean',
        'is_featured' => 'boolean',
        'is_verified_purchase' => 'boolean',
        'helpful_count' => 'integer',
        'like_count' => 'integer',
        'dislike_count' => 'integer',
        'published_at' => 'datetime',
        'external_review_date' => 'date',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function officialReply(): HasOne
    {
        return $this->hasOne(OfficialReply::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ReviewReply::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ProfileReviewReaction::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function getResolvedExternalSourceUrlAttribute(): ?string
    {
        $direct = $this->normalizeUrl((string) ($this->external_source_url ?? ''));
        if ($direct !== null) {
            return $direct;
        }

        $notes = implode("\n", array_filter([
            (string) ($this->admin_note ?? ''),
            (string) ($this->moderation_note ?? ''),
        ]));

        if ($notes === '') {
            return null;
        }

        if (preg_match('#https?://[^\s<>"\')]+#iu', $notes, $m)) {
            return $this->normalizeUrl((string) ($m[0] ?? ''));
        }

        return null;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
