<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\CategoryHierarchy;
use App\Support\MediaUrl;
use App\Support\RegionCityDirectory;
use App\Support\TopRatingIndexCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Programmatic SEO-сторінки «ТОП-10 {категорія} {місто}» (/top/...).
 * Рейтинг — той самий байєсівський зважений бал, що в лідерборді головної
 * (m=10, C=глобальний середній рейтинг): сторінка чесно відповідає сторінці
 * «Як формується рейтинг». Комбінації без даних (менш як MIN_INDEXABLE
 * профілів з відгуками) рендеряться з noindex і не потрапляють у sitemap.
 */
class TopRatingController extends Controller
{
    /** Скільки позицій показуємо в топі. */
    public const TOP_LIMIT = 10;

    /** Мінімум профілів з відгуками, щоб сторінка була індексованою. */
    public const MIN_INDEXABLE = 3;

    /** Байєсівське згладжування: вага апріорного середнього (як у лідерборді). */
    private const BAYES_M = 10;

    /** Поріг відгуків для топу малих категорій, де <3 профілів мають показувану оцінку. */
    private const TOP_FALLBACK_MIN_REVIEWS = 5;

    public function index(): View
    {
        return view('static.top-index', [
            ...TopRatingIndexCache::remember(fn (): array => $this->topIndexPayload()),
            'year' => now()->year,
        ]);
    }

    public function show(string $categorySlug, ?string $citySlug = null): View
    {
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $categorySlug)
            ->first();
        abort_if($category === null, 404);

        $cityName = null;
        if ($citySlug !== null) {
            $cityName = RegionCityDirectory::cityFromSlug($citySlug);
            abort_if($cityName === null, 404);
        }

        $categoryIds = CategoryHierarchy::categoryIdsFromNames([$category->name]);
        abort_if(empty($categoryIds), 404);

        $isBroadParentCategory = $category->parent_id === null && Category::query()
            ->where('parent_id', $category->id)
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->exists();

        // Назви для приховування зі спеціалізацій: сама рубрика + її предки
        // (щоб на сторінці «Адвокати» не мигтіло «Юридичні послуги» у кожному рядку).
        $excludeNames = collect([$category->name]);
        $ancestor = $category;
        $guard = 0;
        while ($ancestor->parent_id && $guard < 10) {
            $ancestor = Category::query()->find($ancestor->parent_id);
            if (! $ancestor) {
                break;
            }
            $excludeNames->push($ancestor->name);
            $guard++;
        }
        $excludeNames = $excludeNames->map(fn ($n) => mb_strtolower(trim((string) $n)))->unique();

        $globalAvg = (float) rescue(
            fn () => Profile::query()
                ->where('status', 'active')
                ->where('reviews_count', '>=', 1)
                ->avg('rating_avg'),
            4.3,
            false
        ) ?: 4.3;

        $base = fn () => Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->where(function ($q): void {
                $q->whereNull('rating_placement')->orWhere('rating_placement', '!=', 'hide');
            })
            ->where('reviews_count', '>=', 1)
            ->whereHas('categories', fn ($q) => $q
                ->whereIn('categories.id', $categoryIds)
                ->where('profile_category.is_primary', true))
            ->when($cityName !== null, function ($q) use ($cityName): void {
                $variants = collect(RegionCityDirectory::variantsForCity($cityName))
                    ->push($cityName)
                    ->map(fn (string $v) => mb_strtolower(trim($v)))
                    ->unique()
                    ->values()
                    ->all();
                $q->whereIn(DB::raw('lower(trim(city))'), $variants);
            });

        // Агрегати для hero і методології — по всій комбінації, не лише топ-10.
        $aggregates = rescue(fn () => $base()
            ->selectRaw('count(*) as profiles, coalesce(sum(reviews_count), 0) as reviews, coalesce(avg(rating_avg), 0) as rating')
            ->first(), null, false);
        $totalProfiles = (int) ($aggregates->profiles ?? 0);
        $totalReviews = (int) ($aggregates->reviews ?? 0);
        $avgRating = round((float) ($aggregates->rating ?? 0), 1);

        // Рейтинг — лише профілі з ПОКАЗУВАНОЮ оцінкою (≥ MIN_REVIEWS): інакше
        // в топ малих категорій потрапляли профілі з 13 відгуками і числом,
        // яке на їхній же сторінці приховане. Якщо таких менше 3 — м'якший
        // поріг, щоб сторінка не була порожньою.
        $minReviews = (int) \App\Support\RatingDisplay::MIN_REVIEWS;
        $qualified = (int) rescue(fn () => $base()->where('reviews_count', '>=', $minReviews)->count(), 0, false);
        $topMinReviews = $qualified >= 3 ? $minReviews : self::TOP_FALLBACK_MIN_REVIEWS;

        $top = rescue(fn () => $base()
            ->where('reviews_count', '>=', $topMinReviews)
            ->select(['id', 'slug', 'name', 'logo_url', 'city', 'rating_avg', 'reviews_count', 'is_verified', 'is_owner_verified', 'is_pro', 'dovira_recommendation_status', 'dossier', 'rating_placement', 'rating_priority'])
            ->with(['categories:id,name,parent_id'])
            ->orderByRaw("CASE WHEN rating_placement = 'force' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('rating_priority')
            ->orderByRaw(
                '(reviews_count / (reviews_count + ' . self::BAYES_M . ')) * rating_avg + (' . self::BAYES_M . ' / (reviews_count + ' . self::BAYES_M . ')) * ? DESC',
                [$globalAvg]
            )
            ->orderByDesc('reviews_count')
            ->take(self::TOP_LIMIT)
            ->get(), collect(), false);

        $rows = $top->values()->map(fn (Profile $profile, int $i) => [
            'rank' => $i + 1,
            'url' => route('profile.show', ['slug' => $profile->slug]),
            'name' => (string) $profile->name,
            'city' => $profile->city ? (string) $profile->city : null,
            'logo_url' => MediaUrl::profileLogoUrl($profile, 128),
            'initials' => mb_strtoupper(mb_substr(trim((string) $profile->name), 0, 1)),
            'rating' => round((float) $profile->rating_avg, 1),
            'rating_visible' => \App\Support\RatingDisplay::visible((int) $profile->reviews_count),
            'reviews_count' => (int) $profile->reviews_count,
            'verified' => (bool) ($profile->is_owner_verified || $profile->is_verified),
            'recommended' => $profile->dovira_recommendation_status === 'recommend',
            'dossier_excerpt' => $this->dossierExcerpt($profile->dossier),
            // Спеціалізації = назви (під)категорій, крім поточної рубрики (щоб не
            // дублювати «Адвокати» на сторінці адвокатів).
            'specializations' => $profile->categories
                ->pluck('name')
                ->map(fn ($n) => trim((string) $n))
                ->filter(fn (string $n) => $n !== '' && ! $excludeNames->contains(mb_strtolower($n)))
                ->unique()
                ->take(2)
                ->values()
                ->all(),
        ])->all();

        // Інші міста цієї категорії, де рейтинг має сенс (внутрішня перелінковка).
        $cityLinks = $this->cityLinks($categoryIds, $categorySlug, $citySlug);

        return view('static.top-rating', [
            'category' => $category,
            'categorySlug' => $categorySlug,
            'categoryRatingName' => $this->ratingCategoryLabel((string) $category->name),
            'cityName' => $cityName,
            'citySlug' => $citySlug,
            'rows' => $rows,
            'totalProfiles' => $totalProfiles,
            'totalReviews' => $totalReviews,
            'avgRating' => $avgRating,
            'indexable' => ! $isBroadParentCategory && count($rows) >= self::MIN_INDEXABLE,
            'cityLinks' => $cityLinks,
            'year' => now()->year,
            'updatedAt' => now(),
        ]);
    }

    /**
     * @return array{groups:array<int, array{name:string, items:array<int, array{name:string, title:string, icon_class:string, url:string, profiles_count:int, reviews_count:int, city_links:array<int, array{name:string, url:string}>}>>, totalRatings:int, totalProfiles:int, updatedAt:\Illuminate\Support\Carbon}
     */
    private function topIndexPayload(): array
    {
        $groups = $this->ratingCategoryGroups();
        $totalRatings = collect($groups)->sum(fn (array $group) => count($group['items']));
        $totalProfiles = collect($groups)
            ->flatMap(fn (array $group) => $group['items'])
            ->sum(fn (array $item) => (int) $item['profiles_count']);

        return [
            'groups' => $groups,
            'totalRatings' => $totalRatings,
            'totalProfiles' => $totalProfiles,
            'updatedAt' => now(),
        ];
    }

    /**
     * @return array<int, array{name:string, items:array<int, array{name:string, title:string, icon_class:string, url:string, profiles_count:int, reviews_count:int, city_links:array<int, array{name:string, url:string}>}>}>
     */
    private function ratingCategoryGroups(): array
    {
        $categories = rescue(fn () => Category::query()
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->with(['parent:id,name,slug,parent_id,sort_order'])
            ->withCount([
                'children as active_children_count' => fn ($children) => $children
                    ->where('is_active', true)
                    ->where('is_indexable', true),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug', 'icon', 'sort_order']), collect(), false);

        if ($categories->isEmpty()) {
            return [];
        }

        $categoryById = $categories->keyBy('id');
        $parentById = $categories->pluck('parent_id', 'id')->all();

        $rows = rescue(fn () => DB::table('profile_category')
            ->join('profiles', 'profiles.id', '=', 'profile_category.profile_id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->where(function ($q): void {
                $q->whereNull('profiles.rating_placement')->orWhere('profiles.rating_placement', '!=', 'hide');
            })
            ->where('profiles.reviews_count', '>=', 1)
            ->where('profile_category.is_primary', true)
            ->select('profile_category.category_id', 'profiles.id as profile_id', 'profiles.city', 'profiles.reviews_count')
            ->get(), collect(), false);

        $profileIdsByCategory = [];
        $reviewsByCategoryProfile = [];
        $cityProfileIdsByCategory = [];

        foreach ($rows as $row) {
            $profileId = (int) $row->profile_id;
            $reviewsCount = (int) $row->reviews_count;
            $citySlug = RegionCityDirectory::citySlug((string) ($row->city ?? ''));
            $categoryId = (int) $row->category_id;
            $guard = 0;

            while ($categoryId && $guard < 10) {
                if (! $categoryById->has($categoryId)) {
                    break;
                }

                $profileIdsByCategory[$categoryId][$profileId] = true;
                $reviewsByCategoryProfile[$categoryId][$profileId] = max(
                    $reviewsByCategoryProfile[$categoryId][$profileId] ?? 0,
                    $reviewsCount
                );

                if ($citySlug !== null) {
                    $cityProfileIdsByCategory[$categoryId][$citySlug][$profileId] = true;
                }

                $categoryId = (int) ($parentById[$categoryId] ?? 0);
                $guard++;
            }
        }

        $groups = [];

        foreach ($categories as $category) {
            $hasChildren = (int) ($category->active_children_count ?? 0) > 0;
            // Публікуємо рейтинги для реальних підкатегорій і для root-категорій,
            // які не мають дітей. Широкі батьківські рубрики лишаються каталогом.
            if ($category->parent_id === null && $hasChildren) {
                continue;
            }

            $profileCount = count($profileIdsByCategory[$category->id] ?? []);
            if ($profileCount < self::MIN_INDEXABLE) {
                continue;
            }

            $parentName = $category->parent?->name ?: 'Інші рейтинги';
            $cityLinks = collect($cityProfileIdsByCategory[$category->id] ?? [])
                ->map(fn (array $profileIds, string $slug) => [
                    'slug' => $slug,
                    'count' => count($profileIds),
                ])
                ->filter(fn (array $city) => $city['count'] >= self::MIN_INDEXABLE)
                ->sortByDesc('count')
                ->take(4)
                ->map(fn (array $city) => [
                    'name' => RegionCityDirectory::cityFromSlug($city['slug']) ?? $city['slug'],
                    'url' => route('top.rating.city', ['category' => $category->slug, 'city' => $city['slug']]),
                ])
                ->values()
                ->all();

            $groups[$parentName]['name'] = $parentName;
            $groups[$parentName]['items'][] = [
                'name' => (string) $category->name,
                'title' => 'Топ ' . $this->ratingCategoryLabel((string) $category->name),
                'icon_class' => $this->ratingCategoryIcon((string) $category->name, (string) ($category->icon ?? '')),
                'url' => route('top.rating', ['category' => $category->slug]),
                'profiles_count' => $profileCount,
                'reviews_count' => array_sum($reviewsByCategoryProfile[$category->id] ?? []),
                'city_links' => $cityLinks,
            ];
        }

        return collect($groups)
            ->map(function (array $group): array {
                $group['items'] = collect($group['items'])
                    ->sortBy([
                        ['profiles_count', 'desc'],
                        ['reviews_count', 'desc'],
                        ['name', 'asc'],
                    ])
                    ->values()
                    ->all();

                return $group;
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function ratingCategoryLabel(string $name): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));

        return [
            'адвокати' => 'адвокатів',
            'нотаріуси' => 'нотаріусів',
            'стоматології' => 'стоматологів',
            'стоматологи' => 'стоматологів',
            'косметолог' => 'косметологів',
            'косметологи' => 'косметологів',
            'косметологічні клініки' => 'косметологічних клінік',
            'пластичні хірурги' => 'пластичних хірургів',
            'агенства нерухомості' => 'агентств нерухомості',
            'агентства нерухомості' => 'агентств нерухомості',
            'автошколи' => 'автошкіл',
            'мовні школи' => 'мовних шкіл',
            'ветеринари та ветеринарні клініки' => 'ветеринарів та ветеринарних клінік',
            'будівельні магазини' => 'будівельних магазинів',
            'приватні клініки' => 'приватних клінік',
            'аудит фінансової звітності' => 'аудиторів фінансової звітності',
        ][$normalized] ?? $normalized;
    }

    /**
     * FontAwesome-іконка категорії для хабу рейтингів — у тому самому стилі, що
     * й решта сайту. ВАЖЛИВО: сайт вантажить урізаний СУБСЕТ FA (~165 гліфів),
     * тож обираємо лише іконки, які вже вживаються деінде (гарантовано є у
     * шрифті). Відсутні у сабсеті гліфи (fa-spa, fa-stethoscope, fa-language,
     * fa-house-medical, fa-user-doctor…) підмінені близькими наявними, інакше
     * рендерився б порожній квадрат.
     */
    private function ratingCategoryIcon(string $name, string $configuredIcon): string
    {
        $configuredIcon = trim($configuredIcon);
        if ($configuredIcon !== '') {
            return $configuredIcon;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));

        return [
            'адвокати' => 'fa-solid fa-gavel',
            'нотаріуси' => 'fa-solid fa-file-shield',
            'стоматології' => 'fa-solid fa-tooth',
            'стоматологи' => 'fa-solid fa-tooth',
            'косметолог' => 'fa-solid fa-sparkles',
            'косметологи' => 'fa-solid fa-sparkles',
            'косметологічні клініки' => 'fa-solid fa-wand-magic-sparkles',
            'пластичні хірурги' => 'fa-solid fa-user-check',
            'агенства нерухомості' => 'fa-solid fa-building',
            'агентства нерухомості' => 'fa-solid fa-building',
            'автошколи' => 'fa-solid fa-car-side',
            'мовні школи' => 'fa-solid fa-graduation-cap',
            'ветеринари та ветеринарні клініки' => 'fa-solid fa-heart',
            'будівельні магазини' => 'fa-solid fa-helmet-safety',
            'приватні клініки' => 'fa-solid fa-prescription-bottle-medical',
            'аудит фінансової звітності' => 'fa-solid fa-chart-line',
        ][$normalized] ?? 'fa-solid fa-ranking-star';
    }

    /**
     * Міста довідника, де в цій категорії достатньо профілів з відгуками.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, array{name: string, url: string}>
     */
    private function cityLinks(array $categoryIds, string $categorySlug, ?string $currentCitySlug): array
    {
        $byCity = rescue(fn () => Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->where(function ($q): void {
                $q->whereNull('rating_placement')->orWhere('rating_placement', '!=', 'hide');
            })
            ->where('reviews_count', '>=', 1)
            ->whereHas('categories', fn ($q) => $q
                ->whereIn('categories.id', $categoryIds)
                ->where('profile_category.is_primary', true))
            ->selectRaw('lower(trim(city)) as city_key, count(*) as n')
            ->groupBy('city_key')
            ->havingRaw('count(*) >= ' . self::MIN_INDEXABLE)
            ->orderByDesc('n')
            ->pluck('n', 'city_key'), collect(), false);

        $links = [];
        foreach (RegionCityDirectory::citySlugMap() as $slug => $name) {
            if ($slug === $currentCitySlug) {
                continue;
            }
            $variants = collect(RegionCityDirectory::variantsForCity($name))
                ->push($name)
                ->map(fn (string $v) => mb_strtolower(trim($v)));
            if ($variants->contains(fn (string $v) => $byCity->has($v))) {
                $links[] = [
                    'name' => $name,
                    'url' => route('top.rating.city', ['category' => $categorySlug, 'city' => $slug]),
                ];
            }
            if (count($links) >= 10) {
                break;
            }
        }

        return $links;
    }

    private function dossierExcerpt(?string $dossier): ?string
    {
        $plain = trim((string) preg_replace([
            '/```.*?```/su',
            '/\*\*|__|[#>*`\[\]\(\)]/u',
            '/^\s*[-+]\s+/mu',
            '/\s+/u',
        ], ['', '', '', ' '], (string) $dossier));

        if (mb_strlen($plain) < 50) {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $excerpt = trim(implode(' ', array_slice($sentences, 0, 2)));

        return Str::limit($excerpt !== '' ? $excerpt : $plain, 230, '…');
    }
}
