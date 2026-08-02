<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Support\MediaUrl;
use App\Support\WebsiteUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomePageDataService
{
    public const CACHE_TTL_SECONDS = 21600;

    public const CACHE_VERSION = 'v7';

    private const BEST_RATING_PRIOR_WEIGHT = 8;

    private const RECOMMENDED_MIN_REVIEWS = 3;

    // "Топ профілі за рейтингом" на головній стала "Найпопулярніші адвокати" —
    // юридична вертикаль лишається головним фокусом платформи.
    private const LAWYER_CATEGORY_NAME = 'Адвокати';

    private const LATEST_REVIEWS_LIMIT = 12;

    private const HOME_PROFILES_LIMIT = 12;

    private const TRENDING_EVENTS_DAYS = 14;

    private const TRENDING_REVIEWS_DAYS = 30;

    private const CATEGORY_ICONS = [
        'banky' => 'fa-solid fa-building-columns',
        'strakhuvannia' => 'fa-solid fa-shield-halved',
        'avtodylery' => 'fa-solid fa-car-side',
        'restorany' => 'fa-solid fa-utensils',
        'apteky' => 'fa-solid fa-prescription-bottle-medical',
        'tekhnika' => 'fa-solid fa-laptop',
        'odiah' => 'fa-solid fa-shirt',
        'iuvelirni-mahazyny' => 'fa-regular fa-gem',
        'mebli' => 'fa-solid fa-couch',
        'onlain-mahazyny' => 'fa-solid fa-bag-shopping',
        'dostavka' => 'fa-solid fa-truck-fast',
        'osvita' => 'fa-solid fa-graduation-cap',
        'kliniky' => 'fa-solid fa-hospital',
        'avtoservisy' => 'fa-solid fa-screwdriver-wrench',
        'budivnytstvo' => 'fa-solid fa-helmet-safety',
        'fitnes' => 'fa-solid fa-dumbbell',
        'iurysty' => 'fa-solid fa-scale-balanced',
        'likari' => 'fa-solid fa-stethoscope',
        'konsultanty' => 'fa-solid fa-user-tie',
    ];

    private const DEFAULT_QUERY_HINTS = [
        'Стоматології Києва',
        'СТО поруч',
        'Онлайн-магазини одягу',
        'Сервісні центри',
        'Ресторани',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        $discovery = $this->remember('discovery', fn (): array => $this->buildDiscoveryPayload());
        $stats = $this->remember('stats', fn (): array => $this->buildStatsPayload());

        return [
            ...$discovery,
            ...$stats,
            'recommendedProfiles' => $this->remember('recommended', fn (): array => $this->buildRecommendedProfiles()),
            'bestProfiles' => $this->remember('best', fn (): array => $this->buildBestProfiles()),
            'popularProfiles' => $this->remember('popular', fn (): array => $this->buildPopularProfiles()),
            'trendingProfiles' => $this->remember('trending', fn (): array => $this->buildTrendingProfiles()),
            'latestReviews' => $this->remember('latest-reviews', fn (): array => $this->buildLatestReviews()),
            'freshReviews' => $this->remember('fresh-reviews', fn (): array => $this->buildFreshReviews()),
            'leaderboard' => $this->remember('leaderboard', fn (): array => $this->buildLeaderboard()),
            'trustStats' => $this->remember('trust-stats', fn (): array => $this->buildTrustStats()),
            'categoryTree' => $this->remember('category-tree', fn (): array => $this->buildCategoryTree()),
            'categoryCards' => $this->categoryCards(),
            'categoryTicker' => $this->categoryTicker(),
        ];
    }

    /**
     * Картки блоку «Категорії відгуків»: реальні агрегати (компанії, відгуки,
     * середня оцінка, розподіл оцінок) + топ-2 підкатегорії. Важкий агрегат по
     * всій базі — тому кешується й прогрівається через refresh(), а не на льоту.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryCards(): array
    {
        return $this->remember('category-cards', fn (): array => $this->buildCategoryCards());
    }

    /**
     * Стрічка активності над сіткою. Пріоритет — СВІЖІ (24 год) реальні відгуки
     * з категорією. Якщо таких немає — імітуємо «живизну»: реальні категорії
     * (частіші там, де більше відгуків) + правдоподібний «X хв тому».
     *
     * @return array{items:array<int,array{category_name:string,category_slug:string,minutes_ago:int}>}
     */
    public function categoryTicker(): array
    {
        return $this->remember('category-ticker', fn (): array => $this->buildCategoryTicker());
    }

    /**
     * Повне дерево категорій для SEO-блоку під hero: топ-рівень з
     * підкатегоріями, лічильниками й іконками. Кожен вузол лінкує на
     * SEO-посадкову /catalog/{slug}. Ключ для внутрішньої перелінковки.
     *
     * @return array<int, array{name:string,slug:string,count:int,icon:string,children:array<int,array{name:string,slug:string}>}>
     */
    private function buildCategoryTree(): array
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->withCount(['profiles as public_profiles_count' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('public_profiles_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => (int) $category->public_profiles_count,
                'icon' => $category->icon ?: (self::CATEGORY_ICONS[$category->slug] ?? 'fa-solid fa-shapes'),
                'children' => $category->children
                    ->map(fn (Category $child): array => ['name' => $child->name, 'slug' => $child->slug])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryCards(): array
    {
        // Топ-рівневі категорії з лічильником компаній — та сама логіка, що й
        // у каталозі (профілі active/published/show_in_catalog), щоб цифри
        // блоку збігалися з тим, що людина побачить у категорії.
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->withCount([
                'profiles as companies_count' => fn ($profiles) => $profiles
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true),
            ])
            ->with(['children' => fn ($children) => $children
                ->where('is_active', true)
                ->where('show_in_catalog', true)
                ->withCount([
                    'profiles as companies_count' => fn ($profiles) => $profiles
                        ->where('status', 'active')
                        ->where('is_published', true)
                        ->where('show_in_catalog', true),
                ])
                ->orderByDesc('companies_count')
                ->orderBy('name'),
            ])
            ->orderByDesc('show_on_homepage')
            ->orderByDesc('companies_count')
            ->orderBy('name')
            ->get()
            ->filter(fn (Category $category) => (int) $category->companies_count > 0)
            ->values();

        $aggregates = $this->categoryReviewAggregates($categories->pluck('id')->all());

        return $categories->map(function (Category $category) use ($aggregates): array {
            $agg = $aggregates[$category->id] ?? null;

            // Чипи картки — ТІЛЬКИ підкатегорії (рішення користувача:
            // сервісні фрази типу «Стрижка котів» виглядали сміттєво).
            $subcategories = $category->children
                ->take(6)
                ->map(fn (Category $child): array => ['name' => $child->name, 'slug' => $child->slug])
                ->values()
                ->all();

            return [
                'slug' => $category->slug,
                'name' => $category->name,
                'companies_count' => (int) $category->companies_count,
                'reviews_count' => (int) ($agg->reviews_count ?? 0),
                'avg_rating' => round((float) ($agg->avg_rating ?? 0), 1),
                'dist_positive' => (int) ($agg->dist_positive ?? 0),
                'dist_neutral' => (int) ($agg->dist_neutral ?? 0),
                'dist_negative' => (int) ($agg->dist_negative ?? 0),
                'subcategories' => $subcategories,
            ];
        })->all();
    }

    /**
     * Один згрупований запит: відгуки → профілі → півот категорій. Відгук
     * рахується в КОЖНУ категорію свого профілю (профіль багатокатегорійний,
     * як і в каталозі). Бакети: 4-5 позитив, 3 нейтрал, 1-2 негатив.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, object>
     */
    private function categoryReviewAggregates(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return DB::table('profile_reviews as pr')
            ->join('profiles as p', function ($join): void {
                $join->on('p.id', '=', 'pr.profile_id')
                    ->where('p.status', 'active')
                    ->where('p.is_published', true)
                    ->where('p.show_in_catalog', true);
            })
            ->join('profile_category as pc', 'pc.profile_id', '=', 'p.id')
            ->whereIn('pc.category_id', $categoryIds)
            ->where('pr.status', 'published')
            ->groupBy('pc.category_id')
            ->selectRaw('pc.category_id,
                COUNT(pr.id) as reviews_count,
                AVG(pr.rating) as avg_rating,
                SUM(CASE WHEN pr.rating >= 4 THEN 1 ELSE 0 END) as dist_positive,
                SUM(CASE WHEN pr.rating = 3 THEN 1 ELSE 0 END) as dist_neutral,
                SUM(CASE WHEN pr.rating <= 2 THEN 1 ELSE 0 END) as dist_negative')
            ->get()
            ->keyBy('category_id')
            ->all();
    }

    /**
     * @return array{items:array<int,array{category_name:string,category_slug:string,minutes_ago:int}>}
     */
    private function buildCategoryTicker(): array
    {
        $threshold = now()->subDay();

        $recent = ProfileReview::query()
            ->select(['id', 'profile_id', 'published_at'])
            ->where('status', 'published')
            ->where('published_at', '>=', $threshold) // спершу реальні свіжі (24 год)
            ->whereHas('profile', fn ($profileQuery) => $profileQuery
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true))
            ->with(['profile' => fn ($profileQuery) => $profileQuery
                ->select('id', 'name')
                ->with(['categories' => fn ($categories) => $categories
                    ->select('categories.id', 'categories.name', 'categories.slug')
                    ->whereNull('categories.parent_id')
                    ->orderByDesc('profile_category.is_primary')])])
            ->latest('published_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $items = [];
        foreach ($recent as $review) {
            $category = optional($review->profile)->categories->first();
            if ($category === null || $review->published_at === null) {
                continue;
            }

            $items[] = [
                'category_name' => $category->name,
                'category_slug' => $category->slug,
                'minutes_ago' => max(1, (int) $review->published_at->diffInMinutes(now())),
            ];

            if (count($items) >= 6) {
                break;
            }
        }

        if ($items !== []) {
            return ['items' => $items];
        }

        // Свіжих реальних відгуків нема — імітуємо активність: реальні категорії
        // (ймовірність пропорційна кількості відгуків) + правдоподібний час.
        $pool = [];
        foreach (array_slice($this->categoryCards(), 0, 6) as $card) {
            $weight = max(1, (int) round($card['reviews_count'] / 12000));
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = ['name' => $card['name'], 'slug' => $card['slug']];
            }
        }

        if ($pool === []) {
            return ['items' => []];
        }

        for ($i = 0; $i < 10; $i++) {
            $pick = $pool[array_rand($pool)];
            $items[] = [
                'category_name' => $pick['name'],
                'category_slug' => $pick['slug'],
                'minutes_ago' => random_int(2, 55),
            ];
        }

        return ['items' => $items];
    }

    /**
     * «Набирають популярність»: профілі з найбільшою свіжою активністю —
     * події (перегляди/кліки) за останні тижні та нові відгуки за місяць.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrendingProfiles(?callable $extraFilter = null): array
    {
        $profiles = $this->publicHomeProfilesQuery()
            ->when($extraFilter !== null, fn (Builder $query) => $query->tap($extraFilter))
            ->withCount([
                'events as recent_events_count' => fn ($events) => $events
                    ->where('created_at', '>=', now()->subDays(self::TRENDING_EVENTS_DAYS)),
                'reviews as recent_reviews_count' => fn ($reviews) => $reviews
                    ->where('status', 'published')
                    ->where('published_at', '>=', now()->subDays(self::TRENDING_REVIEWS_DAYS)),
            ])
            ->orderByDesc('recent_events_count')
            ->orderByDesc('recent_reviews_count')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg')
            ->limit(self::HOME_PROFILES_LIMIT * 3)
            ->get();

        // HAVING по аліасах withCount — MySQL-only, тож фільтруємо в PHP.
        $active = $profiles->filter(
            fn (Profile $profile) => ((int) $profile->recent_events_count + (int) $profile->recent_reviews_count) > 0
        );
        if ($active->isEmpty()) {
            // Свіжої активності ще немає (нова інсталяція) — загальний топ.
            $active = $profiles;
        }

        return $active
            ->take(self::HOME_PROFILES_LIMIT)
            ->map(fn (Profile $profile): array => $this->mapProfileCard($profile) + [
                'recent_reviews_count' => (int) $profile->recent_reviews_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Персоналізація головної: у трьох секціях (рекомендовані, топ за
     * рейтингом, найпопулярніші) профілі з міста користувача йдуть першими,
     * а решта місць добивається глобальним списком.
     *
     * @return array{city:string,sections:array{recommended:array,best:array,popular:array,leaderboard:array}}
     */
    public function citySections(string $city): array
    {
        $canonicalCity = \App\Support\RegionCityDirectory::canonicalCity($city);

        if ($canonicalCity === null || $canonicalCity === '') {
            return ['city' => '', 'sections' => ['recommended' => [], 'best' => [], 'popular' => [], 'leaderboard' => []]];
        }

        return Cache::remember(
            // v7: окремий міський лідерборд «Хто найкращий» тільки з реальними лого.
            'home:city-sections:v7:'.mb_strtolower($canonicalCity),
            now()->addMinutes(30),
            function () use ($canonicalCity): array {
                $globalRatingBaseline = $this->globalRatingBaseline();
                $cityVariants = \App\Support\RegionCityDirectory::variantsForCity($canonicalCity);

                $cityFilter = function (Builder $query) use ($cityVariants): void {
                    $query->where(function (Builder $inner) use ($cityVariants): void {
                        // Звичайний LIKE: у MySQL він нечутливий до регістру за
                        // колацією, а LOWER() у SQLite не вміє кирилицю.
                        foreach ($cityVariants as $variant) {
                            $inner->orWhere('profiles.city', 'like', '%'.$variant.'%');
                        }
                    });
                };

                $cityRecommended = $this->publicHomeProfilesQuery()
                    ->tap($cityFilter)
                    ->where('dovira_recommendation_status', 'recommend')
                    ->where('reviews_count', '>=', self::RECOMMENDED_MIN_REVIEWS)
                    ->orderByRaw($this->weightedRatingOrderSql(), $this->weightedRatingBindings($globalRatingBaseline))
                    ->orderByDesc('rating_avg')
                    ->orderByDesc('reviews_count')
                    ->limit(self::HOME_PROFILES_LIMIT)
                    ->get()
                    ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
                    ->values()
                    ->all();

                // Статус «рекомендує» є в небагатьох міст. Щоб секція справді
                // відображала обране місто, добираємо його топ за рейтингом —
                // і лише потім (у cityFirstFill) глобальний список.
                if (count($cityRecommended) < self::HOME_PROFILES_LIMIT) {
                    $seenRecommendedSlugs = array_flip(array_column($cityRecommended, 'slug'));
                    $cityTopRated = $this->publicHomeProfilesQuery()
                        ->tap($cityFilter)
                        ->where('reviews_count', '>=', self::RECOMMENDED_MIN_REVIEWS)
                        ->where('rating_avg', '>=', 4.3)
                        ->orderByRaw($this->weightedRatingOrderSql(), $this->weightedRatingBindings($globalRatingBaseline))
                        ->orderByDesc('rating_avg')
                        ->orderByDesc('reviews_count')
                        ->limit(self::HOME_PROFILES_LIMIT)
                        ->get()
                        ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
                        ->values()
                        ->all();

                    foreach ($cityTopRated as $profile) {
                        if (count($cityRecommended) >= self::HOME_PROFILES_LIMIT) {
                            break;
                        }
                        $slug = (string) ($profile['slug'] ?? '');
                        if ($slug === '' || isset($seenRecommendedSlugs[$slug])) {
                            continue;
                        }
                        $seenRecommendedSlugs[$slug] = true;
                        $cityRecommended[] = $profile;
                    }
                }

                $cityBestQuery = $this->publicHomeProfilesQuery()->tap($cityFilter);
                $this->filterToLawyers($cityBestQuery, $this->lawyerCategoryIds());
                $cityBest = $cityBestQuery
                    ->orderByDesc('popularity_score')
                    ->orderByDesc('views_count')
                    ->orderByDesc('unique_views_count')
                    ->orderByDesc('contact_clicks_count')
                    ->orderByDesc('website_clicks_count')
                    ->orderByDesc('reviews_count')
                    ->orderByDesc('rating_avg')
                    ->limit(self::HOME_PROFILES_LIMIT)
                    ->get()
                    ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
                    ->values()
                    ->all();

                $cityTrending = $this->buildTrendingProfiles($cityFilter);
                $cityLeaderboard = $this->buildLeaderboard($cityFilter);

                // Свіжі відгуки: спершу відгуки про профілі міста, решту
                // місць добиваємо глобальними без дублів по профілю.
                $cityReviews = $this->buildLatestReviews($cityFilter);
                $seenReviewSlugs = array_flip(array_filter(array_column($cityReviews, 'profile_slug')));
                foreach ($this->latestReviews() as $review) {
                    if (count($cityReviews) >= self::LATEST_REVIEWS_LIMIT) {
                        break;
                    }
                    $slug = (string) ($review['profile_slug'] ?? '');
                    if ($slug !== '' && isset($seenReviewSlugs[$slug])) {
                        continue;
                    }
                    $seenReviewSlugs[$slug] = true;
                    $cityReviews[] = $review;
                }

                return [
                    'city' => $canonicalCity,
                    'sections' => [
                        'recommended' => $this->cityFirstFill($cityRecommended, $this->remember('recommended', fn (): array => $this->buildRecommendedProfiles())),
                        'best' => $this->cityFirstFill($cityBest, $this->remember('best', fn (): array => $this->buildBestProfiles())),
                        'trending' => $this->cityFirstFill($cityTrending, $this->remember('trending', fn (): array => $this->buildTrendingProfiles())),
                        'leaderboard' => $cityLeaderboard,
                        'reviews' => $cityReviews,
                    ],
                ];
            }
        );
    }

    /**
     * Профілі міста першими, решта місць — із глобального списку без дублів.
     *
     * @param  array<int, array<string, mixed>>  $cityList
     * @param  array<int, array<string, mixed>>  $globalList
     * @return array<int, array<string, mixed>>
     */
    private function cityFirstFill(array $cityList, array $globalList, int $limit = self::HOME_PROFILES_LIMIT): array
    {
        $seenSlugs = array_flip(array_column($cityList, 'slug'));

        foreach ($globalList as $profile) {
            if (count($cityList) >= $limit) {
                break;
            }

            $slug = (string) ($profile['slug'] ?? '');
            if ($slug === '' || isset($seenSlugs[$slug])) {
                continue;
            }

            $seenSlugs[$slug] = true;
            $cityList[] = $profile;
        }

        return $cityList;
    }

    /**
     * Останні відгуки для публічних сторінок (спільний кеш із головною).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestReviews(): array
    {
        return $this->remember('latest-reviews', fn (): array => $this->buildLatestReviews());
    }

    public function refresh(): void
    {
        $payload = $this->buildDiscoveryPayload();
        $this->put('discovery', $payload);

        $this->put('stats', $this->buildStatsPayload());
        $this->put('recommended', $this->buildRecommendedProfiles());
        $this->put('best', $this->buildBestProfiles());
        $this->put('popular', $this->buildPopularProfiles());
        $this->put('trending', $this->buildTrendingProfiles());
        $this->put('latest-reviews', $this->buildLatestReviews());
        $this->put('fresh-reviews', $this->buildFreshReviews());
        $this->put('leaderboard', $this->buildLeaderboard());
        $this->put('trust-stats', $this->buildTrustStats());
        // Порядок важливий: стрічка-фолбек читає прогріті картки категорій.
        $this->put('category-cards', $this->buildCategoryCards());
        $this->put('category-ticker', $this->buildCategoryTicker());
    }

    public function forgetLegacyKeys(): void
    {
        Cache::forget('public:home:v2');

        foreach ([
            'discovery',
            'stats',
            'recommended',
            'best',
            'popular',
            'trending',
            'latest-reviews',
            'fresh-reviews',
            'leaderboard',
            'trust-stats',
            'category-tree',
            'category-cards',
            'category-ticker',
        ] as $suffix) {
            Cache::forget('public:home:v5:' . $suffix);
            Cache::forget('public:home:v6:' . $suffix);
        }
    }

    /**
     * @return array{popularQueries: array<int, string>, intents: array<int, array<string, mixed>>}
     */
    private function buildDiscoveryPayload(): array
    {
        $categories = $this->homepageCategories();

        $intents = $categories
            ->take(16)
            ->map(fn (Category $category) => [
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => (int) $category->public_profiles_count,
                'icon' => $category->icon ?: (self::CATEGORY_ICONS[$category->slug] ?? 'fa-solid fa-shapes'),
            ])
            ->values()
            ->all();

        $popularQueries = $categories
            ->pluck('name')
            ->take(5)
            ->values()
            ->all();

        if (count($popularQueries) < 5) {
            $popularQueries = self::DEFAULT_QUERY_HINTS;
        }

        return [
            'popularQueries' => $popularQueries,
            'intents' => $intents,
        ];
    }

    /**
     * @return array{totalReviews:int,totalProfiles:int,totalViews:int}
     */
    private function buildStatsPayload(): array
    {
        $totalReviews = (int) ProfileReview::query()->where('status', 'published')->count();
        if ($totalReviews <= 0) {
            $totalReviews = (int) $this->publicProfilesBaseQuery()->sum('reviews_count');
        }

        // «Свіжість» для hero-варіанта I (bento): нові відгуки за 24 години.
        $reviewsToday = (int) ProfileReview::query()
            ->where('status', 'published')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            'totalReviews' => $totalReviews,
            'totalProfiles' => (int) $this->publicProfilesBaseQuery()->count(),
            'totalViews' => (int) $this->publicProfilesBaseQuery()->sum('views_count'),
            'reviewsToday' => $reviewsToday,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecommendedProfiles(): array
    {
        $globalRatingBaseline = $this->globalRatingBaseline();

        return $this->publicHomeProfilesQuery()
            ->where('dovira_recommendation_status', 'recommend')
            ->where('reviews_count', '>=', self::RECOMMENDED_MIN_REVIEWS)
            ->orderByRaw($this->weightedRatingOrderSql(), $this->weightedRatingBindings($globalRatingBaseline))
            ->orderByDesc('rating_avg')
            ->orderByDesc('reviews_count')
            ->orderByDesc('popularity_score')
            ->limit(self::HOME_PROFILES_LIMIT)
            ->get()
            ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
            ->values()
            ->all();
    }

    /**
     * Категорія «Адвокати» (з підкатегоріями, якщо колись з'являться) —
     * єдине джерело правди для розділу «Найпопулярніші адвокати», і на
     * SSR-версії, і на персоналізованій за містом.
     *
     * @return array<int, int>
     */
    private function lawyerCategoryIds(): array
    {
        return \App\Support\CategoryHierarchy::categoryIdsFromNames([self::LAWYER_CATEGORY_NAME]);
    }

    /**
     * @param  array<int, int>  $lawyerCategoryIds
     */
    private function filterToLawyers(Builder $query, array $lawyerCategoryIds): void
    {
        if (empty($lawyerCategoryIds)) {
            return;
        }

        $query->whereHas(
            'categories',
            fn (Builder $categories) => $categories->whereIn('categories.id', $lawyerCategoryIds)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBestProfiles(): array
    {
        $query = $this->publicHomeProfilesQuery();
        $this->filterToLawyers($query, $this->lawyerCategoryIds());

        return $query
            ->orderByDesc('popularity_score')
            ->orderByDesc('views_count')
            ->orderByDesc('unique_views_count')
            ->orderByDesc('contact_clicks_count')
            ->orderByDesc('website_clicks_count')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg')
            ->limit(self::HOME_PROFILES_LIMIT)
            ->get()
            ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPopularProfiles(): array
    {
        return $this->publicHomeProfilesQuery()
            ->orderByDesc('popularity_score')
            ->orderByDesc('views_count')
            ->orderByDesc('unique_views_count')
            ->orderByDesc('contact_clicks_count')
            ->orderByDesc('website_clicks_count')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg')
            ->limit(self::HOME_PROFILES_LIMIT)
            ->get()
            ->map(fn (Profile $profile) => $this->mapProfileCard($profile))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLatestReviews(?callable $profileFilter = null): array
    {
        return ProfileReview::query()
            ->select([
                'id',
                'profile_id',
                'user_id',
                'author_name',
                'external_review_author_avatar_url',
                'rating',
                'body',
                'published_at',
            ])
            ->with([
                'profile' => fn ($profileQuery) => $profileQuery
                    ->select([
                        'profiles.id',
                        'profiles.slug',
                        'profiles.name',
                        'profiles.logo_url',
                        'profiles.website',
                        'profiles.is_pro',
                        'profiles.city',
                    ])
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true)
                    ->with(['categories' => fn ($categories) => $categories
                        ->select('categories.id', 'categories.name')
                        ->orderByDesc('profile_category.is_primary')]),
                'author:id,avatar_url',
            ])
            ->whereHas('profile', function ($profileQuery) use ($profileFilter): void {
                $profileQuery
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true);
                if ($profileFilter !== null) {
                    $profileFilter($profileQuery);
                }
            })
            ->where('status', 'published')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('profile_reviews as newer_reviews')
                    ->whereColumn('newer_reviews.profile_id', 'profile_reviews.profile_id')
                    ->where('newer_reviews.status', 'published')
                    ->where(function ($newerQuery): void {
                        $newerQuery
                            ->whereColumn('newer_reviews.published_at', '>', 'profile_reviews.published_at')
                            ->orWhere(function ($sameMomentQuery): void {
                                $sameMomentQuery
                                    ->whereColumn('newer_reviews.published_at', 'profile_reviews.published_at')
                                    ->whereColumn('newer_reviews.id', '>', 'profile_reviews.id');
                            });
                    });
            })
            ->latest('published_at')
            ->latest('id')
            ->take(self::LATEST_REVIEWS_LIMIT)
            ->get()
            ->map(fn (ProfileReview $review) => $this->mapReviewCard($review))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReviewCard(ProfileReview $review): array
    {
        $profile = $review->profile;
        $author = trim((string) ($review->author_name ?: 'Користувач DOVIRA'));
        $authorInitial = mb_strtoupper(mb_substr($author, 0, 1));

        $nameParts = preg_split('/\s+/', trim($profile->name));
        // Review-card logos/avatars render small (~40px) — serve resized thumbnails.
        $profileLogo = MediaUrl::profileLogoUrl($profile, 160);
        $profileInitials = collect($nameParts)
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('') ?: 'DV';

        return [
            'author' => $author,
            'author_initial' => $authorInitial,
            'avatar_url' => MediaUrl::avatarUrl(
                $this->normalizeHomeReviewAvatarUrl($review->external_review_author_avatar_url ?: $review->author?->avatar_url),
                $author,
                128
            ),
            'rating' => (float) $review->rating,
            'text' => $review->body,
            'published_at' => $review->published_at?->toIso8601String(),
            'profile_name' => $profile->name,
            // Сайт — контактна дія, публічно доступна лише PRO-профілям.
            'profile_site' => $profile->is_pro ? WebsiteUrl::display($profile->website) : null,
            'profile_city' => trim((string) $profile->city) ?: null,
            'profile_category' => $profile->relationLoaded('categories')
                ? ($profile->categories->first()?->name ?: null)
                : null,
            'profile_logo_url' => $profileLogo,
            'profile_initials' => $profileInitials,
            'profile_slug' => $profile->slug,
        ];
    }

    /**
     * Секція «Свіжі відгуки» на головній: 3 найсвіжіші відгуки від РІЗНИХ
     * профілів, у кожного профілю є реальне лого. Правило нефільтрованості:
     * у вибірці завжди мінімум один відгук нижче 4★. Четверта плитка
     * секції — CTA «Залишити відгук» (рендерить blade).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFreshReviews(): array
    {
        // Перші з робочим лого; якщо таких бракує — добираємо без лого,
        // щоб секція не лишалась порожньою.
        $pick = function (Builder $query, array $usedProfileIds, int $count) {
            $picked = [];
            $fallback = [];
            foreach ($query->take(80)->get() as $review) {
                if (in_array((int) $review->profile_id, $usedProfileIds, true)) {
                    continue;
                }
                $card = $this->mapReviewCard($review);
                if (! empty($card['profile_logo_url'])) {
                    $picked[] = $card;
                    $usedProfileIds[] = (int) $review->profile_id;
                    if (count($picked) === $count) {
                        break;
                    }
                } elseif (count($fallback) < $count) {
                    $fallback[] = ['card' => $card, 'profile_id' => (int) $review->profile_id];
                }
            }
            foreach ($fallback as $item) {
                if (count($picked) >= $count) {
                    break;
                }
                if (in_array($item['profile_id'], $usedProfileIds, true)) {
                    continue;
                }
                $picked[] = $item['card'];
                $usedProfileIds[] = $item['profile_id'];
            }

            return [$picked, $usedProfileIds];
        };

        [$negatives, $used] = $pick($this->freshReviewQuery()->where('rating', '<', 4), [], 1);
        [$positives] = $pick($this->freshReviewQuery()->where('rating', '>=', 4), $used, 2);

        // Ритм: позитив → негатив → позитив.
        return array_values(array_filter([
            $positives[0] ?? null,
            $negatives[0] ?? null,
            $positives[1] ?? null,
        ]));
    }

    /**
     * Цифри-докази для блоку «Чому нам довіряти»: найсильніший аргумент
     * платформи — скільки НЕГАТИВУ опубліковано й видимо (його не ховають).
     *
     * @return array{negative_published:int}
     */
    private function buildTrustStats(): array
    {
        return [
            'negative_published' => (int) ProfileReview::query()
                ->where('status', 'published')
                ->where('rating', '<=', 2)
                ->count(),
        ];
    }

    /**
     * Лідерборд «Хто найкращий»: таби — найбільші підкатегорії («Адвокати»
     * завжди перші як основна), у кожній — топ-5 профілів. Позиція —
     * байєсівський рейтинг: середня оцінка з поправкою на кількість
     * відгуків (m=10, C=середнє по базі), щоб профіль із трьома п'ятірками
     * не бив 4.8 на трьохстах відгуках.
     *
     * @return array<int, array{name:string,slug:string,rows:array<int,array<string,mixed>>}>
     */
    private function buildLeaderboard(?callable $profileFilter = null): array
    {
        $subcategories = Category::query()
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->withCount([
                'profiles as companies_count' => function ($profiles) use ($profileFilter): void {
                    $profiles
                        ->where('status', 'active')
                        ->where('is_published', true)
                        ->where('show_in_catalog', true);
                    $this->filterToRealLogoSource($profiles);
                    if ($profileFilter !== null) {
                        $profileFilter($profiles);
                    }
                },
            ])
            // Розширюємо пул кандидатів (найнаповненіші за кількістю профілів з
            // логотипом — companies_count рахує лише такі, filterToRealLogoSource),
            // щоб у рейтинг потрапляли не лише адвокати/автошколи. Порожні/тонкі
            // категорії відсіє фільтр «≥3 рядки» нижче.
            ->orderByDesc('companies_count')
            ->take(24)
            ->get()
            ->sortBy(fn (Category $category) => mb_strtolower($category->name) === 'адвокати' ? 0 : 1)
            ->values();

        $globalAvg = round((float) Profile::query()
            ->where('status', 'active')
            ->where('reviews_count', '>=', 1)
            ->avg('rating_avg'), 2) ?: 4.3;

        return $subcategories
            ->map(function (Category $category) use ($globalAvg, $profileFilter): array {
                // Беремо широкий зріз (SQL-фільтр лишає й профілі з зовнішнім/
                // згенерованим лого, які realProfileLogoUrl нижче відсіє). Малий
                // ліміт (було 30) обрізав справжні лого ще до фільтра — тому
                // категорії з рідкими лого не набирали п'єдесталу. Ідемо в порядку
                // рейтингу й зупиняємось на 5-му профілі зі справжнім лого, щоб не
                // ганяти realProfileLogoUrl (генерація thumbnail-ів) по всьому зрізу.
                $candidates = Profile::query()
                    ->select([
                        'profiles.id',
                        'profiles.slug',
                        'profiles.name',
                        'profiles.logo_url',
                        'profiles.city',
                        'profiles.rating_avg',
                        'profiles.reviews_count',
                        'profiles.is_verified',
                        'profiles.dovira_recommendation_status',
                    ])
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true)
                    // Поріг знижено з 3 до 1: логотип лишається обов'язковим
                    // (realProfileLogoUrl відсіює безлогові), а поправка на кількість
                    // відгуків (Bayesian нижче) сама притискає профілі з малою базою
                    // вниз. Це відкриває більше категорій.
                    ->where('reviews_count', '>=', 1)
                    ->whereHas('categories', fn ($categories) => $categories->where('categories.id', $category->id))
                    ->tap(fn (Builder $query) => $this->filterToRealLogoSource($query))
                    ->when($profileFilter !== null, fn (Builder $query) => $profileFilter($query))
                    ->orderByRaw(
                        '(reviews_count / (reviews_count + 10)) * rating_avg + (10 / (reviews_count + 10)) * ? DESC',
                        [$globalAvg]
                    )
                    ->orderByDesc('reviews_count')
                    // 200 — компроміс покриття/швидкості: зрізає рядки, що йдуть у
                    // realProfileLogoUrl, але лишає ті самі 7 табів. Після міграції
                    // на зовнішні URL (лого завжди резолвиться) вистачить і ~30.
                    ->take(200)
                    ->get();

                $rows = [];
                foreach ($candidates as $profile) {
                    $logoUrl = MediaUrl::realProfileLogoUrl($profile, 96);
                    if ($logoUrl === null) {
                        continue;
                    }

                    $initials = collect(preg_split('/\s+/', trim($profile->name)))
                        ->filter()
                        ->take(2)
                        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
                        ->implode('') ?: 'DV';

                    $rows[] = [
                        'slug' => $profile->slug,
                        'name' => $profile->name,
                        'city' => trim((string) $profile->city) ?: null,
                        'logo_url' => $logoUrl,
                        'initials' => $initials,
                        'rating' => round((float) $profile->rating_avg, 1),
                        'reviews_count' => (int) $profile->reviews_count,
                        'verified' => (bool) $profile->is_verified,
                        'recommended' => $profile->dovira_recommendation_status === 'recommend',
                    ];

                    if (count($rows) >= 5) {
                        break;
                    }
                }

                return [
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'rows' => $rows,
                ];
            })
            // Таб без повного п'єдесталу — не рейтинг; такі ховаємо.
            ->filter(fn (array $tab) => count($tab['rows']) >= 3)
            // Максимум табів у секції: адвокати першими, далі — за наповненістю.
            ->take(8)
            ->values()
            ->all();
    }

    private function filterToRealLogoSource(Builder $query): void
    {
        $query
            ->whereNotNull('profiles.logo_url')
            ->whereRaw("TRIM(profiles.logo_url) <> ''")
            ->where('profiles.logo_url', 'not like', '%profile-fallbacks/%');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ProfileReview>
     */
    private function freshReviewQuery(): Builder
    {
        return ProfileReview::query()
            ->select([
                'id',
                'profile_id',
                'user_id',
                'author_name',
                'external_review_author_avatar_url',
                'rating',
                'body',
                'published_at',
            ])
            ->with([
                'profile' => fn ($profileQuery) => $profileQuery
                    ->select([
                        'profiles.id',
                        'profiles.slug',
                        'profiles.name',
                        'profiles.logo_url',
                        'profiles.website',
                        'profiles.is_pro',
                        'profiles.city',
                    ])
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true)
                    ->with(['categories' => fn ($categories) => $categories
                        ->select('categories.id', 'categories.name')
                        ->orderByDesc('profile_category.is_primary')]),
                'author:id,avatar_url',
            ])
            ->whereHas('profile', fn ($profileQuery) => $profileQuery
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true))
            ->where('status', 'published')
            // ≥40 символів: текст має заповнювати 3 рядки clamp, а не один.
            // У sqlite (тести) немає CHAR_LENGTH — там LENGTH теж рахує символи.
            ->whereRaw((DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH') . '(TRIM(body)) >= 40')
            ->latest('published_at')
            ->latest('id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    private function publicHomeProfilesQuery(): Builder
    {
        return $this->publicProfilesBaseQuery()
            ->select([
                'profiles.id',
                'profiles.slug',
                'profiles.name',
                'profiles.logo_url',
                'profiles.website',
                'profiles.city',
                'profiles.rating_avg',
                'profiles.reviews_count',
                'profiles.is_verified',
                'profiles.is_owner_verified',
                'profiles.owner_user_id',
                'profiles.is_pro',
                'profiles.popularity_score',
                'profiles.views_count',
                'profiles.unique_views_count',
                'profiles.contact_clicks_count',
                'profiles.website_clicks_count',
                'profiles.dovira_recommendation_status',
            ])
            ->with(['categories' => fn ($categories) => $categories
                ->select(['categories.id', 'categories.name', 'categories.slug', 'categories.icon'])
                ->orderByDesc('profile_category.is_primary'),
            ])
            ->with(['services'])
            ->withCount('officialReplies')
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Profile>
     */
    private function publicProfilesBaseQuery(): Builder
    {
        return Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Category>
     */
    private function homepageCategories(): Collection
    {
        // Empty categories are hidden from the home page on purpose: a
        // "0 профілів" card is a dead click that makes the marketplace
        // look empty. A category appears here automatically as soon as
        // it gets its first published profile; the full list is always
        // available in the catalog / "Всі категорії".
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->withCount([
                'profiles as public_profiles_count' => fn ($profiles) => $profiles
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true),
            ])
            ->orderByDesc('show_on_homepage')
            ->orderByDesc('public_profiles_count')
            ->orderBy('name')
            ->get()
            // HAVING on a withCount alias is MySQL-only (SQLite rejects it
            // on non-aggregate queries), so filter the handful of rows in PHP.
            ->filter(fn (Category $category) => (int) $category->public_profiles_count > 0)
            ->values();

        if ($categories->isNotEmpty()) {
            return $categories;
        }

        return Category::query()
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->withCount([
                'profiles as public_profiles_count' => fn ($profiles) => $profiles
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true),
            ])
            ->orderByDesc('public_profiles_count')
            ->orderBy('name')
            ->get()
            ->filter(fn (Category $category) => (int) $category->public_profiles_count > 0)
            ->values();
    }

    private function globalRatingBaseline(): float
    {
        $baseline = (float) ($this->publicProfilesBaseQuery()
            ->where('reviews_count', '>', 0)
            ->avg('rating_avg') ?? 0.0);

        return $baseline > 0 ? $baseline : 4.0;
    }

    /**
     * Small-sample smoothing so 1 review with 5.0 does not outrank 120 reviews with 4.9.
     */
    private function weightedRatingOrderSql(): string
    {
        return '((rating_avg * reviews_count) + (? * ?)) / nullif(reviews_count + ?, 0) desc';
    }

    /**
     * @return array<int, float|int>
     */
    private function weightedRatingBindings(float $globalRatingBaseline): array
    {
        return [
            $globalRatingBaseline,
            self::BEST_RATING_PRIOR_WEIGHT,
            self::BEST_RATING_PRIOR_WEIGHT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProfileCard(Profile $profile): array
    {
        $nameParts = preg_split('/\s+/', trim($profile->name));
        $initials = collect($nameParts)
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        $primaryCategory = $profile->categories->first();
        $categorySlug = $primaryCategory?->slug;

        $services = ($profile->relationLoaded('services') ? $profile->services : collect())
            ->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values();
        if ($services->isEmpty()) {
            $services = $profile->categories->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values();
        }

        $reviewsCount = (int) $profile->reviews_count;
        $repliesCount = (int) ($profile->official_replies_count ?? 0);
        $recommendationStatus = $profile->dovira_recommendation_status ?? null;

        $logoImageUrl = MediaUrl::profileLogoUrl($profile, 160);

        return [
            'slug' => $profile->slug,
            'name' => $profile->name,
            'services' => $services->all(),
            'recommended' => $recommendationStatus === 'recommend',
            'not_recommended' => $recommendationStatus === 'not_recommend',
            'responds' => $reviewsCount >= 3 && $repliesCount >= (int) ceil($reviewsCount / 2),
            'logo_url' => $logoImageUrl,
            'logo_image_url' => $logoImageUrl,
            'initials' => $initials ?: 'DV',
            'website' => $profile->is_pro ? $profile->website : null,
            'city' => $profile->city,
            'address' => $profile->address,
            'about' => $profile->description,
            'rating' => (float) $profile->rating_avg,
            'reviews_count' => (int) $profile->reviews_count,
            'verified' => (bool) $profile->is_verified,
            'owner_verified' => (bool) ($profile->is_owner_verified || ($profile->has_approved_claim ?? false) || $profile->owner_user_id),
            'pro' => (bool) $profile->is_pro,
            'category_slug' => $categorySlug,
            'category_label' => $primaryCategory?->name ?: 'Категорія',
            'category_icon' => $primaryCategory?->icon
                ?: ($categorySlug ? (self::CATEGORY_ICONS[$categorySlug] ?? 'fa-solid fa-tag') : 'fa-solid fa-tag'),
        ];
    }

    private function normalizeHomeReviewAvatarUrl(?string $value): ?string
    {
        return MediaUrl::publicImageUrl($value, [
            'img/empty.png',
            'img/default.png',
            'img/avatar-placeholder.png',
            'images/empty.png',
            'images/default.png',
            'images/avatar-placeholder.png',
            'storage/img/empty.png',
            'storage/img/default.png',
            'storage/img/avatar-placeholder.png',
            'storage/images/empty.png',
            'storage/images/default.png',
            'storage/images/avatar-placeholder.png',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    private function remember(string $suffix, callable $resolver): mixed
    {
        return Cache::remember(
            $this->cacheKey($suffix),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            $resolver
        );
    }

    private function put(string $suffix, mixed $value): void
    {
        Cache::put(
            $this->cacheKey($suffix),
            $value,
            now()->addSeconds(self::CACHE_TTL_SECONDS)
        );
    }

    private function cacheKey(string $suffix): string
    {
        return sprintf('public:home:%s:%s', self::CACHE_VERSION, $suffix);
    }
}
