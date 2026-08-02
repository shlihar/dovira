<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\ProfileReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CategoryAnalyticsService
{
    public function resolvePeriod(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        return app(ProfileAnalyticsService::class)->resolvePeriod($period, $customFrom, $customTo);
    }

    /**
     * @return array<int, int>
     */
    public function categoryAndChildrenIds(Category $category): array
    {
        $ids = [$category->id];
        $childIds = $category->children()->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($ids, $childIds))));
    }

    public function analyticsSummary(Category $category, string $period = 'last_7', ?string $from = null, ?string $to = null): array
    {
        [$fromAt, $toAt] = $this->resolvePeriod($period, $from, $to);
        $categoryIds = $this->categoryAndChildrenIds($category);

        $profileIds = Profile::query()
            ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds))
            ->pluck('profiles.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $totals = [
            'profiles' => $this->totalProfiles($profileIds),
            'pro_profiles' => $this->totalProProfiles($profileIds),
            'reviews' => $this->totalReviews($profileIds),
            'views' => $this->totalViews($profileIds),
        ];

        $metrics = [
            'views' => $this->eventMetricTrend($profileIds, [ProfileAnalyticsService::EVENT_PROFILE_VIEW], $fromAt, $toAt),
            'reviews' => $this->reviewsMetricTrend($profileIds, $fromAt, $toAt),
            'profiles' => $this->profilesMetricTrend($categoryIds, $fromAt, $toAt),
            'pro_profiles' => $this->proProfilesMetricTrend($categoryIds, $fromAt, $toAt),
        ];

        return [
            'totals' => $totals,
            'metrics' => $metrics,
        ];
    }

    private function totalProfiles(array $profileIds): int
    {
        return count($profileIds);
    }

    private function totalProProfiles(array $profileIds): int
    {
        if (blank($profileIds)) {
            return 0;
        }

        return Profile::query()
            ->whereIn('id', $profileIds)
            ->where('is_pro', true)
            ->count();
    }

    private function totalReviews(array $profileIds): int
    {
        if (blank($profileIds)) {
            return 0;
        }

        return ProfileReview::query()
            ->whereIn('profile_id', $profileIds)
            ->where('status', 'published')
            ->count();
    }

    private function totalViews(array $profileIds): int
    {
        if (blank($profileIds)) {
            return 0;
        }

        return (int) Profile::query()
            ->whereIn('id', $profileIds)
            ->sum('views_count');
    }

    private function eventMetricTrend(array $profileIds, array $eventTypes, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        if (blank($profileIds)) {
            return $this->emptyTrend();
        }

        $base = ProfileEvent::query()
            ->whereIn('profile_id', $profileIds)
            ->whereIn('event_type', $eventTypes);

        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($fromAt, $toAt);

        $current = (clone $base)->whereBetween('created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $sparkline = $this->sparklineByDays(clone $base, $fromAt, $toAt);

        return $this->makeTrend((float) $current, (float) $previous, $sparkline);
    }

    private function reviewsMetricTrend(array $profileIds, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        if (blank($profileIds)) {
            return $this->emptyTrend();
        }

        $base = ProfileReview::query()
            ->whereIn('profile_id', $profileIds)
            ->where('status', 'published');

        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($fromAt, $toAt);

        $current = (clone $base)->whereBetween('created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $sparkline = $this->sparklineByDays(clone $base, $fromAt, $toAt);

        return $this->makeTrend((float) $current, (float) $previous, $sparkline);
    }

    private function profilesMetricTrend(array $categoryIds, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        $base = Profile::query()
            ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds));

        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($fromAt, $toAt);

        $current = (clone $base)->whereBetween('profiles.created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('profiles.created_at', [$previousStart, $previousEnd])->count();
        $sparkline = $this->sparklineByDays(clone $base, $fromAt, $toAt, 'profiles.created_at');

        return $this->makeTrend((float) $current, (float) $previous, $sparkline);
    }

    private function proProfilesMetricTrend(array $categoryIds, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        $base = Profile::query()
            ->where('is_pro', true)
            ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds));

        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($fromAt, $toAt);

        $current = (clone $base)->whereBetween('profiles.created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('profiles.created_at', [$previousStart, $previousEnd])->count();
        $sparkline = $this->sparklineByDays(clone $base, $fromAt, $toAt, 'profiles.created_at');

        return $this->makeTrend((float) $current, (float) $previous, $sparkline);
    }

    private function resolveRange(?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        if (! $fromAt || ! $toAt) {
            $toAt = CarbonImmutable::now()->endOfDay();
            $fromAt = $toAt->subDays(6)->startOfDay();
        }

        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        $previousEnd = $fromAt->subSecond();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return [$fromAt, $toAt, $previousStart, $previousEnd];
    }

    private function makeTrend(float $current, float $previous, array $sparkline): array
    {
        $diff = $current - $previous;
        $diffPercent = $previous > 0 ? ($diff / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);

        return [
            'is_up' => $diff >= 0,
            'diff_percent' => round($diffPercent, 1),
            'current' => $current,
            'previous' => $previous,
            'sparkline' => $sparkline,
        ];
    }

    private function emptyTrend(): array
    {
        return [
            'is_up' => true,
            'diff_percent' => 0.0,
            'current' => 0.0,
            'previous' => 0.0,
            'sparkline' => [0, 0, 0, 0, 0, 0, 0],
        ];
    }

    private function sparklineByDays(Builder $query, CarbonImmutable $fromAt, CarbonImmutable $toAt, string $column = 'created_at'): array
    {
        $rows = $query
            ->selectRaw("DATE({$column}) as day, COUNT(*) as total")
            ->whereBetween($column, [$fromAt, $toAt])
            ->groupByRaw("DATE({$column})")
            ->orderByRaw("DATE({$column})")
            ->get()
            ->pluck('total', 'day');

        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        $points = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $fromAt->addDays($i)->toDateString();
            $points[] = (int) ($rows[$day] ?? 0);
        }

        return $points;
    }
}

