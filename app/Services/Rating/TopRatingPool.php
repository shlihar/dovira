<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\Category;
use App\Models\Profile;
use App\Support\CategoryHierarchy;
use App\Support\RegionCityDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Збирає пул профілів однієї пари «категорія × місто» разом із сигналами, яких
 * немає в таблиці profiles: попитом (події) і відповідями власника на відгуки.
 */
final class TopRatingPool
{
    /** Скільки кандидатів максимум тягнемо на розрахунок (топ-10 з великим запасом). */
    private const CANDIDATES_LIMIT = 200;

    /**
     * @return Collection<int, Profile>
     */
    public function build(Category $category, ?string $cityName): Collection
    {
        $categoryIds = CategoryHierarchy::categoryIdsFromNames([$category->name]);

        if ($categoryIds === []) {
            return collect();
        }

        $profiles = Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            // Пороги — або ручне закріплення з адмінки, яке їх обходить.
            ->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('reviews_count', '>=', TopRatingIndex::MIN_REVIEWS)
                        ->where('rating_avg', '>=', TopRatingIndex::MIN_RATING);
                })->orWhere('rating_placement', 'force');
            })
            ->where(function ($q): void {
                $q->whereNull('rating_placement')->orWhere('rating_placement', '!=', 'hide');
            })
            ->whereHas('categories', fn ($q) => $q
                ->whereIn('categories.id', $categoryIds)
                ->where('profile_category.is_primary', true))
            ->when($cityName !== null, function ($q) use ($cityName): void {
                $q->whereIn(DB::raw('lower(trim(city))'), $this->cityVariants($cityName));
            })
            ->with('rating')
            ->orderByDesc('reviews_count')
            ->limit(self::CANDIDATES_LIMIT)
            ->get();

        if ($profiles->isEmpty()) {
            return $profiles;
        }

        $this->attachDemand($profiles);
        $this->attachReplies($profiles);

        return $profiles;
    }

    /**
     * Попит = перегляди профілю + кліки по контактах за вікно свіжості.
     * Аутріч і внутрішні переходи виключені: інакше наші власні розсилки
     * піднімали б профіль у рейтингу, який ми ж і публікуємо.
     *
     * @param  Collection<int, Profile>  $profiles
     */
    private function attachDemand(Collection $profiles): void
    {
        $counts = DB::table('profile_events')
            ->selectRaw('profile_id, count(*) as c')
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->whereIn('event_type', ['profile_view', 'phone_click', 'website_click', 'map_click', 'lead_submit'])
            ->where('created_at', '>=', now()->subDays(TopRatingIndex::FRESH_WINDOW_DAYS))
            ->where(function ($q): void {
                $q->whereNull('source')
                    ->orWhereNotIn('source', ['outreach', 'internal']);
            })
            ->groupBy('profile_id')
            ->pluck('c', 'profile_id');

        foreach ($profiles as $profile) {
            // Логарифм: профіль із 900 переглядів не має затирати весь пул.
            $profile->top_rating_demand = log10(1 + (float) ($counts[$profile->id] ?? 0));
        }
    }

    /**
     * @param  Collection<int, Profile>  $profiles
     */
    private function attachReplies(Collection $profiles): void
    {
        $counts = DB::table('review_replies')
            ->selectRaw('profile_id, count(*) as c')
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->where('is_official', true)
            ->where('status', 'published')
            ->groupBy('profile_id')
            ->pluck('c', 'profile_id');

        foreach ($profiles as $profile) {
            $profile->top_rating_replies = (int) ($counts[$profile->id] ?? 0);
        }
    }

    /**
     * @return array<int, string>
     */
    private function cityVariants(string $cityName): array
    {
        return collect(RegionCityDirectory::variantsForCity($cityName))
            ->push($cityName)
            ->map(fn (string $v) => mb_strtolower(trim($v)))
            ->unique()
            ->values()
            ->all();
    }
}
