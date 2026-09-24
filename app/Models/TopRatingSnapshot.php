<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopRatingSnapshot extends Model
{
    protected $fillable = [
        'period',
        'category_slug',
        'city_slug',
        'profile_id',
        'position',
        'index_score',
        'components',
        'rating',
        'reviews_count',
        'reviews_last_90d',
    ];

    protected $casts = [
        'components' => 'array',
        'index_score' => 'float',
        'rating' => 'float',
        'position' => 'integer',
        'reviews_count' => 'integer',
        'reviews_last_90d' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Поточний період у форматі 2026-09. */
    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    public static function previousPeriod(string $period): string
    {
        return \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $period . '-01')
            ->subMonth()
            ->format('Y-m');
    }
}
