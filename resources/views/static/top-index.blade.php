@extends('static.layout')

@php
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');
    $pageTitle = 'Рейтинги компаній і спеціалістів України ' . $year . ' — ТОП за відгуками | DOVIRA';
    $pageDescription = 'Усі топ-рейтинги DOVIRA за категоріями: компанії та спеціалісти України за реальними відгуками клієнтів. Оновлюється автоматично.';
    $topSchemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => 'Рейтинги DOVIRA',
            'description' => $pageDescription,
            'url' => route('top.index'),
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
@section('body_class', 'page-top-rating page-top-index')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/top-rating.css') }}?v={{ @filemtime(public_path('static/css/pages/top-rating.css')) }}">
    @foreach ($topSchemas as $topSchema)
        <script type="application/ld+json">@json($topSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
@endpush

@section('content')
    <section class="top-hero top-hero--index" aria-labelledby="top-index-title">
        <div class="top-hero__glow" aria-hidden="true"></div>
        <div class="container top-hero__inner">
            <nav class="top-hero__crumbs" aria-label="Хлібні крихти">
                <a href="{{ route('home') }}">Головна</a>
                <span aria-hidden="true">/</span>
                <span>Рейтинги</span>
            </nav>

            <span class="top-hero__eyebrow"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Рейтинги {{ $year }}</span>

            <h1 class="top-hero__title" id="top-index-title">Рейтинги компаній і спеціалістів України</h1>

            <p class="top-hero__lead">
                @if ($totalRatings > 0)
                    <strong>{{ $fmt($totalRatings) }}</strong> рейтингів за категоріями на основі профілів з реальними відгуками.
                @else
                    Рейтинги за категоріями зʼявляться, коли в каталозі буде достатньо профілів з відгуками.
                @endif
            </p>

            <div class="top-hero__badges">
                <span class="top-hero__pill"><i class="fa-solid fa-lock" aria-hidden="true"></i> Позиції не продаються</span>
                <span class="top-hero__pill"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Зважений рейтинг</span>
                <span class="top-hero__pill"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Оновлено {{ $updatedAt->translatedFormat('d.m.Y') }}</span>
            </div>
        </div>
    </section>

    <section class="section top-index-list" aria-labelledby="top-index-list-title">
        <div class="container">
            <div class="top-head">
                <div class="top-head__text">
                    <h2 class="top-head__title" id="top-index-list-title">Усі рейтинги</h2>
                    <p class="top-head__sub">Публікуємо тільки категорії, де є достатньо профілів з відгуками для чесного порівняння.</p>
                </div>
                <a class="top-head__all" href="{{ route('catalog') }}">Відкрити каталог <span aria-hidden="true">→</span></a>
            </div>

            @if (empty($groups))
                <div class="top-empty">
                    <i class="fa-solid fa-ranking-star" aria-hidden="true"></i>
                    <p>Поки немає категорій з достатньою кількістю профілів з відгуками.</p>
                    <a class="btn btn--primary" href="{{ route('catalog') }}">Відкрити каталог</a>
                </div>
            @else
                <div class="top-index-groups">
                    @foreach ($groups as $group)
                        <section class="top-index-group" aria-labelledby="top-index-group-{{ $loop->index }}">
                            <h3 class="top-index-group__title" id="top-index-group-{{ $loop->index }}">{{ $group['name'] }}</h3>
                            <div class="top-index-grid">
                                @foreach ($group['items'] as $item)
                                    <article class="top-index-card">
                                        <a class="top-index-card__main" href="{{ $item['url'] }}">
                                            <span class="top-index-card__icon"><i class="{{ $item['icon_class'] ?? 'fa-solid fa-ranking-star' }}" aria-hidden="true"></i></span>
                                            <span class="top-index-card__body">
                                                <strong>{{ $item['title'] ?? ('Топ ' . mb_strtolower($item['name'])) }}</strong>
                                                <span>{{ $fmt($item['profiles_count']) }} профілів · {{ $fmt($item['reviews_count']) }} відгуків</span>
                                            </span>
                                            <i class="fa-solid fa-arrow-right top-index-card__go" aria-hidden="true"></i>
                                        </a>
                                        @if (! empty($item['city_links']))
                                            <div class="top-index-card__cities" aria-label="{{ $item['name'] }} за містами">
                                                @foreach ($item['city_links'] as $cityLink)
                                                    <a href="{{ $cityLink['url'] }}">{{ $cityLink['name'] }}</a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
