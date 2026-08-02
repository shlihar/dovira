<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CategoryService extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'is_active',
        'show_in_catalog',
        'sort_order',
        'profiles_count',
        'reviews_count',
        'views_count',
        'pro_profiles_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_catalog' => 'boolean',
        'sort_order' => 'integer',
        'profiles_count' => 'integer',
        'reviews_count' => 'integer',
        'views_count' => 'integer',
        'pro_profiles_count' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_category_service')
            ->withTimestamps();
    }
}

