<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialReplyReaction extends Model
{
    protected $fillable = [
        'official_reply_id',
        'user_id',
        'reaction',
    ];

    public function officialReply(): BelongsTo
    {
        return $this->belongsTo(OfficialReply::class, 'official_reply_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
