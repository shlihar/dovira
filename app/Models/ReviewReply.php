<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewReply extends Model
{
    protected $fillable = [
        'profile_review_id',
        'profile_id',
        'author_user_id',
        'parent_id',
        'body',
        'is_official',
        'status',
        'like_count',
        'dislike_count',
        'is_edited',
        'edited_at',
    ];

    protected $casts = [
        'is_official' => 'boolean',
        'like_count' => 'integer',
        'dislike_count' => 'integer',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProfileReview::class, 'profile_review_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ReviewReplyReaction::class);
    }
}
