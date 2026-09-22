<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\SearchText;
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
        // v6: пошук по профілях став token-based, щоб порядок слів не ламав результат.
        $cacheKey = 'public:search-suggest:v6:' . md5(json_encode([$scope, $query, $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
                if (! empty($patterns['token_groups'])) {
                    foreach ($patterns['token_groups'] as $patternGroup) {
                        $builder->where(function ($tokenQuery) use ($patternGroup) {
                            foreach ($patternGroup as $like) {
                                $tokenQuery->orWhere('name', 'like', $like);
                            }
                        });
                    }

                    return;
                }

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
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            // Підказки «популярне» до введення запиту — за чесною популярністю
            // (та сама шкала, що в каталозі), без платного пріоритету.
            ->orderByDesc('popularity_score')
            ->orderByDesc('reviews_count')
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
            // Пошук власного профілю для підтвердження прав — PRO тут не
            // впливає ні на що, лишаємо просто популярні.
            ->orderByDesc('popularity_score')
            ->orderByDesc('reviews_count')
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
        $normalized = SearchText::normalize($query);
        $title = mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
        $tokens = SearchText::tokens($query);

        return [
            'normalized' => $normalized,
            'title' => $title,
            'tokens' => $tokens,
            'token_groups' => collect($tokens)
                ->map(fn ($token) => collect(SearchText::caseVariants($token))
                    ->filter()
                    ->unique()
                    ->map(fn ($variant) => '%'.$variant.'%')
                    ->values()
                    ->all())
                ->filter()
                ->values()
                ->all(),
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
     * @param array<string, mixed> $patterns
     * @return Builder<Profile>
     */
    private function applyProfileSearch(Builder $query, array $patterns): Builder
    {
        return $query
            ->where('status', 'active')
            // Ті самі умови видимості, що в каталозі: прихований профіль не має
            // спливати у підказках (інакше «приховати» ховає лише наполовину).
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->where(function (Builder $builder) use ($patterns) {
                if (! empty($patterns['token_groups'])) {
                    foreach ($patterns['token_groups'] as $patternGroup) {
                        $builder->where(function (Builder $tokenQuery) use ($patternGroup) {
                            foreach ($patternGroup as $like) {
                                $tokenQuery
                                    ->orWhere('name', 'like', $like)
                                    ->orWhere('website', 'like', $like)
                                    ->orWhere('slug', 'like', $like)
                                    ->orWhere('city', 'like', $like);
                            }
                        });
                    }

                    return;
                }

                $builder
                    ->where('name', 'like', $patterns['contains'])
                    ->orWhere('name', 'like', $patterns['contains_title'])
                    ->orWhere('website', 'like', $patterns['contains'])
                    ->orWhere('slug', 'like', $patterns['contains'])
                    ->orWhere('city', 'like', $patterns['contains'])
                    ->orWhere('city', 'like', $patterns['contains_title']);
            })
            ->orderByRaw(
                $this->profileSearchRelevanceSql($patterns),
                $this->profileSearchRelevanceBindings($patterns)
            )
            // Релевантність вирішує; PRO — лише тайбрейк між однаково
            // релевантними (обіцяна перевага у внутрішньому пошуку),
            // далі — популярність.
            ->orderByDesc('is_pro')
            ->orderByDesc('popularity_score')
            ->orderByDesc('reviews_count');
    }

    /**
     * @param array<string, mixed> $patterns
     */
    private function profileSearchRelevanceSql(array $patterns): string
    {
        $sql = 'CASE
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
        ';

        foreach (['name' => 450, 'slug' => 350, 'website' => 250, 'city' => 150] as $column => $score) {
            [$tokenSql] = $this->tokenAndLikeExpression($column, (array) ($patterns['tokens'] ?? []));
            if ($tokenSql !== '') {
                $sql .= "WHEN {$tokenSql} THEN {$score}\n                    ";
            }
        }

        return $sql.'ELSE 0
                END DESC';
    }

    /**
     * @param array<string, mixed> $patterns
     * @return array<int, string>
     */
    private function profileSearchRelevanceBindings(array $patterns): array
    {
        $bindings = [
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
        ];

        foreach (['name', 'slug', 'website', 'city'] as $column) {
            [, $tokenBindings] = $this->tokenAndLikeExpression($column, (array) ($patterns['tokens'] ?? []));
            array_push($bindings, ...$tokenBindings);
        }

        return $bindings;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array{0:string,1:array<int,string>}
     */
    private function tokenAndLikeExpression(string $column, array $tokens): array
    {
        if (empty($tokens)) {
            return ['', []];
        }

        $parts = [];
        $bindings = [];

        foreach ($tokens as $token) {
            $variants = SearchText::caseVariants($token);
            $variantParts = [];
            foreach ($variants as $variant) {
                $variantParts[] = "{$column} LIKE ?";
                $bindings[] = '%'.$variant.'%';
            }
            $parts[] = '('.implode(' OR ', $variantParts).')';
        }

        return ['('.implode(' AND ', $parts).')', $bindings];
    }

    /**
     * @return array{type:string,label:string,meta:?string,icon:string,url:string,profile_slug:string}
     */
    private function mapClaimProfileItem(Profile $profile, string $query = ''): array
    {
        return [
            'type' => 'profile',
            'label' => $profile->name,
            'meta' => WebsiteUrl::display($profile->website) ?: ($profile->city ?: null),
            'icon' => 'fa-regular fa-user',
            'profile_id' => $profile->id,
            'profile_slug' => $profile->slug,
            'url' => route('pro.account.billing.pay', ['profile' => $profile->id]),
        ];
    }
}
