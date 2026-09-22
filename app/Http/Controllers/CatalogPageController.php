<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\OfficialReplyReaction;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\ProfileReviewReaction;
use App\Models\ReviewReply;
use App\Models\ReviewReplyReaction;
use App\Models\ReviewReport;
use App\Services\ProfileAnalyticsService;
use App\Support\CategoryHierarchy;
use App\Support\MediaUrl;
use App\Support\ProfileRanking;
use App\Support\ProfileSeo;
use App\Support\RegionCityDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogPageController extends Controller
{
    private const PUBLIC_CACHE_TTL_SECONDS = 300;

    private const DEFAULT_POPULAR_QUERIES = [
        'Стоматології Києва',
        'СТО поруч',
        'Онлайн-магазини одягу',
        'Сервісні центри',
        'Ресторани',
    ];

    private ?bool $reviewRepliesAvailable = null;

    private ?bool $reviewReactionsAvailable = null;

    private ?bool $reviewReplyReactionsAvailable = null;

    private ?bool $officialReplyReactionsAvailable = null;

    private const TOP_RATING_MIN_PROFILES = 3;

    public function index(Request $request, ?array $pathContext = null)
    {
        // Консолідація: прямий захід на старий /catalog?category=X (одна чиста
        // категорія) → 301 на ЧПУ /catalog/{slug}. ajax і ЧПУ-роут (pathContext)
        // не чіпаємо, щоб не було редірект-циклу.
        if ($pathContext === null && ! $request->boolean('ajax') && ($request->filled('category') || $request->filled('sub'))) {
            $pretty = $this->buildCatalogPrettyUrl($request);
            if (str_starts_with($pretty, '/catalog/')) {
                return redirect($pretty, 301);
            }
        }

        $search = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $subcategory = trim((string) $request->query('sub', ''));
        $sort = trim((string) $request->query('sort', 'recommended'));
        $allowedSorts = ['recommended', 'popular', 'rating_desc', 'reviews_desc', 'newest', 'name_asc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'recommended';
        }
        $selectedCategories = collect((array) $request->query('categories', []))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $selectedRegions = collect((array) $request->query('regions', []))
            ->map(fn ($item) => RegionCityDirectory::canonicalCity(trim((string) $item)) ?? trim((string) $item))
            ->filter()
            ->unique(fn ($item) => RegionCityDirectory::normalizeCity((string) $item))
            ->values()
            ->all();
        $ratingFilter = trim((string) $request->query('rating', ''));
        $reviewsCountFilter = trim((string) $request->query('reviews_count', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $legacyTab = trim((string) $request->query('tab', ''));
        if ($statusFilter === '' && in_array($legacyTab, ['verified', 'owner_verified'], true)) {
            $statusFilter = $legacyTab;
        }

        $catalogStats = $this->buildCatalogStats();

        $profilesQuery = Profile::query()
            ->select([
                'profiles.id',
                'profiles.slug',
                'profiles.name',
                'profiles.logo_url',
                'profiles.website',
                'profiles.city',
                'profiles.district',
                'profiles.address',
                'profiles.description',
                'profiles.rating_avg',
                'profiles.reviews_count',
                'profiles.is_verified',
                'profiles.is_owner_verified',
                'profiles.owner_user_id',
                'profiles.is_pro',
                'profiles.popularity_score',
                'profiles.dovira_recommendation_status',
                'profiles.dossier_verdict',
                'profiles.created_at',
            ])
            ->with([
                'services',
                // Розподіл оцінок для «% позитивних» на картці.
                'rating:profile_id,dist_1,dist_2,dist_3,dist_4,dist_5,reviews_counted',
                'categories' => fn ($categories) => $categories->select(['categories.id', 'categories.name'])->orderByDesc('profile_category.is_primary'),
            ])
            ->withCount('officialReplies')
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            // Прапорці підписки одним запитом: інакше кожна картка робила
            // власний SELECT EXISTS по pro_subscriptions (N+1).
            ->withProSubscriptionFlags()
            ->where('status', 'active')
            // Прапорці видимості — та сама умова, що в sitemap і на головній.
            // Без них прихований профіль лишався у видачі каталогу.
            ->where('is_published', true)
            ->where('show_in_catalog', true);

        $this->applyCatalogFilters(
            $profilesQuery,
            $search,
            $category,
            $subcategory,
            $selectedCategories,
            $selectedRegions,
            $ratingFilter,
            $reviewsCountFilter,
            $statusFilter
        );

        // PRO-блок: промарковані PRO-профілі окремо НАД чесною органічною
        // видачею (див. App\Support\ProfileRanking і /rating-method). Будуємо
        // до сортування/пагінації, бо їх треба виключити з органіки.
        // Блок «Спонсоровані» прибрано 22.09.2026: підписка тепер піднімає
        // профіль у самій видачі, а не показує його окремою секцією.
        $proBlockProfiles = collect();
        if ($proBlockProfiles->isNotEmpty()) {
            $profilesQuery->whereNotIn('profiles.id', $proBlockProfiles->pluck('id')->all());
        }

        $this->applySorting($profilesQuery, $sort);

        $profilesPaginator = $profilesQuery->simplePaginate(12);

        // Захист від «сторінки з самої реклами»: якщо після виключення PRO
        // органіки майже не лишилось — повертаємо їх у звичайний список.
        if ($proBlockProfiles->isNotEmpty()
            && $profilesPaginator->getCollection()->count() < 5
            && ! $profilesPaginator->hasMorePages()) {
            $profilesPaginator->setCollection(
                $proBlockProfiles->concat($profilesPaginator->getCollection())->values()
            );
            $proBlockProfiles = collect();
        }

        if ($pathContext === null) {
            $profilesPaginator->withQueryString();
        } else {
            // ЧПУ-посадкова: category/місто вже в path — інжектовані landing()-ом
            // параметри не тягнемо в лінки пагінації, інакше краулер бачить
            // брудні дублікати виду ?category=Адвокати&regions[0]=Вінниця&page=2.
            $cleanQuery = collect($request->query())
                ->except(['category'])
                ->map(function ($value, string $key) use ($pathContext) {
                    if ($key !== 'regions' || ! is_array($value)) {
                        return $value;
                    }

                    $pathCity = (string) ($pathContext['cityName'] ?? '');

                    return collect($value)
                        ->reject(fn ($region) => (string) $region === $pathCity)
                        ->values()
                        ->all();
                })
                ->reject(fn ($value) => $value === null || $value === '' || $value === [])
                ->all();

            $profilesPaginator->appends($cleanQuery);
        }

        $mappedProfiles = $profilesPaginator->getCollection()
            ->map(fn (Profile $profile) => $this->mapCatalogProfileCard($profile))
            ->values();

        /** @var LengthAwarePaginator $profilesPaginator */
        $profilesPaginator->setCollection($mappedProfiles);

        // PRO-блок рендериться тією ж карткою, тож і формат даних той самий.
        $proBlockProfiles = $proBlockProfiles
            ->map(fn (Profile $profile) => $this->mapCatalogProfileCard($profile))
            ->values();

        $categoryFilters = $this->buildCategoryFilters();
        $categoryOptions = $this->buildCategoryOptions();
        $regionFilters = $this->buildRegionFilters();
        $popularQueries = $this->buildPopularQueries($categoryFilters);

        if ($request->boolean('ajax')) {
            return $this->buildCatalogAjaxResponse($request, $profilesPaginator, $catalogStats, $proBlockProfiles);
        }

        // Server-rendered посилання на SEO-посадкові: краулер знаходить
        // ЧПУ-сторінки категорій зі звичайного каталогу, не лише з sitemap.
        $seoLandingCategories = rescue(fn () => cache()->remember(
            'catalog.seo-landing-categories',
            now()->addHours(6),
            fn () => Category::query()
                ->where('is_active', true)
                ->where('is_indexable', true)
                ->whereNotNull('slug')
                ->whereHas('profiles', fn ($profiles) => $profiles
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true))
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Category $landingCategory) => [
                    'name' => (string) $landingCategory->name,
                    'slug' => (string) $landingCategory->slug,
                ])
                ->all()
        ), [], false);

        // Єдина вітрина: коли зайшли по ЧПУ /catalog/{slug}[/{city}], каталог
        // рендериться з передобраною категорією + унікальні SEO-теги. Уточнювальні
        // параметри (rating/sort/q…) роблять сторінку noindex, щоб не плодити дублі.
        $pathCategory = $pathContext['category'] ?? null;
        $pathCityName = $pathContext['cityName'] ?? null;
        $pathCitySlug = $pathContext['citySlug'] ?? null;
        $pathCanonical = $pathContext['canonical'] ?? null;
        // Внутрішній пошук і фільтри не є окремими SEO-посадковими.
        // Tracking-параметри не змінюють вміст і не блокують індексацію.
        $pathIndexable = $search === ''
            && $category === ''
            && $subcategory === ''
            && $selectedCategories === []
            && $selectedRegions === []
            && $ratingFilter === ''
            && $reviewsCountFilter === ''
            && $statusFilter === ''
            && $sort === 'recommended'
            && max(1, (int) $request->query('page', 1)) === 1;
        $pathRelated = [];
        $topRatingUrl = null;
        $landingSeo = null;

        if ($pathCategory !== null) {
            $landingSeo = \App\Support\CatalogLandingSeo::build($pathCategory, $pathCityName);

            // Дата-блоки шапки — по категорії (+місту), а не по всьому каталогу:
            // числа в hero мають збігатися з фактами сторінки.
            $catalogStats = [
                'profiles' => $landingSeo['profiles_count'],
                'reviews' => $landingSeo['reviews_count'],
            ];

            $regionsCount = count(array_filter((array) $request->query('regions', [])));
            $hasRefiningParams = $request->filled('q')
                || $request->filled('rating')
                || $request->filled('reviews_count')
                || $statusFilter !== ''
                || ($pathContext['hasExplicitCategoryFilter'] ?? false)
                || (is_array($request->query('categories')) && count(array_filter((array) $request->query('categories'))) > 0)
                || $sort !== 'recommended'
                || $regionsCount > ($pathCityName !== null ? 1 : 0);

            // Пагінація: сторінки 2+ доступні краулеру (лінк-вага тече на глибокі
            // профілі), але noindex — індексується лише перша, канонічна.
            $pageNumber = max(1, (int) $request->query('page', 1));
            if ($pageNumber > 1 && $pathCanonical !== null) {
                $pathCanonical .= '?page=' . $pageNumber;
            }

            // Тонкі пари (1–2 профілі) не індексуємо: не годуємо пошук
            // сторінками-заглушками, follow лишається.
            $pathIndexable = $landingSeo['profiles_count'] >= \App\Support\CatalogLandingSeo::MIN_INDEXABLE_PROFILES
                && (bool) ($pathCategory->is_indexable ?? true)
                && ! $hasRefiningParams
                && $pageNumber === 1;

            $pathRelated = rescue(fn (): array => Category::query()
                ->where('is_active', true)
                ->where('id', '!=', $pathCategory->id)
                ->when(
                    $pathCategory->parent_id,
                    fn ($q) => $q->where('parent_id', $pathCategory->parent_id),
                    fn ($q) => $q->whereNull('parent_id')
                )
                ->orderByDesc('profiles_count')
                ->orderBy('name')
                ->limit(6)
                ->get(['id', 'name', 'slug'])
                ->all(), []);

            $topRatingUrl = $this->getTopRatingUrlForCategory($pathCategory, $pathCityName, $pathCitySlug);
        }

        return view('static.catalog', [
            'profiles' => $profilesPaginator,
            'proProfiles' => $proBlockProfiles,
            'catalogCount' => $this->buildCatalogCount($profilesPaginator),
            'categoryFilters' => $categoryFilters,
            'hasRecommendedProfiles' => $this->hasRecommendedProfiles(),
            'categoryOptions' => $categoryOptions,
            'seoLandingCategories' => $seoLandingCategories,
            'regionFilters' => $regionFilters,
            'popularQueries' => $popularQueries,
            'activeSort' => $sort,
            'catalogStats' => $catalogStats,
            'pathCategory' => $pathCategory,
            'pathCityName' => $pathCityName,
            'pathCitySlug' => $pathCitySlug,
            'pathCanonical' => $pathCanonical,
            'pathIndexable' => $pathIndexable,
            'pathRelated' => $pathRelated,
            'topRatingUrl' => $topRatingUrl,
            'landingSeo' => $landingSeo,
        ]);
    }

    /**
     * ЧПУ-адреса категорії / «категорія × місто» → та сама вітрина каталогу
     * з передобраною категорією (єдина сторінка, а не окрема лайтова). Path-
     * сегменти інжектимо в запит як звичайні фільтри, далі — конвеєр index().
     */
    public function landing(Request $request, string $categorySlug, ?string $citySlug = null)
    {
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $categorySlug)
            ->first();

        abort_if($category === null, 404);

        $cityName = null;
        if ($citySlug !== null) {
            $cityName = RegionCityDirectory::cityFromSlug($citySlug);
            // Другий сегмент — не місто, а послуга зі словника («ринопластика
            // відгуки» без міста) → сторінка послуги по всій Україні.
            if ($cityName === null && \App\Support\ServiceLandingSeo::service($categorySlug, $citySlug) !== null) {
                return app(\App\Http\Controllers\ServiceLandingController::class)->show($categorySlug, null, $citySlug);
            }
            abort_if($cityName === null, 404);
        }

        $hasExplicitCategoryFilter = $request->filled('category') || $request->filled('sub');

        // ЧПУ-сегменти → звичайні фільтри запиту: категорія (за назвою, як у
        // каталозі) і місто (в regions). Далі все працює наявним конвеєром.
        // Якщо це підкатегорія — віддаємо фільтрам пару «батько + підкатегорія»,
        // інакше сайдбар не показував вибір і писав «Усі категорії» на сторінці,
        // де категорія вже застосована (перевірено на /catalog/veterinari-...).
        if ($category->parent_id !== null) {
            $parent = Category::query()->find($category->parent_id);
            $request->merge([
                'category' => (string) ($parent->name ?? $category->name),
                'sub' => (string) $category->name,
            ]);
        } else {
            $request->merge(['category' => (string) $category->name]);
        }
        if ($cityName !== null) {
            $regions = collect((array) $request->query('regions', []))->filter()->values();
            if (! $regions->contains($cityName)) {
                $regions->push($cityName);
            }
            $request->merge(['regions' => $regions->all()]);
        }

        $canonical = $citySlug !== null
            ? route('catalog.landing.city', ['category' => $categorySlug, 'city' => $citySlug])
            : route('catalog.landing', ['category' => $categorySlug]);

        return $this->index($request, [
            'category' => $category,
            'cityName' => $cityName,
            'citySlug' => $citySlug,
            'canonical' => $canonical,
            'hasExplicitCategoryFilter' => $hasExplicitCategoryFilter,
        ]);
    }

    public function showProfile(Request $request, ?string $slug = null, ?ProfileAnalyticsService $analytics = null): Response
    {
        $analytics ??= app(ProfileAnalyticsService::class);
        $reviewRelations = $this->profileReviewRelations($request);

        $profileQuery = Profile::query()
            ->with([
                'categories',
                'services',
            ])
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            ->where('status', 'active');

        $profileModel = $slug
            ? (clone $profileQuery)->where('slug', $slug)->firstOrFail()
            : (clone $profileQuery)
                // Фолбек «показати хоч якийсь профіль», коли slug не заданий:
                // беремо найпопулярніший, без платних пріоритетів.
                ->orderByDesc('popularity_score')
                ->orderByDesc('reviews_count')
                ->firstOrFail();

        $visitorId = $analytics->ensureVisitorId($request);

        $profile = $this->mapProfile($profileModel);
        $profile = array_merge(
            $profile,
            $this->buildTrustMeta($profileModel),
            $this->buildTrustVisualMeta($profileModel),
            $this->buildRatingDistribution($profileModel)
        );
        $sampleReviews = $this->mapProfileReviews($profileModel, 12, $reviewRelations);
        $publishedReviewsTotal = max((int) $profileModel->reviews_count, $this->ratingDistributionTotal($profileModel));
        if ($publishedReviewsTotal === 0 && ! empty($sampleReviews)) {
            $publishedReviewsTotal = count($sampleReviews);
        }

        // Schema.org приймає лише власні відгуки платформи: рейтинги в розмітці
        // мають походити від користувачів сайту, тож імпортовані (external_source_type)
        // у структуровані дані не потрапляють — інакше це spammy structured markup.
        $nativeReviewStats = $profileModel->reviews()
            ->where('status', 'published')
            ->whereNull('external_source_type')
            ->selectRaw('count(*) as total, avg(rating) as avg_rating')
            ->first();
        $nativeReviewStats = [
            'count' => (int) ($nativeReviewStats->total ?? 0),
            'rating' => round((float) ($nativeReviewStats->avg_rating ?? 0), 1),
        ];

        // Thin content: профіль без опису й без відгуків не має унікальної
        // цінності для пошуку — закриваємо від індексації, поки не наповниться.
        $profile['is_indexable'] = $this->profileIsIndexable($profileModel, $publishedReviewsTotal);
        $profile['ai_review_summary'] = $profileModel->ai_review_summary;
        $profile['ai_review_summary_generated_at'] = $profileModel->ai_review_summary_generated_at;
        $profile['google_rating'] = $profileModel->google_rating;
        $profile['google_reviews_count'] = $profileModel->google_reviews_count;
        // Активний PRO не показує конкурентів на своїй сторінці (захист
        // трафіку — платна перевага), тож і не збираємо їх дарма.
        $relatedProfiles = $profileModel->hasActiveProSubscription()
            ? []
            // Саме mapCatalogProfileCard: картка каталогу чекає services як
            // масив НАЗВ, а mapProfile() (дані повної сторінки) віддає масив
            // обʼєктів із цінами — на профілях, де в «схожих» траплялися
            // послуги, це давало «Array to string conversion» у partial.
            : $this->buildRelatedProfiles($profileModel)
                ->map(fn (Profile $relatedProfile) => $this->mapCatalogProfileCard($relatedProfile))
                ->all();

        return response()
            ->view('static.lawyer', compact('profile', 'sampleReviews', 'relatedProfiles', 'publishedReviewsTotal', 'nativeReviewStats'))
            ->cookie(
                'dovira_visitor_id',
                $visitorId,
                60 * 24 * 365,
                '/',
                null,
                $request->isSecure(),
                false,
                false,
                'Lax'
            );
    }

    public function loadProfileReviews(Request $request, string $slug): JsonResponse
    {
        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        $reviewRelations = $this->profileReviewRelations($request);

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = max(1, min(20, (int) $request->query('limit', 10)));
        // Фільтр тональності: пагінація йде в межах відфільтрованого списку,
        // інакше «Показати ще» під фільтром вантажить пачки прихованих карток.
        $filter = trim((string) $request->query('filter', ''));

        $baseQuery = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published');

        if ($filter === 'positive') {
            $baseQuery->where('rating', '>=', 4);
        } elseif ($filter === 'negative') {
            $baseQuery->where('rating', '<=', 3);
        }

        $total = (clone $baseQuery)->count();
        $reviewModels = (clone $baseQuery)
            ->with($reviewRelations)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->skip($offset)
            ->take($limit)
            ->get();

        $items = $this->mapProfileReviewsFromCollection($profile, $reviewModels);
        $loaded = min($total, $offset + count($items));

        return response()->json([
            'items' => $items,
            'total' => $total,
            'loaded' => $loaded,
            'next_offset' => $loaded,
            'has_more' => $loaded < $total,
        ]);
    }

    public function storeReview(Request $request, string $slug): RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login', ['next' => $request->fullUrl()]);
        }

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        $validated = $this->validateReviewPayload($request);
        $message = $this->createPendingReview($request, $profile, $validated);

        return back()->withInput([])->with('review_submitted', $message);
    }

    public function storeReviewReply(Request $request, string $slug, ProfileReview $review): JsonResponse|RedirectResponse
    {
        abort_unless($this->reviewRepliesAvailable(), 503, 'Review replies are not available until migrations are applied.');

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless((int) $review->profile_id === (int) $profile->id && $review->status === 'published', 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parentReply = null;
        if (! empty($validated['parent_id'])) {
            $parentReply = ReviewReply::query()
                ->where('profile_review_id', $review->id)
                ->where('status', 'published')
                ->findOrFail((int) $validated['parent_id']);

            if ($parentReply->parent_id) {
                $parentReply = $parentReply->parent;
            }
        }

        $reply = ReviewReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $request->user()->id,
            'parent_id' => $parentReply?->id,
            'body' => trim((string) $validated['body']),
            'is_official' => false,
            'status' => 'published',
        ]);

        $reply->loadMissing([
            'author:id,name,avatar_url',
            'children.author:id,name,avatar_url',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Коментар опубліковано.',
                'reply' => $this->mapReviewReply($reply),
                'review_id' => $review->id,
                'parent_id' => $parentReply?->id,
            ]);
        }

        return back()->with('review_comment_submitted', 'Коментар опубліковано.');
    }

    public function toggleReviewReaction(Request $request, string $slug, ProfileReview $review): JsonResponse
    {
        abort_unless($this->reviewReactionsAvailable(), 503, 'Review reactions are not available until migrations are applied.');

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless((int) $review->profile_id === (int) $profile->id && $review->status === 'published', 404);

        $validated = $request->validate([
            'reaction' => ['required', 'in:like,dislike'],
        ]);

        $userId = (int) $request->user()->id;
        $requestedReaction = (string) $validated['reaction'];

        DB::transaction(function () use ($review, $userId, $requestedReaction): void {
            $existing = ProfileReviewReaction::query()
                ->where('profile_review_id', $review->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing && $existing->reaction === $requestedReaction) {
                $existing->delete();
            } elseif ($existing) {
                $existing->update(['reaction' => $requestedReaction]);
            } else {
                ProfileReviewReaction::query()->create([
                    'profile_review_id' => $review->id,
                    'user_id' => $userId,
                    'reaction' => $requestedReaction,
                ]);
            }

            $this->syncReviewReactionCounters($review);
        });

        $review->refresh();
        $currentReaction = ProfileReviewReaction::query()
            ->where('profile_review_id', $review->id)
            ->where('user_id', $userId)
            ->value('reaction');

        return response()->json([
            'ok' => true,
            'reaction' => $currentReaction,
            'like_count' => (int) $review->like_count,
            'dislike_count' => (int) $review->dislike_count,
        ]);
    }

    public function toggleReviewReplyReaction(Request $request, string $slug, ProfileReview $review, ReviewReply $reply): JsonResponse
    {
        abort_unless($this->reviewReplyReactionsAvailable(), 503, 'Review reply reactions are not available until migrations are applied.');

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless((int) $review->profile_id === (int) $profile->id && $review->status === 'published', 404);
        abort_unless((int) $reply->profile_review_id === (int) $review->id && $reply->status === 'published', 404);

        $validated = $request->validate([
            'reaction' => ['required', 'in:like,dislike'],
        ]);

        $userId = (int) $request->user()->id;
        $requestedReaction = (string) $validated['reaction'];

        DB::transaction(function () use ($reply, $userId, $requestedReaction): void {
            $existing = ReviewReplyReaction::query()
                ->where('review_reply_id', $reply->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing && $existing->reaction === $requestedReaction) {
                $existing->delete();
            } elseif ($existing) {
                $existing->update(['reaction' => $requestedReaction]);
            } else {
                ReviewReplyReaction::query()->create([
                    'review_reply_id' => $reply->id,
                    'user_id' => $userId,
                    'reaction' => $requestedReaction,
                ]);
            }

            $this->syncReviewReplyReactionCounters($reply);
        });

        $reply->refresh();
        $currentReaction = ReviewReplyReaction::query()
            ->where('review_reply_id', $reply->id)
            ->where('user_id', $userId)
            ->value('reaction');

        return response()->json([
            'ok' => true,
            'reaction' => $currentReaction,
            'like_count' => (int) $reply->like_count,
            'dislike_count' => (int) $reply->dislike_count,
        ]);
    }

    public function toggleOfficialReplyReaction(Request $request, string $slug, ProfileReview $review): JsonResponse
    {
        abort_unless($this->officialReplyReactionsAvailable(), 503, 'Official reply reactions are not available until migrations are applied.');

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless((int) $review->profile_id === (int) $profile->id && $review->status === 'published', 404);

        $officialReply = $review->officialReply()->firstOrFail();

        $validated = $request->validate([
            'reaction' => ['required', 'in:like,dislike'],
        ]);

        $userId = (int) $request->user()->id;
        $requestedReaction = (string) $validated['reaction'];

        DB::transaction(function () use ($officialReply, $userId, $requestedReaction): void {
            $existing = OfficialReplyReaction::query()
                ->where('official_reply_id', $officialReply->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing && $existing->reaction === $requestedReaction) {
                $existing->delete();
            } elseif ($existing) {
                $existing->update(['reaction' => $requestedReaction]);
            } else {
                OfficialReplyReaction::query()->create([
                    'official_reply_id' => $officialReply->id,
                    'user_id' => $userId,
                    'reaction' => $requestedReaction,
                ]);
            }

            $this->syncOfficialReplyReactionCounters($officialReply);
        });

        $officialReply->refresh();
        $currentReaction = OfficialReplyReaction::query()
            ->where('official_reply_id', $officialReply->id)
            ->where('user_id', $userId)
            ->value('reaction');

        return response()->json([
            'ok' => true,
            'reaction' => $currentReaction,
            'like_count' => (int) $officialReply->like_count,
            'dislike_count' => (int) $officialReply->dislike_count,
        ]);
    }

    public function storeReviewReport(Request $request, string $slug, ProfileReview $review): JsonResponse
    {
        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless((int) $review->profile_id === (int) $profile->id && $review->status === 'published', 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = ReviewReport::query()->firstOrCreate(
            [
                'profile_review_id' => $review->id,
                'reporter_user_id' => $request->user()->id,
                'status' => 'open',
            ],
            [
                'reason' => trim((string) ($validated['reason'] ?? 'Публічна скарга на відгук')) ?: 'Публічна скарга на відгук',
                'details' => trim((string) ($validated['details'] ?? '')),
            ]
        );

        if ($report->wasRecentlyCreated) {
            app(\App\Services\Notifications\TelegramAdminNotifier::class)->newReviewReport($report);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Скаргу надіслано на модерацію.',
        ]);
    }

    public function storeReviewByProfile(Request $request): RedirectResponse|JsonResponse
    {
        // Honeypot: bots fill the hidden field; real users never see it.
        if (trim((string) $request->input('website', '')) !== '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Дякуємо! Відгук надіслано на модерацію.',
                ]);
            }

            return back();
        }

        $validated = $this->validateReviewPayload($request, true);

        // Guests can publish with no contact details — the lowest-friction path.
        // Contact (email) is optional; spam is caught by Turnstile + auto-moderation.
        if (! $request->user()) {
            // Антиспам: Turnstile-перевірка анонімних відгуків (вмикається ключами).
            if (! \App\Support\Turnstile::passes($request->input('cf-turnstile-response'), $request->ip())) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'body' => 'Не вдалося підтвердити, що ви не робот. Оновіть сторінку і спробуйте ще раз.',
                ]);
            }
        }

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $validated['profile_slug'])
            ->firstOrFail();

        $message = $this->createPendingReview($request, $profile, $validated);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        }

        return back()->withInput([])->with('review_submitted', $message);
    }

    /**
     * @return array<int, array{name:string,slug:string,count:int,subs:array<int, array{name:string,slug:string,count:int}>}>
     */
    private function buildCategoryFilters(): array
    {
        return Cache::remember(
            'public:catalog:category-filters:v2',
            now()->addSeconds(self::PUBLIC_CACHE_TTL_SECONDS),
            function (): array {
                return Category::query()
                    ->with([
                        'children' => fn ($children) => $children
                            ->where('is_active', true)
                            ->withCount([
                                'profiles as profiles_count' => fn ($profiles) => $profiles->where('status', 'active'),
                            ])
                            ->orderByDesc('profiles_count')
                            ->orderBy('name'),
                    ])
                    ->whereNull('parent_id')
                    ->where('is_active', true)
                    ->withCount([
                        'profiles as profiles_count' => fn ($profiles) => $profiles->where('status', 'active'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(function (Category $parent) {
                        return [
                            'name' => $parent->name,
                            'slug' => (string) $parent->slug,
                            'count' => (int) $parent->profiles_count,
                            // Hide empty subcategories from the filter.
                            'subs' => $parent->children
                                ->filter(fn (Category $child) => (int) $child->profiles_count > 0)
                                ->map(fn (Category $child) => [
                                    'name' => $child->name,
                                    'slug' => (string) $child->slug,
                                    'count' => (int) $child->profiles_count,
                                ])
                                ->values()
                                ->all(),
                        ];
                    })
                    // Hide empty root categories (no profiles anywhere in their tree).
                    ->filter(fn (array $group) => $group['count'] > 0 || ! empty($group['subs']))
                    ->values()
                    ->all();
            }
        );
    }

    private function detectDeviceType(?string $userAgent): string
    {
        $ua = mb_strtolower((string) $userAgent);

        if ($ua === '') {
            return 'unknown';
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * @return array<int, array{name:string,count:int}>
     */
    private function buildCategoryOptions(): array
    {
        return Cache::remember(
            'public:catalog:category-options:v1',
            now()->addSeconds(self::PUBLIC_CACHE_TTL_SECONDS),
            function (): array {
                return Category::query()
                    ->whereNull('parent_id')
                    ->where('is_active', true)
                    ->withCount([
                        'profiles as profiles_count' => fn ($profiles) => $profiles->where('status', 'active'),
                    ])
                    ->orderByDesc('profiles_count')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Category $category) => [
                        'name' => $category->name,
                        'count' => (int) $category->profiles_count,
                    ])
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * @return array<int, string>
     */
    private function buildRegionFilters(): array
    {
        return Cache::remember(
            'public:catalog:regions:v2',
            now()->addSeconds(self::PUBLIC_CACHE_TTL_SECONDS),
            function (): array {
                return Profile::query()
                    ->where('status', 'active')
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->select('city')
                    ->distinct()
                    ->orderBy('city')
                    ->pluck('city')
                    ->map(fn ($city) => RegionCityDirectory::canonicalCity((string) $city) ?? trim((string) $city))
                    ->filter()
                    ->unique(fn ($city) => RegionCityDirectory::normalizeCity((string) $city))
                    ->sortBy(fn ($city) => mb_strtolower((string) $city))
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * @param  array<int, array{name:string,subs:array<int,string>}>  $categoryFilters
     * @return array<int, string>
     */
    private function buildPopularQueries(array $categoryFilters): array
    {
        $queries = collect($categoryFilters)
            ->flatMap(function (array $parent) {
                return collect($parent['subs'])
                    ->pluck('name')
                    ->take(2);
            })
            ->take(5)
            ->values()
            ->all();

        if (count($queries) < 5) {
            return self::DEFAULT_POPULAR_QUERIES;
        }

        return $queries;
    }

    /**
     * @return array{profiles:int,reviews:int,verified:int}
     */
    private function buildCatalogStats(): array
    {
        return Cache::remember(
            'public:catalog:stats:v1',
            now()->addSeconds(self::PUBLIC_CACHE_TTL_SECONDS),
            fn (): array => [
                'profiles' => Profile::query()->where('status', 'active')->count(),
                'reviews' => (int) Profile::query()->where('status', 'active')->sum('reviews_count'),
                // «перевірених» прибрано з каталогу — не рахуємо й не показуємо.
            ]
        );
    }

    /**
     * @param  array<int,string>  $selectedCategories
     * @param  array<int,string>  $selectedRegions
     */
    private function applyCatalogFilters(
        $profilesQuery,
        string $search,
        string $category,
        string $subcategory,
        array $selectedCategories,
        array $selectedRegions,
        string $ratingFilter,
        string $reviewsCountFilter,
        string $statusFilter
    ): void {
        if ($search !== '') {
            $profilesQuery->where(function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('website', 'like', $like)
                    ->orWhereHas('categories', fn ($categories) => $categories->where('name', 'like', $like))
                    // Сервіси профілю («імплантація зубів», «розлучення»…) —
                    // на них ведуть сервіс-чипи секції категорій на головній.
                    ->orWhereHas('services', fn ($services) => $services->where('category_services.name', 'like', $like));

                // Пошук за ПОТРЕБОЮ («вакцинація тварин», «виплати за загиблих»):
                // дивимось у попередньо зібраний індекс profile_search_terms, куди
                // зведені назва, категорії, послуги й ключові слова з відгуків.
                // Прямий LIKE по 720 тис. відгуків тривав би ~6 с — заміряно.
                $boolean = \App\Support\ProfileSearchTerms::resolveBooleanQuery($search);
                if ($boolean !== null && \App\Support\ProfileSearchTerms::indexAvailable()) {
                    $query->orWhereIn('profiles.id', function ($sub) use ($boolean): void {
                        $sub->select('profile_id')
                            ->from('profile_search_terms')
                            ->whereRaw('MATCH(terms) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
                    });
                }
            });

            // Сортування за релевантністю — ПЕРШИМ, ще до звичайного порядку
            // каталогу. Інакше «заміна паспорта» показувала вгорі профіль, який
            // збігся лише зі словом «заміна», а не з обома словами запиту.
            $relevance = \App\Support\ProfileSearchTerms::resolveBooleanQuery($search);
            if ($relevance !== null && \App\Support\ProfileSearchTerms::indexAvailable()) {
                $profilesQuery
                    ->leftJoinSub(
                        \Illuminate\Support\Facades\DB::table('profile_search_terms')
                            ->select('profile_id')
                            ->selectRaw('MATCH(terms) AGAINST (? IN BOOLEAN MODE) AS relevance', [$relevance]),
                        'search_hit',
                        'search_hit.profile_id',
                        '=',
                        'profiles.id'
                    )
                    ->orderByDesc('search_hit.relevance');
            }
        }

        if ($category !== '' || ! empty($selectedCategories) || $subcategory !== '') {
            $categoryNames = $subcategory !== ''
                ? [$subcategory]
                : collect($selectedCategories)
                    ->when($category !== '', fn ($items) => $items->push($category))
                    ->filter()
                    ->unique(fn ($value) => mb_strtolower((string) $value))
                    ->values()
                    ->all();

            $categoryIds = CategoryHierarchy::categoryIdsFromNames($categoryNames);

            if (! empty($categoryIds)) {
                $profilesQuery->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds));
            }
        }

        if (! empty($selectedRegions)) {
            $profilesQuery->where(function ($query) use ($selectedRegions) {
                foreach ($selectedRegions as $city) {
                    $query->orWhereIn('city', RegionCityDirectory::variantsForCity((string) $city));
                }
            });
        }

        $ratingMap = [
            '5.0' => 5.0,
            '4.5+' => 4.5,
            '4.0+' => 4.0,
        ];
        if (isset($ratingMap[$ratingFilter])) {
            $profilesQuery->where('rating_avg', '>=', $ratingMap[$ratingFilter]);
        }

        $reviewsCountMap = [
            '10+' => 10,
            '25+' => 25,
            '50+' => 50,
            '100+' => 100,
        ];
        if (isset($reviewsCountMap[$reviewsCountFilter])) {
            $profilesQuery->where('reviews_count', '>=', $reviewsCountMap[$reviewsCountFilter]);
        }

        if ($statusFilter === 'verified') {
            $profilesQuery->where('is_verified', true);
        } elseif ($statusFilter === 'owner_verified') {
            $profilesQuery->where(function ($query) {
                $query->where('is_owner_verified', true)
                    ->orWhereNotNull('owner_user_id')
                    ->orWhereHas('claims', fn ($claims) => $claims->where('status', 'approved'));
            });
        } elseif ($statusFilter === 'recommended') {
            $profilesQuery->where('dovira_recommendation_status', 'recommend');
        }
    }

    /**
     * PRO-блок каталогу: максимум N промаркованих PRO над органічною видачею.
     *
     * Показуємо ЛИШЕ на першій сторінці дефолтного сортування і лише коли
     * користувач не шукає конкретну компанію текстом — інакше блок перетворює
     * пошук на рекламу поверх наміру. Порядок і ротація — у ProfileRanking.
     *
     * @return \Illuminate\Support\Collection<int, Profile>
     */
    private function buildCatalogProBlock(Request $request, $profilesQuery, string $sort, string $search): Collection
    {
        $page = max(1, (int) $request->query('page', 1));

        if ($sort !== 'recommended' || $page > 1 || trim($search) !== '') {
            return collect();
        }

        $ids = ProfileRanking::proBlockIds(clone $profilesQuery);

        if ($ids === []) {
            return collect();
        }

        $profiles = Profile::query()
            ->select([
                'profiles.id', 'profiles.slug', 'profiles.name', 'profiles.logo_url', 'profiles.website',
                'profiles.city', 'profiles.district', 'profiles.address', 'profiles.description',
                'profiles.rating_avg', 'profiles.reviews_count', 'profiles.is_verified',
                'profiles.is_owner_verified', 'profiles.owner_user_id', 'profiles.is_pro',
                'profiles.popularity_score', 'profiles.dovira_recommendation_status', 'profiles.created_at',
                'profiles.dossier_verdict',
            ])
            ->with([
                'services',
                'rating:profile_id,dist_1,dist_2,dist_3,dist_4,dist_5,reviews_counted',
                'categories' => fn ($categories) => $categories->select(['categories.id', 'categories.name'])->orderByDesc('profile_category.is_primary'),
            ])
            ->withCount('officialReplies')
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            ->withProSubscriptionFlags()
            ->whereIn('profiles.id', $ids)
            ->get();

        return ProfileRanking::sortByIdList($profiles, $ids);
    }

    /**
     * Профілі, підняті нагору поточною підпискою (заповнює applySorting).
     *
     * @var array<int, int>
     */
    private array $promotedIds = [];

    private function applySorting($profilesQuery, string $sort): void
    {
        // Поріг, з якого оцінку взагалі показуємо (той самий, що в /top і на
        // картках). Профілі з кількома відгуками не мають обганяти тих, у кого
        // сотні, лише тому що в них випадкова 5.0.
        $min = (int) \App\Support\RatingDisplay::MIN_REVIEWS;
        $tier = "CASE WHEN profiles.reviews_count >= {$min} THEN 2 WHEN profiles.reviews_count >= 1 THEN 1 ELSE 0 END";

        // Профілі з активною підпискою підіймаються нагору лише в «мʼяких»
        // режимах — «Рекомендовані» (за замовчуванням) і «Популярні»: там
        // порядок і так складає платформа, тож оплачене місце доречне.
        // У режимах із чітким критерієм («Найбільше відгуків», «Нові»,
        // «Найвищий рейтинг», «За назвою») людина просила конкретну ознаку —
        // підмішувати туди оплату означало б обманути очікування і зробити
        // сортування безглуздим. Рейтингів /top підписка не стосується взагалі.
        //
        // Нагору йде ПО ОДНОМУ профілю на бренд (той, де більше відгуків):
        // дублі одного бізнесу не мають займати кілька верхніх місць підряд.
        // promotedIds повертає їх уже в ротованому порядку — FIELD() зберігає
        // саме цю чергу, тож перше місце серед платних щодня в іншого.
        $promotedSorts = ['recommended', 'popular'];
        $promotedIds = in_array($sort, $promotedSorts, true)
            ? ProfileRanking::promotedIds($profilesQuery)
            : [];
        // Запамʼятовуємо, щоб позначити ці картки в розмітці: власник має
        // бачити в кабінеті, скільки показів дала саме оплата.
        $this->promotedIds = $promotedIds;
        if ($promotedIds !== []) {
            $list = implode(',', array_map('intval', $promotedIds));
            $profilesQuery
                ->orderByRaw("CASE WHEN profiles.id IN ({$list}) THEN 1 ELSE 0 END DESC")
                ->orderByRaw("FIELD(profiles.id, {$list})");
        }

        match ($sort) {
            // «Популярні» — реальний попит: перегляди й активність (popularity_score).
            'popular' => ProfileRanking::applyOrganicOrder($profilesQuery),
            // «Найвищий рейтинг» — за самою оцінкою. З 21.09.2026 оцінка — це
            // проста середня, тому й сортуємо прямо по ній, без згладжування;
            // зіставність дає ярус за кількістю відгуків.
            'rating_desc' => $profilesQuery
                ->orderByRaw($tier . ' DESC')
                ->orderByDesc('profiles.rating_avg')
                ->orderByDesc('profiles.reviews_count')
                ->orderBy('profiles.name'),
            'reviews_desc' => $profilesQuery
                ->orderByDesc('profiles.reviews_count')
                ->orderByDesc('profiles.rating_avg')
                ->orderBy('profiles.name'),
            'newest' => $profilesQuery->orderByDesc('profiles.created_at')->orderByDesc('profiles.id'),
            'name_asc' => $profilesQuery->orderBy('profiles.name', 'asc'),
            // «Рекомендовані» (дефолт) — якість передусім: спершу бейдж
            // «Dovira рекомендує», далі ярус за кількістю відгуків, оцінка і вже
            // потім популярність. Раніше цей режим викликав рівно ту саму
            // функцію, що й «Популярні», тому вибір нічого не змінював.
            default => $profilesQuery
                ->orderByDesc('profiles.dovira_recommends')
                ->orderByRaw($tier . ' DESC')
                ->orderByDesc('profiles.rating_avg')
                ->orderByDesc('profiles.popularity_score')
                ->orderByDesc('profiles.reviews_count')
                ->orderBy('profiles.name')
                ->orderBy('profiles.id'),
        };
    }

    /**
     * Апріорне середнє для байєсівського згладжування рейтингу (кешується).
     * Джерело правди узгоджене з лідербордом і TopRatingController.
     */
    /**
     * Чи є взагалі профілі з бейджем «Dovira рекомендує». Якщо жодного —
     * не показуємо відповідний фільтр: він завжди давав «Нічого не знайдено»
     * і виглядав як зламаний (перевірено 21.09.2026 — 0 профілів).
     */
    private function hasRecommendedProfiles(): bool
    {
        return (bool) cache()->remember(
            'catalog:has-recommended:v1',
            now()->addHours(6),
            fn (): bool => Profile::query()
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true)
                ->where('dovira_recommends', true)
                ->exists()
        );
    }

    private function weightedRatingGlobalAvg(): float
    {
        return (float) cache()->remember('catalog:weighted-global-avg:v1', now()->addHours(6), function (): float {
            return (float) rescue(fn () => Profile::query()
                ->where('status', 'active')
                ->where('reviews_count', '>=', 1)
                ->avg('rating_avg'), 4.3, false) ?: 4.3;
        });
    }

    private function buildCatalogAjaxResponse(
        Request $request,
        AbstractPaginator $profilesPaginator,
        array $catalogStats,
        ?Collection $proBlockProfiles = null
    ): JsonResponse
    {
        $backUrl = route('catalog', collect($request->query())
            ->except(['ajax', 'append'])
            ->toArray());

        if ($request->boolean('append')) {
            return $this->buildCatalogAppendResponse($request, $profilesPaginator, $catalogStats, $backUrl);
        }

        $html = view('static.partials.catalog-results', [
            'profiles' => $profilesPaginator,
            'proProfiles' => $proBlockProfiles ?? collect(),
            'catalogProfileUrl' => fn (string $slug) => route('profile.show', [
                'slug' => $slug,
            ]),
            'categoryUrl' => fn (?string $category) => route('catalog', [
                'category' => $category ?: null,
            ]),
        ])->render();

        $filtersHtml = view('static.partials.catalog-filters', [
            'categoryFilters' => $this->buildCategoryFilters(),
            'hasRecommendedProfiles' => $this->hasRecommendedProfiles(),
            'regionFilters' => $this->buildRegionFilters(),
        ])->render();

        $hero = $this->catalogHeroContext($request);
        $heroLandingSeo = $hero['category'] !== null
            ? \App\Support\CatalogLandingSeo::build($hero['category'], $hero['cityName'])
            : null;
        if ($heroLandingSeo !== null) {
            // Ті самі скоуплені числа, що й на server-render посадкової,
            // інакше ajax-оновлення підміняло б їх глобальними каталогу.
            $catalogStats = [
                'profiles' => $heroLandingSeo['profiles_count'],
                'reviews' => $heroLandingSeo['reviews_count'],
            ];
        }
        $heroHtml = view('static.partials.catalog-hero-copy', [
            'heroCategory' => $hero['category'],
            'heroCityName' => $hero['cityName'],
            'heroLandingSeo' => $heroLandingSeo,
        ])->render();

        return response()->json([
            'html' => $html,
            'filtersHtml' => $filtersHtml,
            'count' => $this->buildCatalogCount($profilesPaginator),
            'stats' => $catalogStats,
            'activeFilters' => $this->buildActiveFilterChips($request),
            // ЧПУ-URL поточного стану фільтрів — його pushState-ить фронт.
            'url' => $this->buildCatalogPrettyUrl($request),
            // Оновлена шапка (H1/крихти/опис) — свопиться разом із результатами,
            // інакше заголовок лишається від первинного заходу (розсинхрон).
            'heroHtml' => $heroHtml,
        ]);
    }

    /**
     * «Красивий» URL для поточного стану фільтрів: одна категорія без
     * підкатегорії/мульти-вибору → ЧПУ /catalog/{slug}[/{city}] + решта
     * параметрів; інакше — /catalog?query. Мапінг назва→slug тут (дешево).
     */
    private function buildCatalogPrettyUrl(Request $request): string
    {
        $sub = trim((string) $request->query('sub', ''));
        $category = trim((string) $request->query('category', ''));
        $multi = array_filter((array) $request->query('categories', []));
        $regions = array_values(array_filter((array) $request->query('regions', [])));

        // Slug для ЧПУ: підкатегорія має пріоритет (у неї своя посадкова),
        // інакше категорія. Обидві — рядки в categories, шукаємо за назвою.
        $slug = null;
        if ($multi === []) {
            $name = $sub !== '' ? $sub : ($category !== '' ? $category : '');
            if ($name !== '') {
                $slug = Category::query()
                    ->where('is_active', true)
                    ->where('name', $name)
                    ->value('slug');
            }
        }

        // Лише значущі параметри поверх ЧПУ: без службових, дефолтів і порожніх
        // (sort=recommended, page≤1, tab) — інакше URL брудний і рве canonical.
        $meaningful = fn (array $except): array => collect($request->except($except))
            ->reject(fn ($value, $key) => $value === '' || $value === null || $value === []
                || ($key === 'sort' && $value === 'recommended')
                || ($key === 'page' && (int) $value <= 1)
                || $key === 'tab')
            ->all();

        if ($slug === null || $slug === '') {
            $qs = http_build_query($meaningful(['ajax', 'append']));

            return $qs !== '' ? '/catalog?'.$qs : '/catalog';
        }

        $path = '/catalog/'.$slug;
        $except = ['ajax', 'append', 'category', 'sub', 'categories'];

        // Одне місто → у path (/catalog/{cat}/{city}); кілька → лишаємо в query.
        if (count($regions) === 1) {
            $citySlug = RegionCityDirectory::citySlug((string) $regions[0]);
            if ($citySlug !== null && $citySlug !== '') {
                $path .= '/'.$citySlug;
                $except[] = 'regions';
            }
        }

        $qs = http_build_query($meaningful($except));

        return $qs !== '' ? $path.'?'.$qs : $path;
    }

    /**
     * Контекст шапки каталогу: одна (під)категорія → її модель (+місто, якщо
     * одне), інакше null → загальна шапка. Та сама логіка, що й ЧПУ-URL.
     *
     * @return array{category:?\App\Models\Category, cityName:?string}
     */
    private function catalogHeroContext(Request $request): array
    {
        $sub = trim((string) $request->query('sub', ''));
        $category = trim((string) $request->query('category', ''));
        $multi = array_filter((array) $request->query('categories', []));
        $regions = array_values(array_filter((array) $request->query('regions', [])));

        $model = null;
        if ($multi === []) {
            $name = $sub !== '' ? $sub : ($category !== '' ? $category : '');
            if ($name !== '') {
                $model = Category::query()
                    ->where('is_active', true)
                    ->where('name', $name)
                    ->first(['id', 'name', 'slug', 'parent_id']);
            }
        }

        $cityName = null;
        if ($model !== null && count($regions) === 1) {
            $citySlug = RegionCityDirectory::citySlug((string) $regions[0]);
            if ($citySlug !== null && $citySlug !== '') {
                $cityName = (string) $regions[0];
            }
        }

        return ['category' => $model, 'cityName' => $cityName];
    }

    private function buildCatalogAppendResponse(
        Request $request,
        AbstractPaginator $profilesPaginator,
        array $catalogStats,
        string $backUrl
    ): JsonResponse {
        $itemsHtml = view('static.partials.catalog-results-items', [
            'profiles' => $profilesPaginator,
            'catalogProfileUrl' => fn (string $slug) => route('profile.show', [
                'slug' => $slug,
            ]),
        ])->render();

        $itemsCount = $profilesPaginator->getCollection()->count();
        $loadedCount = $itemsCount > 0
            ? (($profilesPaginator->currentPage() - 1) * $profilesPaginator->perPage()) + $itemsCount
            : 0;

        return response()->json([
            'html' => $itemsHtml,
            'loadedCount' => $loadedCount,
            'hasMore' => $profilesPaginator->hasMorePages(),
            'nextUrl' => $profilesPaginator->hasMorePages() ? $profilesPaginator->nextPageUrl() : null,
            'currentPage' => $profilesPaginator->currentPage(),
            'count' => [
                'label' => $loadedCount > 0
                    ? ('Показано '.$loadedCount.($profilesPaginator->hasMorePages() ? '+ профілів' : ' профілів'))
                    : 'Нічого не знайдено',
                'from' => $loadedCount > 0 ? 1 : 0,
                'to' => $loadedCount,
                'has_more' => $profilesPaginator->hasMorePages(),
                'exact' => false,
                'total' => null,
            ],
            'stats' => $catalogStats,
            'activeFilters' => $this->buildActiveFilterChips($request),
        ]);
    }

    /**
     * @return array{label:string,from:int,to:int,has_more:bool,exact:bool,total:?int}
     */
    private function buildCatalogCount(AbstractPaginator $profilesPaginator): array
    {
        $itemsCount = $profilesPaginator->getCollection()->count();
        $from = $itemsCount > 0
            ? (($profilesPaginator->currentPage() - 1) * $profilesPaginator->perPage()) + 1
            : 0;
        $to = $itemsCount > 0 ? ($from + $itemsCount - 1) : 0;
        $hasMore = $profilesPaginator->hasMorePages();

        if ($to === 0) {
            $label = 'Нічого не знайдено';
        } elseif ($hasMore && $from === 1) {
            $label = 'Показано '.$to.'+ профілів';
        } elseif ($hasMore) {
            $label = 'Показано '.$from.'–'.$to.'+ профілів';
        } elseif ($from > 0 && $from !== $to) {
            $label = 'Показано '.$from.'–'.$to.' профілів';
        } else {
            $label = 'Знайдено '.$to.' профілів';
        }

        return [
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'has_more' => $hasMore,
            'exact' => false,
            'total' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapCatalogProfileCard(Profile $profile): array
    {
        $loadedServices = $profile->relationLoaded('services') ? $profile->services : collect();
        $services = $loadedServices
            ->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values();

        if ($services->isEmpty()) {
            $services = ($profile->relationLoaded('categories') ? $profile->categories : collect())
                ->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values();
        }

        $reviewsCount = (int) $profile->reviews_count;
        $repliesCount = (int) ($profile->official_replies_count ?? 0);
        $recommendationStatus = $profile->dovira_recommendation_status ?? null;

        // «% позитивних» — з розподілу оцінок рейтингової системи (4-5★),
        // лише коли зарахованих відгуків достатньо для чесного відсотка.
        $positivePercent = null;
        $ratingRow = $profile->relationLoaded('rating') ? $profile->rating : null;
        if ($ratingRow && (int) $ratingRow->reviews_counted >= 5) {
            $positivePercent = (int) round(
                ((int) $ratingRow->dist_4 + (int) $ratingRow->dist_5) / max(1, (int) $ratingRow->reviews_counted) * 100
            );
        }


        $logoImageUrl = MediaUrl::profileLogoUrl($profile, 160);

        return [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'name' => $profile->name,
            'services' => $services->all(),
            'positive_percent' => $positivePercent,
            'dossier_verdict' => in_array($profile->dossier_verdict, ['red', 'yellow', 'green'], true)
                ? $profile->dossier_verdict
                : null,
            'recommended' => $recommendationStatus === 'recommend',
            // Публічний негативний вердикт «Dovira не рекомендує» прибрано з усіх
            // профілів (репутаційно-правовий ризик). Дані статусу лишаються в
            // адмінці, але публічно бейдж «не рекомендує» більше не показуємо.
            'not_recommended' => false,
            // "Live profile" signal: business answers at least half of its reviews.
            'responds' => $reviewsCount >= 3 && $repliesCount >= (int) ceil($reviewsCount / 2),
            'logo_url' => $logoImageUrl,
            'logo_image_url' => $logoImageUrl,
            'city' => $profile->city,
            'district' => $profile->district,
            'address' => $profile->address,
            'about' => $profile->description,
            'reviews_count' => (int) $profile->reviews_count,
            'rating' => (float) $profile->rating_avg,
            'verified' => (bool) $profile->is_verified,
            'owner_verified' => (bool) ($profile->is_owner_verified || ($profile->has_approved_claim ?? false) || $profile->owner_user_id),
            'pro' => $profile->hasActiveProSubscription(),
            // Картка стоїть нагорі саме завдяки підписці — покази таких карток
            // рахуємо окремо (контекст catalog_promoted).
            'promoted' => in_array((int) $profile->id, $this->promotedIds, true),
            'website' => $profile->website,
        ];
    }

    /**
     * @return array<int, array{label:string, query:array<string, mixed>}>
     */
    private function buildActiveFilterChips(Request $request): array
    {
        $baseQuery = collect($request->query())
            ->except('ajax', 'page')
            ->toArray();
        $chips = [];
        $without = function (array $remove) use ($baseQuery): array {
            $query = $baseQuery;
            foreach ($remove as $key => $value) {
                if (is_int($key)) {
                    unset($query[$value]);

                    continue;
                }

                if (is_array($query[$key] ?? null)) {
                    $query[$key] = collect($query[$key])
                        ->reject(fn ($item) => (string) $item === (string) $value)
                        ->values()
                        ->all();
                    if ($query[$key] === []) {
                        unset($query[$key]);
                    }

                    continue;
                }

                unset($query[$key]);
            }

            return $query;
        };

        if ($request->filled('q')) {
            $chips[] = ['label' => (string) $request->query('q'), 'query' => $without(['q'])];
        }

        $categories = collect((array) $request->query('categories', []))->filter()->values();
        if ($request->filled('category')) {
            $categories->push((string) $request->query('category'));
        }
        if ($request->filled('sub')) {
            $categories = collect([(string) $request->query('sub')]);
        }
        foreach ($categories->unique(fn ($value) => mb_strtolower((string) $value))->values() as $category) {
            $chips[] = ['label' => (string) $category, 'query' => $without(['categories' => $category, 'category', 'sub'])];
        }

        foreach (collect((array) $request->query('regions', []))->filter()->values() as $region) {
            $chips[] = ['label' => (string) $region, 'query' => $without(['regions' => $region])];
        }

        if ($request->filled('rating')) {
            $chips[] = ['label' => 'Рейтинг '.$request->query('rating'), 'query' => $without(['rating'])];
        }
        if ($request->filled('reviews_count')) {
            $chips[] = ['label' => $request->query('reviews_count').' відгуків', 'query' => $without(['reviews_count'])];
        }
        if ($request->query('status') === 'verified') {
            $chips[] = ['label' => 'Перевірений профіль', 'query' => $without(['status'])];
        } elseif ($request->query('status') === 'owner_verified') {
            $chips[] = ['label' => 'Підтверджений власником', 'query' => $without(['status'])];
        } elseif ($request->query('status') === 'recommended') {
            $chips[] = ['label' => 'Довіра рекомендує', 'query' => $without(['status'])];
        }

        return $chips;
    }

    /**
     * @return Collection<int, Profile>
     */
    private function buildRelatedProfiles(Profile $profile): Collection
    {
        // Добірка «схожих» коштувала до 535 мс: два запити з LIKE '%місто%'
        // (індекс не працює) на кожне відкриття профілю. Склад добірки
        // змінюється рідко, тож тримаємо в кеші список id на годину, а самі
        // моделі дістаємо одним швидким запитом за ключами.
        $cacheKey = 'related-profiles:' . $profile->id;
        $ids = rescue(
            fn () => Cache::remember(
                $cacheKey,
                now()->addHour(),
                fn () => $this->queryRelatedProfileIds($profile)
            ),
            fn () => $this->queryRelatedProfileIds($profile),
            false
        );

        if (empty($ids)) {
            return collect();
        }

        $found = Profile::query()
            ->with(['categories', 'services'])
            ->withProSubscriptionFlags()
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        // Порядок із кешу важливий (PRO і популярність попереду) — whereKey
        // повертає рядки в довільному порядку, тож відновлюємо його вручну.
        return collect($ids)
            ->map(fn (int $id) => $found->get($id))
            ->filter()
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function queryRelatedProfileIds(Profile $profile): array
    {
        $primaryCategory = $profile->categories
            ->sortByDesc(fn (Category $category) => (int) ($category->pivot->is_primary ?? false))
            ->first();

        // Рекомендації — з міста профілю: спершу та сама категорія в місті,
        // потім інші профілі міста, і лише як заповнювач — категорія без
        // привʼязки до міста (коли в місті профілів бракує).
        $cityVariants = RegionCityDirectory::variantsForCity($profile->city);
        $cityFilter = function ($query) use ($cityVariants): void {
            $query->where(function ($inner) use ($cityVariants): void {
                foreach ($cityVariants as $variant) {
                    $inner->orWhere('profiles.city', 'like', '%'.$variant.'%');
                }
            });
        };

        $relatedQuery = fn () => Profile::query()
            ->select(['profiles.id', 'profiles.is_pro', 'profiles.popularity_score', 'profiles.reviews_count'])
            ->where('status', 'active')
            ->whereKeyNot($profile->id)
            // «Схожі профілі» показуються лише на НЕ-PRO сторінках, і показ у
            // них — окремо обіцяна перевага підписки, тому PRO тут попереду.
            // Далі — за популярністю (та сама шкала, що в каталозі).
            ->orderByDesc('is_pro')
            ->orderByDesc('popularity_score')
            ->orderByDesc('reviews_count')
            // Only the first 10 are ever used below — without this the query
            // hydrated every profile in the category (900+ rows with
            // categories/services eager-loaded), which was slow enough to
            // exhaust PHP's memory limit on categories with many profiles.
            ->take(10);

        $sameCategoryCity = collect();
        $sameCity = collect();

        if (! empty($cityVariants)) {
            if ($primaryCategory) {
                $sameCategoryCity = $relatedQuery()
                    ->tap($cityFilter)
                    ->whereHas('categories', fn ($categories) => $categories->whereKey($primaryCategory->id))
                    ->get();
            }

            $sameCity = $relatedQuery()
                ->tap($cityFilter)
                ->get();
        }

        $sameCategoryAnywhere = collect();
        if ($primaryCategory && $sameCategoryCity->count() + $sameCity->count() < 10) {
            $sameCategoryAnywhere = $relatedQuery()
                ->whereHas('categories', fn ($categories) => $categories->whereKey($primaryCategory->id))
                ->get();
        }

        return $sameCategoryCity
            ->concat($sameCity)
            ->concat($sameCategoryAnywhere)
            ->unique('id')
            ->take(10)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   slug:string,
     *   name:string,
     *   logo_url:?string,
     *   city:?string,
     *   district:?string,
     *   reviews_count:int,
     *   rating:float,
     *   recommend_percent:int,
     *   verified:bool,
     *   owner_verified:bool,
     *   pro:bool,
     *   website:?string,
     *   contact_cta_url:?string,
     *   social_links:array<string, string>,
     *   email:?string,
     *   phone:?string,
     *   phone_links:array<int, array{display:string,href:string}>,
     *   address:?string,
     *   about:?string
     * }
     */
    private function mapProfile(Profile $profile): array
    {
        $primaryCategory = $profile->categories->first();
        $ownerProfile = $this->resolveOwnerProfileData((array) ($profile->ai_suggested_data ?? []));
        $recommendationStatus = $profile->dovira_recommendation_status ?? null;
        $ownerVerified = (bool) ($profile->is_owner_verified || ($profile->has_approved_claim ?? false) || $profile->owner_user_id);
        // Базові контакти (телефон, сайт, адреса) відкриті всім публічним
        // профілям. Розширені канали (email, соцмережі, власний CTA-лінк)
        // відкриті лише для PRO. Лід-форма лишається доступною завжди.
        $fullContactsVisible = $profile->hasActiveProSubscription();
        $basicContactsVisible = true;

        $logoImageUrl = MediaUrl::profileLogoUrl($profile, 240);

        return [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'name' => $profile->name,
            'recommended' => $recommendationStatus === 'recommend',
            // Публічний негативний вердикт «Dovira не рекомендує» прибрано з усіх
            // профілів (репутаційно-правовий ризик). Дані статусу лишаються в
            // адмінці, але публічно бейдж «не рекомендує» більше не показуємо.
            'not_recommended' => false,
            'logo_url' => $logoImageUrl,
            'logo_image_url' => $logoImageUrl,
            'seo_title' => ProfileSeo::resolvedSearchTitle(
                $profile->seo_title,
                $profile->name,
                $primaryCategory?->name,
                $profile->city,
                \App\Support\RatingDisplay::visible((int) $profile->reviews_count),
                (float) $profile->rating_avg,
                (int) $profile->reviews_count,
                trim((string) $profile->dossier) !== '',
                is_array($profile->ai_review_summary) && $profile->ai_review_summary !== [],
            ),
            // Meta-опис: крафтовий (людський, нешаблонний) seo_description, інакше —
            // генератор. Досьє → агресивний CTR-хук з вердиктом (🔴/⚠️/✅) у
            // формулюваннях-опініях Dovira; без досьє, але з ai_review_summary →
            // хук «AI розібрав відгуки: хвалять X, скарги на Y». Сирий текст
            // досьє і dossier_verdict_note у сніпет НЕ виносимо ніколи: там
            // бувають імена та прямі звинувачення (наклеп у видачі Google).
            'seo_description' => (filled($profile->seo_description) && ! ProfileSeo::isAutoGeneratedDescription(
                $profile->seo_description,
                $profile->name,
                $primaryCategory?->name,
                $profile->city,
                $profile->services->pluck('name')->all(),
                $profile->short_description,
                $profile->description
            )) ? ProfileSeo::withRatingPrefix(
                (string) $profile->seo_description,
                \App\Support\RatingDisplay::visible((int) $profile->reviews_count),
                (float) $profile->rating_avg,
                (int) $profile->reviews_count
            ) : ProfileSeo::resolvedSearchDescription(
                $profile->seo_description,
                $profile->name,
                $primaryCategory?->name,
                $profile->city,
                $profile->services->pluck('name')->all(),
                $profile->short_description,
                $profile->description,
                \App\Support\RatingDisplay::visible((int) $profile->reviews_count),
                (float) $profile->rating_avg,
                (int) $profile->reviews_count,
                trim((string) $profile->dossier) !== '',
                $profile->dossier_verdict,
                null, // verdict_note лишаємо поза сніпетом: сирий текст з іменами/звинуваченнями
                is_array($profile->ai_review_summary) ? $profile->ai_review_summary : null,
            ),
            'city' => $profile->city,
            'district' => $profile->district,
            'reviews_count' => (int) $profile->reviews_count,
            'rating' => (float) $profile->rating_avg,
            'recommend_percent' => (int) $profile->recommend_percent,
            'verified' => (bool) $profile->is_verified,
            'dovira_recommendation_status' => $profile->dovira_recommendation_status,
            'owner_verified' => $ownerVerified,
            'pro' => $fullContactsVisible,
            'basic_contacts_visible' => $basicContactsVisible,
            'phone_visible' => $basicContactsVisible,
            'website' => $basicContactsVisible ? $profile->website : null,
            'contact_cta_url' => $fullContactsVisible ? $profile->contact_cta_url : null,
            'contact_cta_mode' => $fullContactsVisible ? (string) ($profile->contact_cta_mode ?? 'link') : 'lead_form',
            'og_image_url' => \App\Support\MediaUrl::publicImageUrl($profile->og_image_url),
            'social_links' => (! $fullContactsVisible ? [] : collect((array) ($profile->social_links ?? []))
                ->mapWithKeys(function ($url, $network): array {
                    $key = trim((string) $network);
                    $value = trim((string) $url);

                    if ($key === '' || $value === '') {
                        return [];
                    }

                    return [$key => $value];
                })
                ->all()),
            'email' => $fullContactsVisible ? $profile->email : null,
            'phone' => $basicContactsVisible ? $profile->phone : null,
            'phone_links' => $basicContactsVisible ? $this->mapProfilePhoneLinks((string) ($profile->phone ?? '')) : [],
            'address' => $basicContactsVisible ? $profile->address : null,
            'about' => $profile->description,
            'dossier' => $profile->dossier,
            'dossier_source' => $profile->dossier_source,
            'dossier_generated_at' => $profile->dossier_generated_at,
            'dossier_verdict' => $profile->dossier_verdict,
            'dossier_verdict_note' => $profile->dossier_verdict_note,
            // Прайс: обʼєкти з цінами й описами. Ціни/описи — PRO-перевага
            // (стратегія, розділ 03), FREE віддає лише назви-теги.
            'services' => $profile->services
                ->sortBy([['pivot.sort_order', 'asc'], ['name', 'asc']])
                ->map(fn ($service) => [
                    'name' => trim((string) $service->name),
                    'description' => $fullContactsVisible ? (string) ($service->pivot->description ?? '') : '',
                    'price_label' => $fullContactsVisible
                        ? \App\Support\ServicePrice::label($service->pivot->price_type, $service->pivot->price, $service->pivot->price_max)
                        : null,
                    // Сирі суми — для schema.org Offer (лише коли ціна реальна).
                    'price_min' => $fullContactsVisible && in_array($service->pivot->price_type, ['fixed', 'from', 'range'], true)
                        ? (int) $service->pivot->price
                        : null,
                    'price_max' => $fullContactsVisible && $service->pivot->price_type === 'range'
                        ? (int) $service->pivot->price_max
                        : null,
                    'duration' => $fullContactsVisible ? (string) ($service->pivot->duration ?? '') : '',
                ])
                ->filter(fn (array $service) => $service['name'] !== '')
                ->values()
                ->all(),
            'gallery' => collect((array) ($profile->gallery ?? []))
                ->values()
                ->all(),
            'experience_years' => $ownerProfile['experience_years'] ?? null,
            'consultations_count' => $ownerProfile['consultations_count'] ?? null,
            'response_speed' => $ownerProfile['response_speed'] ?? null,
            'experience' => $ownerProfile['experience'] ?? null,
            'faq' => $ownerProfile['faq'] ?? [],
            'cases' => $this->attachCaseProofs($profile, (array) ($ownerProfile['cases'] ?? [])),
            'specializations_title' => data_get($profile->ai_suggested_data, 'specializations_title'),
            'created_at' => optional($profile->created_at)?->toDateTimeString(),
            'updated_at' => optional($profile->updated_at)?->toDateTimeString(),
            'ai_review_summary' => data_get($profile->ai_suggested_data, 'review_analysis.summary'),
            'ai_review_key_factors' => array_values(array_filter((array) data_get($profile->ai_suggested_data, 'review_analysis.key_factors', []))),
            'ai_review_strengths' => array_values(array_filter((array) data_get($profile->ai_suggested_data, 'review_analysis.strengths', []))),
            'ai_review_risks' => array_values(array_filter((array) data_get($profile->ai_suggested_data, 'review_analysis.risks', []))),
            'ai_review_people' => array_values(array_filter((array) data_get($profile->ai_suggested_data, 'review_analysis.people', []))),
            'ai_review_sentiment' => data_get($profile->ai_suggested_data, 'review_analysis.sentiment'),
            'ai_review_status' => data_get($profile->ai_suggested_data, 'review_analysis.status'),
            'ai_review_generated_at' => data_get($profile->ai_suggested_data, 'review_analysis.generated_at'),
            'category_label' => $primaryCategory?->name ?: 'Категорія',
            'category_slug' => $primaryCategory?->slug,
            'category_icon' => $primaryCategory?->icon ?: 'fa-solid fa-briefcase',
            // Slug міста для breadcrumb-схеми (Каталог › Категорія › Місто › Профіль).
            'city_slug' => \App\Support\RegionCityDirectory::citySlug($profile->city),
            'directions' => $profile->services->pluck('name')->values()->all(),
        ];
    }

    /**
     * Профіль вартий індексації, якщо має щонайменше один опублікований
     * відгук АБО змістовний опис (не заглушку). Спільна логіка для
     * meta robots на сторінці й для включення в sitemap.
     */
    private function profileIsIndexable(Profile $profile, ?int $publishedReviewsTotal = null): bool
    {
        if (\App\Support\ProfileSeoIndexPolicy::indexesAllPublicProfiles()) {
            return \App\Support\ProfileSeoIndexPolicy::isPublic($profile);
        }

        if (\App\Support\ProfileSeoIndexPolicy::usesReviewsDossierAi()) {
            return \App\Support\ProfileSeoIndexPolicy::passesReviewsDossierAi($profile);
        }

        if (\App\Support\ProfileSeoIndexPolicy::usesContentSignals()) {
            return \App\Support\ProfileSeoIndexPolicy::passesContentSignals($profile);
        }

        // Прихований з каталогу профіль не потрапляє ні в sitemap, ні у видачу
        // сайту — тримати його в індексі Google було б непослідовно.
        if (! $profile->is_published || ! $profile->show_in_catalog) {
            return false;
        }

        // Суворий режим: в index лише сторінки з унікальним контентом —
        // критерії в ProfileSeoIndexPolicy, дзеркально до фільтра sitemap.
        if (\App\Support\ProfileSeoIndexPolicy::strictEnabled()) {
            return \App\Support\ProfileSeoIndexPolicy::passes($profile);
        }

        $reviewsCount = $publishedReviewsTotal ?? (int) $profile->reviews_count;
        if ($reviewsCount > 0) {
            return true;
        }

        $description = trim(strip_tags((string) ($profile->description ?? '')));
        $shortDescription = trim(strip_tags((string) ($profile->short_description ?? '')));

        if (mb_strlen($description) >= 120 || mb_strlen($shortDescription) >= 60) {
            return true;
        }

        // Профіль без відгуків і без опису все одно вартий індексації, якщо
        // має власний контент: досьє з реєстрів або перелік послуг. Раніше
        // такі сторінки (≈3,3 тис.) закривались разом із порожніми картками.
        // Порожні — лише назва й адреса — лишаються під noindex свідомо:
        // десять тисяч однотипних сторінок без змісту тягнуть оцінку всього
        // домену вниз, і профіль відкривається сам, щойно зʼявиться контент.
        if (trim((string) ($profile->dossier ?? '')) !== '') {
            return true;
        }

        return rescue(fn (): bool => $profile->services()->exists(), false, false);
    }

    /**
     * Додає до кейсів текст відгуку, з якого кейс складено.
     *
     * Чому не просто посилання на відгук у списку: у профілів із сотнями
     * відгуків потрібний запис лежить на 20-й «сторінці» підвантаження, і
     * перехід за якорем нічого не знаходить (перевірено на профілі з 590
     * відгуками). Тому підтвердження показуємо на місці — один запит на
     * сторінку, розкривається за кліком.
     *
     * @param  array<int, array<string, mixed>>  $cases
     * @return array<int, array<string, mixed>>
     */
    private function attachCaseProofs(Profile $profile, array $cases): array
    {
        $cases = \App\Support\ProfileCases::normalize($cases);
        if ($cases === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (array $case): int => (int) ($case['review_id'] ?? 0),
            $cases
        )));

        if ($ids === []) {
            return $cases;
        }

        $reviews = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->whereIn('id', $ids)
            ->get(['id', 'author_name', 'body', 'rating', 'published_at', 'created_at'])
            ->keyBy('id');

        foreach ($cases as $index => $case) {
            $review = $reviews->get((int) ($case['review_id'] ?? 0));
            if (! $review) {
                // Відгук зник або знятий з публікації — знімаємо й підтвердження,
                // щоб не лишалося порожнього посилання на «доказ».
                unset($cases[$index]['review_id'], $cases[$index]['review_date']);

                continue;
            }

            $date = $review->published_at ?? $review->created_at;
            $cases[$index]['proof'] = [
                'author' => trim((string) $review->author_name) ?: 'Клієнт',
                'rating' => (float) $review->rating,
                'date' => $date?->translatedFormat('d F Y'),
                'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $review->body) ?? ''), 0, 600),
            ];
        }

        return array_values($cases);
    }

    /**
     * @param  array<string, mixed>  $aiSuggestedData
     * @return array<string, mixed>
     */
    private function resolveOwnerProfileData(array $aiSuggestedData): array
    {
        $ownerProfile = data_get($aiSuggestedData, 'owner_profile');
        $ownerProfile = is_array($ownerProfile) ? $ownerProfile : [];
        $keys = [
            'experience_years',
            'consultations_count',
            'response_speed',
            'experience',
            'faq',
            'cases',
        ];

        foreach ($keys as $key) {
            if ($this->isBlankOwnerProfileValue($ownerProfile[$key] ?? null) && array_key_exists($key, $aiSuggestedData)) {
                $ownerProfile[$key] = $aiSuggestedData[$key];
            }
        }

        return [
            'experience_years' => isset($ownerProfile['experience_years']) && $ownerProfile['experience_years'] !== null
                ? (int) $ownerProfile['experience_years']
                : null,
            'consultations_count' => isset($ownerProfile['consultations_count']) && $ownerProfile['consultations_count'] !== null
                ? (int) $ownerProfile['consultations_count']
                : null,
            'response_speed' => $this->nullableString($ownerProfile['response_speed'] ?? null),
            'experience' => $this->nullableString($ownerProfile['experience'] ?? null),
            'faq' => $this->normalizeOwnerProfileFaq($ownerProfile['faq'] ?? []),
            'cases' => \App\Support\ProfileCases::normalize($ownerProfile['cases'] ?? []),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isBlankOwnerProfileValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null;
    }

    /**
     * @return array<int, array{title:string,text:string}>
     */
    /**
     * @return array<int, array{q:string,a:string}>
     */
    private function normalizeOwnerProfileFaq(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function ($item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $question = $this->nullableString($item['q'] ?? ($item['question'] ?? null));
                $answer = $this->nullableString($item['a'] ?? ($item['answer'] ?? null));

                if (! $question || ! $answer) {
                    return null;
                }

                return [
                    'q' => $question,
                    'a' => $answer,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Meta description з першого змістовного абзацу досьє (markdown → текст).
     */
    private function dossierSeoDescription(?string $dossier): ?string
    {
        $dossier = trim((string) $dossier);
        if ($dossier === '') {
            return null;
        }

        foreach (preg_split('/\n\s*\n/u', $dossier) ?: [] as $paragraph) {
            // Прибираємо markdown-розмітку: жирний, заголовки, списки.
            $plain = trim((string) preg_replace(['/\*\*|__|[#>*`]/u', '/\s+/u'], ['', ' '], $paragraph));
            if (mb_strlen($plain) >= 60) {
                return Str::limit($plain, 158, '…');
            }
        }

        return null;
    }

    /**
     * «Світлофор»-висновок досьє першим рядком мета-опису (сніпета). Показуємо
     * лише green/yellow — позитив/нейтрал, що тягне CTR. Red не виводимо у
     * видачу (та сама репутаційно-правова обережність, що й зі знятим публічним
     * «не рекомендуємо»). Застосовується до опису з будь-якого джерела.
     */
    private function withDossierVerdictLead(string $description, ?string $verdict): string
    {
        $lead = match ($verdict) {
            'green' => '✅ Записи чисті. ',
            'yellow' => '⚠️ Є що перевірити. ',
            default => '',
        };

        if ($lead === '' || $description === ''
            || str_starts_with($description, '✅') || str_starts_with($description, '⚠️')) {
            return $description;
        }

        return $lead . Str::limit($description, 160 - mb_strlen($lead), '…');
    }

    /**
     * @return array<int, array{display:string,href:string}>
     */
    private function mapProfilePhoneLinks(string $phone): array
    {
        $parts = preg_split('/\s*(?:,|;|\||\/|\r\n|\r|\n)\s*/u', trim($phone)) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->unique()
            ->map(function (string $part): ?array {
                $href = preg_replace('/[^\d\+]+/u', '', $part) ?: '';

                if ($href === '') {
                    return null;
                }

                return [
                    'display' => $part,
                    'href' => $href,
                ];
            })
            ->filter(fn ($item) => is_array($item) && filled($item['href'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,author:string,date:string,rating:float,text:string,avatar:string,avatar_url:?string,title:?string,media:array<int,string>,official_reply:?array{author:string,text:string,date:string},like_count:int,dislike_count:int,user_reaction:?string,replies_count:int,replies:array<int,array<string,mixed>>}>
     */
    private function mapProfileReviews(Profile $profile, int $limit = 12, array $relations = []): array
    {
        $reviews = $profile->relationLoaded('reviews')
            ? $profile->reviews->take($limit)->values()
            : ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->where('status', 'published')
                ->with($relations)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

        return $this->mapProfileReviewsFromCollection($profile, $reviews);
    }

    /**
     * @param  Collection<int, ProfileReview>  $reviews
     * @return array<int, array{id:int,author:string,date:string,date_iso:?string,rating:float,text:string,avatar:string,avatar_url:?string,title:?string,media:array<int,string>,external_source_label:?string,external_source_type:?string,external_source_url:?string,official_reply:?array{author:string,text:string,date:string},like_count:int,dislike_count:int,user_reaction:?string,replies_count:int,replies:array<int,array<string,mixed>>}>
     */
    private function mapProfileReviewsFromCollection(Profile $profile, Collection $reviews): array
    {
        return $reviews
            ->map(function (ProfileReview $review) use ($profile) {
                $author = $review->author_name ?: 'Користувач DOVIRA';
                $avatar = mb_strtoupper(mb_substr(trim($author), 0, 1));
                $publishedAt = $review->published_at ?: $review->created_at;
                $replies = $this->reviewRepliesAvailable()
                    ? $this->mapReviewRepliesCollection($review->replies)
                    : [];

                if ($review->officialReply) {
                    array_unshift($replies, $this->mapOfficialReplyAsReviewReply($profile, $review));
                }

                return [
                    'id' => (int) $review->id,
                    'author' => $author,
                    'date' => optional($publishedAt)->translatedFormat('d F Y') ?: now()->translatedFormat('d F Y'),
                    'date_iso' => optional($publishedAt)?->toDateString(),
                    'rating' => (float) $review->rating,
                    'title' => $review->title,
                    'text' => $review->body,
                    'avatar' => $avatar,
                    'avatar_url' => MediaUrl::avatarUrl(MediaUrl::publicImageUrl($review->external_review_author_avatar_url, [
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
                    ]), $author, 128),
                    'media' => collect((array) ($review->media ?? []))
                        ->map(fn (string $path) => MediaUrl::publicUrl($path))
                        ->filter()
                        ->values()
                        ->all(),
                    'external_source_label' => $this->externalReviewSourceLabel($review->external_source_type, $review->external_source_url),
                    'external_source_type' => $review->external_source_type,
                    'external_source_url' => $review->resolved_external_source_url,
                    'official_reply' => null,
                    'like_count' => (int) ($review->like_count ?? 0),
                    'dislike_count' => (int) ($review->dislike_count ?? 0),
                    'user_reaction' => $this->reviewReactionsAvailable() ? $review->reactions->first()?->reaction : null,
                    'replies_count' => count($replies),
                    'replies' => $replies,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ReviewReply>  $replies
     * @return array<int, array<string, mixed>>
     */
    private function mapReviewRepliesCollection(Collection $replies): array
    {
        return $replies
            ->map(fn (ReviewReply $reply) => $this->mapReviewReply($reply))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReviewReply(ReviewReply $reply): array
    {
        $authorName = trim((string) ($reply->author?->name ?: ($reply->external_author ?: 'Користувач DOVIRA')));
        $avatar = mb_strtoupper(mb_substr($authorName, 0, 1));

        return [
            'id' => (int) $reply->id,
            'parent_id' => $reply->parent_id ? (int) $reply->parent_id : null,
            'author' => $authorName,
            'avatar' => $avatar,
            'avatar_url' => MediaUrl::avatarUrl($reply->author?->avatar_url, $authorName, 96),
            'text' => (string) $reply->body,
            'date' => optional($reply->created_at)->translatedFormat('d F Y, H:i') ?: now()->translatedFormat('d F Y, H:i'),
            'date_iso' => optional($reply->created_at)?->toIso8601String(),
            'is_official' => (bool) $reply->is_official,
            'is_system' => false,
            'is_edited' => (bool) $reply->is_edited,
            'like_count' => (int) ($reply->like_count ?? 0),
            'dislike_count' => (int) ($reply->dislike_count ?? 0),
            'user_reaction' => $this->reviewReplyReactionsAvailable() && $reply->relationLoaded('reactions')
                ? $reply->reactions->first()?->reaction
                : null,
            'children' => $this->mapReviewRepliesCollection($reply->children),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOfficialReplyAsReviewReply(Profile $profile, ProfileReview $review): array
    {
        $reply = $review->officialReply;
        $authorName = trim((string) ($profile->name ?: 'Профіль DOVIRA'));
        $avatar = mb_strtoupper(mb_substr($authorName, 0, 1));

        return [
            'id' => 'official-'.(int) $review->id,
            'official_reply_id' => (int) ($reply?->id ?? 0),
            'parent_id' => null,
            'author' => $authorName,
            'avatar' => $avatar,
            'avatar_url' => MediaUrl::profileLogoUrl($profile, 96),
            'text' => (string) ($reply?->body ?? ''),
            'date' => optional($reply?->created_at)->translatedFormat('d F Y, H:i') ?: now()->translatedFormat('d F Y, H:i'),
            'date_iso' => optional($reply?->created_at)?->toIso8601String(),
            'is_official' => true,
            'is_system' => true,
            'is_edited' => false,
            'like_count' => (int) ($reply?->like_count ?? 0),
            'dislike_count' => (int) ($reply?->dislike_count ?? 0),
            'user_reaction' => $this->officialReplyReactionsAvailable() && $reply?->relationLoaded('reactions')
                ? $reply->reactions->first()?->reaction
                : null,
            'children' => [],
        ];
    }

    /**
     * @param  Collection<int, ReviewReply>  $replies
     */
    private function countRepliesForThread(Collection $replies): int
    {
        return $replies->sum(function (ReviewReply $reply): int {
            $children = $reply->relationLoaded('children') && $reply->children instanceof Collection
                ? $reply->children
                : collect();

            return 1 + $this->countRepliesForThread($children);
        });
    }

    /**
     * @return array{
     *   updated_status_text:string,
     *   updated_status_title:string,
     *   popularity_rank_label:string,
     *   popularity_rank_title:string,
     *   popularity_rank_color:string,
     *   popularity_rank:?int,
     *   popularity_total:int
     * }
     */
    private function buildTrustMeta(Profile $profile): array
    {
        $updatedAt = $profile->updated_at;
        $updatedStatusText = 'Оновлено: невідомо';
        $updatedStatusTitle = 'Дата останнього оновлення недоступна';

        if ($updatedAt) {
            if ($updatedAt->isToday()) {
                $updatedStatusText = 'Оновлено сьогодні о '.$updatedAt->format('H:i');
            } elseif ($updatedAt->isYesterday()) {
                $updatedStatusText = 'Оновлено вчора о '.$updatedAt->format('H:i');
            } else {
                $updatedStatusText = 'Оновлено '.$updatedAt->format('d.m.Y');
            }

            $updatedStatusTitle = 'Останнє оновлення: '.$updatedAt->format('d.m.Y H:i');
        }

        $popularityRankLabel = 'Популярність: —';
        $popularityRankTitle = 'Немає підкатегорії або міста для розрахунку популярності';
        $popularityRankColor = '#9fb0cf';

        $popularityContext = app(ProfileAnalyticsService::class)->popularityRankInLocalContext($profile);
        $rank = $popularityContext['rank'];
        $total = (int) ($popularityContext['total'] ?? 0);
        $city = (string) ($popularityContext['city'] ?? '');
        $contextType = (string) ($popularityContext['context_type'] ?? '');
        $contextName = (string) ($popularityContext['context_name'] ?? '');

        if ($rank !== null && $total > 0 && $contextName !== '' && $city !== '') {
            $contextLabel = $contextType === 'subcategory' ? 'підкатегорії' : 'категорії';
            $popularityRankLabel = "Популярність: {$contextName}, {$city} · #{$rank} із {$total}";
            $popularityRankTitle = "Позиція #{$rank} серед {$total} профілів у {$contextLabel} «{$contextName}» в місті {$city}";

            $relative = $rank / $total;
            $popularityRankColor = match (true) {
                $relative <= 0.20 => '#30ba73', // top 20%
                $relative <= 0.60 => '#f7a21f', // middle
                default => '#e74b4b', // low positions
            };
        }

        return [
            'updated_status_text' => $updatedStatusText,
            'updated_status_title' => $updatedStatusTitle,
            'popularity_rank_label' => $popularityRankLabel,
            'popularity_rank_title' => $popularityRankTitle,
            'popularity_rank_color' => $popularityRankColor,
            'popularity_rank' => ($rank !== null && $total > 0) ? (int) $rank : null,
            'popularity_total' => $total,
        ];
    }

    /**
     * @return array{
     *   trust_progress:float,
     *   trust_color_accent:string,
     *   trust_color_soft:string,
     *   trust_tone:string
     * }
     */
    private function buildTrustVisualMeta(Profile $profile): array
    {
        $rating = max(0.0, min(5.0, (float) ($profile->rating_avg ?? 0)));
        $progress = round(($rating / 5) * 100, 1);

        $tone = match (true) {
            $rating >= 4.0 => 'excellent',
            $rating >= 2.5 => 'warning',
            default => 'danger',
        };

        [$accentColor, $softColor] = match ($tone) {
            'excellent' => ['#34c77a', '#eaf9f1'],
            'warning' => ['#f7a21f', '#fff4e8'],
            default => ['#e74b4b', '#fdecec'],
        };

        return [
            'trust_progress' => $progress,
            'trust_color_accent' => $accentColor,
            'trust_color_soft' => $softColor,
            'trust_tone' => $tone,
        ];
    }

    /**
     * @return array{
     *   rating_distribution:array{5:int,4:int,3:int,2:int,1:int},
     *   rating_distribution_total:int
     * }
     */
    private function buildRatingDistribution(Profile $profile): array
    {
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $ratingBuckets = ProfileReview::query()
            ->selectRaw('rating, COUNT(*) as aggregate')
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        $total = 0;

        foreach ($ratingBuckets as $rating => $aggregate) {
            $normalizedRating = (int) round((float) $rating);
            if ($normalizedRating < 1 || $normalizedRating > 5) {
                continue;
            }

            $count = (int) $aggregate;
            $counts[$normalizedRating] += $count;
            $total += $count;
        }

        // Пороги узгоджені з клієнтським фільтром на сторінці профілю
        // (позитивні: 4-5★, негативні: 1-3★) — рахуємо тут, а не на клієнті,
        // бо клієнт бачить лише вже підвантажену партію відгуків.
        $positiveCount = $counts[4] + $counts[5];
        $negativeCount = $counts[1] + $counts[2] + $counts[3];

        if ($total <= 0) {
            return [
                'rating_distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
                'rating_distribution_total' => 0,
                'reviews_positive_count' => 0,
                'reviews_negative_count' => 0,
            ];
        }

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $star) {
            $distribution[$star] = (int) round(($counts[$star] / $total) * 100);
        }

        return [
            'rating_distribution' => $distribution,
            'rating_distribution_total' => $total,
            'reviews_positive_count' => $positiveCount,
            'reviews_negative_count' => $negativeCount,
        ];
    }

    private function ratingDistributionTotal(Profile $profile): int
    {
        return (int) ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->count();
    }

    private function profileReviewRelations(Request $request): array
    {
        $currentUser = $request->user();
        $reviewRelations = ['officialReply.author'];

        if ($this->officialReplyReactionsAvailable()) {
            $reviewRelations['officialReply.reactions'] = fn ($query) => $query
                ->when(
                    $currentUser,
                    fn ($reactionQuery) => $reactionQuery->where('user_id', $currentUser->id),
                    fn ($reactionQuery) => $reactionQuery->whereRaw('1 = 0')
                )
                ->select(['id', 'official_reply_id', 'user_id', 'reaction']);
        }

        if ($this->reviewRepliesAvailable()) {
            $childReplyRelations = ['author:id,name,avatar_url'];
            $rootReplyRelations = ['author:id,name,avatar_url'];

            if ($this->reviewReplyReactionsAvailable()) {
                $replyReactionRelation = fn ($reactions) => $reactions
                    ->when(
                        $currentUser,
                        fn ($reactionQuery) => $reactionQuery->where('user_id', $currentUser->id),
                        fn ($reactionQuery) => $reactionQuery->whereRaw('1 = 0')
                    )
                    ->select(['id', 'review_reply_id', 'user_id', 'reaction']);

                $childReplyRelations['reactions'] = $replyReactionRelation;
                $rootReplyRelations['reactions'] = $replyReactionRelation;
            }

            $rootReplyRelations['children'] = fn ($children) => $children
                ->where('status', 'published')
                ->with($childReplyRelations)
                ->orderBy('created_at');

            $reviewRelations['replies'] = fn ($query) => $query
                ->where('status', 'published')
                ->whereNull('parent_id')
                ->with($rootReplyRelations)
                ->orderBy('created_at');
        }

        if ($this->reviewReactionsAvailable()) {
            $reviewRelations['reactions'] = fn ($query) => $query
                ->when(
                    $currentUser,
                    fn ($reactionQuery) => $reactionQuery->where('user_id', $currentUser->id),
                    fn ($reactionQuery) => $reactionQuery->whereRaw('1 = 0')
                )
                ->select(['id', 'profile_review_id', 'user_id', 'reaction']);
        }

        return $reviewRelations;
    }

    private function externalReviewSourceLabel(?string $type, ?string $url): ?string
    {
        if (blank($type) && blank($url)) {
            return null;
        }

        return match ($type) {
            'google_maps', 'google', 'google_business' => 'Відгук з Google',
            'threads' => 'Відгук з Threads',
            'facebook' => 'Відгук з Facebook',
            'instagram' => 'Згадка з Instagram',
            'vidhuk' => 'Відгук з Vidhuk.ua',
            'realreviews' => 'Відгук з RealReviews',
            'list_in_ua' => 'Відгук з List.in.ua',
            // Відгуки з uadvokat показуємо без бейджа джерела.
            'uadvokat' => null,
            default => 'Зовнішній відгук',
        };
    }

    /**
     * @return array{
     *   author_name?:?string,
     *   author_email?:?string,
     *   rating:int,
     *   body:string,
     *   media?:array<int,\Illuminate\Http\UploadedFile>,
     *   profile_slug?:string
     * }
     */
    private function validateReviewPayload(Request $request, bool $withProfileSlug = false): array
    {
        $rules = [
            'author_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'author_email' => ['nullable', 'email:rfc', 'max:255'],
            'author_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{5,40}$/'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // Текст необов'язковий — відгук можна лишити самою оцінкою.
            'body' => ['nullable', 'string', 'max:3000'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime', 'max:51200'],
        ];

        if ($withProfileSlug) {
            $rules['profile_slug'] = ['required', 'string', 'max:180'];
        }

        return $request->validate($rules);
    }

    /**
     * @param array{
     *   author_name?:?string,
     *   author_email?:?string,
     *   rating:int,
     *   body:string,
     *   media?:array<int,\Illuminate\Http\UploadedFile>
     * } $validated
     */
    private function createPendingReview(Request $request, Profile $profile, array $validated): string
    {
        $authorName = $request->user()?->name ?: trim((string) ($validated['author_name'] ?? ''));
        if ($authorName === '') {
            $authorName = 'Користувач DOVIRA';
        }
        $authorEmail = trim((string) ($validated['author_email'] ?? ''));
        if ($authorEmail === '') {
            $authorEmail = (string) ($request->user()?->email ?? '');
        }
        if ($authorEmail === '') {
            $authorEmail = null;
        }

        $authorPhone = trim((string) ($validated['author_phone'] ?? ''));
        if ($authorPhone === '') {
            $authorPhone = null;
        }

        $body = trim((string) ($validated['body'] ?? ''));

        // Автомодерація: очевидний спам одразу відхиляється (rejected) і не
        // потрапляє в чергу модератора; підозрілі позначаються прапорцем.
        $verdict = app(\App\Services\ReviewAutoModerationService::class)->evaluate(
            $profile,
            $body,
            $request->ip(),
            ! $request->user(),
        );

        $mediaPaths = [];
        // Медіа не зберігаємо для авто-відхилених — навіщо тримати спам-файли.
        if ($verdict['status'] !== 'rejected') {
            foreach ((array) ($validated['media'] ?? []) as $file) {
                $mediaPaths[] = Storage::disk('public')->putFile('reviews-media', $file);
            }
        }

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $request->user()?->id,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'author_phone' => $authorPhone,
            'author_ip' => $request->ip(),
            'author_user_agent' => (string) $request->userAgent(),
            'is_anonymous' => ! $request->user(),
            'rating' => (int) $validated['rating'],
            'title' => null,
            'body' => $body,
            'media' => ! empty($mediaPaths) ? $mediaPaths : null,
            'status' => $verdict['status'],
            'risk_score' => $verdict['risk_score'],
            'is_suspicious' => $verdict['is_suspicious'],
            'moderation_reason' => $verdict['reason'],
            'is_verified_purchase' => false,
            'published_at' => null,
        ]);

        return 'Дякуємо! Відгук надіслано на модерацію. Після перевірки він зʼявиться на сторінці профілю.';
    }

    private function syncReviewReactionCounters(ProfileReview $review): void
    {
        if (! $this->reviewReactionsAvailable()) {
            return;
        }

        $likes = ProfileReviewReaction::query()
            ->where('profile_review_id', $review->id)
            ->where('reaction', 'like')
            ->count();

        $dislikes = ProfileReviewReaction::query()
            ->where('profile_review_id', $review->id)
            ->where('reaction', 'dislike')
            ->count();

        $review->forceFill([
            'helpful_count' => $likes,
            'like_count' => $likes,
            'dislike_count' => $dislikes,
        ])->saveQuietly();
    }

    private function syncReviewReplyReactionCounters(ReviewReply $reply): void
    {
        if (! $this->reviewReplyReactionsAvailable()) {
            return;
        }

        $likes = ReviewReplyReaction::query()
            ->where('review_reply_id', $reply->id)
            ->where('reaction', 'like')
            ->count();

        $dislikes = ReviewReplyReaction::query()
            ->where('review_reply_id', $reply->id)
            ->where('reaction', 'dislike')
            ->count();

        $reply->forceFill([
            'like_count' => $likes,
            'dislike_count' => $dislikes,
        ])->saveQuietly();
    }

    private function syncOfficialReplyReactionCounters($officialReply): void
    {
        if (! $this->officialReplyReactionsAvailable()) {
            return;
        }

        $likes = OfficialReplyReaction::query()
            ->where('official_reply_id', $officialReply->id)
            ->where('reaction', 'like')
            ->count();

        $dislikes = OfficialReplyReaction::query()
            ->where('official_reply_id', $officialReply->id)
            ->where('reaction', 'dislike')
            ->count();

        $officialReply->forceFill([
            'like_count' => $likes,
            'dislike_count' => $dislikes,
        ])->saveQuietly();
    }

    private function reviewRepliesAvailable(): bool
    {
        if ($this->reviewRepliesAvailable !== null) {
            return $this->reviewRepliesAvailable;
        }

        return $this->reviewRepliesAvailable = self::schemaFeature('review_replies', fn (): bool =>
            Schema::hasTable('review_replies')
            && Schema::hasColumns('review_replies', ['parent_id', 'status']));
    }

    private function reviewReactionsAvailable(): bool
    {
        if ($this->reviewReactionsAvailable !== null) {
            return $this->reviewReactionsAvailable;
        }

        return $this->reviewReactionsAvailable = self::schemaFeature('review_reactions', fn (): bool =>
            Schema::hasTable('profile_review_reactions')
            && Schema::hasColumns('profile_reviews', ['like_count', 'dislike_count']));
    }

    private function reviewReplyReactionsAvailable(): bool
    {
        if ($this->reviewReplyReactionsAvailable !== null) {
            return $this->reviewReplyReactionsAvailable;
        }

        return $this->reviewReplyReactionsAvailable = self::schemaFeature('reply_reactions', fn (): bool =>
            Schema::hasTable('review_reply_reactions')
            && Schema::hasColumns('review_replies', ['like_count', 'dislike_count']));
    }

    private function officialReplyReactionsAvailable(): bool
    {
        if ($this->officialReplyReactionsAvailable !== null) {
            return $this->officialReplyReactionsAvailable;
        }

        return $this->officialReplyReactionsAvailable = self::schemaFeature('official_reply_reactions', fn (): bool =>
            Schema::hasTable('official_reply_reactions')
            && Schema::hasColumns('official_replies', ['like_count', 'dislike_count']));
    }

    /**
     * Наявність таблиць/колонок фіч кешуємо на добу: схема БД між деплоями не
     * змінюється, а Schema::hasTable() б'є в information_schema і на віддаленій
     * базі коштував до 180 мс — по чотири рази на кожне відкриття профілю.
     * Після міграцій кеш скидається штатним `php artisan cache:clear`.
     */
    private static function schemaFeature(string $key, \Closure $probe): bool
    {
        // fallback передаємо ЗАМИКАННЯМ: rescue() обчислює другий аргумент
        // одразу, тож $probe() тут виконував зайву перевірку схеми щоразу.
        return (bool) rescue(
            fn () => Cache::remember('schema-feature:' . $key, now()->addDay(), $probe),
            $probe,
            false
        );
    }

    private function getTopRatingUrlForCategory(?Category $category, ?string $cityName, ?string $citySlug): ?string
    {
        if ($category === null || ! $category->is_indexable) {
            return null;
        }

        if (Category::where('parent_id', $category->id)->where('is_active', true)->where('is_indexable', true)->exists()) {
            return null;
        }

        $categoryIds = CategoryHierarchy::categoryIdsFromNames([$category->name]);
        if (empty($categoryIds)) {
            return null;
        }

        $base = Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->where('reviews_count', '>=', 1)
            ->where(function ($q): void {
                $q->whereNull('rating_placement')->orWhere('rating_placement', '!=', 'hide');
            })
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));

        if ($cityName !== null && $citySlug !== null) {
            $variants = collect(RegionCityDirectory::variantsForCity($cityName))
                ->push($cityName)
                ->map(fn (string $v) => mb_strtolower(trim($v)))
                ->unique()
                ->values()
                ->all();
            $count = (clone $base)->whereIn(DB::raw('lower(trim(city))'), $variants)->count();
            if ($count >= self::TOP_RATING_MIN_PROFILES) {
                return route('top.rating.city', ['category' => $category->slug, 'city' => $citySlug]);
            }
        } else {
            $count = (clone $base)->count();
            if ($count >= self::TOP_RATING_MIN_PROFILES) {
                return route('top.rating', ['category' => $category->slug]);
            }
        }

        return null;
    }
}
