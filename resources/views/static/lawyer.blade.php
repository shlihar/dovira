@extends('static.layout')

@section('title', $profile['name'] . ' — DOVIRA')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/lawyer.css') }}">
@endpush

@section('content')
    @php
        $nameParts = preg_split('/\s+/', trim($profile['name']));
        $initials = collect($nameParts)->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');

        $fullStars = (int) floor($profile['rating']);
        $hasHalfStar = ($profile['rating'] - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);

        $five = max(40, min(74, (int) round($profile['rating'] * 14)));
        $four = 100 - $five < 26 ? 18 : 22;
        $three = 8;
        $two = 4;
        $one = max(2, 100 - ($five + $four + $three + $two));

        $sampleReviews = [
            [
                'author' => 'Ірина Коваль',
                'meta' => '1 відгук · ' . $profile['city'],
                'date' => '24 березня 2026',
                'rating' => min(5, max(4, $profile['rating'])),
                'title' => 'Зручний сервіс і швидка комунікація',
                'text' => 'Профіль актуальний, відповіді на звернення були оперативні. Інформація по послугах зрозуміла та корисна.',
                'avatar' => 'І',
            ],
            [
                'author' => 'Олег Мельник',
                'meta' => '2 відгуки · ' . $profile['city'],
                'date' => '18 березня 2026',
                'rating' => max(4, min(5, $profile['rating'] - 0.1)),
                'title' => 'Професійний підхід до клієнтів',
                'text' => 'Отримав повну консультацію і зрозумілі наступні кроки. Видно, що сторінка підтримується та модеруються звернення.',
                'avatar' => 'О',
            ],
        ];

        $categoryIconMap = [
            'nova-market' => ['label' => 'Супермаркети', 'icon' => 'fa-solid fa-bag-shopping'],
            'tech-hub-store' => ['label' => 'Техніка', 'icon' => 'fa-solid fa-laptop'],
            'citydent-clinic' => ['label' => 'Клініки', 'icon' => 'fa-solid fa-tooth'],
            'autocare-service' => ['label' => 'Автосервіси', 'icon' => 'fa-solid fa-car-side'],
            'green-delivery' => ['label' => 'Доставка', 'icon' => 'fa-solid fa-truck-fast'],
            'smarthome-store' => ['label' => 'Електроніка', 'icon' => 'fa-solid fa-microchip'],
            'resto-family' => ['label' => 'Ресторани', 'icon' => 'fa-solid fa-utensils'],
            'bookflow' => ['label' => 'Сервіси', 'icon' => 'fa-solid fa-book-open'],
            'freshcare-pharmacy' => ['label' => 'Аптеки', 'icon' => 'fa-solid fa-prescription-bottle-medical'],
            'quickbox-delivery' => ['label' => 'Логістика', 'icon' => 'fa-solid fa-box'],
            'tutorspace-academy' => ['label' => 'Освіта', 'icon' => 'fa-solid fa-graduation-cap'],
            'buildcraft-studio' => ['label' => 'Будівництво', 'icon' => 'fa-solid fa-helmet-safety'],
        ];

        $cameFromCatalog = request()->query('from') === 'catalog';
        $catalogBackUrl = route('catalog');
        $requestedBackUrl = request()->query('back');

        if ($cameFromCatalog && is_string($requestedBackUrl) && $requestedBackUrl !== '') {
            $backPath = parse_url($requestedBackUrl, PHP_URL_PATH);
            if ($backPath === '/catalog') {
                $catalogBackUrl = $requestedBackUrl;
            }
        }

        $relatedProfileUrl = fn (string $slug) => $cameFromCatalog
            ? route('lawyer', ['slug' => $slug, 'from' => 'catalog', 'back' => $catalogBackUrl])
            : route('lawyer', ['slug' => $slug]);
    @endphp

    <section class="section profile-page">
        <div class="container">
            @if ($cameFromCatalog)
                <nav class="profile-breadcrumbs" aria-label="Breadcrumbs">
                    <a href="{{ $catalogBackUrl }}">← Назад до каталогу</a>
                    <span>›</span>
                    <span>{{ $profile['name'] }}</span>
                </nav>
            @endif

            <header class="profile-hero">
                <div class="profile-hero__main">
                    <div class="profile-hero__logo-wrap">
                        <div class="profile-hero__logo review-list-card__logo {{ empty($profile['logo_url']) ? 'has-random-gradient' : '' }}" @if(empty($profile['logo_url'])) data-seed="{{ $profile['name'] }}" @endif aria-label="Лого {{ $profile['name'] }}">
                            @if (!empty($profile['logo_url']))
                                <img src="{{ $profile['logo_url'] }}" alt="Лого {{ $profile['name'] }}" loading="lazy">
                            @else
                                {{ $initials }}
                            @endif
                        </div>
                    </div>

                    <div class="profile-hero__info">
                        <h1 class="profile-hero__title">{{ $profile['name'] }}</h1>
                        <div class="profile-hero__meta">
                            <span>{{ $profile['city'] }} · {{ $profile['district'] }}</span>
                            <span class="dot">•</span>
                            <span>{{ $profile['reviews_count'] }} відгуків</span>
                        </div>

                        <div class="profile-hero__rating">
                            <div class="rating-stars" aria-hidden="true">
                                @for ($i = 0; $i < $fullStars; $i++)
                                    <i class="fa-solid fa-star"></i>
                                @endfor
                                @if ($hasHalfStar)
                                    <i class="fa-solid fa-star-half-stroke"></i>
                                @endif
                                @for ($i = 0; $i < $emptyStars; $i++)
                                    <i class="fa-regular fa-star"></i>
                                @endfor
                            </div>
                            <strong>{{ number_format($profile['rating'], 1) }}</strong>
                            <span>на основі публічних відгуків</span>
                        </div>

                        <div class="profile-hero__badges">
                            @if ($profile['verified'])
                                <span class="best-lawyer-card__verified-badge">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений акаунт
                                </span>
                            @endif
                            @if ($profile['pro'])
                                <span class="best-lawyer-card__pro-badge">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO
                                </span>
                            @endif
                        </div>

                        <div class="profile-hero__actions">
                            <a class="btn btn--primary" href="{{ route('login') }}">
                                <span>Написати відгук</span>
                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <aside class="profile-hero__recommend">
                    <strong class="profile-hero__recommend-value">{{ $profile['recommend_percent'] }}%</strong>
                    <p class="profile-hero__recommend-text">користувачів рекомендують цей профіль</p>
                    <div class="profile-hero__recommend-bar">
                        <span style="width: {{ $profile['recommend_percent'] }}%"></span>
                    </div>
                    <a class="btn btn--primary btn--full profile-hero__reviews-btn" href="#reviews">Читати відгуки</a>
                </aside>
            </header>

            <div class="profile-side-card profile-side-card--contacts-mobile">
                <h3>Контакти профілю</h3>
                <ul>
                    <li><i class="fa-solid fa-globe"></i> {{ $profile['website'] }}</li>
                    <li><i class="fa-solid fa-envelope"></i> {{ $profile['email'] }}</li>
                    <li><i class="fa-solid fa-phone"></i> {{ $profile['phone'] }}</li>
                    <li><i class="fa-solid fa-location-dot"></i> {{ $profile['address'] }}</li>
                </ul>
                <a class="profile-side-card__claim-link" href="#">Володієте цією компанією?</a>
            </div>

            <div class="profile-layout">
                <div class="profile-main">
                    <section class="profile-card" id="summary">
                        <div class="profile-card__head">
                            <h2>Підсумок</h2>
                        </div>
                        <div class="profile-summary-ai" data-ai-summary-placeholder>
                            <div class="profile-summary-ai__head">
                                <i class="fa-solid fa-brain" aria-hidden="true"></i>
                                <span>AI-аналіз відгуків</span>
                            </div>
                            <p class="profile-summary-ai__text">
                                Заглушка: тут буде короткий підсумок від AI на основі всіх відгуків про профіль.
                                Наприклад, сильні сторони сервісу, типові зауваження та загальний рівень довіри.
                            </p>
                        </div>

                        <div class="profile-rating-bars">
                            <div class="profile-rating-row">
                                <div class="profile-rating-row__mark" aria-label="5 зірок">
                                    <span>5</span>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="profile-rating-row__bar"><i style="width:{{ $five }}%"></i></div>
                                <strong>{{ $five }}%</strong>
                            </div>
                            <div class="profile-rating-row">
                                <div class="profile-rating-row__mark" aria-label="4 зірки">
                                    <span>4</span>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="profile-rating-row__bar"><i style="width:{{ $four }}%"></i></div>
                                <strong>{{ $four }}%</strong>
                            </div>
                            <div class="profile-rating-row">
                                <div class="profile-rating-row__mark" aria-label="3 зірки">
                                    <span>3</span>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="profile-rating-row__bar"><i style="width:{{ $three }}%"></i></div>
                                <strong>{{ $three }}%</strong>
                            </div>
                            <div class="profile-rating-row">
                                <div class="profile-rating-row__mark" aria-label="2 зірки">
                                    <span>2</span>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="profile-rating-row__bar"><i style="width:{{ $two }}%"></i></div>
                                <strong>{{ $two }}%</strong>
                            </div>
                            <div class="profile-rating-row">
                                <div class="profile-rating-row__mark" aria-label="1 зірка">
                                    <span>1</span>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="profile-rating-row__bar"><i style="width:{{ $one }}%"></i></div>
                                <strong>{{ $one }}%</strong>
                            </div>
                        </div>
                    </section>

                    <section class="profile-card" id="reviews">
                        <div class="profile-card__head">
                            <h2>Відгуки клієнтів</h2>
                            <select class="profile-select" aria-label="Сортування відгуків">
                                <option>За актуальністю</option>
                                <option>Спочатку позитивні</option>
                                <option>Спочатку критичні</option>
                            </select>
                        </div>

                        <div class="profile-reviews">
                            @foreach ($sampleReviews as $review)
                                @php
                                    $reviewFull = (int) floor($review['rating']);
                                    $reviewHalf = ($review['rating'] - $reviewFull) >= 0.5;
                                    $reviewEmpty = 5 - $reviewFull - ($reviewHalf ? 1 : 0);
                                @endphp
                                <article class="profile-review">
                                    <div class="profile-review__top">
                                        <div class="profile-review__author">
                                            <div class="profile-review__avatar">{{ $review['avatar'] }}</div>
                                            <div>
                                                <strong>{{ $review['author'] }}</strong>
                                                <span>{{ $review['meta'] }}</span>
                                            </div>
                                        </div>
                                        <time>{{ $review['date'] }}</time>
                                    </div>
                                    <div class="profile-review__rating">
                                        <div class="rating-stars">
                                            @for ($i = 0; $i < $reviewFull; $i++)
                                                <i class="fa-solid fa-star"></i>
                                            @endfor
                                            @if ($reviewHalf)
                                                <i class="fa-solid fa-star-half-stroke"></i>
                                            @endif
                                            @for ($i = 0; $i < $reviewEmpty; $i++)
                                                <i class="fa-regular fa-star"></i>
                                            @endfor
                                        </div>
                                        <strong>{{ number_format($review['rating'], 1) }}</strong>
                                    </div>
                                    <p>{{ $review['text'] }}</p>
                                </article>
                            @endforeach
                        </div>
                    </section>
                </div>

                <aside class="profile-side">
                    <div class="profile-side-card profile-side-card--contacts-desktop">
                        <h3>Контакти профілю</h3>
                        <ul>
                            <li><i class="fa-solid fa-globe"></i> {{ $profile['website'] }}</li>
                            <li><i class="fa-solid fa-envelope"></i> {{ $profile['email'] }}</li>
                            <li><i class="fa-solid fa-phone"></i> {{ $profile['phone'] }}</li>
                            <li><i class="fa-solid fa-location-dot"></i> {{ $profile['address'] }}</li>
                        </ul>
                        <a class="profile-side-card__claim-link" href="#">Володієте цією компанією?</a>
                    </div>

                    <div class="profile-side-card" id="about">
                        <h3>Про профіль</h3>
                        <p>{{ $profile['about'] }}</p>
                        <a class="btn btn--primary btn--full profile-side-card__review-btn" href="{{ route('login') }}">
                            <span>Написати відгук</span>
                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        </a>
                    </div>
                </aside>
            </div>

            <section class="section best-lawyers" aria-labelledby="profile-related-title">
                <div class="home-intents__head">
                    <h2 id="profile-related-title" class="h2">Найкращі в категорії</h2>
                    <div class="home-intents__actions">
                        <button type="button" class="home-intents__nav home-intents__nav--prev" aria-label="Попередні профілі" data-carousel-prev="best-lawyers-related">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="home-intents__nav" aria-label="Наступні профілі" data-carousel-next="best-lawyers-related">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <a class="home-intents__more" href="{{ route('catalog') }}">Дивитись більше</a>
                    </div>
                </div>

                <div class="best-lawyers__carousel reviews-carousel__track" data-carousel="best-lawyers-related">
                    @foreach ($relatedProfiles as $related)
                        @php
                            $relatedNameParts = preg_split('/\s+/', trim($related['name']));
                            $relatedInitials = collect($relatedNameParts)->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
                            $relatedFull = (int) floor($related['rating']);
                            $relatedHalf = ($related['rating'] - $relatedFull) >= 0.5;
                            $relatedEmpty = 5 - $relatedFull - ($relatedHalf ? 1 : 0);
                            $categoryMeta = $categoryIconMap[$related['slug']] ?? ['label' => 'Компанії', 'icon' => 'fa-solid fa-building'];
                        @endphp

                        <article class="best-lawyer-card result-card--carousel">
                            <div class="best-lawyer-card__top">
                                <div class="best-lawyer-card__logo review-list-card__logo {{ empty($related['logo_url']) ? 'has-random-gradient' : '' }}" @if(empty($related['logo_url'])) data-seed="{{ $related['name'] }}" @endif>
                                    @if (!empty($related['logo_url']))
                                        <img src="{{ $related['logo_url'] }}" alt="Лого {{ $related['name'] }}" loading="lazy">
                                    @else
                                        {{ $relatedInitials }}
                                    @endif
                                </div>
                                <div class="best-lawyer-card__badges">
                                    @if ($related['pro'])
                                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                                    @endif
                                    @if ($related['verified'])
                                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                                    @endif
                                </div>
                            </div>
                            <h3 class="best-lawyer-card__name">
                                <a href="{{ $relatedProfileUrl($related['slug']) }}">{{ $related['name'] }}</a>
                            </h3>
                            <a class="best-lawyer-card__site" href="https://{{ $related['website'] }}" target="_blank" rel="noopener noreferrer">
                                <i class="fa-solid fa-globe" aria-hidden="true"></i><span>{{ $related['website'] }}</span>
                            </a>
                            <div class="best-lawyer-card__rating">
                                <span class="best-lawyer-card__stars" aria-label="Рейтинг {{ number_format($related['rating'], 1) }} з 5">
                                    @for ($i = 0; $i < $relatedFull; $i++)
                                        <i class="fa-solid fa-star"></i>
                                    @endfor
                                    @if ($relatedHalf)
                                        <i class="fa-solid fa-star-half-stroke"></i>
                                    @endif
                                    @for ($i = 0; $i < $relatedEmpty; $i++)
                                        <i class="fa-regular fa-star"></i>
                                    @endfor
                                </span>
                                <strong>{{ number_format($related['rating'], 1) }}</strong>
                                <span>({{ $related['reviews_count'] }})</span>
                            </div>
                            <span class="best-lawyer-card__category" title="{{ $categoryMeta['label'] }}" aria-label="Категорія: {{ $categoryMeta['label'] }}">
                                <i class="{{ $categoryMeta['icon'] }}" aria-hidden="true"></i>
                            </span>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>
    </section>
@endsection
