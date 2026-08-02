<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficialReply extends Model
{
    protected $fillable = [
        'profile_review_id',
        'profile_id',
        'author_user_id',
        'body',
        'like_count',
        'dislike_count',
        'is_edited',
        'edited_at',
    ];

    protected $casts = [
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

    public function reactions(): HasMany
    {
        return $this->hasMany(OfficialReplyReaction::class);
    }
}
