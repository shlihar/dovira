<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\TopRatingSnapshot;
use App\Services\Rating\TopRatingIndex;
use App\Services\Rating\TopRatingPool;
use App\Support\RegionCityDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Місячний зріз рейтингів. Запускається першого числа: сторінка показує
 * заморожений результат, а не живий запит, і різниця між зрізами дає абзац
 * «що змінилось».
 */
class BuildTopRatingSnapshots extends Command
{
    protected $signature = 'dovira:top:snapshot
        {--period= : Період YYYY-MM; типово поточний місяць}
        {--category= : Тільки одна категорія (слаг)}
        {--city= : Тільки одне місто (слаг)}
        {--limit=10 : Скільки позицій зберігати}
        {--min-rows=5 : Не зберігати зріз, якщо позицій менше}
        {--force : Перерахувати навіть якщо зріз за період уже є}
        {--dry-run : Порахувати й показати, нічого не пишучи}';

    protected $description = 'Рахує місячний зріз «ТОП-{N} {категорія} {місто}» за індексом DOVIRA.';

    public function handle(TopRatingPool $pool, TopRatingIndex $index): int
    {
        $period = (string) ($this->option('period') ?: TopRatingSnapshot::currentPeriod());
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $this->error('Некоректний --period. Формат: YYYY-MM.');

            return self::FAILURE;
        }

        $limit = max(3, (int) $this->option('limit'));
        $minRows = max(1, (int) $this->option('min-rows'));
        $dryRun = (bool) $this->option('dry-run');

        $categories = Category::query()
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->when($this->option('category'), fn ($q) => $q->where('slug', (string) $this->option('category')))
            // Батьківські рубрики з індексованими дітьми пропускаємо: їхні
            // сторінки й так noindex, щоб не конкурувати з дочірніми.
            ->get()
            ->filter(fn (Category $c): bool => ! ($c->parent_id === null && Category::query()
                ->where('parent_id', $c->id)->where('is_active', true)->where('is_indexable', true)->exists()));

        if ($categories->isEmpty()) {
            $this->error('Не знайдено жодної придатної категорії.');

            return self::FAILURE;
        }

        $cityFilter = $this->option('city') ? (string) $this->option('city') : null;
        $stats = ['combos' => 0, 'saved' => 0, 'skipped_thin' => 0, 'rows' => 0];

        foreach ($categories as $category) {
            $targets = [[null, null]];

            foreach ($this->citiesFor($category) as $citySlug => $cityName) {
                $targets[] = [$citySlug, $cityName];
            }

            foreach ($targets as [$citySlug, $cityName]) {
                if ($cityFilter !== null && $citySlug !== $cityFilter) {
                    continue;
                }

                $stats['combos']++;

                $exists = TopRatingSnapshot::query()
                    ->where('period', $period)
                    ->where('category_slug', $category->slug)
                    ->where('city_slug', $citySlug)
                    ->exists();

                if ($exists && ! $this->option('force')) {
                    continue;
                }

                $ranked = array_slice($index->rank($pool->build($category, $cityName)), 0, $limit);

                if (count($ranked) < $minRows) {
                    $stats['skipped_thin']++;
                    continue;
                }

                $stats['saved']++;
                $stats['rows'] += count($ranked);

                if ($dryRun) {
                    $this->line(sprintf('  %s / %s → %d позицій, лідер: %s (%.1f)',
                        $category->slug, $citySlug ?? 'Україна', count($ranked),
                        mb_substr((string) $ranked[0]['profile']->name, 0, 34), $ranked[0]['index']));
                    continue;
                }

                DB::transaction(function () use ($period, $category, $citySlug, $ranked): void {
                    TopRatingSnapshot::query()
                        ->where('period', $period)
                        ->where('category_slug', $category->slug)
                        ->where('city_slug', $citySlug)
                        ->delete();

                    foreach ($ranked as $i => $row) {
                        TopRatingSnapshot::create([
                            'period' => $period,
                            'category_slug' => $category->slug,
                            'city_slug' => $citySlug,
                            'profile_id' => $row['profile']->id,
                            'position' => $i + 1,
                            'index_score' => $row['index'],
                            'components' => $row['components'],
                            'rating' => round((float) $row['profile']->rating_avg, 2),
                            'reviews_count' => (int) $row['profile']->reviews_count,
                            'reviews_last_90d' => (int) ($row['profile']->rating->reviews_last_90d ?? 0),
                        ]);
                    }
                });
            }
        }

        $this->newLine();
        $this->table(['Метрика', 'Значення'], [
            ['Період', $period],
            ['Комбінацій перевірено', (string) $stats['combos']],
            ['Зрізів збережено', (string) $stats['saved']],
            ['Пропущено (замало позицій)', (string) $stats['skipped_thin']],
            ['Рядків у зрізах', (string) $stats['rows']],
        ]);

        return self::SUCCESS;
    }

    /**
     * Міста, де в категорії досить профілів для рейтингу.
     *
     * @return array<string, string> слаг → назва
     */
    private function citiesFor(Category $category): array
    {
        $ids = \App\Support\CategoryHierarchy::categoryIdsFromNames([$category->name]);

        if ($ids === []) {
            return [];
        }

        $cities = DB::table('profiles')
            ->join('profile_category', 'profile_category.profile_id', '=', 'profiles.id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->where('profiles.reviews_count', '>=', TopRatingIndex::MIN_REVIEWS)
            ->where('profiles.rating_avg', '>=', TopRatingIndex::MIN_RATING)
            ->whereIn('profile_category.category_id', $ids)
            ->where('profile_category.is_primary', true)
            ->whereNotNull('profiles.city')
            ->selectRaw('profiles.city, count(*) as c')
            ->groupBy('profiles.city')
            ->having('c', '>=', 5)
            ->pluck('c', 'city');

        $out = [];

        foreach ($cities as $city => $count) {
            $slug = RegionCityDirectory::citySlug((string) $city);

            if ($slug !== null && ! isset($out[$slug])) {
                $out[$slug] = (string) $city;
            }
        }

        return $out;
    }
}
