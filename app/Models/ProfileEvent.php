<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileEvent extends Model
{
    protected $fillable = [
        'profile_id',
        'user_id',
        'visitor_id',
        'event_type',
        'source',
        'internal_source',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'target_url',
        'device_type',
        'country',
        'city',
        'ip_hash',
        'user_agent',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

