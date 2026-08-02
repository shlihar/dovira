<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePageEvent extends Model
{
    protected $fillable = [
        'user_id',
        'visitor_id',
        'event_type',
        'event_label',
        'page_path',
        'page_url',
        'source',
        'internal_source',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'device_type',
        'country',
        'city',
        'ip_hash',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

