<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'subject', 'body_html', 'body_text', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
