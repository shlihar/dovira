<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Батч-перерахунок рейтингів/популярності по всіх профілях. Ідемпотентний,
 * перезапускний (курсор по id), чанками. dry-run нічого не пише і повертає
 * діф для звіту «як зміняться числа».
 */
class RatingRecalculator
{
    public function __construct(
        private readonly CategoryStatsService $categoryStats,
        private readonly ProfileRatingCalculator $calculator,
    ) {
    }

    /**
     * @param  callable|null  $onProgress  fn(int $done, int $total): void
     * @return array{processed:int, changes:array<int,array{id:int,name:string,category_id:?int,old_rating:float,new_rating:float,old_pop:float,new_pop:float,reliable:bool,recommended:bool}>}
     */
    public function run(bool $dryRun = false, ?int $profileId = null, ?int $categoryId = null, ?callable $onProgress = null): array
    {
        $globalMean = $this->categoryStats->globalMean();
        $means = $dryRun ? $this->categoryStats->computeMeans() : $this->categoryStats->recalculate();
        $minProfiles = (int) config('rating.category_stats.category_min_profiles', 30);
        $chunk = (int) config('rating.batch.chunk', 500);

        // Первинна категорія кожного профілю — одним запитом (без N+1).
        $primaryCat = DB::table('profile_category')->where('is_primary', 1)
            ->pluck('category_id', 'profile_id');

        $baseIds = $categoryId !== null
            ? DB::table('profile_category')->where('category_id', $categoryId)->pluck('profile_id')->map(fn ($i) => (int) $i)->all()
            : null;

        $total = $this->withReconnect(function () use ($profileId, $baseIds) {
            $q = Profile::query();
            if ($profileId !== null) {
                $q->where('id', $profileId);
            }
            if ($baseIds !== null) {
                $q->whereIn('id', $baseIds);
            }

            return $q->count();
        });

        $processed = 0;
        $changes = [];
        $failed = [];
        $lastId = 0;

        // Свіже підключення перед довгим циклом запису: важкі агрегати вище
        // могли «підвісити» з'єднання на віддаленому сервері (MySQL gone away).
        DB::reconnect();

        while (true) {
            $rows = $this->withReconnect(function () use ($profileId, $baseIds, $lastId, $chunk) {
                $q = Profile::query()->select(['id'])->where('id', '>', $lastId)->orderBy('id')->limit($chunk);
                if ($profileId !== null) {
                    $q->where('id', $profileId);
                }
                if ($baseIds !== null) {
                    $q->whereIn('id', $baseIds);
                }

                return $q->get();
            });

            if ($rows->isEmpty()) {
                break;
            }

            $ids = $rows->pluck('id')->map(fn ($i) => (int) $i)->all();
            $lastId = (int) end($ids);

            // Чанк із ретраєм: якщо з'єднання впало посеред чанку —
            // переконектимось і повторимо весь чанк (розрахунок ідемпотентний).
            $chunkChanges = null;
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                try {
                    $chunkChanges = $this->processChunk($ids, $primaryCat, $means, $globalMean, $minProfiles, $dryRun);
                    break;
                } catch (Throwable $e) {
                    DB::reconnect();
                    if ($attempt === 4) {
                        $failed = array_merge($failed, $ids);
                    }
                }
            }

            foreach (($chunkChanges ?? []) as $c) {
                $changes[] = $c;
                $processed++;
                if ($onProgress) {
                    $onProgress($processed, $total);
                }
            }

            if ($profileId !== null) {
                break;
            }
        }

        return ['processed' => $processed, 'changes' => $changes, 'failed' => $failed];
    }

    /**
     * Обробити чанк профілів. Повертає масив змін (для звіту). Кидає виняток
     * при розриві з'єднання — зовнішній цикл переконектиться й повторить.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function processChunk(array $ids, $primaryCat, array $means, float $globalMean, int $minProfiles, bool $dryRun): array
    {
        $current = DB::table('profiles')->whereIn('id', $ids)->pluck('rating_avg', 'id');
        $currentPop = DB::table('profiles')->whereIn('id', $ids)->pluck('popularity_score', 'id');
        $profiles = Profile::query()->whereIn('id', $ids)->with('rating')->get();

        $out = [];
        foreach ($profiles as $profile) {
            $pid = (int) $profile->id;
            $primaryCategoryId = isset($primaryCat[$pid]) ? (int) $primaryCat[$pid] : null;
            $p = $this->categoryStats->resolveP($primaryCategoryId, $means, $globalMean, $minProfiles);
            $result = $this->calculator->calculate($profile, $p, ! $dryRun);

            $out[] = [
                'id' => $pid,
                'name' => (string) $profile->name,
                'category_id' => $primaryCategoryId,
                'old_rating' => (float) ($current[$pid] ?? 0),
                'new_rating' => (float) $result['rating'],
                'old_pop' => (float) ($currentPop[$pid] ?? 0),
                'new_pop' => (float) $result['popularity_score'],
                'reliable' => (bool) $result['is_rating_reliable'],
                'recommended' => (bool) $result['is_recommended'],
            ];
        }

        return $out;
    }

    /**
     * Виконати замикання з переконектом і ретраями на розрив з'єднання.
     */
    private function withReconnect(callable $fn, int $tries = 4): mixed
    {
        for ($i = 1; $i <= $tries; $i++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                DB::reconnect();
                if ($i === $tries) {
                    throw $e;
                }
            }
        }

        return null;
    }
}
