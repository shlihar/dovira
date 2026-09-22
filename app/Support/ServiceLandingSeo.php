<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Дата-блоки посадкових рівня ПОСЛУГИ (config/service_landings.php):
 * «{послуга} {у місті} — відгуки». Профілі відбираються за реальними
 * згадками послуги у відгуках, на сторінку йдуть цитати саме цих відгуків.
 *
 * Усе кешується по трійці «категорія × місто × послуга»: сторінки публічні
 * й індексовані, а пошук по тексту відгуків — важкий.
 */
class ServiceLandingSeo
{
    private const CACHE_TTL_HOURS = 6;

    /** @return array{slug:string,name:string,about:string,patterns:array<int,string>}|null */
    public static function service(string $categorySlug, string $serviceSlug): ?array
    {
        foreach ((array) config("service_landings.categories.{$categorySlug}", []) as $service) {
            if (($service['slug'] ?? null) === $serviceSlug) {
                return $service;
            }
        }

        return null;
    }

    /** @return array<int, array{slug:string,name:string,about:string,patterns:array<int,string>}> */
    public static function servicesFor(string $categorySlug): array
    {
        return array_values((array) config("service_landings.categories.{$categorySlug}", []));
    }

    public static function minProfiles(array $service): int
    {
        return max(1, (int) ($service['min_profiles'] ?? config('service_landings.min_profiles_default', 3)));
    }

    /**
     * @return array{
     *   profiles_count:int, reviews_count:int, avg_rating:float,
     *   profile_ids:array<int,int>, matches_by_profile:array<int,int>,
     *   excerpts:array<int, array{profile_id:int,profile_name:string,profile_slug:string,author:string,rating:int,date:?string,text:string}>,
     *   other_services:array<int, array{slug:string,name:string,count:int}>,
     *   other_cities:array<int, array{name:string,slug:string,count:int}>,
     *   updated_at:string
     * }
     */
    /** $cityName = null → уся Україна. */
    public static function build(Category $category, ?string $cityName, array $service): array
    {
        $cacheKey = sprintf('catalog:service-landing:%d:%s:%s:v2', $category->id, self::cityKey($cityName) ?? 'ua', $service['slug']);

        return rescue(
            fn (): array => cache()->remember(
                $cacheKey,
                now()->addHours(self::CACHE_TTL_HOURS),
                fn (): array => self::compute($category, $cityName, $service)
            ),
            self::empty(),
            false
        );
    }

    private static function compute(Category $category, ?string $cityName, array $service): array
    {
        $cityKey = self::cityKey($cityName);

        $matches = self::matchesQuery((string) $category->slug, $service['slug'], $cityKey)
            ->selectRaw('profile_id, count(*) as c, avg(rating) as r')
            ->groupBy('profile_id')
            ->get();

        if ($matches->isEmpty()) {
            return array_replace(self::empty(), [
                'other_services' => self::otherServices($category, $cityKey, $service['slug']),
                'other_cities' => self::otherCities($category, $service, $cityKey),
            ]);
        }

        $matchesByProfile = $matches->pluck('c', 'profile_id')->map(fn ($c) => (int) $c)->all();
        $reviewsCount = (int) $matches->sum('c');
        $avgRating = $reviewsCount > 0
            ? round((float) ($matches->sum(fn ($m) => (float) $m->r * (int) $m->c) / $reviewsCount), 1)
            : 0.0;

        // Цитати: свіжі змістовні відгуки саме про цю послугу. Беремо й
        // критичні — сторінка про досвід пацієнтів, а не реклама.
        $excerptLimit = (int) config('service_landings.excerpt_limit', 6);
        // Спершу дешевий список кандидатів із таблиці збігів (без тіл відгуків):
        // беремо з запасом, бо один заклад із сотнею свіжих відгуків не має
        // монополізувати цитати — не більше двох на профіль.
        $candidateIds = self::matchesQuery((string) $category->slug, $service['slug'], $cityKey)
            ->where('service_review_matches.body_length', '>=', 60)
            ->orderByDesc('service_review_matches.reviewed_at')
            ->orderByDesc('service_review_matches.review_id')
            ->limit(max(60, $excerptLimit * 20))
            ->get(['service_review_matches.review_id', 'service_review_matches.profile_id'])
            ->groupBy('profile_id')
            ->flatMap(fn (Collection $group) => $group->take(2))
            ->pluck('review_id')
            ->take($excerptLimit)
            ->all();

        $excerpts = $candidateIds === []
            ? collect()
            : DB::table('profile_reviews')
                ->whereIn('id', $candidateIds)
                ->where('status', 'published')
                ->orderByRaw('COALESCE(external_review_date, published_at, created_at) DESC')
                ->get(['id', 'profile_id', 'author_name', 'rating', 'body', 'external_review_date', 'published_at']);

        $profileMeta = Profile::query()
            ->whereIn('id', $excerpts->pluck('profile_id')->unique())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $excerptRows = $excerpts->map(fn ($r): array => [
            'profile_id' => (int) $r->profile_id,
            'profile_name' => (string) ($profileMeta[$r->profile_id]->name ?? ''),
            'profile_slug' => (string) ($profileMeta[$r->profile_id]->slug ?? ''),
            'author' => trim((string) $r->author_name) ?: 'Клієнт',
            'rating' => max(1, min(5, (int) round((float) $r->rating))),
            'date' => $r->external_review_date ?: ($r->published_at ? substr((string) $r->published_at, 0, 10) : null),
            'text' => self::excerpt((string) $r->body, $service['patterns']),
        ])->all();

        return [
            'profiles_count' => count($matchesByProfile),
            'reviews_count' => $reviewsCount,
            'avg_rating' => $avgRating,
            'profile_ids' => array_map('intval', array_keys($matchesByProfile)),
            'matches_by_profile' => $matchesByProfile,
            'excerpts' => $excerptRows,
            'other_services' => self::otherServices($category, $cityKey, $service['slug']),
            'other_cities' => self::otherCities($category, $service, $cityKey),
            'updated_at' => now()->toDateString(),
        ];
    }

    /**
     * Послуги категорії з достатньою кількістю профілів у місті — для блоку
     * «Популярні послуги» на категорійній посадковій (лінки на сервісні сторінки).
     *
     * @return array<int, array{slug:string,name:string,count:int}>
     */
    public static function servicesInCity(Category $category, string $cityName): array
    {
        if (self::servicesFor((string) $category->slug) === []) {
            return [];
        }
        $cityKey = self::cityKey($cityName);
        $cacheKey = sprintf('catalog:service-landing:city-index:%d:%s:v2', $category->id, $cityKey ?? 'ua');

        return rescue(
            fn (): array => cache()->remember(
                $cacheKey,
                now()->addHours(self::CACHE_TTL_HOURS),
                fn (): array => self::otherServices($category, $cityKey, '')
            ),
            [],
            false
        );
    }

    /** Скинути всі кеші сервісних посадкових (після перебудови таблиці збігів). */
    public static function forgetCaches(): void
    {
        cache()->forget('catalog:service-landing:sitemap:v2');
        foreach ((array) config('service_landings.categories', []) as $categorySlug => $services) {
            $category = Category::query()->where('slug', $categorySlug)->first();
            if (! $category) {
                continue;
            }
            $cityKeys = DB::table('service_review_matches')
                ->where('category_slug', $categorySlug)
                ->distinct()
                ->pluck('city_key')
                ->push(null)
                ->unique();
            foreach ($cityKeys as $cityKey) {
                cache()->forget(sprintf('catalog:service-landing:city-index:%d:%s:v2', $category->id, $cityKey ?? 'ua'));
                foreach ($services as $service) {
                    cache()->forget(sprintf('catalog:service-landing:%d:%s:%s:v2', $category->id, $cityKey ?? 'ua', $service['slug']));
                }
            }
        }
    }

    /**
     * Базовий запит до матеріалізованих збігів: категорія × послуга [× місто].
     * Лише видимі профілі — таблиця оновлюється раз на добу, а профіль могли сховати.
     */
    private static function matchesQuery(string $categorySlug, string $serviceSlug, ?string $cityKey)
    {
        return DB::table('service_review_matches')
            ->where('service_review_matches.category_slug', $categorySlug)
            ->where('service_review_matches.service_slug', $serviceSlug)
            ->when($cityKey !== null, fn ($q) => $q->where('service_review_matches.city_key', $cityKey))
            ->whereIn('service_review_matches.profile_id', self::visibleProfileIds());
    }

    private static function visibleProfileIds()
    {
        return DB::table('profiles')
            ->select('id')
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true);
    }

    /** Інші послуги цієї категорії, що мають достатньо профілів у цьому місті (null — вся Україна). */
    private static function otherServices(Category $category, ?string $cityKey, string $currentSlug): array
    {
        $services = self::servicesFor((string) $category->slug);
        if ($services === []) {
            return [];
        }
        $counts = DB::table('service_review_matches')
            ->where('category_slug', (string) $category->slug)
            ->when($cityKey !== null, fn ($q) => $q->where('city_key', $cityKey))
            ->whereIn('profile_id', self::visibleProfileIds())
            ->selectRaw('service_slug, count(distinct profile_id) as c')
            ->groupBy('service_slug')
            ->pluck('c', 'service_slug');

        $rows = [];
        foreach ($services as $svc) {
            if ($svc['slug'] === $currentSlug) {
                continue;
            }
            $count = (int) ($counts[$svc['slug']] ?? 0);
            if ($count >= self::minProfiles($svc)) {
                $rows[] = ['slug' => $svc['slug'], 'name' => $svc['name'], 'count' => $count];
            }
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    /** Міста, де ця послуга має достатньо профілів (лінк-хаб «в інших містах»). */
    private static function otherCities(Category $category, array $service, ?string $currentCityKey): array
    {
        $rows = DB::table('service_review_matches')
            ->where('category_slug', (string) $category->slug)
            ->where('service_slug', $service['slug'])
            ->whereNotNull('city_key')
            ->when($currentCityKey !== null, fn ($q) => $q->where('city_key', '!=', $currentCityKey))
            ->whereIn('profile_id', self::visibleProfileIds())
            ->selectRaw('city_key, min(city_name) as city_name, count(distinct profile_id) as c')
            ->groupBy('city_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $slug = RegionCityDirectory::citySlug((string) $row->city_name);
            if ($slug === null || (int) $row->c < self::minProfiles($service)) {
                continue;
            }
            $out[] = ['name' => (string) $row->city_name, 'slug' => $slug, 'count' => (int) $row->c];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Усі indexable трійки для sitemap: по кожній послузі — міста з
     * достатньою кількістю профілів + сторінка по всій Україні (city = null).
     *
     * @return array<int, array{category:string, city:?string, service:string}>
     */
    public static function sitemapEntries(): array
    {
        return rescue(fn (): array => cache()->remember(
            'catalog:service-landing:sitemap:v2',
            now()->addHours(self::CACHE_TTL_HOURS),
            function (): array {
                $entries = [];
                foreach ((array) config('service_landings.categories', []) as $categorySlug => $services) {
                    $category = Category::query()->where('slug', $categorySlug)->where('is_active', true)->first();
                    if (! $category) {
                        continue;
                    }
                    $totals = DB::table('service_review_matches')
                        ->where('category_slug', $categorySlug)
                        ->whereIn('profile_id', self::visibleProfileIds())
                        ->selectRaw('service_slug, count(distinct profile_id) as c')
                        ->groupBy('service_slug')
                        ->pluck('c', 'service_slug');
                    foreach ($services as $service) {
                        foreach (self::otherCities($category, $service, null) as $city) {
                            $entries[] = ['category' => $categorySlug, 'city' => $city['slug'], 'service' => $service['slug']];
                        }
                        if ((int) ($totals[$service['slug']] ?? 0) >= self::minProfiles($service)) {
                            $entries[] = ['category' => $categorySlug, 'city' => null, 'service' => $service['slug']];
                        }
                    }
                }

                return $entries;
            }
        ), [], false);
    }

    /** Ключ канонічного міста для таблиці збігів; null — уся Україна. */
    private static function cityKey(?string $cityName): ?string
    {
        if ($cityName === null || trim($cityName) === '') {
            return null;
        }
        $canonical = RegionCityDirectory::canonicalCity($cityName) ?? $cityName;

        return RegionCityDirectory::normalizeCity($canonical);
    }

    /** Фрагмент відгуку навколо першої згадки послуги (до ~220 символів). */
    private static function excerpt(string $body, array $patterns): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $body));
        $lower = mb_strtolower($text);
        $pos = null;
        foreach ($patterns as $p) {
            $i = mb_strpos($lower, mb_strtolower($p));
            if ($i !== false && ($pos === null || $i < $pos)) {
                $pos = $i;
            }
        }
        if (mb_strlen($text) <= 220) {
            return $text;
        }
        $start = max(0, ($pos ?? 0) - 80);
        // До межі слова.
        if ($start > 0) {
            $sp = mb_strpos($text, ' ', $start);
            $start = $sp !== false ? $sp + 1 : $start;
        }
        $slice = mb_substr($text, $start, 220);
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > 120) {
            $slice = mb_substr($slice, 0, $lastSpace);
        }

        return ($start > 0 ? '…' : '') . $slice . '…';
    }

    private static function empty(): array
    {
        return [
            'profiles_count' => 0, 'reviews_count' => 0, 'avg_rating' => 0.0,
            'profile_ids' => [], 'matches_by_profile' => [], 'excerpts' => [],
            'other_services' => [], 'other_cities' => [], 'updated_at' => now()->toDateString(),
        ];
    }
}
