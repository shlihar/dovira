<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Агрегати по категорії. mean_rating = p у байєсівському згладжуванні.
 */
class CategoryStats extends Model
{
    protected $table = 'category_stats';

    protected $guarded = [];

    protected $casts = [
        'mean_rating' => 'float',
        'median_reviews' => 'integer',
        'profiles_count' => 'integer',
        'reviews_count' => 'integer',
        'calculated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
