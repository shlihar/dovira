@extends('static.layout')

@php
    /**
     * Стаття-рейтинг «ТОП-{N} {категорія} {місто} — {місяць рік}».
     *
     * Сторінка рендерить ЗАМОРОЖЕНИЙ місячний зріз (top_rating_snapshots), а не
     * живий запит: інакше склад топу пливе щодня і стаття не може чесно сказати
     * «станом на вересень». Порядок — індекс DOVIRA з шести вимірів, кожен із
     * яких показаний читачеві під профілем.
     */
    $catName = (string) $category->name;
    $catRatingName = (string) ($categoryRatingName ?? mb_strtolower($catName));
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');
    $starFill = fn (float $r): string => round(max(0, min(5, $r)) / 5 * 100, 2) . '%';

    $cityLocative = $cityName ? \App\Support\RegionCityDirectory::cityLocative($cityName) : null;
    $placeLabel = $cityName ? ($cityLocative ?: $cityName) : 'України';
    $topCount = count($rows);
    $period = $period ?? \App\Models\TopRatingSnapshot::currentPeriod();
    $periodLabel = $periodLabel ?? '';
    $weights = $weights ?? [];
    $minReviews = $minReviews ?? 20;

    $pageTitle = 'ТОП-' . $topCount . ' ' . $catRatingName . ' ' . $placeLabel
        . ' — ' . $periodLabel . ' | DOVIRA';
    $pageDescription = 'ТОП-' . $topCount . ' ' . $catRatingName . ' ' . $placeLabel . ' станом на '
        . $periodLabel . '. Рейтинг за індексом DOVIRA: оцінка, обсяг і свіжість відгуків, попит, перевірка профілю. '
        . $fmt($totalReviews) . ' відгуків, ' . $fmt($totalProfiles) . ' профілів.';

    $selfUrl = $citySlug
        ? route('top.rating.city', ['category' => $categorySlug, 'city' => $citySlug])
        : route('top.rating', ['category' => $categorySlug]);

    // КАНОНІЧНА сторінка пари «категорія × місто» — каталог. Обидві сторінки
    // відповідають на той самий запит, і колись вони конкурували між собою у
    // видачі з різними цифрами по одному місту. Поки агрегати не зведені до
    // одного джерела, вагу лишаємо на каталозі.
    $canonical = $citySlug
        ? route('catalog.landing.city', ['category' => $categorySlug, 'city' => $citySlug])
        : route('catalog.landing', ['category' => $categorySlug]);

    $catalogUrl = $canonical;

    $weightLabels = [
        'quality' => ['Якість відгуків', 'Середня оцінка плюс «чистий сентимент»: пʼятірки мінус одиниці й двійки. Так профіль «або 5, або 1» не виглядає відмінником.'],
        'volume' => ['Обсяг доказів', 'Кількість відгуків за логарифмом: 200 відгуків важать більше за 20, але не в десять разів.'],
        'freshness' => ['Свіжість', 'Відгуки за останні 90 днів. Рейтинг має показувати тих, хто працює зараз, а не зібрав відгуки три роки тому.'],
        'demand' => ['Попит', 'Перегляди профілю й звернення до контактів. Наші власні розсилки з підрахунку виключені.'],
        'trust' => ['Перевірка', 'Підтверджений власник, досьє з відкритих джерел, розбір тем у відгуках.'],
        'responsiveness' => ['Робота з відгуками', 'Публічні відповіді власника, зокрема на негативні відгуки.'],
    ];

    $topFaq = [
        [
            'q' => 'Як формується цей рейтинг?',
            'a' => 'Місце визначає індекс DOVIRA — сума шести вимірів: якість відгуків (35%), обсяг відгуків (20%), свіжість за 90 днів (20%), попит на профіль (10%), перевірка профілю (10%) і робота з відгуками (5%). Індекс рахується всередині пари «категорія та місто»: '
                . $catRatingName . ' ' . $placeLabel . ' порівнюються між собою, а не з іншими містами.',
        ],
        [
            'q' => 'Хто потрапляє в рейтинг?',
            'a' => 'Профіль має мати щонайменше ' . $minReviews . ' відгуків і оцінку від 4.0. Якщо ми складали досьє з відкритих джерел і знайшли тривожні сигнали, профіль у рейтинг не потрапляє — він лишається в каталозі.',
        ],
        [
            'q' => 'Чи можна купити місце в рейтингу?',
            'a' => 'Ні. Платна підписка PRO у формулу не входить жодним чином. Єдиний спосіб піднятися — реальні відгуки клієнтів, свіжі оцінки й підтверджений профіль.',
        ],
        [
            'q' => 'Як часто оновлюється рейтинг?',
            'a' => 'Раз на місяць. Ми фіксуємо зріз на початку місяця, і він не змінюється до наступного — тому сторінку можна цитувати з датою. Різниця між зрізами показана в розділі «Що змінилось».',
        ],
    ];

    $topSchemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'ТОП-' . $topCount . ' ' . $catRatingName . ' ' . $placeLabel . ' — ' . $periodLabel,
            'description' => $pageDescription,
            'datePublished' => $period . '-01',
            'dateModified' => optional($updatedAt)->toDateString(),
            'inLanguage' => 'uk-UA',
            'isAccessibleForFree' => true,
            'author' => ['@type' => 'Organization', 'name' => 'DOVIRA', 'url' => route('home')],
            'publisher' => ['@type' => 'Organization', 'name' => 'DOVIRA', 'url' => route('home')],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $selfUrl],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'ТОП-' . $topCount . ' ' . $catRatingName . ' ' . $placeLabel . ' — ' . $periodLabel,
            'numberOfItems' => $topCount,
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
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
@endphp

@section('title', $pageTitle)
@section('description', $pageDescription)
@section('canonical', $canonical)
@section('body_class', 'page-top-rating page-top-article')
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
    $starsHtml = function (float $rating) use ($starFill): string {
        return '<span class="stars" role="img" aria-label="' . number_format($rating, 1) . ' з 5">'
            . '<span class="stars__bg">★★★★★</span>'
            . '<span class="stars__fg" style="width:' . $starFill($rating) . '">★★★★★</span>'
            . '</span>';
    };
@endphp

@section('content')
<article class="tp-article">
    <div class="wrap tp-article__wrap">

        <nav class="tp-crumbs" aria-label="Хлібні крихти">
            <a href="{{ route('home') }}">Головна</a>
            <span aria-hidden="true">›</span>
            <a href="{{ route('top.index') }}">Рейтинги</a>
            <span aria-hidden="true">›</span>
            <a href="{{ route('top.rating', ['category' => $categorySlug]) }}">{{ $catName }}</a>
            @if ($cityName)
                <span aria-hidden="true">›</span>
                <span aria-current="page">{{ $cityName }}</span>
            @endif
        </nav>

        {{-- ── Шапка статті ── --}}
        <header class="tp-head">
            <p class="tp-head__eyebrow">
                <i class="fa-solid fa-ranking-star" aria-hidden="true"></i>
                Рейтинг DOVIRA · {{ $periodLabel }}
            </p>
            <h1 class="tp-head__title">
                ТОП-{{ $topCount }} {{ $catRatingName }} {{ $placeLabel }} — {{ $periodLabel }}
            </h1>
            <p class="tp-head__byline">
                <span><i class="fa-regular fa-calendar" aria-hidden="true"></i> Зріз за {{ $periodLabel }}</span>
                <span><i class="fa-solid fa-database" aria-hidden="true"></i> {{ $fmt($totalReviews) }} {{ \App\Support\Plural::uk($totalReviews, 'відгук', 'відгуки', 'відгуків') }} із {{ $fmt($totalProfiles) }} {{ \App\Support\Plural::uk($totalProfiles, 'профілю', 'профілів', 'профілів') }}</span>
                <span><i class="fa-solid fa-lock" aria-hidden="true"></i> Місця не продаються</span>
            </p>

            <figure class="tp-cover tp-cover--{{ ['blue','violet','green','amber'][crc32($categorySlug) % 4] }}">
                @if (! empty($coverUrl))
                    <img src="{{ $coverUrl }}" alt="{{ $catRatingName }} {{ $placeLabel }}" loading="eager" decoding="async">
                @else
                    <i class="{{ $coverIcon ?? 'fa-solid fa-ranking-star' }}" aria-hidden="true"></i>
                @endif
            </figure>

            <div class="tp-lead">
                <p>
                    Це рейтинг {{ $catRatingName }} {{ $placeLabel }} станом на {{ $periodLabel }}.
                    Він побудований не на редакційній думці, а на даних: у розрахунок увійшло
                    {{ $fmt($totalReviews) }} {{ \App\Support\Plural::uk($totalReviews, 'відгук', 'відгуки', 'відгуків') }}
                    про {{ $fmt($totalProfiles) }} {{ \App\Support\Plural::uk($totalProfiles, 'профіль', 'профілі', 'профілів') }}.
                    До списку потрапляють лише ті, хто має щонайменше {{ $minReviews }} відгуків і оцінку від 4.0.
                </p>
                <p>
                    Місце визначає індекс DOVIRA — він враховує не тільки середню оцінку, а й те,
                    скільки відгуків зібрано, чи зʼявляються нові, наскільки профіль затребуваний
                    і що ми змогли перевірити про виконавця. Розклад індексу показаний під кожним
                    учасником, тож будь-яку позицію можна перевірити.
                </p>
            </div>
        </header>

        {{-- ── Що змінилось ── --}}
        @if (!empty($changesParagraph))
            <section class="tp-changes" aria-labelledby="tp-changes-title">
                <h2 class="tp-h2" id="tp-changes-title">Що змінилось за {{ $periodLabel }}</h2>
                <p>{{ $changesParagraph }}</p>
            </section>
        @endif

        {{-- ── Методологія ── --}}
        <section class="tp-method" aria-labelledby="tp-method-title">
            <h2 class="tp-h2" id="tp-method-title">Як ми рахували</h2>
            <p class="tp-method__intro">
                Індекс складається з шести вимірів. Кожен рахується всередині пари «{{ mb_strtolower($catName) }} + {{ $cityName ?: 'Україна' }}»,
                тобто учасники порівнюються між собою, а не з іншими містами.
            </p>
            <ul class="tp-method__list">
                @foreach ($weights as $key => $weight)
                    @php $label = $weightLabels[$key] ?? [$key, '']; @endphp
                    <li class="tp-method__item">
                        <div class="tp-method__bar" aria-hidden="true">
                            <span style="width: {{ round($weight * 100) }}%"></span>
                        </div>
                        <div class="tp-method__text">
                            <strong>{{ $label[0] }} — {{ round($weight * 100) }}%</strong>
                            <span>{{ $label[1] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="tp-method__note">
                PRO-підписка у формулу не входить. Профілі, у яких наше досьє виявило тривожні
                сигнали, до рейтингу не допускаються.
            </p>
        </section>

        {{-- ── Рейтинг ── --}}
        <section class="tp-list" aria-labelledby="tp-list-title">
            <h2 class="tp-h2" id="tp-list-title">Рейтинг {{ $catRatingName }} {{ $placeLabel }}: місця з 1 по {{ $topCount }}</h2>

            @if ($topCount === 0)
                <p class="tp-empty">Для цієї комбінації поки замало даних, щоб скласти чесний рейтинг.</p>
            @endif

            @foreach ($rows as $row)
                <article class="tp-entry" id="place-{{ $row['rank'] }}">
                    {{-- Шапка запису: номер, аватарка, назва й оцінка в одному
                         рядку. На мобільному все лишається зверху, а текст іде
                         під ним — раніше аватарка провалювалась у кінець картки. --}}
                    <header class="tp-entry__head">
                        <span class="tp-entry__rank" aria-label="Місце {{ $row['rank'] }}">{{ $row['rank'] }}</span>

                        <a class="tp-entry__logo" href="{{ $row['url'] }}" tabindex="-1" aria-hidden="true">
                            @if ($row['logo_url'])
                                <img src="{{ $row['logo_url'] }}" alt="" loading="lazy" decoding="async" width="56" height="56">
                            @else
                                <span>{{ $row['initials'] }}</span>
                            @endif
                        </a>

                        <span class="tp-entry__headings">
                            <h3 class="tp-entry__name">
                                <a href="{{ $row['url'] }}">{{ $row['name'] }}</a>
                                @if ($row['verified'])
                                    <span class="tp-entry__badge" title="Власник підтвердив профіль"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                                @endif
                            </h3>
                            <span class="tp-entry__facts">{{ $row['facts'] }}</span>
                        </span>

                        @if ($row['rating_visible'])
                            <span class="tp-entry__rating">
                                <strong>{{ number_format($row['rating'], 2, ',', ' ') }}</strong>
                                {!! $starsHtml((float) $row['rating']) !!}
                            </span>
                        @endif
                    </header>

                    <div class="tp-entry__body">
                        @if (!empty($row['description']))
                            <p class="tp-entry__text">{{ $row['description'] }}</p>
                        @endif

                        @if (!empty($row['topics']))
                            <ul class="tp-topics">
                                @foreach ($row['topics'] as $topic)
                                    <li class="tp-topic tp-topic--{{ $topic['sentiment'] }}">
                                        <span class="tp-topic__name">{{ $topic['topic'] }}</span>
                                        @if (!empty($topic['note']))
                                            <span class="tp-topic__note">{{ $topic['note'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <details class="tp-entry__index">
                            <summary>Індекс {{ number_format($row['index'], 1, ',', ' ') }} — з чого складається</summary>
                            <ul>
                                @foreach ($weights as $key => $weight)
                                    @php
                                        $value = (float) ($row['components'][$key] ?? 0);
                                        $label = $weightLabels[$key] ?? [$key, ''];
                                    @endphp
                                    <li>
                                        <span class="tp-entry__index-label">{{ $label[0] }}</span>
                                        <span class="tp-entry__index-bar" aria-hidden="true"><span style="width: {{ round(min(1, max(0, $value)) * 100) }}%"></span></span>
                                        <span class="tp-entry__index-value">{{ number_format($value, 2, ',', ' ') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>

                        <a class="tp-entry__more" href="{{ $row['url'] }}">Профіль і всі відгуки <span aria-hidden="true">→</span></a>
                    </div>
                </article>
            @endforeach
        </section>

        {{-- ── FAQ ── --}}
        <section class="tp-faq" aria-labelledby="tp-faq-title">
            <h2 class="tp-h2" id="tp-faq-title">Часті питання про рейтинг</h2>
            @foreach ($topFaq as $item)
                <details class="tp-faq__item">
                    <summary>{{ $item['q'] }}</summary>
                    <p>{{ $item['a'] }}</p>
                </details>
            @endforeach
        </section>

        {{-- ── Перелінковка ── --}}
        <section class="tp-more" aria-labelledby="tp-more-title">
            <h2 class="tp-h2" id="tp-more-title">Дивіться також</h2>
            <p class="tp-more__back">
                <a href="{{ route('top.index') }}"><span aria-hidden="true">←</span> Усі рейтинги DOVIRA</a>
            </p>
            <p class="tp-more__catalog">
                <a href="{{ $catalogUrl }}">Усі {{ $fmt(max($totalProfiles, $topCount)) }} {{ $catRatingName }} {{ $placeLabel }} у каталозі <span aria-hidden="true">→</span></a>
            </p>
            @if (!empty($cityLinks))
                <div class="tp-more__cities">
                    @if ($citySlug)
                        <a class="tp-more__chip" href="{{ route('top.rating', ['category' => $categorySlug]) }}">По всій Україні</a>
                    @endif
                    @foreach ($cityLinks as $link)
                        <a class="tp-more__chip" href="{{ $link['url'] }}">{{ $link['name'] }}</a>
                    @endforeach
                </div>
            @endif
        </section>

    </div>
</article>
@endsection
