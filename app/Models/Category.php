<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'short_description',
        'icon',
        'image',
        'cover_image',
        'status',
        'show_on_homepage',
        'show_in_menu',
        'show_in_footer',
        'show_in_catalog',
        'is_indexable',
        'pro_enabled',
        'pro_price',
        'is_active',
        'sort_order',
        'seo_title',
        'seo_description',
        'seo_h1',
        'seo_text',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'profiles_count',
        'reviews_count',
        'views_count',
        'pro_profiles_count',
        'popularity_score',
    ];

    protected $casts = [
        'status' => 'string',
        'show_on_homepage' => 'boolean',
        'show_in_menu' => 'boolean',
        'show_in_footer' => 'boolean',
        'show_in_catalog' => 'boolean',
        'is_indexable' => 'boolean',
        'pro_enabled' => 'boolean',
        'pro_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'profiles_count' => 'integer',
        'reviews_count' => 'integer',
        'views_count' => 'integer',
        'pro_profiles_count' => 'integer',
        'popularity_score' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            // Пробіли по краях/подвійні пробіли ламали сортування й вигляд
            // фільтрів («  Медицина…» ставала першою в списку).
            if (is_string($category->name)) {
                $category->name = preg_replace('/\s+/u', ' ', trim($category->name));
            }

            if (filled($category->status)) {
                $category->is_active = $category->status === 'active';
            } elseif ($category->is_active !== null) {
                $category->status = $category->is_active ? 'active' : 'inactive';
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_category')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function filters(): HasMany
    {
        return $this->hasMany(CategoryFilter::class)->orderBy('sort_order')->orderBy('name');
    }

    public function services(): HasMany
    {
        return $this->hasMany(CategoryService::class)->orderBy('sort_order')->orderBy('name');
    }
}
