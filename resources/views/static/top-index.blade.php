@extends('static.layout')

@php
    /**
     * Хаб рейтингів у вигляді блогу: кожен місячний зріз — стаття
     * «ТОП-N {категорія} {місто} — {місяць}». Верстка навмисно та сама, що в
     * /blog (класи blog-*), щоб розділи виглядали як один продукт, а не як
     * дві різні сторінки.
     */
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');
    $articles = $articles ?? [];
    $latestPeriodLabel = $articles[0]['period_label'] ?? null;

    $featuredArticle = $articles[0] ?? null;
    $restArticles = array_slice($articles, 1);

    // Чипи-фільтри: категорії, за якими є опубліковані огляди.
    $articleCategories = [];
    foreach ($articles as $article) {
        $articleCategories[$article['category_slug']] = $article['category_name'];
    }

    $pageTitle = 'Рейтинги DOVIRA — ' . ($latestPeriodLabel ?: $year) . ': ТОП компаній і спеціалістів України | DOVIRA';
    $pageDescription = 'Щомісячні огляди-рейтинги компаній і спеціалістів України за реальними відгуками: '
        . count($articles) . ' публікацій за категоріями та містами. Індекс враховує оцінку, обсяг і свіжість відгуків, попит і перевірку профілю.';

    $topSchemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            'name' => 'Рейтинги DOVIRA',
            'description' => $pageDescription,
            'url' => route('top.index'),
            'inLanguage' => 'uk',
            'publisher' => ['@id' => url('/') . '#organization'],
            'blogPost' => collect($articles)->take(30)->map(fn (array $a) => [
                '@type' => 'BlogPosting',
                'headline' => $a['title'] . ' — ' . $a['period_label'],
                'url' => $a['url'],
                'datePublished' => $a['period'] . '-01',
            ])->all(),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Рейтинги', 'item' => route('top.index')],
            ],
        ],
    ];
@endphp

@section('title', $pageTitle)
@section('description', $pageDescription)
@section('canonical', route('top.index'))
@section('body_class', 'page-blog page-top-index')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/blog.css') }}?v={{ @filemtime(public_path('static/css/pages/blog.css')) }}">
    @foreach ($topSchemas as $topSchema)
        <script type="application/ld+json">@json($topSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
@endpush

@section('content')
<section class="section blog-page" aria-labelledby="top-index-title">
    <div class="container">

        <header class="blog-hero">
            <p class="blog-hero__eyebrow">{{ $latestPeriodLabel ? 'Рейтинги · ' . $latestPeriodLabel : 'Рейтинги DOVIRA' }}</p>
            <h1 class="blog-hero__title" id="top-index-title">Рейтинги компаній і спеціалістів України</h1>
            <p class="blog-hero__lead">
                Щомісяця фіксуємо зріз по кожній категорії та місту. Місце визначає індекс DOVIRA:
                оцінка й розподіл відгуків, їхній обсяг і свіжість, попит на профіль і перевірка.
                Місця не продаються — підписка у формулу не входить.
            </p>

            @if (! empty($articleCategories))
                <div class="blog-filters" role="group" aria-label="Фільтр за категорією">
                    <button type="button" class="blog-filters__chip is-active" data-blog-filter="all">Усі огляди</button>
                    @foreach ($articleCategories as $slug => $label)
                        <button type="button" class="blog-filters__chip" data-blog-filter="{{ $slug }}">{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        </header>

        @if (empty($articles))
            {{-- Зрізів ще немає (перший деплой або команда ще не ганялась) —
                 показуємо категорії, щоб хаб не був порожнім. --}}
            <div class="blog-grid">
                @foreach ($groups as $group)
                    @foreach ($group['items'] as $item)
                        <div class="blog-card top-fallback-card">
                            <a class="top-fallback-card__link" href="{{ $item['url'] }}">
                                <div class="blog-card__cover blog-cover blog-cover--blue" aria-hidden="true">
                                    <i class="{{ $item['icon_class'] ?? 'fa-solid fa-ranking-star' }}"></i>
                                </div>
                                <div class="blog-card__body">
                                    <div class="blog-card__meta">
                                        <span class="blog-card__chip">{{ $group['name'] }}</span>
                                    </div>
                                    <h2 class="blog-card__title">{{ $item['title'] ?? ('Топ ' . mb_strtolower($item['name'])) }}</h2>
                                    <p class="blog-card__excerpt">
                                        {{ $fmt($item['profiles_count']) }} {{ \App\Support\Plural::uk($item['profiles_count'], 'профіль', 'профілі', 'профілів') }},
                                        {{ $fmt($item['reviews_count']) }} {{ \App\Support\Plural::uk($item['reviews_count'], 'відгук', 'відгуки', 'відгуків') }}.
                                    </p>
                                    <div class="blog-card__foot">
                                        <span class="blog-card__read">Відкрити рейтинг</span>
                                        <span class="blog-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                                    </div>
                                </div>
                            </a>
                            @if (! empty($item['city_links']))
                                <div class="top-fallback-card__cities" aria-label="{{ $item['name'] }} за містами">
                                    @foreach ($item['city_links'] as $cityLink)
                                        <a href="{{ $cityLink['url'] }}">{{ $cityLink['name'] }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        @else

            @if ($featuredArticle)
                <a
                    class="blog-featured"
                    href="{{ $featuredArticle['url'] }}"
                    data-blog-card
                    data-blog-category="{{ $featuredArticle['category_slug'] }}"
                >
                    <div class="blog-featured__cover blog-cover blog-cover--{{ $featuredArticle['accent'] }}" aria-hidden="true">
                        @include('static.partials.top-cover', ['avatars' => $featuredArticle['avatars'], 'icon' => $featuredArticle['icon_class']])
                    </div>
                    <div class="blog-featured__body">
                        <div class="blog-card__meta">
                            <span class="blog-card__chip">{{ $featuredArticle['category_name'] }}</span>
                            <span class="blog-card__dot" aria-hidden="true"></span>
                            <time datetime="{{ $featuredArticle['period'] }}-01">{{ $featuredArticle['period_label'] }}</time>
                            <span class="blog-card__dot" aria-hidden="true"></span>
                            <span>{{ $featuredArticle['places'] }} {{ \App\Support\Plural::uk($featuredArticle['places'], 'позиція', 'позиції', 'позицій') }}</span>
                        </div>
                        <h2 class="blog-featured__title">{{ $featuredArticle['title'] }} — {{ $featuredArticle['period_label'] }}</h2>
                        <p class="blog-featured__excerpt">
                            @if (! empty($featuredArticle['leader']))
                                Перше місце — {{ $featuredArticle['leader'] }} з оцінкою {{ number_format($featuredArticle['leader_rating'], 2, ',', ' ') }}.
                            @endif
                            У розрахунок увійшло {{ $fmt($featuredArticle['reviews_total']) }} {{ \App\Support\Plural::uk($featuredArticle['reviews_total'], 'відгук', 'відгуки', 'відгуків') }}.
                        </p>
                        <span class="blog-featured__more">
                            <span>Читати огляд</span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </span>
                    </div>
                </a>
            @endif

            <div class="blog-grid">
                @foreach ($restArticles as $article)
                    <a
                        class="blog-card"
                        href="{{ $article['url'] }}"
                        data-blog-card
                        data-blog-category="{{ $article['category_slug'] }}"
                    >
                        <div class="blog-card__cover blog-cover blog-cover--{{ $article['accent'] }}" aria-hidden="true">
                            @include('static.partials.top-cover', ['avatars' => $article['avatars'], 'icon' => $article['icon_class']])
                        </div>
                        <div class="blog-card__body">
                            <div class="blog-card__meta">
                                <span class="blog-card__chip">{{ $article['city_name'] ?: 'Україна' }}</span>
                                <span class="blog-card__dot" aria-hidden="true"></span>
                                <time datetime="{{ $article['period'] }}-01">{{ $article['period_label'] }}</time>
                            </div>
                            <h2 class="blog-card__title">{{ $article['title'] }} — {{ $article['period_label'] }}</h2>
                            <p class="blog-card__excerpt">
                                @if (! empty($article['leader']))
                                    Перше місце — {{ $article['leader'] }} з оцінкою {{ number_format($article['leader_rating'], 2, ',', ' ') }}.
                                @endif
                            </p>
                            <div class="blog-card__foot">
                                <span class="blog-card__read">{{ $article['places'] }} {{ \App\Support\Plural::uk($article['places'], 'позиція', 'позиції', 'позицій') }} · {{ $fmt($article['reviews_total']) }} {{ \App\Support\Plural::uk($article['reviews_total'], 'відгук', 'відгуки', 'відгуків') }}</span>
                                <span class="blog-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <p class="blog-empty" data-blog-empty hidden>За цією категорією оглядів поки немає.</p>

        @endif

    </div>
</section>

<script>
    (() => {
        const chips = document.querySelectorAll('[data-blog-filter]');
        const cards = document.querySelectorAll('[data-blog-card]');
        const empty = document.querySelector('[data-blog-empty]');

        chips.forEach((chip) => {
            chip.addEventListener('click', () => {
                const filter = chip.dataset.blogFilter;

                chips.forEach((item) => item.classList.toggle('is-active', item === chip));

                let visible = 0;
                cards.forEach((card) => {
                    const show = filter === 'all' || card.dataset.blogCategory === filter;
                    card.hidden = !show;
                    if (show) visible++;
                });

                if (empty) empty.hidden = visible > 0;
            });
        });
    })();
</script>
@endsection
