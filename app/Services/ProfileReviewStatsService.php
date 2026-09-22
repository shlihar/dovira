<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileReview;

class ProfileReviewStatsService
{
    public function recalculateForProfileId(int $profileId): void
    {
        $profile = Profile::query()->find($profileId);

        if (! $profile) {
            return;
        }

        $publishedQuery = ProfileReview::query()
            ->where('profile_id', $profileId)
            ->where('status', 'published');

        $reviewsCount = (int) (clone $publishedQuery)->count();
        $ratingAvg = (float) ((clone $publishedQuery)->avg('rating') ?? 0);

        $profile->forceFill([
            'reviews_count' => $reviewsCount,
            'rating_avg' => round($ratingAvg, 2),
        ])->save();

        // popularity_score перераховує лише рейтингова система (нічний
        // rating:recalculate) — тут його не чіпаємо, щоб стара формула не
        // затирала зважений розрахунок.
    }
}

