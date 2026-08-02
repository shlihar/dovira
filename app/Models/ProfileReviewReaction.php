<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileReviewReaction extends Model
{
    protected $fillable = [
        'profile_review_id',
        'user_id',
        'reaction',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProfileReview::class, 'profile_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
