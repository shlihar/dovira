<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Дата-блоки SEO-посадкових «категорія [× місто]»: агрегати по реальних
 * профілях і відгуках (унікальний контент кожної сторінки без AI-генерації),
 * топ профілів за зваженим рейтингом і перехресні лінки міст/категорій.
 * Все кешується — сторінки каталогу високочастотні.
 */
class CatalogLandingSeo
{
    private const CACHE_TTL_HOURS = 6;

    /** Мінімум профілів, щоб пара «категорія × місто» була indexable і в sitemap. */
    public const MIN_INDEXABLE_PROFILES = 3;

    /**
     * @return array{
     *   profiles_count:int,
     *   reviews_count:int,
     *   avg_rating:float,
     *   owner_verified_count:int,
     *   top_profiles:array<int, array{name:string,slug:string,rating:float,reviews_count:int}>,
     *   city_links:array<int, array{name:string,slug:string,count:int}>,
     *   city_categories:array<int, array{name:string,slug:string,count:int}>,
     *   updated_at:string
     * }
     */
    public static function build(Category $category, ?string $cityName): array
    {
        $cacheKey = sprintf(
            'catalog:landing-seo:%d:%s:v2',
            $category->id,
            $cityName !== null ? (RegionCityDirectory::citySlug($cityName) ?? md5($cityName)) : 'ua'
        );

        return rescue(
            fn (): array => cache()->remember(
                $cacheKey,
                now()->addHours(self::CACHE_TTL_HOURS),
                fn (): array => self::compute($category, $cityName)
            ),
            self::emptyPayload(),
            false
        );
    }

    private static function compute(Category $category, ?string $cityName): array
    {
        $categoryIds = CategoryHierarchy::categoryIdsFromNames([(string) $category->name]);
        if ($categoryIds === []) {
            return self::emptyPayload();
        }

        $base = fn () => Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));

        $cityVariants = $cityName !== null ? RegionCityDirectory::variantsForCity($cityName) : [];
        $withCity = function ($query) use ($cityVariants) {
            if ($cityVariants !== []) {
                $query->whereIn('city', $cityVariants);
            }

            return $query;
        };

        $aggregate = $withCity($base())
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(reviews_count), 0) as reviews_total')
            ->selectRaw('avg(case when reviews_count >= 1 then rating_avg end) as avg_rating')
            ->selectRaw('sum(case when is_owner_verified = 1 or owner_user_id is not null then 1 else 0 end) as owner_verified')
            ->first();

        // Той самий зважений (байєсівський) рейтинг, що й у сортуванні каталогу
        // та лідербордах — топ у FAQ/schema збігається з видачею сторінки.
        $globalAvg = (float) cache()->remember(
            'catalog:weighted-global-avg:v2',
            now()->addHours(6),
            fn (): float => (float) rescue(fn () => Profile::query()
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true)
                ->where('reviews_count', '>=', 1)
                ->avg('rating_avg'), 4.3, false) ?: 4.3
        );

        $topProfiles = $withCity($base())
            ->where('reviews_count', '>=', 1)
            ->orderByRaw(
                '(reviews_count / (reviews_count + 10)) * rating_avg + (10 / (reviews_count + 10)) * ? DESC',
                [$globalAvg]
            )
            ->orderByDesc('reviews_count')
            ->limit(5)
            ->get(['name', 'slug', 'rating_avg', 'reviews_count'])
            ->map(fn (Profile $profile) => [
                'name' => (string) $profile->name,
                'slug' => (string) $profile->slug,
                'rating' => round((float) $profile->rating_avg, 1),
                'reviews_count' => (int) $profile->reviews_count,
            ])
            ->all();

        return [
            'profiles_count' => (int) ($aggregate->total ?? 0),
            'reviews_count' => (int) ($aggregate->reviews_total ?? 0),
            'avg_rating' => round((float) ($aggregate->avg_rating ?? 0), 1),
            'owner_verified_count' => (int) ($aggregate->owner_verified ?? 0),
            'top_profiles' => $topProfiles,
            'city_links' => self::cityLinks($base, $cityName),
            'city_categories' => $cityName !== null ? self::cityCategories($category, $cityVariants) : [],
            'updated_at' => now()->toDateString(),
        ];
    }

    /**
     * Компактний лінк-хаб для СТОРІНКИ ПРОФІЛЮ: «{категорія} в інших містах»
     * і «інші послуги в {місто}». Сторінок профілів десятки тисяч, і кожна
     * досі віддавала посадковим лише одне посилання з хлібних крихт — через
     * це категорійні сторінки збирали в 10–20 разів менше внутрішньої ваги,
     * ніж службові сторінки з футера (дані GSC, 17.09.2026).
     *
     * Кеш спільний по парі «категорія × місто», тож усі профілі одного міста
     * і категорії рахують блок один раз.
     *
     * @return array{cities:array<int, array{name:string,slug:string,count:int}>, categories:array<int, array{name:string,slug:string,count:int}>}
     */
    public static function profileLinkHub(Category $category, ?string $cityName, int $limit = 8): array
    {
        $citySlug = $cityName !== null ? (RegionCityDirectory::citySlug($cityName) ?? md5($cityName)) : 'ua';
        $cacheKey = sprintf('catalog:profile-link-hub:%d:%s:%d:v2', $category->id, $citySlug, $limit);

        return rescue(
            fn (): array => cache()->remember(
                $cacheKey,
                now()->addHours(self::CACHE_TTL_HOURS),
                function () use ($category, $cityName, $limit): array {
                    $data = self::build($category, $cityName);

                    return [
                        'cities' => array_slice($data['city_links'] ?? [], 0, $limit),
                        'categories' => array_slice($data['city_categories'] ?? [], 0, $limit),
                    ];
                }
            ),
            ['cities' => [], 'categories' => []],
            false
        );
    }

    /**
     * Міста цієї категорії з ЧПУ-slug і кількістю профілів (лінк-хаб
     * «{Категорія} в інших містах»). Поточне місто виключаємо.
     *
     * @return array<int, array{name:string,slug:string,count:int}>
     */
    private static function cityLinks(callable $base, ?string $cityName): array
    {
        $currentNormalized = $cityName !== null ? RegionCityDirectory::normalizeCity($cityName) : null;

        $rows = $base()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city')
            ->selectRaw('count(*) as total')
            ->groupBy('city')
            ->get();

        // Сирі назви міст → канонічні (аліаси, регістр), зливаємо лічильники.
        $byCity = [];
        foreach ($rows as $row) {
            $canonical = RegionCityDirectory::canonicalCity((string) $row->city);
            $slug = RegionCityDirectory::citySlug($canonical);
            if ($canonical === null || $slug === null) {
                continue;
            }
            $key = RegionCityDirectory::normalizeCity($canonical);
            if ($key === $currentNormalized) {
                continue;
            }
            $byCity[$key] ??= ['name' => $canonical, 'slug' => $slug, 'count' => 0];
            $byCity[$key]['count'] += (int) $row->total;
        }

        return collect($byCity)
            ->filter(fn (array $city) => $city['count'] >= self::MIN_INDEXABLE_PROFILES)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Інші категорії з профілями в цьому місті («Інші послуги у Вінниці») —
     * перехресна перелінковка міських посадкових між категоріями.
     *
     * @param  array<int, string>  $cityVariants
     * @return array<int, array{name:string,slug:string,count:int}>
     */
    private static function cityCategories(Category $current, array $cityVariants): array
    {
        if ($cityVariants === []) {
            return [];
        }

        $rows = DB::table('profile_category')
            ->join('profiles', 'profiles.id', '=', 'profile_category.profile_id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->whereIn('profiles.city', $cityVariants)
            ->select('profile_category.category_id')
            ->selectRaw('count(distinct profiles.id) as total')
            ->groupBy('profile_category.category_id')
            ->having('total', '>=', self::MIN_INDEXABLE_PROFILES)
            ->pluck('total', 'category_id');

        if ($rows->isEmpty()) {
            return [];
        }

        return Category::query()
            ->where('is_active', true)
            ->whereNotNull('slug')
            ->whereKeyNot($current->id)
            ->whereIn('id', $rows->keys())
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $category) => [
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'count' => (int) $rows[$category->id],
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Найбільші пари «категорія × місто» для сайтвайд-перелінковки (футер,
     * головна): лінк-вага з найсильніших сторінок тече на посадкові. Кеш 12h,
     * не більше 3 міст на категорію — інакше список забиває одна категорія.
     *
     * @return array<int, array{label:string,category_slug:string,city_slug:string,count:int}>
     */
    public static function popularLandings(int $limit = 24): array
    {
        $all = rescue(
            fn (): array => cache()->remember(
                'catalog:popular-landings:v2',
                now()->addHours(12),
                fn (): array => self::computePopularLandings()
            ),
            [],
            false
        );

        return array_slice($all, 0, $limit);
    }

    /**
     * @return array<int, array{label:string,category_slug:string,city_slug:string,count:int}>
     */
    private static function computePopularLandings(): array
    {
        $rows = DB::table('profile_category')
            ->join('profiles', 'profiles.id', '=', 'profile_category.profile_id')
            ->join('categories', 'categories.id', '=', 'profile_category.category_id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->where('categories.is_active', true)
            ->where('categories.is_indexable', true)
            ->whereNotNull('categories.slug')
            ->whereNotNull('profiles.city')
            ->selectRaw('categories.name as category_name, categories.slug as category_slug, profiles.city as city, count(*) as total')
            ->groupBy('categories.name', 'categories.slug', 'profiles.city')
            ->get();

        // Сирі назви міст → канонічні slug-міста, зливаємо лічильники варіантів.
        $pairs = [];
        foreach ($rows as $row) {
            $citySlug = RegionCityDirectory::citySlug((string) $row->city);
            if ($citySlug === null) {
                continue;
            }
            $key = $row->category_slug . '/' . $citySlug;
            $pairs[$key] ??= [
                'category_name' => (string) $row->category_name,
                'category_slug' => (string) $row->category_slug,
                'city_slug' => $citySlug,
                'count' => 0,
            ];
            $pairs[$key]['count'] += (int) $row->total;
        }

        $perCategory = [];
        $result = [];
        foreach (collect($pairs)->sortByDesc('count') as $pair) {
            if ($pair['count'] < self::MIN_INDEXABLE_PROFILES) {
                continue;
            }
            $perCategory[$pair['category_slug']] = ($perCategory[$pair['category_slug']] ?? 0) + 1;
            if ($perCategory[$pair['category_slug']] > 3) {
                continue;
            }

            $cityName = RegionCityDirectory::cityFromSlug($pair['city_slug']);
            $result[] = [
                // Анкор = H1 посадкової («Адвокати у Вінниці») — релевантність запиту.
                'label' => $pair['category_name'] . ' ' . (RegionCityDirectory::cityLocative($cityName) ?? $cityName),
                'category_slug' => $pair['category_slug'],
                'city_slug' => $pair['city_slug'],
                'count' => $pair['count'],
            ];
            if (count($result) >= 30) {
                break;
            }
        }

        return $result;
    }

    private static function emptyPayload(): array
    {
        return [
            'profiles_count' => 0,
            'reviews_count' => 0,
            'avg_rating' => 0.0,
            'owner_verified_count' => 0,
            'top_profiles' => [],
            'city_links' => [],
            'city_categories' => [],
            'updated_at' => now()->toDateString(),
        ];
    }
}
