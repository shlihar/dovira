@extends('static.layout')

@php
    $landingYear = now()->year;
    $categoryName = trim((string) $category->name);
    $cityName = $city ? trim((string) $city) : null;

    $landingHeading = $cityName
        ? $categoryName . ' — ' . $cityName
        : $categoryName . ' в Україні';

    $landingTitle = $cityName
        ? $categoryName . ' — ' . $cityName . ': рейтинг і відгуки ' . $landingYear . ' | DOVIRA'
        : $categoryName . ' в Україні — рейтинг і відгуки ' . $landingYear . ' | DOVIRA';

    $landingDescription = $cityName
        ? sprintf(
            '%s у місті %s: %d профілів з реальними відгуками клієнтів. Порівняйте рейтинги, прочитайте відгуки та оберіть перевіреного виконавця на DOVIRA.',
            $categoryName,
            $cityName,
            $totalFound
        )
        : sprintf(
            '%s в Україні: %d профілів з реальними відгуками клієнтів. Рейтинги, відгуки та контакти перевірених компаній і спеціалістів на DOVIRA.',
            $categoryName,
            $totalFound
        );

    $landingBreadcrumbs = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => route('catalog')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $categoryName, 'item' => route('catalog.landing', ['category' => $category->slug])],
    ];
    if ($cityName) {
        $landingBreadcrumbs[] = ['@type' => 'ListItem', 'position' => 4, 'name' => $cityName, 'item' => $canonicalUrl];
    }

    $landingBreadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $landingBreadcrumbs,
    ];

    $landingItemListSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $landingHeading . ' — рейтинг і відгуки',
        'numberOfItems' => (int) $totalFound,
        'itemListElement' => $profiles->getCollection()
            ->values()
            ->map(fn (array $profile, int $index) => [
                '@type' => 'ListItem',
                'position' => ($profiles->currentPage() - 1) * $profiles->perPage() + $index + 1,
                'name' => $profile['name'],
                'url' => route('profile.show', ['slug' => $profile['slug']]),
            ])
            ->all(),
    ];

    // Посилання «відкрити з фільтрами» веде в живий каталог з тим самим зрізом.
    $landingCatalogUrl = route('catalog', array_filter([
        'categories' => [$categoryName],
        'regions' => $cityName ? [$cityName] : null,
    ]));

    // FAQ під конкретний зріз — рангує за питальними запитами й дає rich-snippet.
    $landingPlace = $cityName ? ('у місті ' . $cityName) : 'в Україні';
    $landingFaq = [
        [
            'q' => sprintf('Скільки профілів у категорії «%s» %s?', $categoryName, $landingPlace),
            'a' => sprintf('Зараз на DOVIRA %d профілів у категорії «%s» %s з рейтингами та реальними відгуками клієнтів. Каталог оновлюється, тож кількість може змінюватись.', (int) $totalFound, $categoryName, $landingPlace),
        ],
        [
            'q' => sprintf('Як обрати «%s», кому можна довіряти?', mb_strtolower($categoryName)),
            'a' => 'Порівняйте рейтинги, прочитайте відгуки реальних клієнтів і зверніть увагу на офіційні відповіді бізнесу та позначку «Керує власник» — це ознака, що профіль веде підтверджений представник компанії.',
        ],
        [
            'q' => 'Чи можна довіряти відгукам на DOVIRA?',
            'a' => 'Відгуки проходять модерацію та антиспам-перевірку. Позначені як «Відгук з Google» імпортовані з відкритих джерел, решта — залишені користувачами DOVIRA після авторизації.',
        ],
    ];

    $landingFaqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($landingFaq)->map(fn ($item) => [
            '@type' => 'Question',
            'name' => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ])->all(),
    ];
@endphp

@section('title', $landingTitle)
@section('description', $landingDescription)
{{-- Пагінація: кожна сторінка канонічна сама собі, інакше Google склеїть
     page=2+ з першою і не побачить глибших профілів. --}}
@section('canonical', $profiles->currentPage() > 1 ? $canonicalUrl . '?page=' . $profiles->currentPage() : $canonicalUrl)
@section('body_class', 'page-catalog page-catalog-landing')
@unless ($isIndexable)
    {{-- Порожня або закрита адміном посадкова — noindex, посилання лишаємо живими. --}}
    @section('robots', 'noindex, follow')
@endunless

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/catalog.css') }}">
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}">
    <script type="application/ld+json">@json($landingBreadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @if ($isIndexable)
        <script type="application/ld+json">@json($landingItemListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
        <script type="application/ld+json">@json($landingFaqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endif
@endpush

@section('content')
    @php
        $catalogProfileUrl = fn (string $slug) => route('profile.show', [
            'slug' => $slug,
        ]);
    @endphp

    <section class="section reviews-page catalog-landing">
        <div class="container">
            <nav class="catalog-landing__breadcrumbs" aria-label="Хлібні крихти">
                <a href="{{ route('home') }}">Головна</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('catalog') }}">Каталог</a>
                <span aria-hidden="true">/</span>
                @if ($cityName)
                    <a href="{{ route('catalog.landing', ['category' => $category->slug]) }}">{{ $categoryName }}</a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">{{ $cityName }}</span>
                @else
                    <span aria-current="page">{{ $categoryName }}</span>
                @endif
            </nav>

            <div class="catalog-hero">
                <div class="catalog-hero__copy">
                    <p class="catalog-hero__eyebrow">DOVIRA каталог</p>
                    <h1>{{ $landingHeading }}: рейтинг і відгуки</h1>
                    <p>
                        @if ($totalFound > 0)
                            {{ $cityName ? $categoryName . ' у місті ' . $cityName : $categoryName . ' в Україні' }}:
                            {{ $totalFound }} {{ trans_choice('профіль|профілі|профілів', $totalFound) }} з реальними відгуками клієнтів.
                            Порівняйте рейтинги й оберіть перевіреного виконавця.
                        @else
                            У цьому розрізі поки немає профілів — подивіться інші міста або весь каталог.
                        @endif
                    </p>
                </div>
                <div class="catalog-hero__aside">
                    <a class="catalog-add-profile-btn" href="{{ $landingCatalogUrl }}">
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                        <span>Відкрити з фільтрами</span>
                    </a>
                </div>
            </div>

            @if (!empty($citiesWithProfiles))
                <nav class="catalog-landing__cities" aria-label="{{ $categoryName }} за містами">
                    @foreach ($citiesWithProfiles as $linkSlug => $linkCity)
                        @continue($linkSlug === $citySlug)
                        <a class="catalog-active-filter" href="{{ route('catalog.landing.city', ['category' => $category->slug, 'city' => $linkSlug]) }}">
                            <span>{{ $linkCity }}</span>
                        </a>
                    @endforeach
                    @if ($cityName)
                        <a class="catalog-active-filter" href="{{ route('catalog.landing', ['category' => $category->slug]) }}">
                            <span>Вся Україна</span>
                        </a>
                    @endif
                </nav>
            @endif

            <div class="reviews-list catalog-landing__list">
                @include('static.partials.catalog-results-items', [
                    'profiles' => $profiles,
                    'catalogProfileUrl' => $catalogProfileUrl,
                ])
            </div>

            @if ($profiles->hasPages())
                {{-- Класична пагінація з реальними href: краулер обходить усі
                     сторінки без JS-безкінечного скролу. --}}
                <nav class="catalog-landing__pagination" aria-label="Сторінки">
                    @if ($profiles->onFirstPage())
                        <span class="catalog-landing__page is-disabled" aria-hidden="true">Назад</span>
                    @else
                        <a class="catalog-landing__page" href="{{ $profiles->previousPageUrl() }}" rel="prev">Назад</a>
                    @endif

                    @foreach ($profiles->getUrlRange(max(1, $profiles->currentPage() - 2), min($profiles->lastPage(), $profiles->currentPage() + 2)) as $page => $pageUrl)
                        @if ($page === $profiles->currentPage())
                            <span class="catalog-landing__page is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="catalog-landing__page" href="{{ $pageUrl }}">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if ($profiles->hasMorePages())
                        <a class="catalog-landing__page" href="{{ $profiles->nextPageUrl() }}" rel="next">Далі</a>
                    @else
                        <span class="catalog-landing__page is-disabled" aria-hidden="true">Далі</span>
                    @endif
                </nav>
            @endif

            <div class="catalog-landing__seo-text">
                <h2>Як обрати: {{ mb_strtolower($categoryName) }}{{ $cityName ? ' — ' . $cityName : '' }}</h2>
                <p>
                    На DOVIRA зібрані профілі з категорії «{{ $categoryName }}»{{ $cityName ? ' у місті ' . $cityName : ' з усієї України' }}
                    з рейтингами та відгуками реальних клієнтів. Відгуки проходять модерацію, а компанії з позначкою
                    «Керує власник» підтвердили право власності на профіль. Порівнюйте оцінки, читайте досвід інших
                    людей і звертайте увагу на офіційні відповіді бізнесу — це найшвидший спосіб знайти виконавця,
                    якому можна довіряти.
                </p>
            </div>

            @if (!empty($relatedCategories))
                <div class="catalog-landing__related">
                    <h2>Порівняйте з іншими напрямами{{ $cityName ? ' — ' . $cityName : '' }}</h2>
                    <div class="catalog-landing__related-links">
                        @foreach ($relatedCategories as $related)
                            @php
                                $relatedUrl = ($cityName && $citySlug)
                                    ? route('catalog.landing.city', ['category' => $related->slug, 'city' => $citySlug])
                                    : route('catalog.landing', ['category' => $related->slug]);
                            @endphp
                            <a class="catalog-active-filter" href="{{ $relatedUrl }}">
                                {{ $related->name }}{{ $cityName ? ' — ' . $cityName : '' }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="catalog-landing__faq faq">
                <h2>Часті питання</h2>
                <div class="faq__list">
                    @foreach ($landingFaq as $faqIndex => $item)
                        <details class="faq__item @if($faqIndex === 0) is-open @endif" @if($faqIndex === 0) open @endif>
                            <summary class="faq__question">{{ $item['q'] }}</summary>
                            <div class="faq__answer"><p>{{ $item['a'] }}</p></div>
                        </details>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endsection
