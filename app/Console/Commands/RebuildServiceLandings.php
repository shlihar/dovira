<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Profile;
use App\Support\RegionCityDirectory;
use App\Support\ServiceLandingSeo;
use App\Support\SitemapCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Перебудова таблиці service_review_matches: для кожної послуги зі словника
 * config/service_landings.php шукає згадки у відгуках профілів категорії
 * (LIKE по шаблонах) і зберігає збіги з канонічним містом профілю.
 * Сторінки послуг і sitemap читають лише цю таблицю — швидко й без таймаутів.
 */
class RebuildServiceLandings extends Command
{
    protected $signature = 'dovira:service-landings:rebuild {--category= : Лише одна категорія (slug)}';

    protected $description = 'Перебудовує збіги відгуків для сервісних посадкових (service_review_matches)';

    public function handle(): int
    {
        $t0 = microtime(true);
        $only = (string) ($this->option('category') ?? '');
        $total = 0;

        foreach ((array) config('service_landings.categories', []) as $categorySlug => $services) {
            if ($only !== '' && $only !== $categorySlug) {
                continue;
            }
            $category = Category::query()->where('slug', $categorySlug)->where('is_active', true)->first();
            if (! $category) {
                $this->warn("Категорії {$categorySlug} нема — пропускаю");
                continue;
            }

            $categoryIds = array_values(array_unique(array_merge(
                [$category->id],
                Category::query()->where('parent_id', $category->id)->pluck('id')->all()
            )));

            // profile_id → [city_key, city_name] для видимих профілів категорії.
            $profiles = Profile::query()
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true)
                ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->whereIn('category_id', $categoryIds))
                ->pluck('city', 'id');

            $cityByProfile = [];
            foreach ($profiles as $profileId => $city) {
                $canonical = RegionCityDirectory::canonicalCity((string) $city);
                $cityByProfile[(int) $profileId] = $canonical !== null
                    ? [RegionCityDirectory::normalizeCity($canonical), $canonical]
                    : [null, null];
            }
            $profileIds = array_keys($cityByProfile);

            foreach ($services as $service) {
                $ts = microtime(true);
                $rows = [];
                $inserted = 0;

                $query = DB::table('profile_reviews')
                    ->whereIn('profile_id', $profileIds)
                    ->where('status', 'published')
                    ->where(function ($q) use ($service): void {
                        foreach ((array) $service['patterns'] as $p) {
                            $q->orWhereRaw('LOWER(body) LIKE ?', ['%' . mb_strtolower($p) . '%']);
                        }
                    })
                    ->selectRaw('id, profile_id, rating, DATE(COALESCE(external_review_date, published_at, created_at)) as reviewed_at, CHAR_LENGTH(body) as body_length');

                DB::transaction(function () use ($query, $categorySlug, $service, $cityByProfile, &$rows, &$inserted): void {
                    DB::table('service_review_matches')
                        ->where('category_slug', $categorySlug)
                        ->where('service_slug', $service['slug'])
                        ->delete();

                    foreach ($query->lazyById(2000) as $r) {
                        [$cityKey, $cityName] = $cityByProfile[(int) $r->profile_id] ?? [null, null];
                        $rows[] = [
                            'category_slug' => $categorySlug,
                            'service_slug' => $service['slug'],
                            'review_id' => (int) $r->id,
                            'profile_id' => (int) $r->profile_id,
                            'city_key' => $cityKey,
                            'city_name' => $cityName,
                            'rating' => max(0, min(5, (int) round((float) $r->rating))),
                            'reviewed_at' => $r->reviewed_at,
                            'body_length' => min(65535, (int) $r->body_length),
                        ];
                        if (count($rows) >= 1000) {
                            DB::table('service_review_matches')->insert($rows);
                            $inserted += count($rows);
                            $rows = [];
                        }
                    }
                    if ($rows !== []) {
                        DB::table('service_review_matches')->insert($rows);
                        $inserted += count($rows);
                        $rows = [];
                    }
                });

                $total += $inserted;
                $this->line(sprintf('%-34s %-28s %6d збігів  %.1fs', $categorySlug, $service['slug'], $inserted, microtime(true) - $ts));
            }
        }

        // Кеші сторінок і sitemap читають нову таблицю — скидаємо.
        ServiceLandingSeo::forgetCaches();
        SitemapCache::forget();
        $entries = ServiceLandingSeo::sitemapEntries();

        $this->info(sprintf('Разом збігів: %d; сторінок у sitemap: %d; час %.0fs', $total, count($entries), microtime(true) - $t0));

        return self::SUCCESS;
    }
}
