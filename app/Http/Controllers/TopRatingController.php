<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\CategoryHierarchy;
use App\Support\MediaUrl;
use App\Support\RegionCityDirectory;
use App\Models\TopRatingSnapshot;
use App\Services\Rating\TopRatingIndex;
use App\Services\Rating\TopRatingNarrative;
use App\Services\Rating\TopRatingPool;
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
            'articles' => TopRatingIndexCache::remember(fn (): array => $this->topIndexPayload())['articles'] ?? [],
            'year' => now()->year,
        ]);
    }

    /**
     * Стрічка опублікованих статей-рейтингів: будуємо з реальних місячних
     * зрізів, а не з категорій. Сторінка існує лише там, де зріз збережено,
     * тож хаб показує рівно те, що справді опубліковано.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ratingArticles(): array
    {
        $period = TopRatingSnapshot::query()->max('period');

        if ($period === null) {
            return [];
        }

        $rows = TopRatingSnapshot::query()
            ->where('period', $period)
            ->orderBy('category_slug')
            ->orderByRaw('city_slug is null desc')
            ->orderBy('city_slug')
            ->orderBy('position')
            ->with('profile:id,name,slug,logo_url,city')
            ->get()
            ->groupBy(fn (TopRatingSnapshot $row) => $row->category_slug . '|' . (string) $row->city_slug);

        $categories = Category::query()
            ->whereIn('slug', $rows->map(fn ($g) => $g->first()->category_slug)->unique())
            ->get()
            ->keyBy('slug');

        $articles = [];

        foreach ($rows as $group) {
            $first = $group->first();
            $category = $categories->get($first->category_slug);

            if ($category === null) {
                continue;
            }

            $cityName = $first->city_slug ? RegionCityDirectory::cityFromSlug($first->city_slug) : null;
            $catLabel = $this->ratingCategoryLabel((string) $category->name);
            $placeLabel = $cityName
                ? (RegionCityDirectory::cityLocative($cityName) ?: $cityName)
                : 'України';

            $articles[] = [
                'title' => 'ТОП-' . $group->count() . ' ' . $catLabel . ' ' . $placeLabel,
                'category_name' => (string) $category->name,
                'category_slug' => $first->category_slug,
                'city_name' => $cityName,
                'url' => $first->city_slug
                    ? route('top.rating.city', ['category' => $first->category_slug, 'city' => $first->city_slug])
                    : route('top.rating', ['category' => $first->category_slug]),
                'period' => $first->period,
                'period_label' => $this->periodLabel($first->period),
                'places' => $group->count(),
                'leader' => (string) ($group->first()->profile->name ?? ''),
                'leader_rating' => (float) $group->first()->rating,
                // Обкладинку картки робимо з фото самих учасників рейтингу —
                // це доречніше за абстрактний градієнт і чесно показує, про
                // кого огляд. Беремо перших, у кого лого справді є.
                'avatars' => $group
                    ->map(function (TopRatingSnapshot $row): ?array {
                        if (! $row->profile) {
                            return null;
                        }

                        // Справжнє фото цінніше за згенеровану заглушку, тому
                        // спершу шукаємо його і виносимо такі картки наперед.
                        $real = MediaUrl::realProfileLogoUrl($row->profile, 256);
                        $url = $real ?: MediaUrl::profileLogoUrl($row->profile, 256);

                        return filled($url)
                            ? ['name' => (string) $row->profile->name, 'url' => $url, 'real' => $real !== null]
                            : null;
                    })
                    ->filter()
                    ->sortByDesc('real')
                    ->take(4)
                    ->values()
                    ->all(),
                'reviews_total' => (int) $group->sum('reviews_count'),
                'icon_class' => $this->ratingCategoryIcon((string) $category->name, (string) ($category->icon ?? '')),
                // Колір обкладинки — як у блозі (blog-cover--*), детерміновано
                // за категорією, щоб стрічка виглядала різнобарвно, але стабільно.
                'accent' => ['blue', 'violet', 'green', 'amber'][crc32($first->category_slug) % 4],
                'cover_url' => \App\Support\TopRatingCover::forCategory($category),
                'group' => $first->city_slug === null ? 'ua' : 'city',
            ];
        }

        usort($articles, fn (array $a, array $b): int => [$b['reviews_total']] <=> [$a['reviews_total']]);

        return $articles;
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

        // Рейтинг беремо із замороженого місячного зрізу: сторінка — стаття
        // «станом на {місяць}», а не живий запит, який пливе щодня.
        $period = TopRatingSnapshot::currentPeriod();
        $snapshot = $this->snapshotRows($period, $categorySlug, $citySlug);

        if ($snapshot->isEmpty()) {
            $period = $this->latestPeriodFor($categorySlug, $citySlug) ?? $period;
            $snapshot = $this->snapshotRows($period, $categorySlug, $citySlug);
        }

        $narrative = app(TopRatingNarrative::class);

        if ($snapshot->isEmpty()) {
            // Зрізу ще немає (нова комбінація) — рахуємо наживо, щоб сторінка
            // не була порожньою до першого прогону команди.
            $ranked = array_slice(
                app(TopRatingIndex::class)->rank(app(TopRatingPool::class)->build($category, $cityName)),
                0,
                self::TOP_LIMIT
            );
            $rows = [];
            foreach ($ranked as $i => $item) {
                $rows[] = $this->articleRow($item['profile'], $i + 1, (float) $item['index'], $item['components'], $excludeNames, $narrative);
            }
            $changes = null;
        } else {
            $profiles = Profile::query()
                ->whereIn('id', $snapshot->pluck('profile_id'))
                ->with(['rating', 'categories:id,name,parent_id'])
                ->get()
                ->keyBy('id');

            $rows = [];
            foreach ($snapshot as $row) {
                $profile = $profiles->get($row->profile_id);
                if (! $profile) {
                    continue;
                }
                $rows[] = $this->articleRow($profile, (int) $row->position, (float) $row->index_score, (array) $row->components, $excludeNames, $narrative);
            }

            $previous = $this->snapshotRows(TopRatingSnapshot::previousPeriod($period), $categorySlug, $citySlug);
            $changes = $narrative->changesParagraph(
                $snapshot->load('profile:id,name'),
                $previous->load('profile:id,name'),
                $this->periodLabel($period)
            );
        }

        // Інші міста цієї категорії, де рейтинг має сенс (внутрішня перелінковка).
        $cityLinks = $this->cityLinks($categoryIds, $categorySlug, $citySlug);

        return view('static.top-rating', [
            'category' => $category,
            'categorySlug' => $categorySlug,
            'categoryRatingName' => $this->ratingCategoryLabel((string) $category->name),
            'cityName' => $cityName,
            'citySlug' => $citySlug,
            'rows' => $rows,
            'period' => $period,
            'periodLabel' => $this->periodLabel($period),
            'changesParagraph' => $changes,
            'coverUrl' => \App\Support\TopRatingCover::forCategory($category),
            'coverIcon' => $this->ratingCategoryIcon((string) $category->name, (string) ($category->icon ?? '')),
            'weights' => TopRatingIndex::WEIGHTS,
            'minReviews' => TopRatingIndex::MIN_REVIEWS,
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
            'articles' => $this->ratingArticles(),
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

    /**
     * @return \Illuminate\Support\Collection<int, TopRatingSnapshot>
     */
    private function snapshotRows(string $period, string $categorySlug, ?string $citySlug): \Illuminate\Support\Collection
    {
        return TopRatingSnapshot::query()
            ->where('period', $period)
            ->where('category_slug', $categorySlug)
            ->when($citySlug === null, fn ($q) => $q->whereNull('city_slug'), fn ($q) => $q->where('city_slug', $citySlug))
            ->orderBy('position')
            ->get();
    }

    private function latestPeriodFor(string $categorySlug, ?string $citySlug): ?string
    {
        return TopRatingSnapshot::query()
            ->where('category_slug', $categorySlug)
            ->when($citySlug === null, fn ($q) => $q->whereNull('city_slug'), fn ($q) => $q->where('city_slug', $citySlug))
            ->max('period');
    }

    private function periodLabel(string $period): string
    {
        return \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $period . '-01')->translatedFormat('F Y');
    }

    /**
     * Рядок статті: профіль + індекс + опис + теми відгуків.
     *
     * @param  array<string, float>  $components
     * @return array<string, mixed>
     */
    private function articleRow(
        Profile $profile,
        int $position,
        float $index,
        array $components,
        \Illuminate\Support\Collection $excludeNames,
        TopRatingNarrative $narrative
    ): array {
        return [
            'rank' => $position,
            'index' => $index,
            'components' => $components,
            'url' => route('profile.show', ['slug' => $profile->slug]),
            'name' => (string) $profile->name,
            'city' => $profile->city ? (string) $profile->city : null,
            'logo_url' => MediaUrl::profileLogoUrl($profile, 128),
            'initials' => mb_strtoupper(mb_substr(trim((string) $profile->name), 0, 1)),
            'rating' => round((float) $profile->rating_avg, 2),
            'rating_visible' => \App\Support\RatingDisplay::visible((int) $profile->reviews_count),
            'reviews_count' => (int) $profile->reviews_count,
            'verified' => (bool) ($profile->is_owner_verified || $profile->is_verified),
            'description' => $narrative->profileDescription($profile, $position),
            'topics' => $narrative->reviewTopics($profile),
            'facts' => $narrative->factsLine($profile, $components),
            'specializations' => $profile->categories
                ->pluck('name')
                ->map(fn ($n) => trim((string) $n))
                ->filter(fn (string $n) => $n !== '' && ! $excludeNames->contains(mb_strtolower($n)))
                ->unique()
                ->take(2)
                ->values()
                ->all(),
        ];
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
            'ресторани' => 'ресторанів',
            'хімчистки' => 'хімчисток',
            'забудовники' => 'забудовників',
            'встановлення вікон' => 'компаній зі встановлення вікон',
            'юридичні послуги' => 'юридичних послуг',
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
