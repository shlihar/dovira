@extends('static.layout')

@php
    /**
     * «ТОП-10 {категорія} {місто}» — programmatic SEO-рейтинг на реальних
     * даних платформи. Дизайн-мова головної: hero-градієнт, скромні шапки
     * секцій, картки на токенах. Комбінації з < MIN_INDEXABLE профілів —
     * noindex (рендеряться, але не індексуються і не потрапляють у sitemap).
     */
    $catName = (string) $category->name;
    $catNameLower = mb_strtolower($catName);
    $catRatingName = (string) ($categoryRatingName ?? $catNameLower);
    $inCity = $cityName ? ' у місті ' . $cityName : ' України';
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');

    // Візуальний відсоток заповнення 5 зірок за рейтингом.
    $starFill = fn (float $r): string => round(max(0, min(5, $r)) / 5 * 100, 2) . '%';

    $leader = $rows[0] ?? null;
    $rest = array_slice($rows, 1);

    // Заголовок за формулою, що працює на посадкових каталогу: спершу сам
    // запит («адвокати у Києві»), далі конкретне число відгуків. Старий
    // патерн («Топ адвокатів — Київ 2026») починався зі слова «Топ», якого
    // немає в запитах людей, і не мав жодної цифри — конкуренти з числами
    // у заголовку виглядали у видачі змістовнішими.
    $cityLocative = $cityName ? \App\Support\RegionCityDirectory::cityLocative($cityName) : null;
    $topCount = count($rows);
    $reviewsWord = \App\Support\Plural::uk($totalReviews, 'відгуком', 'відгуками', 'відгуками');
    $pageTitle = $cityName
        ? 'ТОП-' . $topCount . ' ' . $catRatingName . ' ' . ($cityLocative ?: $cityName)
            . ' ' . $year . ' — за ' . $fmt($totalReviews) . ' ' . $reviewsWord . ' | DOVIRA'
        : 'ТОП-' . $topCount . ' ' . $catRatingName . ' України ' . $year
            . ' — рейтинг за ' . $fmt($totalReviews) . ' ' . $reviewsWord . ' | DOVIRA';
    $pageDescription = 'Топ ' . $catRatingName . $inCity . ' за реальними відгуками клієнтів. Рейтинг '
        . count($rows)
        . ' з ' . $fmt($totalProfiles) . ' профілів, ' . $fmt($totalReviews) . ' відгуків. Оновлюється щодня — безкоштовно, без реєстрації.';
    // Власна адреса сторінки (для хлібних крихт і schema — там потрібен
    // саме цей URL, а не канонічний).
    $selfUrl = $citySlug
        ? route('top.rating.city', ['category' => $categorySlug, 'city' => $citySlug])
        : route('top.rating', ['category' => $categorySlug]);

    // КАНОНІЧНА сторінка пари «категорія × місто» — каталог, а не рейтинг.
    // Обидві відповідали на той самий запит («адвокати Київ»), конкурували
    // між собою у видачі й показували різні цифри по одному місту. Каталог
    // повніший (усі профілі проти ТОП-10) і має сильніший заголовок, тому
    // вагу консолідуємо на ньому; /top лишається для людей і внутрішньої
    // перелінковки, але в індексі не дублює каталог.
    $canonical = $citySlug
        ? route('catalog.landing.city', ['category' => $categorySlug, 'city' => $citySlug])
        : route('catalog.landing', ['category' => $categorySlug]);

    $topFaq = [
        [
            'q' => 'Як формується рейтинг «' . $catName . ($cityName ? ' — ' . $cityName : '') . '»?',
            'a' => 'Оцінка — проста середня всіх відгуків профілю, її можна перерахувати самому. Щоб профіль із трьома пʼятірками не обганяв спеціаліста з сотнею оцінок, у топ потрапляють лише профілі від 20 відгуків. Купити місце в рейтингу неможливо — він однаковий для всіх.',
        ],
        [
            'q' => 'Чи можна довіряти відгукам у рейтингу?',
            'a' => 'Відгуки проходять автоматичну й ручну модерацію, а профілі з підтвердженими власниками позначаються окремо. Негативні відгуки не видаляються — спеціаліст може лише публічно відповісти на них.',
        ],
        [
            'q' => 'Як потрапити в цей рейтинг?',
            'a' => 'Безкоштовно: підтвердіть свій профіль на DOVIRA і збирайте реальні відгуки клієнтів. Що більше підтверджених відгуків і вища оцінка — то вища позиція. Платних місць у рейтингу немає.',
        ],
    ];

    $topSchemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Топ ' . $catRatingName . $inCity . ' — рейтинг за відгуками',
            'numberOfItems' => count($rows),
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            // Оцінки з видимого рейтингу віддаємо і в розмітці (для AI-движків
            // і як опис позиції). reviews_count завжди ≥1 — сторінка будується
            // лише на профілях із відгуками, тож AggregateRating валідний.
            'itemListElement' => collect($rows)->map(fn (array $row) => [
                '@type' => 'ListItem',
                'position' => $row['rank'],
                'name' => $row['name'],
                'url' => $row['url'],
                'item' => [
                    '@type' => 'LocalBusiness',
                    'name' => $row['name'],
                    'url' => $row['url'],
                    'aggregateRating' => [
                        '@type' => 'AggregateRating',
                        'ratingValue' => number_format($row['rating'], 1, '.', ''),
                        'reviewCount' => $row['reviews_count'],
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ],
                ],
            ])->all(),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_values(array_filter([
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Рейтинг: ' . $catName, 'item' => route('top.rating', ['category' => $categorySlug])],
                $cityName ? ['@type' => 'ListItem', 'position' => 3, 'name' => $cityName, 'item' => $selfUrl] : null,
            ])),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($topFaq)->map(fn (array $item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ])->all(),
        ],
    ];

    $catalogUrl = $cityName
        ? route('catalog.landing.city', ['category' => $categorySlug, 'city' => $citySlug])
        : route('catalog.landing', ['category' => $categorySlug]);
@endphp

@section('title', $pageTitle)
@section('description', $pageDescription)
@section('canonical', $canonical)
@section('body_class', 'page-top-rating')
@unless ($indexable)
    @section('robots', 'noindex, follow')
@endunless

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/top-rating.css') }}?v={{ @filemtime(public_path('static/css/pages/top-rating.css')) }}">
    @if ($indexable)
        @foreach ($topSchemas as $topSchema)
            <script type="application/ld+json">@json($topSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
        @endforeach
    @endif
@endpush

@php
    // Переюзний рендер зірок (bg + clipped fg).
    $starsHtml = function (float $rating) use ($starFill): string {
        return '<span class="stars" role="img" aria-label="' . number_format($rating, 1) . ' з 5">'
            . '<span class="stars__bg">★★★★★</span>'
            . '<span class="stars__fg" style="width:' . $starFill($rating) . '">★★★★★</span>'
            . '</span>';
    };
@endphp

@section('content')
    {{-- ── Hero ── --}}
    <section class="top-hero" aria-labelledby="top-title">
        <div class="top-hero__glow" aria-hidden="true"></div>
        <div class="container top-hero__inner">
            <nav class="top-hero__crumbs" aria-label="Хлібні крихти">
                <a href="{{ route('home') }}">Головна</a>
                <span aria-hidden="true">/</span>
                @if ($cityName)
                    <a href="{{ route('top.rating', ['category' => $categorySlug]) }}">Рейтинг: {{ $catName }}</a>
                    <span aria-hidden="true">/</span>
                    <span>{{ $cityName }}</span>
                @else
                    <span>Рейтинг: {{ $catName }}</span>
                @endif
            </nav>

            <span class="top-hero__eyebrow"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Рейтинг {{ $year }}</span>

            <h1 class="top-hero__title" id="top-title">
                Топ {{ $catRatingName }}{{ $cityName ? ' — ' . $cityName : ' України' }}
            </h1>

            <p class="top-hero__lead">
                @if ($totalProfiles > 0)
                    ТОП-{{ count($rows) }} за реальними відгуками з <strong>{{ $fmt($totalProfiles) }}</strong> профілів
                    і <strong>{{ $fmt($totalReviews) }}</strong> відгуків
                @else
                    Рейтинг за реальними відгуками клієнтів
                @endif
            </p>

            <div class="top-hero__badges">
                <span class="top-hero__pill"><i class="fa-solid fa-lock" aria-hidden="true"></i> Позиції не продаються</span>
                <span class="top-hero__pill"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Зважений рейтинг</span>
                <span class="top-hero__pill"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Оновлено {{ $updatedAt->translatedFormat('d.m.Y') }}</span>
            </div>
        </div>
    </section>

    {{-- ── Інші міста ── --}}
    @if (count($cityLinks) > 0)
        <section class="section top-cities" aria-labelledby="top-cities-title">
            <div class="container">
                <div class="top-head">
                    <div class="top-head__text">
                        <h2 class="top-head__title" id="top-cities-title">{{ $catName }} в інших містах</h2>
                    </div>
                </div>
                <div class="top-cities__chips">
                    @if ($cityName)
                        <a class="top-cities__chip top-cities__chip--all" href="{{ route('top.rating', ['category' => $categorySlug]) }}">
                            <i class="fa-solid fa-map" aria-hidden="true"></i> Вся Україна
                        </a>
                    @endif
                    @foreach ($cityLinks as $link)
                        <a class="top-cities__chip" href="{{ $link['url'] }}">{{ $link['name'] }}</a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ── Рейтинг ── --}}
    <section class="section top-list" aria-labelledby="top-list-title">
        <div class="container">
            <div class="top-head">
                <div class="top-head__text">
                    <h2 class="top-head__title" id="top-list-title">Хто в топі</h2>
                    <p class="top-head__sub">За зваженою оцінкою і кількістю реальних відгуків.</p>
                </div>
                <a class="top-head__all" href="{{ $catalogUrl }}">Всі {{ $fmt(max($totalProfiles, count($rows))) }} у каталозі <span aria-hidden="true">→</span></a>
            </div>

            @if (count($rows) === 0)
                <div class="top-empty">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                    <p>У цій комбінації поки замало профілів з відгуками, щоб будувати чесний рейтинг.</p>
                    <a class="btn btn--primary" href="{{ route('catalog') }}">Відкрити каталог</a>
                </div>
            @else
                {{-- Лідер рейтингу (#1) --}}
                @if ($leader)
                    <a class="top-leader" href="{{ $leader['url'] }}">
                        <span class="top-leader__ribbon"><i class="fa-solid fa-crown" aria-hidden="true"></i> Лідер рейтингу</span>
                        <span class="top-leader__logo">
                            @if ($leader['logo_url'])
                                <img src="{{ $leader['logo_url'] }}" alt="{{ $leader['name'] }}" loading="lazy" onerror="this.remove()">
                            @endif
                            <span aria-hidden="true">{{ $leader['initials'] }}</span>
                        </span>
                        <span class="top-leader__body">
                            <span class="top-leader__name">
                                {{ $leader['name'] }}
                                @if ($leader['verified'])
                                    <i class="fa-solid fa-circle-check top-leader__verified" title="Профіль підтверджено" aria-hidden="true"></i>
                                @endif
                            </span>
                            <span class="top-leader__meta">
                                @if ($leader['city'])
                                    <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $leader['city'] }}</span>
                                @endif
                                @foreach ($leader['specializations'] as $spec)
                                    <span class="top-chip">{{ $spec }}</span>
                                @endforeach
                                @if ($leader['recommended'])
                                    <span class="top-chip top-chip--rec"><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i> Рекомендує Dovira</span>
                                @endif
                            </span>
                            <span class="top-leader__rating">
                                @if ($leader['rating_visible'] ?? true)
                                    {!! $starsHtml($leader['rating']) !!}
                                    <b>{{ number_format($leader['rating'], 1) }}</b>
                                @else
                                    {{-- Число ховаємо так само, як на сторінці профілю (< MIN_REVIEWS). --}}
                                    <b>оцінка формується</b>
                                @endif
                                <span class="top-leader__reviews">{{ $fmt($leader['reviews_count']) }} відгуків</span>
                            </span>
                            @if (!empty($leader['dossier_excerpt']))
                                <span class="top-card__dossier">{{ $leader['dossier_excerpt'] }}</span>
                            @endif
                        </span>
                        <span class="top-leader__cta">Переглянути профіль <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                @endif

                {{-- Решта рейтингу (#2…#10) --}}
                @if (count($rest) > 0)
                    <ol class="top-rows" start="2">
                        @foreach ($rest as $row)
                            <li>
                                <a class="top-row {{ in_array($row['rank'], [2, 3], true) ? 'top-row--medal top-row--medal-' . $row['rank'] : '' }}" href="{{ $row['url'] }}">
                                    <span class="top-row__rank" aria-hidden="true">{{ $row['rank'] }}</span>
                                    <span class="top-row__logo">
                                        @if ($row['logo_url'])
                                            <img src="{{ $row['logo_url'] }}" alt="{{ $row['name'] }}" loading="lazy" onerror="this.remove()">
                                        @endif
                                        <span aria-hidden="true">{{ $row['initials'] }}</span>
                                    </span>
                                    <span class="top-row__main">
                                        <span class="top-row__name">
                                            {{ $row['name'] }}
                                            @if ($row['verified'])
                                                <i class="fa-solid fa-circle-check top-row__verified" title="Профіль підтверджено" aria-hidden="true"></i>
                                            @endif
                                        </span>
                                        <span class="top-row__meta">
                                            @if ($row['city'])
                                                <span class="top-row__city"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $row['city'] }}</span>
                                            @endif
                                            @foreach (array_slice($row['specializations'], 0, 1) as $spec)
                                                <span class="top-chip">{{ $spec }}</span>
                                            @endforeach
                                            @if ($row['recommended'])
                                                <span class="top-chip top-chip--rec"><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i> Рекомендує Dovira</span>
                                            @endif
                                        </span>
                                        @if (!empty($row['dossier_excerpt']))
                                            <span class="top-card__dossier">{{ $row['dossier_excerpt'] }}</span>
                                        @endif
                                    </span>
                                    <span class="top-row__rating">
                                        @if ($row['rating_visible'] ?? true)
                                            {!! $starsHtml($row['rating']) !!}
                                            <span class="top-row__score"><b>{{ number_format($row['rating'], 1) }}</b> · {{ $fmt($row['reviews_count']) }} відг.</span>
                                        @else
                                            <span class="top-row__score">оцінка формується · {{ $fmt($row['reviews_count']) }} відг.</span>
                                        @endif
                                    </span>
                                    <i class="fa-solid fa-chevron-right top-row__go" aria-hidden="true"></i>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            @endif
        </div>
    </section>

    {{-- ── Методологія ── --}}
    <section class="section top-method" aria-labelledby="top-method-title">
        <div class="container">
            <div class="top-method__wrap">
                <div class="top-method__col">
                    <h2 class="top-method__title" id="top-method-title">Як рахується цей рейтинг</h2>
                    <p>Оцінка — це <b>проста середня</b> всіх відгуків профілю: сума оцінок поділена на їхню кількість. Щоб порівняння було чесним, у топ беруться лише профілі від 20 відгуків — трьома пʼятірками сюди не потрапити.</p>
                    <p>Рейтинг перераховується автоматично з кожним новим відгуком. Платних місць немає: єдиний спосіб піднятися — реальні відгуки клієнтів. Докладніше — <a href="{{ route('rating.method') }}">як формується рейтинг</a>.</p>
                </div>
                <div class="top-method__facts">
                    <div class="top-method__fact">
                        <b>{{ $avgRating > 0 ? number_format($avgRating, 1) : '—' }}</b>
                        <span>середній рейтинг{{ $cityName ? ' у місті' : '' }}</span>
                    </div>
                    <div class="top-method__fact">
                        <b>{{ $fmt($totalReviews) }}</b>
                        <span>відгуків враховано</span>
                    </div>
                    <div class="top-method__fact">
                        <b>{{ $fmt($totalProfiles) }}</b>
                        <span>профілів у вибірці</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ── --}}
    <section class="section top-faq" aria-labelledby="top-faq-title">
        <div class="container">
            <div class="top-head">
                <div class="top-head__text">
                    <h2 class="top-head__title" id="top-faq-title">Часті питання</h2>
                </div>
                @if ($categorySlug === 'advokaty')
                    <a class="top-head__all" href="{{ route('lawyer.check') }}">Як перевірити адвоката <span aria-hidden="true">→</span></a>
                @endif
            </div>
            <div class="top-faq__list">
                @foreach ($topFaq as $item)
                    <details class="top-faq__item" @if ($loop->first) open @endif>
                        <summary class="top-faq__q">
                            <span>{{ $item['q'] }}</span>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <p class="top-faq__a">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA для бізнесу ── --}}
    <section class="section top-cta" aria-labelledby="top-cta-title">
        <div class="container">
            <div class="top-cta__wrap">
                <div class="top-cta__text">
                    <h2 class="top-cta__title" id="top-cta-title">Ви маєте бути в цьому рейтингу?</h2>
                    <p class="top-cta__lead">Підтвердіть свій профіль безкоштовно і збирайте реальні відгуки — рейтинг оновиться автоматично. Платних місць тут немає.</p>
                </div>
                <div class="top-cta__actions">
                    <a class="btn top-cta__btn" href="{{ route('pro') }}">Знайти свій профіль <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>
    </section>
@endsection
