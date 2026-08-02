<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\WebsiteUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class SearchSuggestController extends Controller
{
    private const PUBLIC_CACHE_TTL_SECONDS = 300;

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

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $limit = max(4, min(12, (int) $request->query('limit', 8)));
        $scope = trim((string) $request->query('scope', 'all'));
        // v4: мета профілю = місто · підкатегорія (замість сайту).
        $cacheKey = 'public:search-suggest:v4:' . md5(json_encode([$scope, $query, $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $items = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PUBLIC_CACHE_TTL_SECONDS),
            function () use ($scope, $query, $limit): array {
                if ($scope === 'profiles') {
                    return $query === ''
                        ? $this->buildPopularProfileItems($limit)
                        : $this->buildProfileQueryItems($query, $limit);
                }

                if ($scope === 'claim_profiles') {
                    return $query === ''
                        ? $this->buildPopularClaimProfileItems($limit)
                        : $this->buildClaimProfileQueryItems($query, $limit);
                }

                return $query === ''
                    ? $this->buildPopularItems($limit)
                    : $this->buildQueryItems($query, $limit);
            }
        );

        return response()->json([
            'query' => $query,
            'items' => $items,
        ]);
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug?:string}>
     */
    private function buildPopularItems(int $limit): array
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'icon', 'parent_id'])
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->withCount('profiles')
            ->orderByDesc('profiles_count')
            ->orderBy('name')
            ->take($limit)
            ->get();

        $items = $categories->map(function (Category $category) {
            return [
                'type' => 'category',
                'label' => $category->name,
                'meta' => $category->profiles_count > 0 ? ((int) $category->profiles_count . ' профілів') : null,
                'icon' => $category->icon ?: (self::CATEGORY_ICONS[$category->slug] ?? 'fa-solid fa-tag'),
                'url' => route('catalog', ['sub' => $category->name]),
            ];
        })->values()->all();

        if (!empty($items)) {
            return $items;
        }

        return [[
            'type' => 'query',
            'label' => 'Популярні запити',
            'meta' => null,
            'icon' => 'fa-solid fa-arrow-trend-up',
            'url' => route('catalog'),
        ]];
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug?:string}>
     */
    private function buildQueryItems(string $query, int $limit): array
    {
        $patterns = $this->buildSearchPatterns($query);

        // Категорії/підкатегорії — першими у підказках: вони ведуть одразу
        // на відфільтрований каталог і корисніші за окремий профіль.
        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'icon', 'parent_id'])
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->where(function ($builder) use ($patterns) {
                $builder
                    ->where('name', 'like', $patterns['contains'])
                    ->orWhere('name', 'like', $patterns['contains_title']);
            })
            ->withCount('profiles')
            ->orderByDesc('profiles_count')
            ->orderBy('name')
            ->take((int) ceil($limit * 0.4))
            ->get();

        $remainingLimit = max(0, $limit - $categories->count());
        $profiles = $this->applyProfileSearch(Profile::query(), $patterns)
            ->select(['id', 'slug', 'name', 'website', 'city'])
            ->with('categories:id,name,parent_id')
            ->take($remainingLimit)
            ->get();

        $profileItems = $profiles->map(function (Profile $profile) use ($query) {
            return [
                'type' => 'profile',
                'label' => $profile->name,
                'meta' => $this->profileLocationMeta($profile),
                'icon' => 'fa-regular fa-user',
                'profile_slug' => $profile->slug,
                'url' => route('profile.show', [
                    'slug' => $profile->slug,
                ]),
            ];
        });

        $categoryItems = $categories->map(function (Category $category) use ($query) {
            return [
                'type' => 'category',
                'label' => $category->name,
                'meta' => $category->profiles_count > 0 ? ((int) $category->profiles_count . ' профілів') : null,
                'icon' => $category->icon ?: (self::CATEGORY_ICONS[$category->slug] ?? 'fa-solid fa-tag'),
                'url' => route('catalog', ['q' => $query, 'sub' => $category->name]),
            ];
        });

        $items = $categoryItems
            ->concat($profileItems)
            ->take($limit)
            ->values()
            ->all();

        if (!empty($items)) {
            return $items;
        }

        return [[
            'type' => 'query',
            'label' => 'Пошук у каталозі',
            'meta' => 'Показати результати за запитом "' . $query . '"',
            'icon' => 'fa-solid fa-magnifying-glass',
            'url' => route('catalog', ['q' => $query]),
        ]];
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}>
     */
    private function buildPopularProfileItems(int $limit): array
    {
        return Profile::query()
            ->select(['id', 'slug', 'name', 'website', 'city', 'is_verified', 'is_pro', 'reviews_count', 'rating_avg'])
            ->with('categories:id,name,parent_id')
            ->where('status', 'active')
            ->orderByDesc('is_verified')
            ->orderByDesc('is_pro')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg')
            ->take($limit)
            ->get()
            ->map(fn (Profile $profile) => [
                'type' => 'profile',
                'label' => $profile->name,
                'meta' => $this->profileLocationMeta($profile),
                'icon' => 'fa-regular fa-user',
                'profile_slug' => $profile->slug,
                'url' => route('profile.show', [
                    'slug' => $profile->slug,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}>
     */
    private function buildProfileQueryItems(string $query, int $limit): array
    {
        $patterns = $this->buildSearchPatterns($query);

        return $this->applyProfileSearch(Profile::query(), $patterns)
            ->select(['id', 'slug', 'name', 'website', 'city', 'is_verified', 'is_pro', 'reviews_count', 'rating_avg'])
            ->with('categories:id,name,parent_id')
            ->take($limit)
            ->get()
            ->map(fn (Profile $profile) => [
                'type' => 'profile',
                'label' => $profile->name,
                'meta' => $this->profileLocationMeta($profile),
                'icon' => 'fa-regular fa-user',
                'profile_slug' => $profile->slug,
                'url' => route('profile.show', [
                    'slug' => $profile->slug,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}>
     */
    private function buildPopularClaimProfileItems(int $limit): array
    {
        return Profile::query()
            ->select(['id', 'slug', 'name', 'website', 'city', 'is_verified', 'is_pro', 'reviews_count', 'rating_avg'])
            ->where('status', 'active')
            ->orderByDesc('is_verified')
            ->orderByDesc('is_pro')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg')
            ->take($limit)
            ->get()
            ->map(fn (Profile $profile) => $this->mapClaimProfileItem($profile))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}>
     */
    private function buildClaimProfileQueryItems(string $query, int $limit): array
    {
        $patterns = $this->buildSearchPatterns($query);

        return $this->applyProfileSearch(Profile::query(), $patterns)
            ->select(['id', 'slug', 'name', 'website', 'city', 'is_verified', 'is_pro', 'reviews_count', 'rating_avg'])
            ->take($limit)
            ->get()
            ->map(fn (Profile $profile) => $this->mapClaimProfileItem($profile, $query))
            ->values()
            ->all();
    }

    /**
     * Мета профілю в публічних підказках: «місто · підкатегорія» — одразу
     * зрозуміло, хто це і звідки. Сайт свідомо не показуємо (у claim-потоці
     * кабінету він лишається — там допомагає впізнати СВОЮ компанію).
     */
    private function profileLocationMeta(Profile $profile): ?string
    {
        $subcategory = $profile->categories
            ->first(fn (Category $category) => $category->parent_id !== null)
            ?->name;

        $parts = array_filter([
            trim((string) $profile->city) ?: null,
            $subcategory ? trim((string) $subcategory) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function buildSearchPatterns(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $title = mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');

        return [
            'normalized' => $normalized,
            'title' => $title,
            'contains' => '%' . $normalized . '%',
            'contains_title' => '%' . $title . '%',
            'starts' => $normalized . '%',
            'starts_title' => $title . '%',
            'word' => '% ' . $normalized . '%',
            'word_title' => '% ' . $title . '%',
        ];
    }

    /**
     * @param Builder<Profile> $query
     * @param array<string, string> $patterns
     * @return Builder<Profile>
     */
    private function applyProfileSearch(Builder $query, array $patterns): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($patterns) {
                $builder
                    ->where('name', 'like', $patterns['contains'])
                    ->orWhere('name', 'like', $patterns['contains_title'])
                    ->orWhere('website', 'like', $patterns['contains'])
                    ->orWhere('slug', 'like', $patterns['contains'])
                    ->orWhere('city', 'like', $patterns['contains'])
                    ->orWhere('city', 'like', $patterns['contains_title']);
            })
            ->orderByRaw(
                'CASE
                    WHEN name LIKE ? THEN 700
                    WHEN name LIKE ? THEN 700
                    WHEN name LIKE ? THEN 600
                    WHEN name LIKE ? THEN 600
                    WHEN name LIKE ? THEN 500
                    WHEN name LIKE ? THEN 500
                    WHEN slug LIKE ? THEN 400
                    WHEN website LIKE ? THEN 300
                    WHEN city LIKE ? THEN 200
                    WHEN city LIKE ? THEN 200
                    ELSE 0
                END DESC',
                [
                    $patterns['starts'],
                    $patterns['starts_title'],
                    $patterns['word'],
                    $patterns['word_title'],
                    $patterns['contains'],
                    $patterns['contains_title'],
                    $patterns['contains'],
                    $patterns['contains'],
                    $patterns['contains'],
                    $patterns['contains_title'],
                ]
            )
            ->orderByDesc('is_verified')
            ->orderByDesc('is_pro')
            ->orderByDesc('reviews_count')
            ->orderByDesc('rating_avg');
    }

    /**
     * @return array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}
     */
    private function mapClaimProfileItem(Profile $profile, string $query = ''): array
    {
        $params = [
            'tab' => 'claims',
            'claim_profile' => $profile->id,
        ];

        if ($query !== '') {
            $params['q'] = $query;
        }

        return [
            'type' => 'profile',
            'label' => $profile->name,
            'meta' => WebsiteUrl::display($profile->website) ?: ($profile->city ?: null),
            'icon' => 'fa-regular fa-user',
            'profile_id' => $profile->id,
            'profile_slug' => $profile->slug,
            'url' => route('pro.account', $params),
        ];
    }
}
