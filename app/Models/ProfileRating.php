<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Денормалізований рейтинг + популярність + стан бейджа профілю.
 * Заповнюється батчем (App\Services\Rating\*). Сторінки читають звідси.
 */
class ProfileRating extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'float',
        'raw_average' => 'float',
        'weighted_average' => 'float',
        'bayesian_rating' => 'float',
        'reviews_total' => 'integer',
        'reviews_counted' => 'integer',
        'reviews_own' => 'integer',
        'reviews_confirmed' => 'integer',
        'reviews_imported' => 'integer',
        'reviews_last_90d' => 'integer',
        'effective_weight_sum' => 'float',
        'dist_1' => 'integer',
        'dist_2' => 'integer',
        'dist_3' => 'integer',
        'dist_4' => 'integer',
        'dist_5' => 'integer',
        'distribution_entropy' => 'float',
        'popularity_score' => 'float',
        'completeness_factor' => 'float',
        'engagement_factor' => 'float',
        'responsiveness_factor' => 'float',
        'is_recommended' => 'boolean',
        'recommended_since' => 'datetime',
        'recommended_lost_at' => 'datetime',
        'badge_at_risk' => 'boolean',
        'badge_at_risk_since' => 'datetime',
        'badge_failed_criteria' => 'array',
        'is_rating_reliable' => 'boolean',
        'calculation_breakdown' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
