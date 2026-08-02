<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryFilter extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'type',
        'options',
        'unit',
        'is_active',
        'is_required',
        'show_in_catalog',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_active' => 'boolean',
        'is_required' => 'boolean',
        'show_in_catalog' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
