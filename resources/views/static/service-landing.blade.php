@extends('static.layout')

@php
    /**
     * Посадкова «{послуга} {у місті} — відгуки». Дані — ServiceLandingSeo::build:
     * профілі зі згадками послуги у відгуках + цитати саме цих відгуків.
     */
    $cityLocative = $cityName !== null
        ? (\App\Support\RegionCityDirectory::cityLocative($cityName) ?? ('у місті ' . $cityName))
        : 'в Україні';
    $cityUrl = $citySlug !== null ? route('catalog.landing.city', ['category' => $category->slug, 'city' => $citySlug]) : route('catalog.landing', ['category' => $category->slug]);
    $cityLocativeUc = \Illuminate\Support\Str::ucfirst($cityLocative);
    $num = fn (int $n): string => number_format($n, 0, '.', ' ');
    $profilesCount = (int) $seo['profiles_count'];
    $reviewsCount = (int) $seo['reviews_count'];
    $avg = (float) $seo['avg_rating'];
    $catLower = mb_strtolower((string) $category->name);
    $audience = (string) config('service_landings.audience.' . $category->slug, 'клієнтів');
    $h1Format = (string) config('service_landings.h1.' . $category->slug, '{service} {city} — відгуки ' . $audience);
    $h1 = strtr($h1Format, ['{service}' => $service['name'], '{city}' => $cityLocative]);

    $pageTitle = $indexable
        ? $h1 . ' ' . date('Y') . ': ' . $num($profilesCount) . ' ' . \App\Support\Plural::uk($profilesCount, 'фахівець', 'фахівці', 'фахівців') . ', ' . $num($reviewsCount) . ' ' . \App\Support\Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків') . ' | DOVIRA'
        : $h1 . ' | DOVIRA';
    $pageDescription = $indexable
        ? '⭐ ' . $service['name'] . ' ' . $cityLocative . ': ' . $num($profilesCount) . ' ' . \App\Support\Plural::uk($profilesCount, 'фахівець', 'фахівці', 'фахівців') . ' категорії «' . $category->name . '» з реальними відгуками про ' . $service['about'] . ' — ' . $num($reviewsCount) . ' ' . \App\Support\Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків') . ($avg > 0 ? ', середня оцінка ' . number_format($avg, 1) : '') . '. Цитати клієнтів, рейтинги, контакти.'
        : 'Відгуки про ' . $service['about'] . ' ' . $cityLocative . ' на DOVIRA.';

    $faq = [];
    if ($indexable) {
        $faq[] = ['q' => 'Хто робить ' . $service['about'] . ' ' . $cityLocative . '?', 'a' => 'На DOVIRA ' . $cityLocative . ' — ' . $num($profilesCount) . ' ' . \App\Support\Plural::uk($profilesCount, 'фахівець', 'фахівці', 'фахівців') . ' категорії «' . $category->name . '», у чиїх відгуках клієнти згадують ' . $service['about'] . '. Список нижче впорядковано за рейтингом і кількістю таких відгуків.'];
        $faq[] = ['q' => 'Скільки відгуків про ' . $service['about'] . ' зібрано?', 'a' => $num($reviewsCount) . ' ' . \App\Support\Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків') . ' клієнтів' . ($avg > 0 ? ' із середньою оцінкою ' . number_format($avg, 1) . ' з 5' : '') . '. Це реальні відгуки з профілів на DOVIRA; ми не пишемо їх самі й не видаляємо негативні.'];
        $faq[] = ['q' => 'Як обрати фахівця для послуги «' . mb_strtolower($service['name']) . '»?', 'a' => 'Читайте не лише оцінку, а й тексти відгуків саме про цю послугу — вони є на сторінці кожного профілю. Зверніть увагу на кількість відгуків, відповіді фахівця на критику та позначку «Перевірений акаунт».'];
    }
    $updated = ! empty($seo['updated_at']) ? \Illuminate\Support\Carbon::parse($seo['updated_at'])->format('d.m.Y') : null;
    $starPath = 'M8 .6l2.1 4.6 5 .5-3.8 3.4 1.1 5-4.4-2.6-4.4 2.6 1.1-5L.9 5.7l5-.5z';
@endphp

@section('title', $pageTitle)
@section('description', $pageDescription)
@section('canonical', $canonical)
@section('body_class', 'page-catalog page-service-landing')
@if (! $indexable)
    @section('robots', 'noindex, follow')
@endif

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/catalog.css') }}?v={{ @filemtime(public_path('static/css/pages/catalog.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}?v={{ @filemtime(public_path('static/css/profile-card-catalog.css')) }}">
    @if ($indexable)
        @php
            $schema = ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'BreadcrumbList', 'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Каталог', 'item' => route('catalog')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $category->name, 'item' => route('catalog.landing', ['category' => $category->slug])],
                    ...($cityName !== null ? [['@type' => 'ListItem', 'position' => 3, 'name' => $cityName, 'item' => $cityUrl]] : []),
                    ['@type' => 'ListItem', 'position' => $cityName !== null ? 4 : 3, 'name' => $service['name'], 'item' => $canonical],
                ]],
                ['@type' => 'CollectionPage', 'name' => $pageTitle, 'url' => $canonical, 'inLanguage' => 'uk',
                    'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $profilesCount, 'itemListElement' => $profiles->take(20)->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['name'], 'url' => route('profile.show', ['slug' => $p['slug']])])->all()],
                ],
                ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faq)],
            ]];
        @endphp
        <script type="application/ld+json">@json($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endif
@endpush

@section('content')
    <section class="section catalog-page">
        <div class="container">
            <div class="catalog-hero">
                <div class="catalog-hero__copy">
                    <nav class="catalog-hero__breadcrumbs" aria-label="Хлібні крихти">
                        <a href="{{ route('catalog') }}">Каталог</a>
                        <span aria-hidden="true">›</span>
                        <a href="{{ route('catalog.landing', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                        <span aria-hidden="true">›</span>
                        @if ($cityName !== null)
                            <a href="{{ $cityUrl }}">{{ $cityName }}</a>
                            <span aria-hidden="true">›</span>
                        @endif
                        <span aria-current="page">{{ $service['name'] }}</span>
                    </nav>
                    <h1>{{ $h1 }}</h1>
                    @if ($profilesCount > 0)
                        <p class="catalog-hero__lead">
                            {{ $cityLocativeUc }} на DOVIRA — {{ $num($profilesCount) }} {{ \App\Support\Plural::uk($profilesCount, 'фахівець', 'фахівці', 'фахівців') }}
                            ({{ $catLower }}), про яких клієнти залишили {{ $num($reviewsCount) }} {{ \App\Support\Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків') }}
                            саме про {{ $service['about'] }}@if ($avg > 0), середня оцінка — {{ number_format($avg, 1) }} з 5 @endif.
                            Нижче — цитати з цих відгуків і рейтинг фахівців.
                        </p>
                    @else
                        <p class="catalog-hero__lead">Відгуків про {{ $service['about'] }} {{ $cityLocative }} поки що немає. Подивіться <a href="{{ $cityUrl }}">усіх {{ $catLower }} {{ $cityLocative }}</a>.</p>
                    @endif
                    @if ($updated)
                        <p class="catalog-hero__updated">Дані оновлено {{ $updated }}</p>
                    @endif
                </div>
                @if ($profilesCount > 0)
                    <div class="catalog-hero__aside">
                        <div class="catalog-hero__stats" aria-label="Статистика">
                            <div><b>{{ $num($profilesCount) }}</b><span>{{ \App\Support\Plural::uk($profilesCount, 'фахівець', 'фахівці', 'фахівців') }}</span></div>
                            <div><b>{{ $num($reviewsCount) }}</b><span>{{ \App\Support\Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків') }}</span></div>
                            @if ($avg > 0)<div><b>{{ number_format($avg, 1) }}</b><span>середня оцінка</span></div>@endif
                        </div>
                    </div>
                @endif
            </div>

            @if (! empty($seo['excerpts']))
                <section class="service-quotes" aria-labelledby="service-quotes-title">
                    <h2 class="catalog-seo-links__title" id="service-quotes-title">Що кажуть {{ $audience === 'пацієнтів' ? 'пацієнти' : 'клієнти' }} про {{ $service['about'] }}</h2>
                    <div class="service-quotes__grid">
                        @foreach ($seo['excerpts'] as $ex)
                            <blockquote class="service-quote">
                                <div class="service-quote__top">
                                    <span class="service-quote__stars" aria-label="Оцінка {{ $ex['rating'] }} з 5">
                                        @for ($i = 1; $i <= 5; $i++)<svg viewBox="0 0 16 16" width="13" height="13" fill="{{ $i <= $ex['rating'] ? '#f5a623' : '#dbe3ee' }}" aria-hidden="true"><path d="{{ $starPath }}"/></svg>@endfor
                                    </span>
                                    <span class="service-quote__author">{{ $ex['author'] }}</span>
                                    @if ($ex['date'])<span class="service-quote__date">{{ \Illuminate\Support\Carbon::parse($ex['date'])->format('d.m.Y') }}</span>@endif
                                </div>
                                <p class="service-quote__text">{{ $ex['text'] }}</p>
                                <a class="service-quote__profile" href="{{ route('profile.show', ['slug' => $ex['profile_slug']]) }}#reviews">{{ $ex['profile_name'] }} →</a>
                            </blockquote>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($profiles->isNotEmpty())
                <section class="service-list" aria-labelledby="service-list-title">
                    <h2 class="catalog-seo-links__title" id="service-list-title">{{ $category->name }} {{ $cityLocative }}: {{ mb_strtolower($service['name']) }} — рейтинг за відгуками</h2>
                    @if ($profilesCount > $profiles->count())
                        <p class="service-list__note">Показано {{ $profiles->count() }} з {{ $profilesCount }} профілів із згадками послуги. Повний список — у розділі <a href="{{ $cityUrl }}">{{ $category->name }} {{ $cityLocative }}</a>.</p>
                    @endif
                    <div class="catalog-results__list">
                        @foreach ($profiles as $profile)
                            @include('static.partials.profile-card-catalog', ['profile' => $profile, 'profileUrl' => fn (string $slug) => route('profile.show', ['slug' => $slug])])
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($faq !== [])
                <section class="catalog-faq" aria-labelledby="service-faq-title">
                    <h2 class="catalog-seo-links__title" id="service-faq-title">Часті питання</h2>
                    @foreach ($faq as $item)
                        <details class="catalog-faq__item" @if ($loop->first) open @endif>
                            <summary class="catalog-faq__question">{{ $item['q'] }}</summary>
                            <p class="catalog-faq__answer">{{ $item['a'] }}</p>
                        </details>
                    @endforeach
                </section>
            @endif

            @if (! empty($seo['other_services']))
                <nav class="catalog-seo-links" aria-label="Інші послуги">
                    <h2 class="catalog-seo-links__title">Інші послуги: {{ $catLower }} {{ $cityLocative }}</h2>
                    <div class="catalog-seo-links__list">
                        @foreach ($seo['other_services'] as $other)
                            <a class="catalog-active-filter catalog-seo-links__item" href="{{ $citySlug !== null ? route('catalog.landing.service', ['category' => $category->slug, 'city' => $citySlug, 'service' => $other['slug']]) : route('catalog.landing.city', ['category' => $category->slug, 'city' => $other['slug']]) }}">{{ $other['name'] }} <span>({{ $other['count'] }})</span></a>
                        @endforeach
                    </div>
                </nav>
            @endif

            @if (! empty($seo['other_cities']))
                <nav class="catalog-seo-links" aria-label="{{ $service['name'] }} за містами">
                    <h2 class="catalog-seo-links__title">{{ $service['name'] }} {{ $cityName !== null ? 'в інших містах' : 'за містами' }}</h2>
                    <div class="catalog-seo-links__list">
                        @foreach ($seo['other_cities'] as $other)
                            <a class="catalog-active-filter catalog-seo-links__item" href="{{ route('catalog.landing.service', ['category' => $category->slug, 'city' => $other['slug'], 'service' => $service['slug']]) }}">{{ $other['name'] }} <span>({{ $other['count'] }})</span></a>
                        @endforeach
                    </div>
                </nav>
            @endif

            <p class="service-landing__back">
                <a href="{{ $cityUrl }}">Усі {{ $catLower }} {{ $cityLocative }}</a>
                @if ($cityName !== null)
                    <span aria-hidden="true">·</span>
                    <a href="{{ route('catalog.landing.city', ['category' => $category->slug, 'city' => $service['slug']]) }}">{{ $service['name'] }} в Україні</a>
                @endif
            </p>
        </div>
    </section>
@endsection
