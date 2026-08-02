<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReplyReaction extends Model
{
    protected $fillable = [
        'review_reply_id',
        'user_id',
        'reaction',
    ];

    public function reply(): BelongsTo
    {
        return $this->belongsTo(ReviewReply::class, 'review_reply_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
