<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\CategoryStats;
use Illuminate\Support\Facades\DB;

/**
 * Рахує середню оцінку по категорії (p у байєсівському згладжуванні) та
 * глобальну середню-фолбек. Викликається ПЕРШОЮ в конвеєрі перерахунку.
 */
class CategoryStatsService
{
    /**
     * Глобальна середня по всіх зарахованих відгуках — фолбек, коли в категорії
     * замало профілів.
     */
    public function globalMean(): float
    {
        $mean = DB::table('profile_reviews')
            ->where('status', 'published')
            ->where('is_excluded_from_rating', false)
            ->avg('rating');

        return $mean !== null
            ? (float) $mean
            : (float) config('rating.category_stats.global_mean_fallback', 4.6);
    }

    /**
     * Порахувати мапу середніх по категоріях БЕЗ запису (для dry-run).
     * Ключ — ПЕРВИННА категорія профілю (profile_category.is_primary=1).
     *
     * @return array<int,array{mean:float,profiles:int,reviews:int}>
     */
    public function computeMeans(): array
    {
        $rows = DB::table('profile_reviews as r')
            ->join('profile_category as pc', function ($join): void {
                $join->on('pc.profile_id', '=', 'r.profile_id')->where('pc.is_primary', '=', 1);
            })
            ->where('r.status', 'published')
            ->where('r.is_excluded_from_rating', false)
            ->groupBy('pc.category_id')
            ->select([
                'pc.category_id',
                DB::raw('AVG(r.rating) as mean_rating'),
                DB::raw('COUNT(*) as reviews_count'),
                DB::raw('COUNT(DISTINCT r.profile_id) as profiles_count'),
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->category_id] = [
                'mean' => round((float) $row->mean_rating, 3),
                'profiles' => (int) $row->profiles_count,
                'reviews' => (int) $row->reviews_count,
            ];
        }

        return $map;
    }

    /**
     * Перерахувати й ЗАПИСАТИ category_stats. Повертає мапу середніх.
     *
     * @return array<int,array{mean:float,profiles:int,reviews:int}>
     */
    public function recalculate(): array
    {
        $map = $this->computeMeans();
        $now = now();
        foreach ($map as $categoryId => $data) {
            CategoryStats::query()->updateOrCreate(
                ['category_id' => $categoryId],
                [
                    'mean_rating' => $data['mean'],
                    'reviews_count' => $data['reviews'],
                    'profiles_count' => $data['profiles'],
                    'calculated_at' => $now,
                ]
            );
        }

        return $map;
    }

    /**
     * p для профілю: середня його первинної категорії, якщо в ній достатньо
     * профілів; інакше — глобальна.
     *
     * @param array<int,array{mean:float,profiles:int}> $categoryMeans мапа category_id → дані
     */
    public function resolveP(?int $primaryCategoryId, array $categoryMeans, float $globalMean, int $minProfiles): float
    {
        $p = $globalMean;
        if ($primaryCategoryId !== null
            && isset($categoryMeans[$primaryCategoryId])
            && $categoryMeans[$primaryCategoryId]['profiles'] >= $minProfiles) {
            $p = $categoryMeans[$primaryCategoryId]['mean'];
        }

        // Підлога якоря: p ніколи не тягне оцінку вниз більше, ніж дозволено —
        // щоб профіль із сирою 5.0 не провалювався на бал.
        $floor = (float) config('rating.category_stats.mean_floor', 4.4);

        return max($floor, $p);
    }
}
