<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmsSection extends Model
{
    protected $fillable = [
        'page_key',
        'section_key',
        'title',
        'subtitle',
        'cards',
        'button_text',
        'button_link',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'cards' => 'array',
        'is_active' => 'boolean',
    ];
}
