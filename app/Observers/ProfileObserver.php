<?php

namespace App\Observers;

use App\Jobs\RefreshHomePageCache;
use App\Jobs\RefreshProfileReviewAiAnalysis;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ProfileObserver
{
    public function created(Profile $profile): void
    {
        if ($this->isPublished($profile)) {
            $this->invalidatePublicProfileCachesAfterCommit();
            $this->refreshReviewAnalysisAfterCommit($profile);
        }
    }

    public function updated(Profile $profile): void
    {
        $publicFieldsTouched = $profile->wasChanged([
            'status',
            'is_published',
            'show_in_catalog',
            'city',
            'region_id',
            'name',
            'reviews_count',
            'rating_avg',
            'is_verified',
            'is_owner_verified',
            'is_pro',
            'popularity_score',
            'views_count',
            'unique_views_count',
            'contact_clicks_count',
            'website_clicks_count',
            'dovira_recommendation_status',
        ]);

        if ($publicFieldsTouched && ($this->isPublished($profile) || $this->wasPublishedBefore($profile))) {
            $this->invalidatePublicProfileCachesAfterCommit();
        }

        if (! $this->isPublished($profile)) {
            return;
        }

        $becamePublished = ! $this->wasPublishedBefore($profile);
        $publishFieldsTouched = $profile->wasChanged(['status', 'is_published', 'show_in_catalog']);

        if ($becamePublished || $publishFieldsTouched) {
            $this->refreshReviewAnalysisAfterCommit($profile);
        }
    }

    public function deleted(Profile $profile): void
    {
        if ($this->isPublished($profile) || $this->wasPublishedBefore($profile)) {
            $this->invalidatePublicProfileCachesAfterCommit();
        }
    }

    private function invalidatePublicProfileCachesAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            Cache::forget('public:catalog:stats:v1');
            Cache::forget('public:catalog:category-filters:v1');
            Cache::forget('public:catalog:category-options:v1');
            Cache::forget('public:catalog:regions:v2');

            RefreshHomePageCache::dispatch()->afterCommit();
        });
    }

    private function refreshReviewAnalysisAfterCommit(Profile $profile): void
    {
        $profileId = (int) $profile->id;

        DB::afterCommit(function () use ($profileId): void {
            RefreshProfileReviewAiAnalysis::dispatch($profileId)->afterCommit();
        });
    }

    private function isPublished(Profile $profile): bool
    {
        return (string) $profile->status === 'active'
            && (bool) $profile->is_published
            && (bool) $profile->show_in_catalog;
    }

    private function wasPublishedBefore(Profile $profile): bool
    {
        return (string) $profile->getOriginal('status') === 'active'
            && (bool) $profile->getOriginal('is_published')
            && (bool) $profile->getOriginal('show_in_catalog');
    }
}
