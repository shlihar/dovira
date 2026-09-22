@extends('static.layout')

@php
    // Єдина вітрина: якщо зайшли по ЧПУ /catalog/{slug}[/{city}] — унікальні
    // SEO-теги під категорію (+місто), інакше — загальні каталогу.
    $pathCategory = $pathCategory ?? null;
    $pathCityName = $pathCityName ?? null;
    $pathIndexable = $pathIndexable ?? true;
    $pathCanonical = $pathCanonical ?? null;
    $pathRelated = $pathRelated ?? [];
    $landingSeo = $landingSeo ?? null;

    $catNum = fn (int $n): string => number_format($n, 0, '.', ' ');
    $catLower = $pathCategory ? mb_strtolower((string) $pathCategory->name) : null;
    $catCityLocative = $pathCityName ? \App\Support\RegionCityDirectory::cityLocative($pathCityName) : null;
    $catPlace = $catCityLocative ?? 'в Україні';
    $catYear = now()->year;
    $catProfilesTotal = (int) ($landingSeo['profiles_count'] ?? 0);
    $catReviewsTotal = (int) ($landingSeo['reviews_count'] ?? 0);
    $catAvgRating = (float) ($landingSeo['avg_rating'] ?? 0);

    // Title/description посадкової: конкретика (числа, рік) підіймає CTR у
    // видачі; «Найкращі …» — під частотний запит, коли реально є з чого обирати.
    if ($pathCategory && $catProfilesTotal >= 3) {
        $catSeoTitle = 'Найкращі ' . $catLower . ' ' . $catPlace . ' — рейтинг ' . $catYear
            . ($catReviewsTotal > 0
                ? ' за ' . $catNum($catReviewsTotal) . ' ' . \App\Support\Plural::uk($catReviewsTotal, 'відгуком', 'відгуками', 'відгуками')
                : ', ' . $catNum($catProfilesTotal) . ' профілів')
            . ' | DOVIRA';
        $catSeoDescription = '⭐ ' . $pathCategory->name . ' ' . $catPlace . ': ' . $catNum($catProfilesTotal)
            . ' ' . \App\Support\Plural::uk($catProfilesTotal, 'профіль', 'профілі', 'профілів')
            . ($catReviewsTotal > 0 ? ', ' . $catNum($catReviewsTotal) . ' ' . \App\Support\Plural::uk($catReviewsTotal, 'реальний відгук', 'реальні відгуки', 'реальних відгуків') : '')
            . ($catAvgRating > 0 ? ', середній рейтинг ' . number_format($catAvgRating, 1) : '')
            . '. Порівняйте всіх і оберіть перевіреного — безкоштовний рейтинг DOVIRA ' . $catYear . '.';
    } elseif ($pathCategory) {
        $catSeoTitle = $pathCategory->name . ($pathCityName ? ' — ' . $pathCityName : '') . ' — відгуки та рейтинги — DOVIRA';
        $catSeoDescription = 'Відгуки, рейтинги й перевірені профілі в категорії «' . $pathCategory->name . '»' . ($pathCityName ? ' у місті ' . $pathCityName : '') . ' на DOVIRA.';
    } else {
        $catSeoTitle = 'Каталог компаній і спеціалістів України з відгуками — DOVIRA';
        $catSeoDescription = 'Каталог перевірених компаній, магазинів, сервісів і спеціалістів України. Реальні відгуки клієнтів, рейтинги, фільтри за категоріями та містами.';
    }

    // FAQ посадкової: видимий блок і FAQPage-schema з одного джерела.
    $catFaqItems = [];
    if ($pathCategory && $catProfilesTotal >= 3) {
        $catFaqItems[] = [
            'q' => 'Скільки профілів у категорії «' . $pathCategory->name . '» ' . ($catCityLocative ? 'є ' . $catCityLocative : 'є в Україні') . '?',
            'a' => 'На DOVIRA зібрано ' . $catNum($catProfilesTotal) . ' ' . \App\Support\Plural::uk($catProfilesTotal, 'профіль', 'профілі', 'профілів')
                . ' у категорії «' . $pathCategory->name . '» ' . $catPlace
                . ($catReviewsTotal > 0 ? ' та ' . $catNum($catReviewsTotal) . ' ' . \App\Support\Plural::uk($catReviewsTotal, 'відгук', 'відгуки', 'відгуків') . ' клієнтів' : '')
                . '. Список поповнюється щодня.',
        ];
        $catTopProfile = $landingSeo['top_profiles'][0] ?? null;
        if ($catTopProfile && (int) $catTopProfile['reviews_count'] >= 3) {
            $catFaqItems[] = [
                'q' => 'Хто має найвищий рейтинг у категорії «' . $pathCategory->name . '» ' . ($catCityLocative ?? 'в Україні') . '?',
                'a' => 'Найвищу позицію рейтингу за відгуками наразі має ' . $catTopProfile['name'] . ' — '
                    . number_format((float) $catTopProfile['rating'], 1) . ' з 5 на основі '
                    . $catNum((int) $catTopProfile['reviews_count']) . ' ' . \App\Support\Plural::uk((int) $catTopProfile['reviews_count'], 'відгуку', 'відгуків', 'відгуків')
                    . '. Позиції в рейтингу залежать і від оцінок, і від кількості відгуків.',
            ];
        }
        $catFaqItems[] = [
            'q' => 'Як обрати надійного виконавця в категорії «' . $pathCategory->name . '»?',
            'a' => 'Дивіться не лише на середню оцінку: важлива кількість відгуків, відповіді бізнесу на критику та позначка «Підтверджено власником». Обовʼязково читайте негативні відгуки — вони показують, як компанія розвʼязує проблеми клієнтів.',
        ];
        $catFaqItems[] = [
            'q' => 'Як залишити відгук на DOVIRA?',
            'a' => 'Відкрийте профіль компанії чи спеціаліста та натисніть «Залишити відгук». Це безкоштовно, реєстрація не обовʼязкова; відгук публікується після модерації.',
        ];
    }
@endphp

@section('title', $catSeoTitle)
@section('description', $catSeoDescription)
@section('canonical', $pathCanonical ?? route('catalog'))
@section('body_class', 'page-catalog')
@if (! $pathIndexable)
    @section('robots', 'noindex, follow')
@endif

@if ($pathCategory && $pathIndexable)
    @push('head')
        @php
            $catBreadcrumbItems = [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Каталог', 'item' => route('catalog')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $pathCategory->name, 'item' => route('catalog.landing', ['category' => $pathCategory->slug])],
            ];
            if ($pathCityName && ($pathCitySlug ?? null)) {
                $catBreadcrumbItems[] = ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $pathCityName, 'item' => route('catalog.landing.city', ['category' => $pathCategory->slug, 'city' => $pathCitySlug])];
            }

            // ItemList — профілі першої сторінки видачі (імена + URL, без
            // рейтингів: у розмітку йдуть лише нативні відгуки платформи).
            $catItemList = $profiles->getCollection()
                ->values()
                ->map(fn (array $profile, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => (string) ($profile['name'] ?? ''),
                    'url' => route('profile.show', ['slug' => $profile['slug']]),
                ])
                ->all();

            $catLandingSchema = [
                '@context' => 'https://schema.org',
                '@graph' => array_values(array_filter([
                    [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => $catBreadcrumbItems,
                    ],
                    [
                        '@type' => 'CollectionPage',
                        'name' => html_entity_decode($catSeoTitle, ENT_QUOTES, 'UTF-8'),
                        'url' => $pathCanonical ?? url()->current(),
                        'inLanguage' => 'uk',
                        'isPartOf' => ['@id' => url('/') . '#website'],
                        'mainEntity' => [
                            '@type' => 'ItemList',
                            'numberOfItems' => $catProfilesTotal,
                            'itemListElement' => $catItemList,
                        ],
                    ],
                    $catFaqItems !== [] ? [
                        '@type' => 'FAQPage',
                        'mainEntity' => collect($catFaqItems)->map(fn (array $item) => [
                            '@type' => 'Question',
                            'name' => $item['q'],
                            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                        ])->all(),
                    ] : null,
                ])),
            ];
        @endphp
        <script type="application/ld+json">@json($catLandingSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endpush
@endif

@push('head')
    {{-- ?v=filemtime: без версії браузери тримали стару копію стилів каталогу
         після кожного деплою (решта ассетів версіонується так само). --}}
    <link rel="stylesheet" href="{{ asset('static/css/pages/catalog.css') }}?v={{ @filemtime(public_path('static/css/pages/catalog.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}?v={{ @filemtime(public_path('static/css/profile-card-catalog.css')) }}">
@endpush

@section('content')
    @php
        $catalogProfileUrl = fn (string $slug) => route('profile.show', [
            'slug' => $slug,
        ]);
        $categoryUrl = fn (?string $category) => route('catalog', [
            'category' => $category ?: null,
        ]);
        $popularQueries = $popularQueries ?? [];
        $catalogCount = $catalogCount ?? ['label' => 'Знайдено профілі'];
        $activeSort = $activeSort ?? 'recommended';
        $catalogStats = $catalogStats ?? [
            'profiles' => $profiles->getCollection()->count(),
            'reviews' => $profiles->getCollection()->sum(fn ($profile) => (int) ($profile['reviews_count'] ?? 0)),
            'verified' => $profiles->getCollection()->filter(fn ($profile) => !empty($profile['verified']))->count(),
        ];
        $selectedCategories = collect((array) request()->query('categories', []))->filter()->values();
        if (request()->filled('category')) {
            $selectedCategories->push((string) request('category'));
        }
        if (request()->filled('sub')) {
            $selectedCategories = collect([(string) request('sub')]);
        }
        $selectedCategories = $selectedCategories->unique(fn ($value) => mb_strtolower((string) $value))->values();
        $selectedRegions = collect((array) request()->query('regions', []))->filter()->values();
    $activeFilterChips = collect();
        $chipUrl = function (array $remove): string {
            $query = request()->except('ajax', 'page');
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

            return route('catalog', $query);
        };
        if (request()->filled('q')) {
            $activeFilterChips->push(['label' => request('q'), 'url' => $chipUrl(['q'])]);
        }
        foreach ($selectedCategories as $category) {
            $activeFilterChips->push(['label' => $category, 'url' => $chipUrl(['categories' => $category, 'category', 'sub'])]);
        }
        foreach ($selectedRegions as $region) {
            $activeFilterChips->push(['label' => $region, 'url' => $chipUrl(['regions' => $region])]);
        }
        if (request()->filled('rating')) {
            $activeFilterChips->push(['label' => 'Рейтинг ' . request('rating'), 'url' => $chipUrl(['rating'])]);
        }
        if (request()->filled('reviews_count')) {
            $activeFilterChips->push(['label' => request('reviews_count') . ' відгуків', 'url' => $chipUrl(['reviews_count'])]);
        }
        if (request('status') === 'owner_verified') {
            $activeFilterChips->push(['label' => 'Підтверджений власником', 'url' => $chipUrl(['status'])]);
        } elseif (request('status') === 'recommended') {
            $activeFilterChips->push(['label' => 'Довіра рекомендує', 'url' => $chipUrl(['status'])]);
        }
    $mobileQuickCategories = collect($categoryOptions ?? [])
        ->whenEmpty(function ($collection) use ($categoryFilters) {
            return collect($categoryFilters ?? [])
                    ->map(fn (array $category) => ['name' => $category['name'] ?? null, 'count' => (int) ($category['count'] ?? 0)]);
            })
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->unique(fn ($item) => mb_strtolower((string) ($item['name'] ?? '')))
            ->take(3)
            ->values();
    $catalogSortLabels = [
        'recommended' => 'Рекомендовані',
        'popular' => 'За популярністю',
        'rating_desc' => 'За рейтингом',
        'reviews_desc' => 'За відгуками',
        'newest' => 'Нові профілі',
        'name_asc' => 'Від А до Я',
    ];
    $currentMobileSortLabel = $catalogSortLabels[$activeSort] ?? 'Сортування';
    $catalogAddProfileUrl = route('pro.account', ['tab' => 'claims']);
    @endphp

    <section class="section reviews-page">
        <div class="container">
            <div class="reviews-page__layout">
                {{-- H1 + лід-абзац стоять ПЕРЕД сайдбаром у DOM: розмітка
                     фільтрів важить ~84 КБ, і головний заголовок сторінки
                     опинявся аж на 36% файлу — Google бачив спершу стіну
                     фільтрів, а не тему сторінки. Візуально блок лишається
                     зверху на всю ширину (grid-column: 1 / -1). --}}
                <div class="catalog-hero">
                    <div class="catalog-hero__copy" data-catalog-hero-copy>
                        @include('static.partials.catalog-hero-copy', ['heroCategory' => $pathCategory, 'heroCityName' => $pathCityName, 'heroLandingSeo' => $landingSeo])
                    </div>
                    <div class="catalog-hero__aside">
                        <div class="catalog-hero__stats" aria-label="Статистика каталогу">
                            <div class="catalog-stat">
                                <span class="catalog-stat__icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                                <strong data-catalog-stat="profiles">{{ number_format((int) ($catalogStats['profiles'] ?? 0), 0, '.', ' ') }}+</strong>
                                <small>профілів</small>
                            </div>
                            <div class="catalog-stat">
                                <span class="catalog-stat__icon catalog-stat__icon--star"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                                <strong data-catalog-stat="reviews">{{ number_format((int) ($catalogStats['reviews'] ?? 0), 0, '.', ' ') }}+</strong>
                                <small>відгуків</small>
                            </div>
                        </div>
                        <a class="catalog-add-profile-btn" href="{{ $catalogAddProfileUrl }}">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            <span>Додати профіль</span>
                        </a>
                    </div>
                </div>

                <aside class="reviews-sidebar" aria-label="Фільтри">
                    <div class="catalog-filter-card">
                        <div class="catalog-filter-card__head">
                            <h2>Фільтри</h2>
                            <a href="{{ route('catalog') }}">Очистити все</a>
                        </div>
                        <form class="reviews-filter-form" data-catalog-filters-form>
                            <input type="hidden" name="sort" value="{{ $activeSort }}" data-filter-input data-catalog-sort-input>
                            <div data-catalog-filters-body>
                                @include('static.partials.catalog-filters')
                            </div>
                        </form>
                    </div>
                </aside>

                <div class="reviews-content">
                    <div class="catalog-toolbar">
                        <div class="reviews-primary-search reviews-sidebar__search-wrap catalog-toolbar__search catalog-toolbar__search--desktop">
                            <form
                                class="hero__desktop-search reviews-sidebar__search"
                                action="{{ route('catalog') }}"
                                method="get"
                                aria-label="Пошук у каталозі"
                                data-search-form
                            >
                                <div class="reviews-sidebar__search-field">
                                    <i class="fa-solid fa-magnifying-glass catalog-search__icon" aria-hidden="true"></i>
                                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Пошук компанії, спеціаліста або послуги...">
                                    <button type="submit" aria-label="Шукати">
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="hero__search-suggest reviews-search__suggest" data-search-suggest hidden>
                                    <p class="hero__search-suggest-title">Популярні запити</p>
                                </div>
                            </form>
                        </div>

                        <div class="reviews-mobile-search catalog-toolbar__search catalog-toolbar__search--mobile" data-search-mobile-shell>
                            <form
                                class="hero__desktop-search hero__mobile-search reviews-sidebar__search"
                                action="{{ route('catalog') }}"
                                method="get"
                                aria-label="Пошук у каталозі"
                                data-search-form
                                data-search-mobile-app
                            >
                                <div class="reviews-sidebar__search-field hero__mobile-search-field">
                                    <i class="fa-solid fa-magnifying-glass catalog-search__icon" aria-hidden="true"></i>
                                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Пошук компанії, спеціаліста або послуги...">
                                    <button type="submit" aria-label="Шукати">
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="hero__search-suggest reviews-search__suggest" data-search-suggest hidden>
                                    <p class="hero__search-suggest-title">Популярні запити</p>
                                </div>
                            </form>
                        </div>
                        <div class="catalog-mobile-controls" aria-label="Керування каталогом">
                            <button type="button" class="catalog-mobile-control" data-catalog-open-sort aria-expanded="false" aria-controls="catalog-mobile-sort-panel">
                                <i class="fa-solid fa-star" aria-hidden="true"></i>
                                <span data-catalog-mobile-sort-label>{{ $currentMobileSortLabel }}</span>
                            </button>
                            <button type="button" class="catalog-mobile-control" data-catalog-open-filters aria-expanded="false" aria-controls="catalog-mobile-filters-panel">
                                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                                <span>Фільтр</span>
                            </button>
                        </div>
                        <div class="catalog-sort">
                            <label class="sr-only" for="catalog-sort-desktop">Сортування</label>
                            <select id="catalog-sort-desktop" class="profile-select" data-catalog-sort-select>
                                <option value="recommended" {{ $activeSort === 'recommended' ? 'selected' : '' }}>Рекомендовані</option>
                                <option value="popular" {{ $activeSort === 'popular' ? 'selected' : '' }}>Популярні</option>
                                <option value="rating_desc" {{ $activeSort === 'rating_desc' ? 'selected' : '' }}>Найвищий рейтинг</option>
                                <option value="reviews_desc" {{ $activeSort === 'reviews_desc' ? 'selected' : '' }}>Найбільше відгуків</option>
                                <option value="newest" {{ $activeSort === 'newest' ? 'selected' : '' }}>Нові профілі</option>
                                <option value="name_asc" {{ $activeSort === 'name_asc' ? 'selected' : '' }}>Від А до Я</option>
                            </select>
                        </div>
                        <div class="reviews-content__actions">
                            <div class="reviews-content__count" data-catalog-results-count>{{ $catalogCount['label'] ?? 'Знайдено профілі' }}</div>
                            <div class="catalog-view-toggle" data-catalog-view-toggle>
                                <button type="button" class="catalog-view-toggle__btn" data-catalog-view="list" aria-label="Список">
                                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="catalog-view-toggle__btn is-active" data-catalog-view="grid" aria-label="Сітка">
                                    <i class="fa-solid fa-grip" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="catalog-active-filters" data-catalog-active-filters @if($activeFilterChips->isEmpty()) hidden @endif>
                            @foreach ($activeFilterChips as $chip)
                                <a class="catalog-active-filter" href="{{ $chip['url'] }}" data-catalog-filter-chip data-no-page-skeleton>
                                    <span>{{ $chip['label'] }}</span>
                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                </a>
                            @endforeach
                            @if ($activeFilterChips->isNotEmpty())
                                <a class="catalog-active-filters__clear" href="{{ route('catalog') }}" data-no-page-skeleton>Очистити все</a>
                            @endif
                        </div>
                    </div>

                    <div class="reviews-mobile-filters">
                        <details id="catalog-mobile-sort-panel" class="reviews-mobile-filters__details reviews-mobile-filters__details--sort">
                            <summary class="sr-only">Сортування каталогу</summary>
                            <button type="button" class="reviews-mobile-filters__backdrop" data-catalog-close-sort aria-label="Закрити сортування"></button>
                            <div class="reviews-mobile-filters__panel">
                                <div class="reviews-mobile-filters__panel-inner">
                                    <div class="catalog-mobile-filter-modal catalog-mobile-filter-modal--sort catalog-filter-card">
                                        <div class="catalog-mobile-filter-modal__handle" aria-hidden="true"></div>
                                        <header class="catalog-mobile-filter-modal__head">
                                            <h3>Сортування <i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i></h3>
                                            <button type="button" class="catalog-mobile-filter-modal__close" data-catalog-close-sort aria-label="Закрити">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </header>
                                        <div class="catalog-mobile-filter-modal__body">
                                            <div class="catalog-mobile-options">
                                                @foreach ($catalogSortLabels as $sortValue => $sortLabel)
                                                    <button type="button" class="catalog-mobile-option @if($activeSort === $sortValue) is-active @endif" data-catalog-mobile-sort-option data-sort-value="{{ $sortValue }}">
                                                        <span>{{ $sortLabel }}</span>
                                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </details>
                        <details id="catalog-mobile-filters-panel" class="reviews-mobile-filters__details">
                            <summary class="sr-only">Фільтри каталогу</summary>
                            <button type="button" class="reviews-mobile-filters__backdrop" data-catalog-close-filters aria-label="Закрити фільтри"></button>
                            <div class="reviews-mobile-filters__panel">
                                <div class="reviews-mobile-filters__panel-inner">
                                    <div class="catalog-mobile-filter-modal catalog-filter-card">
                                        <div class="catalog-mobile-filter-modal__handle" aria-hidden="true"></div>
                                        <header class="catalog-mobile-filter-modal__head">
                                            <h3>Фільтрувати за <i class="fa-solid fa-sliders" aria-hidden="true"></i></h3>
                                            <button type="button" class="catalog-mobile-filter-modal__close" data-catalog-close-filters aria-label="Закрити">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </header>
                                        <div class="catalog-mobile-filter-modal__body">
                                            <div class="reviews-filter">
                                                <form class="reviews-filter-form" data-catalog-filters-form>
                                                    <input type="hidden" name="sort" value="{{ $activeSort }}" data-filter-input data-catalog-sort-input>
                                                    <div data-catalog-filters-body>
                                                        @include('static.partials.catalog-filters')
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                        <footer class="catalog-mobile-filter-modal__foot">
                                            <button type="button" class="btn catalog-mobile-filter-modal__btn catalog-mobile-filter-modal__btn--ghost" data-catalog-reset-filters>Скинути</button>
                                            <button type="button" class="btn btn--primary catalog-mobile-filter-modal__btn" data-catalog-apply-filters>Показати</button>
                                        </footer>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div data-catalog-results>
                        @include('static.partials.catalog-results', [
                            'profiles' => $profiles,
                            'catalogProfileUrl' => $catalogProfileUrl,
                            'categoryUrl' => $categoryUrl,
                        ])
                    </div>

                    @php $topRatingUrl = $topRatingUrl ?? null; @endphp
                    @if ($pathCategory && $topRatingUrl)
                        <div class="catalog-top-rating">
                            <a class="catalog-top-rating__link" href="{{ $topRatingUrl }}">
                                <span class="catalog-top-rating__icon"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i></span>
                                <span class="catalog-top-rating__text">
                                    <span class="catalog-top-rating__label">🏆 Дивіться також</span>
                                    <span class="catalog-top-rating__title">ТОП-10 {{ mb_strtolower((string) $pathCategory->name) }}</span>
                                </span>
                            </a>
                        </div>
                    @endif

                    @if ($pathCategory && ! empty($catFaqItems))
                        <section class="catalog-faq" aria-labelledby="catalog-faq-title">
                            <h2 class="catalog-seo-links__title" id="catalog-faq-title">Часті питання</h2>
                            @foreach ($catFaqItems as $faqItem)
                                <details class="catalog-faq__item" @if($loop->first) open @endif>
                                    <summary class="catalog-faq__question">{{ $faqItem['q'] }}</summary>
                                    <p class="catalog-faq__answer">{{ $faqItem['a'] }}</p>
                                </details>
                            @endforeach
                        </section>
                    @endif

                    @php
                        // Лінки на посадкові рівня послуги («пластика носа у Києві»):
                        // лише для міських сторінок категорій зі словника service_landings.
                        $catServiceLinks = ($pathCategory && $pathCityName && ($pathCitySlug ?? null))
                            ? \App\Support\ServiceLandingSeo::servicesInCity($pathCategory, $pathCityName)
                            : [];
                    @endphp
                    @if ($catServiceLinks !== [])
                        <nav class="catalog-seo-links" aria-label="Популярні послуги">
                            <h2 class="catalog-seo-links__title">Популярні послуги: {{ mb_strtolower((string) $pathCategory->name) }} {{ $catCityLocative ?? $pathCityName }} — відгуки</h2>
                            <div class="catalog-seo-links__list">
                                @foreach ($catServiceLinks as $svcLink)
                                    <a class="catalog-active-filter catalog-seo-links__item" href="{{ route('catalog.landing.service', ['category' => $pathCategory->slug, 'city' => $pathCitySlug, 'service' => $svcLink['slug']]) }}">{{ $svcLink['name'] }} <span>({{ $svcLink['count'] }})</span></a>
                                @endforeach
                            </div>
                        </nav>
                    @endif

                    @if ($pathCategory && ! empty($landingSeo['city_links']))
                        {{-- Хаб міст категорії: на сторінці міста — «в інших містах»,
                             на сторінці категорії — повний список міст із лічильниками. --}}
                        <nav class="catalog-seo-links" aria-label="{{ $pathCategory->name }} за містами">
                            <h2 class="catalog-seo-links__title">{{ $pathCategory->name }} {{ $pathCityName ? 'в інших містах' : 'за містами' }}</h2>
                            <div class="catalog-seo-links__list">
                                @foreach ($landingSeo['city_links'] as $cityLink)
                                    <a class="catalog-active-filter catalog-seo-links__item" href="{{ route('catalog.landing.city', ['category' => $pathCategory->slug, 'city' => $cityLink['slug']]) }}">
                                        <span>{{ $cityLink['name'] }} ({{ $cityLink['count'] }})</span>
                                    </a>
                                @endforeach
                            </div>
                        </nav>
                    @endif

                    @if ($pathCategory && $pathCityName && ! empty($landingSeo['city_categories']))
                        <nav class="catalog-seo-links" aria-label="Інші категорії {{ $catCityLocative ?? $pathCityName }}">
                            <h2 class="catalog-seo-links__title">Інші послуги {{ $catCityLocative ?? 'у місті ' . $pathCityName }}</h2>
                            <div class="catalog-seo-links__list">
                                @foreach ($landingSeo['city_categories'] as $cityCategory)
                                    <a class="catalog-active-filter catalog-seo-links__item" href="{{ route('catalog.landing.city', ['category' => $cityCategory['slug'], 'city' => $pathCitySlug]) }}">
                                        <span>{{ $cityCategory['name'] }} ({{ $cityCategory['count'] }})</span>
                                    </a>
                                @endforeach
                            </div>
                        </nav>
                    @endif

                    @if ($pathCategory && ! $pathCityName && ! empty($pathRelated))
                        <nav class="catalog-seo-links" aria-label="Суміжні категорії">
                            <h2 class="catalog-seo-links__title">Суміжні категорії</h2>
                            <div class="catalog-seo-links__list">
                                @foreach ($pathRelated as $relatedCategory)
                                    <a class="catalog-active-filter catalog-seo-links__item" href="{{ route('catalog.landing', ['category' => $relatedCategory->slug]) }}">
                                        <span>{{ $relatedCategory->name }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </nav>
                    @endif

                    @if (!empty($seoLandingCategories))
                        {{-- SEO-лінки на посадкові категорій: усі посилання завжди в HTML
                             (для краулера), візуально згорнуто до перших 10 з кнопкою
                             «Всі категорії». --}}
                        <nav class="catalog-seo-links" aria-label="Популярні категорії каталогу" data-catalog-seo-links>
                            <h2 class="catalog-seo-links__title">Популярні категорії</h2>
                            <div class="catalog-seo-links__list">
                                @foreach ($seoLandingCategories as $index => $landingCategory)
                                    <a
                                        class="catalog-active-filter catalog-seo-links__item @if ($index >= 10) is-collapsed @endif"
                                        href="{{ route('catalog.landing', ['category' => $landingCategory['slug']]) }}"
                                    >
                                        <span>{{ $landingCategory['name'] }}</span>
                                    </a>
                                @endforeach
                                @if (count($seoLandingCategories) > 10)
                                    <button type="button" class="catalog-seo-links__toggle" data-catalog-seo-links-toggle aria-expanded="false">
                                        Всі категорії ({{ count($seoLandingCategories) }})
                                    </button>
                                @endif
                            </div>
                        </nav>
                        <script>
                            (() => {
                                const toggle = document.querySelector('[data-catalog-seo-links-toggle]');
                                toggle?.addEventListener('click', () => {
                                    document.querySelectorAll('[data-catalog-seo-links] .is-collapsed')
                                        .forEach((item) => item.classList.remove('is-collapsed'));
                                    toggle.remove();
                                });
                            })();
                        </script>
                    @endif
                </div>
            </div>
        </div>
    </section>

@endsection
