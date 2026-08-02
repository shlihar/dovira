<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileLead extends Model
{
    protected $fillable = [
        'profile_id', 'name', 'phone', 'message', 'source', 'status', 'is_read', 'ip_hash',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
