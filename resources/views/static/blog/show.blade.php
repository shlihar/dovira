@extends('static.layout')

@section('title', $post['seo_title'])
@section('description', $post['description'])
@section('canonical', route('blog.show', ['slug' => $post['slug']]))
@section('og_type', 'article')
@section('body_class', 'page-blog page-blog-post')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/blog.css') }}?v={{ @filemtime(public_path('static/css/pages/blog.css')) }}">
    @php
        $postUrl = route('blog.show', ['slug' => $post['slug']]);

        $articleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            '@id' => $postUrl . '#article',
            'headline' => $post['title'],
            'description' => $post['description'],
            'url' => $postUrl,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $postUrl],
            'datePublished' => $post['published_at'],
            'dateModified' => $post['updated_at'],
            'inLanguage' => 'uk',
            'articleSection' => $post['category'],
            'author' => ['@id' => url('/') . '#organization'],
            'publisher' => ['@id' => url('/') . '#organization'],
            'image' => asset('static/assets/dovira_banner_transparent_full_head.png'),
        ];

        $postBreadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Блог', 'item' => route('blog')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $post['title'], 'item' => $postUrl],
            ],
        ];
    @endphp
    <script type="application/ld+json">@json($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    <script type="application/ld+json">@json($postBreadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    <meta property="article:published_time" content="{{ $post['published_at'] }}">
    <meta property="article:modified_time" content="{{ $post['updated_at'] }}">
    <meta property="article:section" content="{{ $post['category'] }}">
@endpush

@php
    $formatBlogDate = function (string $date): string {
        return \Illuminate\Support\Carbon::parse($date)->translatedFormat('j F Y');
    };
@endphp

@section('content')
<article class="section blog-post">
    <div class="container">

        <nav class="blog-post__breadcrumbs" aria-label="Хлібні крихти">
            <a href="{{ route('home') }}">Головна</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <a href="{{ route('blog') }}">Блог</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">{{ $post['category'] }}</span>
        </nav>

        <header class="blog-post__head">
            <div class="blog-card__meta blog-post__meta">
                <span class="blog-card__chip">{{ $post['category'] }}</span>
                <span class="blog-card__dot" aria-hidden="true"></span>
                <time datetime="{{ $post['published_at'] }}">{{ $formatBlogDate($post['published_at']) }}</time>
                <span class="blog-card__dot" aria-hidden="true"></span>
                <span>{{ $post['reading_minutes'] }} хв читання</span>
            </div>
            <h1 class="blog-post__title">{{ $post['title'] }}</h1>
            <p class="blog-post__lead">{{ $post['description'] }}</p>
        </header>

        <div class="blog-post__cover blog-cover blog-cover--{{ $post['accent'] }}" aria-hidden="true">
            <i class="{{ $post['icon'] }}"></i>
        </div>

        <div class="blog-post__content">
            @include('static.blog.posts.' . $post['slug'])
        </div>

        <footer class="blog-post__foot">
            <div class="blog-post__author">
                <span class="blog-post__author-logo" aria-hidden="true">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
                <div>
                    <strong>Команда DOVIRA</strong>
                    <p>Пишемо про довіру, відгуки та репутацію — на основі досвіду платформи.</p>
                </div>
            </div>

            <aside class="blog-cta blog-cta--post">
                <div class="blog-cta__copy">
                    <h2 class="blog-cta__title">Маєте свій бізнес?</h2>
                    <p class="blog-cta__text">
                        Створіть профіль компанії на DOVIRA безкоштовно — збирайте відгуки
                        і відповідайте клієнтам від імені бізнесу.
                    </p>
                </div>
                <div class="blog-cta__actions">
                    <a class="btn btn--primary" href="{{ route('pro') }}">
                        <span>Дізнатися про PRO</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </aside>
        </footer>

        @if (!empty($relatedPosts))
            <section class="blog-related" aria-labelledby="blog-related-title">
                <h2 class="blog-related__title" id="blog-related-title">Читайте також</h2>
                <div class="blog-grid blog-grid--related">
                    @foreach ($relatedPosts as $related)
                        <a class="blog-card" href="{{ route('blog.show', ['slug' => $related['slug']]) }}">
                            <div class="blog-card__cover blog-cover blog-cover--{{ $related['accent'] }}" aria-hidden="true">
                                <i class="{{ $related['icon'] }}"></i>
                            </div>
                            <div class="blog-card__body">
                                <div class="blog-card__meta">
                                    <span class="blog-card__chip">{{ $related['category'] }}</span>
                                    <span class="blog-card__dot" aria-hidden="true"></span>
                                    <time datetime="{{ $related['published_at'] }}">{{ $formatBlogDate($related['published_at']) }}</time>
                                </div>
                                <h3 class="blog-card__title">{{ $related['title'] }}</h3>
                                <p class="blog-card__excerpt">{{ $related['excerpt'] }}</p>
                                <div class="blog-card__foot">
                                    <span class="blog-card__read">{{ $related['reading_minutes'] }} хв читання</span>
                                    <span class="blog-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

    </div>
</article>
@endsection
