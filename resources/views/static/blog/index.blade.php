@extends('static.layout')

@section('title', 'Блог DOVIRA — про відгуки, довіру та репутацію бізнесу')
@section('description', 'Практичні статті про те, як перевіряти компанії, писати корисні відгуки та керувати репутацією бізнесу онлайн. Поради для покупців і власників бізнесу.')
@section('canonical', route('blog'))
@section('body_class', 'page-blog')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/blog.css') }}?v={{ @filemtime(public_path('static/css/pages/blog.css')) }}">
    @php
        $blogListSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            '@id' => route('blog') . '#blog',
            'name' => 'Блог DOVIRA',
            'url' => route('blog'),
            'description' => 'Практичні статті про відгуки, довіру та онлайн-репутацію бізнесу.',
            'inLanguage' => 'uk',
            'publisher' => ['@id' => url('/') . '#organization'],
            'blogPost' => collect($allPosts)->map(fn ($item) => [
                '@type' => 'BlogPosting',
                'headline' => $item['title'],
                'url' => route('blog.show', ['slug' => $item['slug']]),
                'datePublished' => $item['published_at'],
                'dateModified' => $item['updated_at'],
                'description' => $item['description'],
            ])->values()->all(),
        ];

        $blogBreadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Блог', 'item' => route('blog')],
            ],
        ];
    @endphp
    <script type="application/ld+json">@json($blogListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    <script type="application/ld+json">@json($blogBreadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
@endpush

@php
    $formatBlogDate = function (string $date): string {
        return \Illuminate\Support\Carbon::parse($date)->translatedFormat('j F Y');
    };
@endphp

@section('content')
<section class="section blog-page" aria-labelledby="blog-title">
    <div class="container">

        <header class="blog-hero">
            <p class="blog-hero__eyebrow">Блог DOVIRA</p>
            <h1 class="blog-hero__title" id="blog-title">Про відгуки, довіру<br>та репутацію бізнесу</h1>
            <p class="blog-hero__lead">
                Практичні поради для покупців і власників бізнесу: як перевіряти компанії,
                писати корисні відгуки та будувати репутацію, якій довіряють.
            </p>

            <div class="blog-filters" role="group" aria-label="Фільтр за категорією">
                <button type="button" class="blog-filters__chip is-active" data-blog-filter="all">Усі статті</button>
                @foreach ($categories as $categorySlug => $categoryLabel)
                    <button type="button" class="blog-filters__chip" data-blog-filter="{{ $categorySlug }}">{{ $categoryLabel }}</button>
                @endforeach
            </div>
        </header>

        @if ($featuredPost)
            <a
                class="blog-featured"
                href="{{ route('blog.show', ['slug' => $featuredPost['slug']]) }}"
                data-blog-card
                data-blog-category="{{ $featuredPost['category_slug'] }}"
            >
                <div class="blog-featured__cover blog-cover blog-cover--{{ $featuredPost['accent'] }}" aria-hidden="true">
                    <i class="{{ $featuredPost['icon'] }}"></i>
                </div>
                <div class="blog-featured__body">
                    <div class="blog-card__meta">
                        <span class="blog-card__chip">{{ $featuredPost['category'] }}</span>
                        <span class="blog-card__dot" aria-hidden="true"></span>
                        <time datetime="{{ $featuredPost['published_at'] }}">{{ $formatBlogDate($featuredPost['published_at']) }}</time>
                        <span class="blog-card__dot" aria-hidden="true"></span>
                        <span>{{ $featuredPost['reading_minutes'] }} хв читання</span>
                    </div>
                    <h2 class="blog-featured__title">{{ $featuredPost['title'] }}</h2>
                    <p class="blog-featured__excerpt">{{ $featuredPost['excerpt'] }}</p>
                    <span class="blog-featured__more">
                        <span>Читати статтю</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </span>
                </div>
            </a>
        @endif

        <div class="blog-grid">
            @foreach ($posts as $post)
                <a
                    class="blog-card"
                    href="{{ route('blog.show', ['slug' => $post['slug']]) }}"
                    data-blog-card
                    data-blog-category="{{ $post['category_slug'] }}"
                >
                    <div class="blog-card__cover blog-cover blog-cover--{{ $post['accent'] }}" aria-hidden="true">
                        <i class="{{ $post['icon'] }}"></i>
                    </div>
                    <div class="blog-card__body">
                        <div class="blog-card__meta">
                            <span class="blog-card__chip">{{ $post['category'] }}</span>
                            <span class="blog-card__dot" aria-hidden="true"></span>
                            <time datetime="{{ $post['published_at'] }}">{{ $formatBlogDate($post['published_at']) }}</time>
                        </div>
                        <h2 class="blog-card__title">{{ $post['title'] }}</h2>
                        <p class="blog-card__excerpt">{{ $post['excerpt'] }}</p>
                        <div class="blog-card__foot">
                            <span class="blog-card__read">{{ $post['reading_minutes'] }} хв читання</span>
                            <span class="blog-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <p class="blog-empty" data-blog-empty hidden>У цій категорії поки немає статей.</p>

        <aside class="blog-cta">
            <div class="blog-cta__copy">
                <h2 class="blog-cta__title">Маєте свій бізнес?</h2>
                <p class="blog-cta__text">
                    Створіть профіль компанії на DOVIRA — збирайте відгуки, відповідайте клієнтам
                    і будуйте репутацію, яка приводить нових замовників.
                </p>
            </div>
            <div class="blog-cta__actions">
                <a class="btn btn--primary" href="{{ route('pro') }}">
                    <span>Дізнатися про PRO</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </aside>

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
