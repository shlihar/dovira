@extends('static.layout')

@section('title', $profile['seo_title'] ?? ($profile['name'] . ' — DOVIRA'))
@section('description', $profile['seo_description'] ?? 'Профіль на DOVIRA')
@section('body_class', 'page-profile')

@php
    $seoProfileUrl = route('profile.show', ['slug' => $profile['slug']]);
@endphp

@section('canonical', $seoProfileUrl)
@if (empty($profile['is_indexable']))
    {{-- Thin content: без опису й відгуків — не індексуємо, але лишаємо follow. --}}
    @section('robots', 'noindex, follow')
@endif
@section('og_type', 'profile')
@php
    // OG-зображення: власне з кабінету, інакше — логотип/аватарка профілю.
    // Шеринг-скрейпери вимагають абсолютний URL.
    $ogImage = $profile['og_image_url'] ?? null ?: ($profile['logo_image_url'] ?? $profile['logo_url'] ?? null);
    if (!empty($ogImage) && str_starts_with($ogImage, '/')) {
        $ogImage = url($ogImage);
    }
@endphp
@if (!empty($ogImage))
    @section('og_image', $ogImage)
@endif

@push('head')
    {{-- home-main.css НЕ підключаємо: його правила scoped під .page-home-main
         (не діють на .page-profile), а спільну карусель «Dovira рекомендує»
         повністю стилізує lawyer.css. Економія ~72 КБ на кожному профілі. --}}
    <link rel="stylesheet" href="{{ asset('static/css/pages/lawyer.css') }}">
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}">
    @php
        $seoBusinessSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => $seoProfileUrl . '#business',
            'name' => $profile['name'],
            'url' => $seoProfileUrl,
            'image' => ($profile['logo_image_url'] ?? $profile['logo_url'] ?? null) ?: null,
            'description' => $profile['seo_description'] ?? null,
            // Контакти в розмітці — лише для PRO: інакше телефон/email
            // витікали б у сирці сторінки в обхід публічного гейтингу.
            'telephone' => ! empty($profile['pro']) ? ($profile['phone'] ?: null) : null,
            'email' => ! empty($profile['pro']) ? ($profile['email'] ?: null) : null,
            'address' => ($profile['city'] || $profile['address']) ? array_filter([
                '@type' => 'PostalAddress',
                'addressLocality' => $profile['city'] ?: null,
                'streetAddress' => $profile['address'] ?: null,
                'addressCountry' => 'UA',
            ]) : null,
            'sameAs' => array_values(array_filter(array_merge(
                array_values((array) ($profile['social_links'] ?? [])),
                [$profile['website'] ?? null],
            ))) ?: null,
            'knowsAbout' => !empty($profile['services']) ? array_values($profile['services']) : null,
            'areaServed' => $profile['city'] ?: null,
        ]);

        // Freshness-сигнал для AI/пошуку: найсвіжіша з дат — оновлення профілю
        // або останній опублікований відгук. AI важить свіжість на запити
        // «[назва] відгуки», тож віддаємо dateModified і показуємо «Оновлено».
        $seoDateModified = rescue(function () use ($profile, $sampleReviews) {
            $dates = collect($sampleReviews ?? [])
                ->pluck('date_iso')
                ->push($profile['updated_at'] ?? null)
                ->filter()
                ->map(fn ($value) => \Illuminate\Support\Carbon::parse($value))
                ->filter();

            return $dates->isNotEmpty() ? $dates->max() : null;
        }, null, false);

        if ($seoDateModified) {
            $seoBusinessSchema['dateModified'] = $seoDateModified->toIso8601String();
        }

        // У розмітку йдуть лише власні відгуки платформи: імпортовані з
        // зовнішніх джерел Google забороняє віддавати як Review/AggregateRating
        // (рейтинг має походити від користувачів сайту).
        $seoNativeStats = $nativeReviewStats ?? ['count' => 0, 'rating' => 0];

        // Розмітка має відповідати видимому контенту: коли оцінку на сторінці
        // ховаємо (замало відгуків), AggregateRating теж не віддаємо.
        if ($seoNativeStats['count'] > 0
            && $seoNativeStats['rating'] > 0
            && \App\Support\RatingDisplay::visible((int) ($profile['reviews_count'] ?? 0))) {
            $seoBusinessSchema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $seoNativeStats['rating'],
                'reviewCount' => $seoNativeStats['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        $seoReviewItems = collect($sampleReviews ?? [])
            ->filter(fn ($review) => !empty($review['text']) && empty($review['external_source_type']))
            ->take(10)
            ->map(fn ($review) => array_filter([
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $review['author']],
                'datePublished' => $review['date_iso'] ?? null,
                'name' => $review['title'] ?: null,
                'reviewBody' => $review['text'],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (float) $review['rating'],
                    'bestRating' => 5,
                    'worstRating' => 1,
                ],
            ]))
            ->values()
            ->all();

        if (!empty($seoReviewItems)) {
            $seoBusinessSchema['review'] = $seoReviewItems;
        }

        $seoBreadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => route('catalog')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $profile['name'], 'item' => $seoProfileUrl],
            ],
        ];

        $seoProfileFaqItems = collect((array) ($profile['faq'] ?? []))
            ->filter(fn ($item) => is_array($item) && filled($item['q'] ?? null) && filled($item['a'] ?? null))
            ->take(20)
            ->map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ])
            ->values()
            ->all();

        $seoProfileFaqSchema = !empty($seoProfileFaqItems) ? [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $seoProfileFaqItems,
        ] : null;
    @endphp
    <script type="application/ld+json">@json($seoBusinessSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    <script type="application/ld+json">@json($seoBreadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @if ($seoProfileFaqSchema)
        <script type="application/ld+json">@json($seoProfileFaqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endif
@endpush

@section('content')
    @php
        // Гість спершу бачить лендінг PRO з цінністю і тарифами, авторизований — кабінет.
        $lawyerProUrl = auth()->check() ? route('pro.account') : route('pro');
        $lawyerProLabel = auth()->check() ? 'Відкрити PRO кабінет' : 'Дізнатися про PRO';
    @endphp

    @php
        $nameParts = preg_split('/\s+/', trim($profile['name']));
        $initials = collect($nameParts)->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');

        $fullStars = (int) floor($profile['rating']);
        $hasHalfStar = ($profile['rating'] - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);

        // Показуємо числову оцінку лише при достатній кількості відгуків —
        // середнє з кількох штук вводить в оману (див. RatingDisplay).
        $ratingVisible = \App\Support\RatingDisplay::visible((int) ($profile['reviews_count'] ?? 0));
        $ratingToneClass = static function (float $rating): string {
            return match (true) {
                $rating >= 4 => 'rating-stars--excellent',
                $rating >= 3 => 'rating-stars--fair',
                default => 'rating-stars--poor',
            };
        };

        $ratingDistribution = (array) ($profile['rating_distribution'] ?? []);
        $five = (int) ($ratingDistribution[5] ?? 0);
        $four = (int) ($ratingDistribution[4] ?? 0);
        $three = (int) ($ratingDistribution[3] ?? 0);
        $two = (int) ($ratingDistribution[2] ?? 0);
        $one = (int) ($ratingDistribution[1] ?? 0);
        $distributionTotal = (int) ($profile['rating_distribution_total'] ?? $profile['reviews_count'] ?? 0);

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

        // «Назад до каталогу» беремо з Referer, а не з параметрів URL — щоб
        // адреса профілю лишалась чистою (/profiles/{slug}). При переході в
        // межах сайту Referer містить повний шлях каталогу з фільтрами, тож
        // повертає рівно в той самий стан. Показуємо крихту лише коли реально
        // прийшли з каталогу (того самого сайту).
        $referer = (string) request()->headers->get('referer', '');
        $refererHost = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;
        $refererPath = (string) ($referer !== '' ? parse_url($referer, PHP_URL_PATH) : '');
        $cameFromCatalog = $refererHost === request()->getHost()
            && ($refererPath === '/catalog' || str_starts_with($refererPath, '/catalog/'));
        $catalogBackUrl = $cameFromCatalog ? $referer : route('catalog');

        $relatedProfileUrl = fn (string $slug) => route('profile.show', ['slug' => $slug]);
        $profileWebsiteHref = \App\Support\WebsiteUrl::href($profile['website'] ?? null);
        $profileWebsiteDisplay = \App\Support\WebsiteUrl::display($profile['website'] ?? null);
        $profileContactHref = \App\Support\WebsiteUrl::href($profile['contact_cta_url'] ?? null)
            ?? $profileWebsiteHref;
        $profilePhoneLinks = collect((array) ($profile['phone_links'] ?? []))
            ->filter(fn ($item) => is_array($item) && filled($item['display'] ?? null) && filled($item['href'] ?? null))
            ->values();
        $socialNetworkMeta = [
            'instagram' => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram'],
            'telegram' => ['label' => 'Telegram', 'icon' => 'fa-brands fa-telegram'],
            'facebook' => ['label' => 'Facebook', 'icon' => 'fa-brands fa-facebook-f'],
            'tiktok' => ['label' => 'TikTok', 'icon' => 'fa-brands fa-tiktok'],
            'youtube' => ['label' => 'YouTube', 'icon' => 'fa-brands fa-youtube'],
            'linkedin' => ['label' => 'LinkedIn', 'icon' => 'fa-brands fa-linkedin-in'],
            'x' => ['label' => 'X', 'icon' => 'fa-brands fa-x-twitter'],
            'viber' => ['label' => 'Viber', 'icon' => 'fa-brands fa-viber'],
            'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'fa-brands fa-whatsapp'],
            'other' => ['label' => 'Соцмережа', 'icon' => 'fa-solid fa-share-nodes'],
        ];
        $profileSocialLinks = collect((array) ($profile['social_links'] ?? []))
            ->map(function ($url, $network) use ($socialNetworkMeta) {
                if (is_array($url)) {
                    $network = trim((string) ($url['network'] ?? $network));
                    $url = trim((string) ($url['url'] ?? ''));
                } else {
                    $network = trim((string) $network);
                    $url = trim((string) $url);
                }

                if ($network === '' || $url === '' || $network === 'website') {
                    return null;
                }

                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    return null;
                }

                $meta = $socialNetworkMeta[$network] ?? $socialNetworkMeta['other'];

                return [
                    'network' => $network,
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'url' => $url,
                ];
            })
            ->filter(fn ($item) => is_array($item) && filled($item['url'] ?? null))
            ->values();
        $claimProfileUrl = route('pro.account', array_filter([
            'tab' => 'claims',
            'claim_profile' => $profile['id'] ?? null,
        ]));

        // Досьє: вердикт потрібен у трьох місцях — hero-картці довіри,
        // тизері в «Огляді відгуків» і самому блоці досьє.
        $hasDossier = trim((string) ($profile['dossier'] ?? '')) !== '';
        $dossierVerdict = in_array($profile['dossier_verdict'] ?? '', ['red', 'yellow', 'green'], true) ? $profile['dossier_verdict'] : null;
        $dossierVerdictMeta = [
            'red' => ['icon' => 'fa-circle-exclamation', 'label' => 'Є критичні факти', 'short' => 'є критичні факти'],
            'yellow' => ['icon' => 'fa-triangle-exclamation', 'label' => 'Є що перевірити', 'short' => 'є що перевірити'],
            'green' => ['icon' => 'fa-circle-check', 'label' => 'Записи чисті', 'short' => 'записи чисті'],
        ];
    @endphp

    @php
        $commentAuthorName = auth()->user()?->name ?: 'Ви';
    $commentAuthorAvatarUrl = auth()->check()
        ? \App\Support\MediaUrl::avatarUrl(auth()->user()?->avatar_url, auth()->user()?->name ?: auth()->user()?->email, 96)
        : null;
        $commentAuthorInitial = mb_strtoupper(mb_substr(trim($commentAuthorName), 0, 1)) ?: 'В';
    @endphp

    <section class="section profile-page" data-profile-slug="{{ $profile['slug'] }}">
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
                        <div class="profile-hero__logo review-list-card__logo {{ empty($profile['logo_image_url'] ?? $profile['logo_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $profile['name'] }}" aria-label="Лого {{ $profile['name'] }}">
                            @if (!empty($profile['logo_image_url'] ?? $profile['logo_url']))
                                <img src="{{ $profile['logo_image_url'] ?? $profile['logo_url'] }}" alt="Лого {{ $profile['name'] }}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)">
                                <span class="review-list-card__fallback" aria-hidden="true">{{ $initials }}</span>
                            @else
                                {{ $initials }}
                            @endif
                        </div>
                        @include('static.partials.verification-badge', [
                            'verified' => $profile['verified'] ?? false,
                            'ownerVerified' => $profile['owner_verified'] ?? false,
                            'modifier' => 'owner-verified-badge--hero',
                        ])
                    </div>

                    <div class="profile-hero__info">
                        <h1 class="profile-hero__title"><span class="profile-hero__title-text">{{ $profile['name'] }}</span></h1>
                        @php
                            $heroDirections = collect((array) ($profile['directions'] ?? []))
                                ->filter(fn ($value) => filled($value))
                                ->values();
                            $heroLocation = collect([$profile['city'] ?? null, $profile['district'] ?? null])
                                ->filter(fn ($value) => filled($value))
                                ->implode(' · ');
                            $heroReviewsCount = (int) $profile['reviews_count'];
                            $heroReviewsLabel = ($heroReviewsCount % 10 === 1 && $heroReviewsCount % 100 !== 11)
                                ? 'відгук'
                                : (in_array($heroReviewsCount % 10, [2, 3, 4], true) && !in_array($heroReviewsCount % 100, [12, 13, 14], true) ? 'відгуки' : 'відгуків');
                        @endphp

                        @php
                            $heroSubtitle = collect([$heroDirections->first(), $heroLocation !== '' ? $heroLocation : null])
                                ->filter(fn ($value) => filled($value))
                                ->implode(' · ');
                        @endphp

                        @if ($heroSubtitle !== '')
                            <div class="profile-hero__meta">
                                <span>{{ $heroSubtitle }}</span>
                            </div>
                        @endif

                        <div class="profile-hero__rating">
                            @if ($ratingVisible)
                                <div class="rating-stars {{ $ratingToneClass((float) $profile['rating']) }}" aria-hidden="true">
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
                                <span>{{ $heroReviewsCount }} {{ $heroReviewsLabel }}</span>
                            @else
                                <span class="profile-rating-pending">
                                    <i class="fa-regular fa-star" aria-hidden="true"></i>
                                    Оцінка формується@if ($heroReviewsCount > 0) · {{ $heroReviewsCount }} {{ $heroReviewsLabel }}@endif
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($heroReviewsCount > 0)
                        @php
                            $heroRank = $profile['popularity_rank'] ?? null;
                            $heroRankTotal = (int) ($profile['popularity_total'] ?? 0);
                        @endphp
                        {{-- App-like stats strip: mobile-only replacement for the inline rating row. --}}
                        <div class="profile-hero__stats">
                            @if ($ratingVisible)
                                <div class="profile-hero__stat">
                                    <strong>{{ number_format($profile['rating'], 1) }} <i class="fa-solid fa-star" aria-hidden="true"></i></strong>
                                    <span>рейтинг</span>
                                </div>
                            @else
                                <div class="profile-hero__stat">
                                    <strong><i class="fa-regular fa-star" aria-hidden="true"></i></strong>
                                    <span>формується</span>
                                </div>
                            @endif
                            <div class="profile-hero__stat">
                                <strong>{{ $heroReviewsCount }}</strong>
                                <span>{{ $heroReviewsLabel }}</span>
                            </div>
                            @if ($heroRank !== null && $heroRankTotal > 0)
                                <div class="profile-hero__stat profile-hero__stat--rank" style="--stat-accent: {{ $profile['popularity_rank_color'] ?? '#30ba73' }}">
                                    <strong>#{{ $heroRank }}</strong>
                                    <span>із {{ $heroRankTotal }} у місті</span>
                                </div>
                            @elseif ((int) ($profile['recommend_percent'] ?? 0) > 0)
                                <div class="profile-hero__stat">
                                    <strong>{{ (int) $profile['recommend_percent'] }}%</strong>
                                    <span>рекомендують</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="profile-hero__actions">
                        <a
                            class="btn btn--primary"
                            href="#"
                            data-open-review-popup
                            data-profile-slug="{{ $profile['slug'] }}"
                            data-profile-name="{{ $profile['name'] }}"
                        >
                            <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                            <span>Написати відгук</span>
                        </a>
                        {{-- Друга дія — рівно одна, щоб не було трьох кнопок:
                             є досьє → «Досьє», немає → «Відгуки» (скрол до відгуків). --}}
                        @if ($hasDossier)
                            <a class="btn btn--profile-contact profile-hero__actions-dossier" href="#profile-dossier" data-scroll-to="dossier">
                                <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                                <span>Досьє</span>
                            </a>
                        @else
                            <a class="btn btn--profile-contact profile-hero__actions-reviews" href="#reviews" data-scroll-to="reviews">
                                <i class="fa-regular fa-star" aria-hidden="true"></i>
                                <span>Відгуки</span>
                            </a>
                        @endif
                    </div>
                </div>

                <aside
                    class="profile-hero__trust profile-hero__trust--{{ $profile['trust_tone'] ?? 'excellent' }}"
                    style="--trust-color-accent: {{ $profile['trust_color_accent'] ?? '#66cf95' }}; --trust-color-soft: {{ $profile['trust_color_soft'] ?? '#eefaf3' }}; --popularity-dot-color: {{ $profile['popularity_rank_color'] ?? '#9fb0cf' }};"
                >
                    <div class="profile-hero__trust-top">
                        <div class="profile-hero__trust-copy">
                            <p class="profile-hero__trust-label">
                                Рейтинг довіри
                                <span
                                    class="profile-hero__trust-help"
                                    title="Рейтинг довіри формується за оцінками та відгуками, а також за позицією профілю у категорії."
                                    aria-label="Пояснення рейтингу довіри"
                                >
                                    <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                                    <span class="profile-hero__trust-help-tooltip" role="tooltip">
                                        Рейтинг довіри формується за оцінками та відгуками, а також за позицією профілю у категорії.
                                    </span>
                                </span>
                            </p>
                            @if ($ratingVisible)
                                <p class="profile-hero__trust-rating">
                                    <strong>{{ number_format($profile['rating'], 1) }}</strong>
                                    <span>/5</span>
                                </p>
                                <p class="profile-hero__trust-reviews">{{ $heroReviewsCount }} {{ $heroReviewsLabel }}</p>
                            @else
                                <p class="profile-hero__trust-rating profile-hero__trust-rating--pending">
                                    <strong>Формується</strong>
                                </p>
                                <p class="profile-hero__trust-reviews">@if ($heroReviewsCount > 0){{ $heroReviewsCount }} {{ $heroReviewsLabel }} з {{ \App\Support\RatingDisplay::MIN_REVIEWS }}@else Ще немає відгуків @endif</p>
                            @endif
                        </div>
                        <div class="profile-hero__trust-icon" aria-hidden="true">
                            <span class="profile-hero__trust-icon-core">
                                <span class="profile-hero__trust-badge">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </span>
                            </span>
                        </div>
                    </div>

                    <div class="profile-hero__trust-list">
                        <div class="profile-hero__trust-item">
                            <span class="profile-hero__trust-item-left" title="{{ $profile['popularity_rank_title'] ?? '' }}">
                                <i class="fa-solid fa-circle profile-hero__trust-dot" aria-hidden="true"></i>
                                {{ $profile['popularity_rank_label'] ?? 'Рейтинг популярності: —' }}
                            </span>
                        </div>
                        @if ($hasDossier)
                            {{-- Вердикт досьє — сигнал довіри, тому видно з першого
                                 екрана; клік веде до повного досьє у вкладці «Інформація». --}}
                            <button
                                type="button"
                                class="profile-hero__trust-item profile-hero__trust-dossier @if ($dossierVerdict) profile-hero__trust-dossier--{{ $dossierVerdict }} @endif"
                                data-scroll-to="dossier"
                                title="Відкрити повне досьє"
                            >
                                <span class="profile-hero__trust-item-left">
                                    <i class="fa-solid {{ $dossierVerdict ? $dossierVerdictMeta[$dossierVerdict]['icon'] : 'fa-file-shield' }}" aria-hidden="true"></i>
                                    Досьє: {{ $dossierVerdict ? $dossierVerdictMeta[$dossierVerdict]['short'] : 'факти з відкритих джерел' }}
                                </span>
                                <i class="fa-solid fa-chevron-right profile-hero__trust-dossier-arrow" aria-hidden="true"></i>
                            </button>
                        @endif
                    </div>
                </aside>
            </header>

            @if (empty($profile['owner_verified']))
                {{-- Unclaimed-profile status (Trustpilot pattern): tells visitors
                     the data may be incomplete and gives the business a direct,
                     prefilled path to claim the profile — a conversion driver. --}}
                <div class="profile-claim-banner">
                    <p class="profile-claim-banner__text">
                        <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                        <span><strong>Цей профіль ще не підтверджений власником.</strong> Інформація може бути неповною або застарілою.</span>
                    </p>
                    <a class="profile-claim-banner__cta" href="{{ auth()->check() ? $claimProfileUrl : route('register', ['next' => $claimProfileUrl]) }}">
                        <span>Це ваш бізнес? Підтвердіть безкоштовно</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            @endif

            @php
                $profileServices = collect((array) ($profile['services'] ?? []))
                    ->map(function ($service) {
                        if (is_array($service)) {
                            return [
                                'name' => trim((string) ($service['name'] ?? '')),
                                'description' => trim((string) ($service['description'] ?? '')),
                                'category' => trim((string) ($service['category'] ?? '')),
                                'price_from' => trim((string) ($service['price_from'] ?? '')),
                                'duration' => trim((string) ($service['duration'] ?? '')),
                                'cta' => trim((string) ($service['cta'] ?? 'Залишити заявку')),
                            ];
                        }

                        $value = trim((string) $service);
                        return [
                            'name' => $value,
                            'description' => '',
                            'category' => '',
                            'price_from' => '',
                            'duration' => '',
                            'cta' => 'Залишити заявку',
                        ];
                    })
                    ->filter(fn ($item) => ($item['name'] ?? '') !== '')
                    ->values();

                $proGallery = collect((array) ($profile['gallery'] ?? []))
                    ->map(function ($item, $index) {
                        if (is_array($item)) {
                            $rawUrl = trim((string) ($item['url'] ?? $item['image'] ?? ''));
                            $title = trim((string) ($item['title'] ?? $item['caption'] ?? ''));
                            $visible = ! array_key_exists('visible', $item) || (bool) $item['visible'];
                        } else {
                            $rawUrl = trim((string) $item);
                            $title = '';
                            $visible = true;
                        }

                        if ($rawUrl === '' || ! $visible) {
                            return null;
                        }

                        $url = \App\Support\MediaUrl::publicImageUrl($rawUrl);

                        return [
                            'url' => $url,
                            // Мініатюра для сітки/стрічки; повний $url лишається для лайтбокса.
                            'thumb' => \App\Support\MediaUrl::thumbUrl($rawUrl, 400) ?: $url,
                            'title' => $title !== '' ? $title : 'Фото ' . ($index + 1),
                        ];
                    })
                    ->filter(fn ($item) => is_array($item) && filled($item['url'] ?? null))
                    ->values();

                $proFaq = collect((array) ($profile['faq'] ?? []))
                    ->filter(fn ($item) => is_array($item) && filled($item['q'] ?? null) && filled($item['a'] ?? null))
                    ->values();

                // Видимий абзац-відповідь (те, що AI-пошук цитує на «[назва] відгуки»)
                // + freshness + чесний Google-рейтинг для імпортованих профілів.
                $profileLeadAnswer = \App\Support\ProfileSeo::answerParagraph(
                    $profile['name'] ?? null,
                    $profile['category_label'] ?? null,
                    $profile['city'] ?? null,
                    (array) ($profile['services'] ?? []),
                    $ratingVisible,
                    (float) ($profile['rating'] ?? 0),
                    (int) ($profile['reviews_count'] ?? 0)
                );

                // Свіжа дата: найновіша з оновлення профілю / останнього відгуку.
                // Обчислюємо тут самодостатньо (не покладаємось на змінну з @push).
                $profileFreshDate = rescue(function () use ($profile, $sampleReviews) {
                    $dates = collect($sampleReviews ?? [])
                        ->pluck('date_iso')
                        ->push($profile['updated_at'] ?? null)
                        ->filter()
                        ->map(fn ($value) => \Illuminate\Support\Carbon::parse($value))
                        ->filter();

                    return $dates->isNotEmpty() ? $dates->max() : null;
                }, null, false);

                $profileUpdatedLabel = $profileFreshDate
                    ? $profileFreshDate->locale('uk')->translatedFormat('d F Y')
                    : null;

                // Google-рейтинг — ЧЕСНИМ ТЕКСТОМ, не в schema (там лише нативні відгуки).
                $googleRatingValue = (float) ($profile['google_rating'] ?? 0);
                $googleReviewsCount = (int) ($profile['google_reviews_count'] ?? 0);
                $profileGoogleNote = ($googleRatingValue > 0 && $googleReviewsCount > 0)
                    ? 'За даними Google — ' . rtrim(rtrim(number_format($googleRatingValue, 1, '.', ''), '0'), '.')
                        . ' з 5 (' . $googleReviewsCount . ' ' . \App\Support\ProfileSeo::reviewsWord($googleReviewsCount) . ').'
                    : null;
            @endphp

            @if ($profileLeadAnswer !== '')
                <section class="profile-intro" aria-label="Коротко про профіль">
                    <p class="profile-intro__lead">{{ $profileLeadAnswer }}</p>
                    @if ($profileGoogleNote)
                        <p class="profile-intro__source">{{ $profileGoogleNote }}</p>
                    @endif
                    @if ($profileUpdatedLabel)
                        <p class="profile-intro__updated">Оновлено {{ $profileUpdatedLabel }}</p>
                    @endif
                </section>
            @endif

            <div class="profile-layout">
                <div class="profile-main">
                    <nav class="profile-tabs profile-tabs--page" aria-label="Розділи профілю" role="tablist">
                        <button type="button" class="profile-tabs__link profile-tabs__link--info" role="tab" aria-selected="false" aria-controls="profile-tab-info" id="profile-tab-trigger-info" data-profile-tab-trigger="info">
                            <i class="fa-solid fa-circle-info profile-tabs__icon" aria-hidden="true"></i>
                            <span>Інформація</span>
                        </button>
                        <button type="button" class="profile-tabs__link profile-tabs__link--reviews is-active" role="tab" aria-selected="true" aria-controls="profile-tab-reviews" id="profile-tab-trigger-reviews" data-profile-tab-trigger="reviews">
                            <i class="fa-regular fa-star profile-tabs__icon" aria-hidden="true"></i>
                            <span>Відгуки</span>
                        </button>
                    </nav>

                    <section class="profile-tab-panel" id="profile-tab-info" role="tabpanel" aria-labelledby="profile-tab-trigger-info" aria-hidden="true" data-profile-tab-panel="info">
                        <section class="profile-card profile-about-card" id="about">
                            @php
                                $specializationTitle = !empty($profile['specializations_title'])
                                    ? (string) $profile['specializations_title']
                                    : 'Сфери практики';

                                $quickStats = collect([
                                    !empty($profile['experience_years']) ? [
                                        'icon' => 'fa-solid fa-briefcase',
                                        'value' => (string) $profile['experience_years'] . '+',
                                        'label' => 'років досвіду',
                                    ] : null,
                                    !empty($profile['consultations_count']) ? [
                                        'icon' => 'fa-solid fa-users',
                                        'value' => (string) $profile['consultations_count'],
                                        'label' => 'консультацій',
                                    ] : null,
                                    !empty($profile['response_speed']) ? [
                                        'icon' => 'fa-solid fa-bolt',
                                        'value' => (string) $profile['response_speed'],
                                        'label' => 'оперативно',
                                        'accent' => true,
                                    ] : null,
                                ])->filter()->values();

                                $experienceText = trim((string) ($profile['experience'] ?? ''));
                                $aboutRaw = trim((string) ($profile['about'] ?? ''));
                                // AI-досьє: markdown → безпечний HTML (сирий html вирізаємо,
                                // лінки без unsafe-схем). Якщо досьє є — воно замість опису.
                                $dossierRaw = trim((string) ($profile['dossier'] ?? ''));
                                $dossierHtml = '';
                                if ($dossierRaw !== '') {
                                    try {
                                        $dossierHtml = (string) Illuminate\Support\Str::markdown($dossierRaw, [
                                            'html_input' => 'strip',
                                            'allow_unsafe_links' => false,
                                            'max_nesting_level' => 6,
                                        ]);
                                        // Кольорові виділення: цитати з маркерами 🔴/⚠️/✅
                                        // стають червоним/жовтим/зеленим блоками.
                                        $dossierHtml = preg_replace(
                                            [
                                                '/<blockquote>\s*<p>\s*🔴\s*/u',
                                                '/<blockquote>\s*<p>\s*⚠️?\s*/u',
                                                '/<blockquote>\s*<p>\s*✅\s*/u',
                                            ],
                                            [
                                                '<blockquote class="dossier-callout dossier-callout--critical"><p>',
                                                '<blockquote class="dossier-callout dossier-callout--warning"><p>',
                                                '<blockquote class="dossier-callout dossier-callout--positive"><p>',
                                            ],
                                            $dossierHtml
                                        );
                                    } catch (\Throwable $e) {
                                        $dossierHtml = '';
                                    }
                                }
                                $dossierUpdatedLabel = $profile['dossier_generated_at']
                                    ? \Illuminate\Support\Carbon::parse($profile['dossier_generated_at'])->translatedFormat('d F Y')
                                    : null;
                                // Опис — це звичайна textarea без rich-text; будь-який HTML тут
                                // може бути лише ін'єкцією. Завжди екрануємо і робимо абзаци
                                // самі, ніколи не виводимо сирий HTML (stored XSS).
                                $aboutPlain = html_entity_decode(strip_tags($aboutRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                $aboutMarkup = $aboutPlain !== ''
                                    ? collect(preg_split('/\r\n|\r|\n/', $aboutPlain) ?: [])
                                        ->map(fn ($line) => trim($line))
                                        ->filter()
                                        ->map(fn ($line) => '<p>' . e($line) . '</p>')
                                        ->implode('')
                                    : '';
                            @endphp

                            @if ($dossierHtml !== '')
                                {{-- Досьє: головний контент-блок сторінки (і головний SEO-текст) --}}
                                <div class="profile-pro-block profile-pro-block--first profile-dossier" id="profile-dossier">
                                    <div class="profile-pro-section-head profile-dossier__head">
                                        <h2 class="profile-dossier__title"><i class="fa-solid fa-file-shield" aria-hidden="true"></i><span>Досьє: {{ $profile['name'] }}</span></h2>
                                    </div>
                                    @if ($dossierVerdict)
                                        <div class="profile-dossier__verdict profile-dossier__verdict--{{ $dossierVerdict }}">
                                            <i class="fa-solid {{ $dossierVerdictMeta[$dossierVerdict]['icon'] }}" aria-hidden="true"></i>
                                            <span class="profile-dossier__verdict-label">{{ $dossierVerdictMeta[$dossierVerdict]['label'] }}</span>
                                            @if (filled($profile['dossier_verdict_note'] ?? null))
                                                <span class="profile-dossier__verdict-note">{{ $profile['dossier_verdict_note'] }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    <p class="profile-dossier__meta">
                                        @if (($profile['dossier_source'] ?? '') === 'owner')
                                            <span class="profile-dossier__badge profile-dossier__badge--owner"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Відредаговано власником профілю</span>
                                        @else
                                            <span class="profile-dossier__badge"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Зібрано ШІ з відкритих джерел</span>
                                        @endif
                                        @if ($dossierUpdatedLabel)
                                            <span class="profile-dossier__date">Оновлено {{ $dossierUpdatedLabel }}</span>
                                        @endif
                                    </p>
                                    @if ($quickStats->isNotEmpty())
                                        <div class="profile-quick-stats">
                                            @foreach ($quickStats as $stat)
                                                @php
                                                    $valueIsText = !preg_match('/\d/u', (string) ($stat['value'] ?? ''));
                                                @endphp
                                                <div class="profile-quick-stats__item">
                                                    <span class="profile-quick-stats__icon" data-seed="{{ ($profile['slug'] ?? $profile['name']) . '-quick-' . $loop->index . '-' . ($stat['label'] ?? '') }}"><i class="{{ $stat['icon'] }}" aria-hidden="true"></i></span>
                                                    <span class="profile-quick-stats__copy">
                                                        <strong @if($valueIsText) class="is-text" @endif>{{ $stat['value'] }}</strong>
                                                        <small @if(!empty($stat['accent'])) class="is-accent" @endif>{{ $stat['label'] }}</small>
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="profile-about-card__text profile-dossier__text is-collapsed" data-about-text>{!! $dossierHtml !!}</div>
                                    <button type="button" class="profile-about-card__more is-hidden" data-about-toggle>Читати більше</button>
                                    <p class="profile-dossier__disclaimer">Досьє сформовано з відкритих джерел і може бути неповним чи застарілим. Це не юридична оцінка діяльності — перевіряйте критичні факти за першоджерелами.</p>
                                </div>
                            @elseif ($quickStats->isNotEmpty() || $aboutMarkup !== '')
                                <div class="profile-pro-block profile-pro-block--first">
                                    <div class="profile-pro-section-head">
                                        <h3><i class="fa-regular fa-id-card" aria-hidden="true"></i><span>Коротко про спеціаліста</span></h3>
                                    </div>
                                    @if ($quickStats->isNotEmpty())
                                        <div class="profile-quick-stats">
                                            @foreach ($quickStats as $stat)
                                                @php
                                                    $valueIsText = !preg_match('/\d/u', (string) ($stat['value'] ?? ''));
                                                @endphp
                                                <div class="profile-quick-stats__item">
                                                    <span class="profile-quick-stats__icon" data-seed="{{ ($profile['slug'] ?? $profile['name']) . '-quick-' . $loop->index . '-' . ($stat['label'] ?? '') }}"><i class="{{ $stat['icon'] }}" aria-hidden="true"></i></span>
                                                    <span class="profile-quick-stats__copy">
                                                        <strong @if($valueIsText) class="is-text" @endif>{{ $stat['value'] }}</strong>
                                                        <small @if(!empty($stat['accent'])) class="is-accent" @endif>{{ $stat['label'] }}</small>
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($aboutMarkup !== '')
                                        <div class="profile-about-card__text is-collapsed" data-about-text>{!! $aboutMarkup !!}</div>
                                        <button type="button" class="profile-about-card__more is-hidden" data-about-toggle>Читати більше</button>
                                    @endif
                                </div>
                            @endif

                            @if ($profileServices->isNotEmpty())
                                <div class="profile-pro-block profile-pro-block--services-inline">
                                    <div class="profile-pro-section-head">
                                        <h3><i class="fa-solid fa-briefcase" aria-hidden="true"></i><span>Послуги</span></h3>
                                    </div>
                                    <div class="profile-services-grid profile-services-grid--inline">
                                        @foreach ($profileServices as $service)
                                            <span class="profile-service-chip">
                                                {{ $service['name'] }}
                                                @if ($service['price_from'] !== '')
                                                    <strong>від {{ $service['price_from'] }}</strong>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($experienceText !== '')
                                <div class="profile-pro-block">
                                    <div class="profile-pro-section-head">
                                        <h3><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span>Досвід та кваліфікація</span></h3>
                                    </div>
                                    <div class="profile-education-list">
                                        <article class="profile-education-item">
                                            <span class="profile-education-item__icon" data-seed="{{ ($profile['slug'] ?? $profile['name']) . '-experience' }}"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span>
                                            <div class="profile-education-item__content">
                                                <p>{!! nl2br(e(strip_tags($experienceText))) !!}</p>
                                            </div>
                                        </article>
                                    </div>
                                </div>
                            @endif

                            @if ($profile['pro'])
                                {{-- Блок «Переваги / чому обирають» прибрано: самопіар власника
                                     суперечить суті платформи відгуків. Реальні сильні сторони
                                     показує AI-аналіз відгуків, підтверджений клієнтами. --}}

                                @if ($proGallery->isNotEmpty())
                                    <div class="profile-pro-block">
                                        <div class="profile-pro-section-head">
                                            <h3><i class="fa-regular fa-image" aria-hidden="true"></i><span>Фото / галерея</span></h3>
                                            <button type="button" class="profile-pro-section-head__more profile-pro-section-head__more--button" data-open-profile-gallery>
                                                Переглянути всі фото
                                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="profile-pro-gallery">
                                            @foreach ($proGallery as $imageIndex => $image)
                                                <button type="button" class="profile-pro-gallery__item" data-open-profile-gallery data-gallery-index="{{ $imageIndex }}">
                                                    <span class="profile-pro-gallery__media">
                                                        <img src="{{ $image['thumb'] }}" alt="{{ $image['title'] }}" loading="lazy">
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($proGallery->isNotEmpty())
                                    <div class="profile-gallery-popup" data-profile-gallery-popup hidden>
                                        <div class="profile-gallery-popup__backdrop" data-profile-gallery-close></div>
                                        <div class="profile-gallery-popup__dialog" role="dialog" aria-modal="true" aria-label="Галерея фото профілю">
                                            <div class="profile-gallery-popup__top">
                                                <p class="profile-gallery-popup__title">Галерея фото</p>
                                                <span class="profile-gallery-popup__counter" data-profile-gallery-counter>1 / {{ $proGallery->count() }}</span>
                                            </div>
                                            <button type="button" class="profile-gallery-popup__close" aria-label="Закрити галерею" data-profile-gallery-close>
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>

                                            <div class="profile-gallery-popup__stage">
                                                <button type="button" class="profile-gallery-popup__nav profile-gallery-popup__nav--prev" aria-label="Попереднє фото" data-profile-gallery-prev>
                                                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                                </button>
                                                {{-- Стартовий src — мініатюра; повне фото JS підвантажить із data-gallery-url
                                                     при відкритті лайтбокса, щоб прихована галерея не тягла сотні КБ наперед. --}}
                                                <img src="{{ $proGallery->first()['thumb'] }}" alt="{{ $proGallery->first()['title'] }}" loading="lazy" data-profile-gallery-active-image>
                                                <button type="button" class="profile-gallery-popup__nav profile-gallery-popup__nav--next" aria-label="Наступне фото" data-profile-gallery-next>
                                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <div class="profile-gallery-popup__thumbs" data-profile-gallery-thumbs>
                                                @foreach ($proGallery as $imageIndex => $image)
                                                    <button
                                                        type="button"
                                                        class="profile-gallery-popup__thumb @if ($loop->first) is-active @endif"
                                                        data-profile-gallery-thumb
                                                        data-gallery-index="{{ $imageIndex }}"
                                                        data-gallery-url="{{ $image['url'] }}"
                                                        data-gallery-title="{{ $image['title'] }}"
                                                        aria-label="Відкрити фото {{ $imageIndex + 1 }}"
                                                        @if ($loop->first) aria-current="true" @endif
                                                    >
                                                        <img src="{{ $image['thumb'] }}" alt="{{ $image['title'] }}" loading="lazy">
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if ($proFaq->isNotEmpty())
                                    <div class="profile-pro-block">
                                        <div class="profile-pro-section-head">
                                            <h3><i class="fa-regular fa-circle-question" aria-hidden="true"></i><span>FAQ</span></h3>
                                        </div>
                                        <div class="profile-pro-faq faq">
                                            <div class="faq__list">
                                            @foreach ($proFaq as $faqIndex => $faqItem)
                                                <details class="faq__item @if($faqIndex === 0) is-open @endif" @if($faqIndex === 0) open @endif>
                                                    <summary class="faq__question" aria-expanded="{{ $faqIndex === 0 ? 'true' : 'false' }}">{{ $faqItem['q'] }}</summary>
                                                    <div class="faq__panel" @if($faqIndex === 0) style="height: auto;" @else style="height: 0px;" @endif>
                                                        <div class="faq__answer">{{ $faqItem['a'] }}</div>
                                                    </div>
                                                </details>
                                            @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                            @endif
                            {{-- «Базовий блок довіри» для не-PRO профілів прибрано:
                                 загальні фрази без конкретики не додавали цінності. --}}

                            {{-- Заявка тепер живе в попапі (кнопка «Залишити заявку»
                                 в контактах), канали звʼязку — в сайдбарі/мобільній
                                 картці. Тут лишається тільки гачок для незаявлених. --}}
                            {{-- Картку «Це ваш профіль?» (і її якір contacts-anchor)
                                 прибрано — owner-CTA на сторінці один: банер «Це ваш
                                 бізнес?» зверху. Скрол «Контакти» тепер веде на
                                 сайдбарну/мобільну картку контактів (фолбеки в JS). --}}
                        </section>
                    </section>

                    <section class="profile-tab-panel is-active" id="profile-tab-reviews" role="tabpanel" aria-labelledby="profile-tab-trigger-reviews" aria-hidden="false" data-profile-tab-panel="reviews">
                    @php
                        // Шкалу настрою показуємо лише коли відгуків достатньо для
                        // статистики (той самий поріг, що й для числової оцінки):
                        // «100% позитивних» з двох відгуків — шум, а не сигнал.
                        $showSentimentScale = $ratingVisible && $distributionTotal > 0;
                        $overviewHasAi = is_array($profile['ai_review_summary'] ?? null);
                        $overviewHasDossierTeaser = ! $overviewHasAi && $dossierHtml !== '';
                        // Без шкали, AI-аналізу й тизера досьє секція «Огляд відгуків»
                        // була б самим заголовком — тоді не показуємо її зовсім,
                        // одразу йде список відгуків нижче.
                        $hasReviewOverview = $showSentimentScale || $overviewHasAi || $overviewHasDossierTeaser;
                    @endphp
                    @if ($hasReviewOverview)
                    <section class="profile-card" id="summary">
                        <div class="profile-card__head">
                            <h2>Огляд відгуків</h2>
                        </div>
                        @php
                            $aiReviewPending = (($profile['ai_review_status'] ?? null) === 'pending') || (int) ($publishedReviewsTotal ?? 0) === 0;
                            // Новий аспектний AI-аналіз: {summary, aspects[], tags[]} з колонки
                            // ai_review_summary. Імен людей у ньому немає за побудовою.
                            $aiSummaryData = is_array($profile['ai_review_summary'] ?? null) ? $profile['ai_review_summary'] : null;

                            // Тизер досьє: коли AI-аналізу ще немає, порожній слот
                            // праворуч займає досьє — вердикт + перший абзац.
                            $summaryDossierTeaser = '';
                            if (! $aiSummaryData && $dossierHtml !== '') {
                                if (preg_match('/<p>(.+?)<\/p>/su', $dossierHtml, $dossierTeaserMatch)) {
                                    $summaryDossierTeaser = trim(html_entity_decode(strip_tags($dossierTeaserMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                                }
                                if ($summaryDossierTeaser === '') {
                                    $summaryDossierTeaser = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($dossierHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                                }
                                $summaryDossierTeaser = \Illuminate\Support\Str::limit($summaryDossierTeaser, 260, '…');
                            }

                            // Компактна шкала настрою (як у категоріях на головній):
                            // 4–5★ = позитив, 3★ = нейтрально, 1–2★ = негатив.
                            $scalePos = $five + $four;
                            $scaleNeu = $three;
                            $scaleNeg = $two + $one;
                            // Непорожній сегмент — мін. 3% ширини, потім нормалізація
                            // до 100% (заодно гасить огріхи округлення).
                            $scaleSegments = collect(['pos' => $scalePos, 'neu' => $scaleNeu, 'neg' => $scaleNeg])
                                ->map(fn ($value) => $value > 0 ? max(3.0, (float) $value) : 0.0);
                            $scaleSegmentsSum = $scaleSegments->sum();
                            if ($scaleSegmentsSum > 0) {
                                $scaleSegments = $scaleSegments->map(fn ($value) => round($value / $scaleSegmentsSum * 100, 2));
                            }
                            $aiAspects = ($aiSummaryData && is_array($aiSummaryData['aspects'] ?? null)) ? $aiSummaryData['aspects'] : [];
                            $aiTags = ($aiSummaryData && is_array($aiSummaryData['tags'] ?? null)) ? $aiSummaryData['tags'] : [];
                            $aiSentimentMeta = [
                                'positive' => ['icon' => 'fa-solid fa-thumbs-up', 'label' => 'Хвалять'],
                                'mixed' => ['icon' => 'fa-solid fa-scale-balanced', 'label' => 'Неоднозначно'],
                                'negative' => ['icon' => 'fa-solid fa-thumbs-down', 'label' => 'Звертають увагу'],
                            ];
                            $summaryText = (string) (($aiSummaryData['summary'] ?? null)
                                ?: ($aiReviewPending
                                    ? 'AI-аналіз ще не готовий. Після появи та обробки відгуків тут зʼявиться короткий аналіз сильних сторін і ризиків.'
                                    : 'AI-підсумок ще не згенеровано. Після обробки відгуків тут зʼявиться короткий аналіз сильних сторін і ризиків.'));
                            $summaryLines = collect(preg_split('/\r\n|\r|\n/', $summaryText) ?: [])
                                ->map(fn ($line) => trim((string) $line))
                                ->filter(fn ($line) => $line !== '')
                                ->values();
                            $positiveLines = collect();
                            $negativeLines = collect();
                            $fitLines = collect();
                            $introLines = collect();
                            $conclusionLine = null;
                            $flatPositiveLines = $summaryLines
                                ->filter(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '✅ '))
                                ->map(fn ($line) => trim((string) preg_replace('/^✅\s*/u', '', (string) $line)))
                                ->filter(fn ($line) => $line !== '')
                                ->values();
                            $flatNegativeLines = $summaryLines
                                ->filter(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '⚠️ '))
                                ->map(fn ($line) => trim((string) preg_replace('/^⚠️\s*/u', '', (string) $line)))
                                ->filter(fn ($line) => $line !== '')
                                ->values();
                            $flatFitLines = $summaryLines
                                ->filter(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '💡 '))
                                ->map(fn ($line) => trim((string) preg_replace('/^💡\s*/u', '', (string) $line)))
                                ->filter(fn ($line) => $line !== '')
                                ->values();
                            $hasFlatSummary = $flatPositiveLines->isNotEmpty() || $flatNegativeLines->isNotEmpty() || $flatFitLines->isNotEmpty();

                            if ($hasFlatSummary) {
                                $positiveLines = $flatPositiveLines;
                                $negativeLines = $flatNegativeLines;
                                $fitLines = $flatFitLines;
                            } else {
                                $summaryTextLower = $summaryLines->map(fn ($line) => mb_strtolower((string) $line))->values();
                                $positiveHeaderIndex = $summaryTextLower->search(fn ($line) => $line === 'переваги');
                                $negativeHeaderIndex = $summaryTextLower->search(fn ($line) => $line === 'недоліки');
                                $strengthHeaderIndex = $summaryLines->search(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '✅ ['));
                                $complaintHeaderIndex = $summaryLines->search(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '⚠️ ['));
                                $fitHeaderIndex = $summaryLines->search(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, '💡 ['));
                                $conclusionLine = $summaryLines
                                    ->first(fn ($line) => \Illuminate\Support\Str::startsWith(mb_strtolower($line), 'висновок:'));

                                if ($strengthHeaderIndex !== false) {
                                    $positiveStart = $strengthHeaderIndex + 1;
                                    $positiveLength = ($complaintHeaderIndex !== false && $complaintHeaderIndex > $strengthHeaderIndex)
                                        ? ($complaintHeaderIndex - $positiveStart)
                                        : (($fitHeaderIndex !== false && $fitHeaderIndex > $strengthHeaderIndex)
                                            ? ($fitHeaderIndex - $positiveStart)
                                            : null);
                                    $positiveLines = $summaryLines
                                        ->slice($positiveStart, $positiveLength)
                                        ->map(fn ($line) => trim((string) $line))
                                        ->filter(fn ($line) => $line !== '')
                                        ->values();
                                } elseif ($positiveHeaderIndex !== false) {
                                    $positiveStart = $positiveHeaderIndex + 1;
                                    $positiveLength = ($negativeHeaderIndex !== false && $negativeHeaderIndex > $positiveHeaderIndex)
                                        ? ($negativeHeaderIndex - $positiveStart)
                                        : null;
                                    $positiveLines = $summaryLines
                                        ->slice($positiveStart, $positiveLength)
                                        ->map(fn ($line) => trim((string) $line))
                                        ->filter(fn ($line) => $line !== '')
                                        ->values();
                                }
                                if ($complaintHeaderIndex !== false) {
                                    $negativeStart = $complaintHeaderIndex + 1;
                                    $negativeLength = ($fitHeaderIndex !== false && $fitHeaderIndex > $complaintHeaderIndex)
                                        ? ($fitHeaderIndex - $negativeStart)
                                        : null;
                                    $negativeLines = $summaryLines
                                        ->slice($negativeStart, $negativeLength)
                                        ->reject(fn ($line) => $conclusionLine !== null && $line === $conclusionLine)
                                        ->map(fn ($line) => trim((string) $line))
                                        ->filter(fn ($line) => $line !== '')
                                        ->values();
                                } elseif ($negativeHeaderIndex !== false) {
                                    $negativeStart = $negativeHeaderIndex + 1;
                                    $negativeLines = $summaryLines
                                        ->slice($negativeStart)
                                        ->reject(fn ($line) => $conclusionLine !== null && $line === $conclusionLine)
                                        ->map(fn ($line) => trim((string) $line))
                                        ->filter(fn ($line) => $line !== '')
                                        ->values();
                                }
                                if ($fitHeaderIndex !== false) {
                                    $fitStart = $fitHeaderIndex + 1;
                                    $fitLines = $summaryLines
                                        ->slice($fitStart)
                                        ->reject(fn ($line) => $conclusionLine !== null && $line === $conclusionLine)
                                        ->map(fn ($line) => trim((string) $line))
                                        ->filter(fn ($line) => $line !== '')
                                        ->values();
                                }

                                $stripBasedOnReviewsPrefix = function (string $line): string {
                                    $normalized = preg_replace(
                                        '/^на\s+основі\s+\d+\s+відгук(?:ів|и|а)?\s*[:\-–—]?\s*/iu',
                                        '',
                                        $line
                                    );

                                    return trim((string) $normalized);
                                };
                                $introLines = $summaryLines
                                    ->reject(fn ($line) => in_array(mb_strtolower((string) $line), ['переваги', 'недоліки'], true))
                                    ->reject(fn ($line) => \Illuminate\Support\Str::startsWith((string) $line, ['✅ [', '⚠️ [', '💡 [']))
                                    ->reject(fn ($line) => $positiveLines->contains($line) || $negativeLines->contains($line) || $fitLines->contains($line))
                                    ->reject(fn ($line) => $conclusionLine !== null && $line === $conclusionLine)
                                    ->map(fn ($line) => $stripBasedOnReviewsPrefix((string) $line))
                                    ->filter(fn ($line) => $line !== '')
                                    ->values();
                            }
                            $aiLeadLine = $hasFlatSummary
                                ? ''
                                : ($introLines->first() ?: 'AI-аналіз формується на основі публічних відгуків з різних джерел.');
                            $aiHighlights = $positiveLines->isNotEmpty()
                                ? $positiveLines->take(2)->values()
                                : collect((array) ($profile['ai_review_key_factors'] ?? []))
                                    ->map(fn ($line) => trim((string) $line))
                                    ->filter(fn ($line) => $line !== '')
                                    ->take(2)
                                    ->values();
                            $aiDetailIntro = $hasFlatSummary ? collect() : $introLines->slice(1)->values();
                            $expandedPositiveLines = $positiveLines
                                ->reject(fn ($line) => $aiHighlights->contains($line))
                                ->values();
                            $aiPeople = collect((array) ($profile['ai_review_people'] ?? []))
                                ->map(function ($person) {
                                    if (! is_array($person)) {
                                        return null;
                                    }

                                    $name = trim((string) ($person['name'] ?? ''));
                                    $role = trim((string) ($person['role'] ?? ''));
                                    $text = trim((string) ($person['text'] ?? ''));

                                    if ($name === '' || $text === '') {
                                        return null;
                                    }

                                    return [
                                        'name' => $name,
                                        'role' => $role,
                                        'text' => rtrim($text, ';'),
                                    ];
                                })
                                ->filter()
                                ->values();
                            $hasExpandedContent = $aiDetailIntro->isNotEmpty()
                                || $expandedPositiveLines->isNotEmpty()
                                || $negativeLines->isNotEmpty()
                                || $fitLines->isNotEmpty()
                                || $aiPeople->isNotEmpty()
                                || !empty($conclusionLine);
                        @endphp

                        <div class="profile-summary-overview">
                            {{-- Смуга настрою на всю ширину. Числову оцінку тут не
                                 дублюємо — вона вже двічі в hero; цінність цього
                                 блоку — розподіл позитив/негатив із контекстом обсягу.
                                 Показуємо лише з достатньою вибіркою (див. $showSentimentScale). --}}
                            @if ($showSentimentScale)
                                <div class="profile-summary-strip">
                                    <div class="profile-summary-strip__scale">
                                        <div class="profile-rating-scale" role="img" aria-label="{{ $scalePos }}% позитивних, {{ $scaleNeu }}% нейтральних, {{ $scaleNeg }}% негативних відгуків">
                                            <div class="profile-rating-scale__bar" aria-hidden="true">
                                                @if ($scaleSegments['pos'] > 0)<span class="profile-rating-scale__seg profile-rating-scale__seg--pos" style="width: {{ $scaleSegments['pos'] }}%"></span>@endif
                                                @if ($scaleSegments['neu'] > 0)<span class="profile-rating-scale__seg profile-rating-scale__seg--neu" style="width: {{ $scaleSegments['neu'] }}%"></span>@endif
                                                @if ($scaleSegments['neg'] > 0)<span class="profile-rating-scale__seg profile-rating-scale__seg--neg" style="width: {{ $scaleSegments['neg'] }}%"></span>@endif
                                            </div>
                                        </div>
                                        <div class="profile-rating-scale__legend">
                                            <span class="profile-rating-scale__legend-item profile-rating-scale__legend-item--pos"><b>{{ $scalePos }}%</b> позитивних</span>
                                            @if ($scaleNeu > 0)
                                                <span class="profile-rating-scale__legend-item profile-rating-scale__legend-item--neu"><b>{{ $scaleNeu }}%</b> нейтральних</span>
                                            @endif
                                            <span class="profile-rating-scale__legend-item profile-rating-scale__legend-item--neg"><b>{{ $scaleNeg }}%</b> негативних</span>
                                            <span class="profile-rating-scale__legend-count">з {{ $distributionTotal }} відгуків</span>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Нижній слот: AI-аналіз, а поки його немає — тизер досьє.
                                 Порожній плейсхолдер «ще не згенеровано» не показуємо. --}}
                            @if ($aiSummaryData)
                            <article class="profile-summary-panel profile-summary-panel--ai" data-ai-summary-placeholder>
                                <div class="profile-summary-panel__head">
                                    <h3 class="profile-summary-panel__title profile-summary-panel__title--ai">
                                        <i class="fa-solid fa-sparkles" aria-hidden="true"></i>
                                        <span>AI-аналіз відгуків</span>
                                    </h3>
                                    <span class="profile-summary-panel__chip">{{ $aiReviewPending ? 'Аналіз очікує відгуки' : 'На основі ' . $distributionTotal . ' відгуків' }}</span>
                                </div>
                                {{-- Контент кліпається JS-ом до висоти лівої панелі («Рейтинг і
                                     відгуки») і розгортається кнопкою нижче. --}}
                                <div class="profile-ai-summary__clamp" data-ai-collapse-body>
                                @if ($aiSummaryData)
                                    <p class="profile-summary-panel__lead">{{ $aiSummaryData['summary'] }}</p>

                                    @if (!empty($aiTags))
                                        <div class="profile-ai-summary__tags">
                                            @foreach (array_slice($aiTags, 0, 6) as $tag)
                                                <span class="profile-ai-summary__tag">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (!empty($aiAspects))
                                        <div class="profile-ai-summary__aspects">
                                            @foreach ($aiAspects as $aspect)
                                                @php $meta = $aiSentimentMeta[$aspect['sentiment'] ?? 'mixed'] ?? $aiSentimentMeta['mixed']; @endphp
                                                <div class="profile-ai-aspect profile-ai-aspect--{{ $aspect['sentiment'] ?? 'mixed' }}">
                                                    <span class="profile-ai-aspect__icon" title="{{ $meta['label'] }}">
                                                        <i class="{{ $meta['icon'] }}" aria-hidden="true"></i>
                                                    </span>
                                                    <span class="profile-ai-aspect__body">
                                                        <strong>{{ $aspect['topic'] ?? '' }}</strong>
                                                        @if (filled($aspect['note'] ?? null))
                                                            <span>{{ $aspect['note'] }}</span>
                                                        @endif
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                @else
                                    <p class="profile-summary-panel__lead">{{ $summaryText }}</p>
                                @endif
                                </div>
                                <button type="button" class="profile-ai-summary__toggle" data-ai-collapse-toggle hidden aria-expanded="false">
                                    <span data-ai-toggle-label>Показати повністю</span>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                @if ($hasDossier)
                                    <a class="profile-summary-ai__dossier-link" href="#profile-dossier" data-scroll-to="dossier">
                                        <i class="fa-solid fa-file-shield" aria-hidden="true"></i>
                                        <span>Повне досьє: факти з реєстрів, судів і ЗМІ</span>
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </article>
                            @elseif ($summaryDossierTeaser !== '')
                            <article class="profile-summary-panel profile-summary-panel--ai profile-summary-panel--dossier">
                                <div class="profile-summary-panel__head">
                                    <h3 class="profile-summary-panel__title profile-summary-panel__title--ai">
                                        <i class="fa-solid fa-file-shield" aria-hidden="true"></i>
                                        <span>Досьє</span>
                                    </h3>
                                    <span class="profile-summary-panel__chip">{{ $dossierUpdatedLabel ? 'Оновлено ' . $dossierUpdatedLabel : 'З відкритих джерел' }}</span>
                                </div>
                                @if ($dossierVerdict)
                                    <div class="profile-dossier__verdict profile-dossier__verdict--compact profile-dossier__verdict--{{ $dossierVerdict }}">
                                        <i class="fa-solid {{ $dossierVerdictMeta[$dossierVerdict]['icon'] }}" aria-hidden="true"></i>
                                        <span class="profile-dossier__verdict-label">{{ $dossierVerdictMeta[$dossierVerdict]['label'] }}</span>
                                        @if (filled($profile['dossier_verdict_note'] ?? null))
                                            <span class="profile-dossier__verdict-note">{{ $profile['dossier_verdict_note'] }}</span>
                                        @endif
                                    </div>
                                @endif
                                <p class="profile-summary-panel__lead">{{ $summaryDossierTeaser }}</p>
                                <a class="profile-summary-ai__dossier-link" href="#profile-dossier" data-scroll-to="dossier">
                                    <i class="fa-solid fa-file-shield" aria-hidden="true"></i>
                                    <span>Читати повне досьє</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </article>
                            @endif
                        </div>
                    </section>
                    @endif

                    <section class="profile-card" id="reviews">
                        @php
                            $initialReviewsLoaded = count($sampleReviews);
                            $reviewsTotal = (int) ($publishedReviewsTotal ?? $initialReviewsLoaded);
                            if ($reviewsTotal < $initialReviewsLoaded) {
                                $reviewsTotal = $initialReviewsLoaded;
                            }
                            $reviewsHasMore = $reviewsTotal > $initialReviewsLoaded;
                        @endphp
                        <div class="profile-card__head @if ($reviewsTotal > 0) has-reviews @endif">
                            <h2>Відгуки клієнтів</h2>
                        </div>
                        {{-- Фільтри/сортування без відгуків — контроли над порожнечею. --}}
                        @if ($reviewsTotal > 0)
                        <div class="profile-review-toolbar">
                            <div class="profile-review-filters">
                                <button type="button" class="profile-filter-chip is-active" data-review-filter="all">Усі</button>
                                <button type="button" class="profile-filter-chip" data-review-filter="positive">Позитивні</button>
                                <button type="button" class="profile-filter-chip" data-review-filter="negative">Негативні</button>
                            </div>
                            <select class="profile-select" aria-label="Сортування відгуків" data-review-sort>
                                <option value="newest">Нові спочатку</option>
                                <option value="relevant">За актуальністю</option>
                                <option value="highest">Найвищі</option>
                                <option value="lowest">Найнижчі</option>
                            </select>
                        </div>
                        <div class="profile-mobile-review-controls" aria-label="Керування відгуками">
                            <button type="button" class="profile-mobile-review-control" data-review-open-filter-sheet aria-expanded="false" aria-controls="profile-mobile-review-filter-sheet">
                                <i class="fa-solid fa-comments" aria-hidden="true"></i>
                                <span data-review-mobile-filter-label>Усі</span>
                            </button>
                            <button type="button" class="profile-mobile-review-control" data-review-open-sort-sheet aria-expanded="false" aria-controls="profile-mobile-review-sort-sheet">
                                <i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i>
                                <span data-review-mobile-sort-label>Нові спочатку</span>
                            </button>
                        </div>
                        <div class="profile-mobile-review-sheets" aria-hidden="false">
                            <details id="profile-mobile-review-filter-sheet" class="profile-mobile-review-sheet">
                                <summary class="sr-only">Показувати відгуки</summary>
                                <button type="button" class="profile-mobile-review-sheet__backdrop" data-review-close-filter-sheet aria-label="Закрити вибір відгуків"></button>
                                <div class="profile-mobile-review-sheet__panel">
                                    <div class="profile-mobile-review-sheet__dialog">
                                        <div class="profile-mobile-review-sheet__handle" aria-hidden="true"></div>
                                        <header class="profile-mobile-review-sheet__head">
                                            <h3>Показувати <i class="fa-solid fa-comments" aria-hidden="true"></i></h3>
                                            <button type="button" class="profile-mobile-review-sheet__close" data-review-close-filter-sheet aria-label="Закрити">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </header>
                                        <div class="profile-mobile-review-sheet__body">
                                            <div class="profile-mobile-review-options">
                                                <button type="button" class="profile-mobile-review-option is-active" data-review-mobile-filter-option="all">
                                                    <span>Усі</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="profile-mobile-review-option" data-review-mobile-filter-option="positive">
                                                    <span>Позитивні</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="profile-mobile-review-option" data-review-mobile-filter-option="negative">
                                                    <span>Негативні</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </details>
                            <details id="profile-mobile-review-sort-sheet" class="profile-mobile-review-sheet">
                                <summary class="sr-only">Сортування відгуків</summary>
                                <button type="button" class="profile-mobile-review-sheet__backdrop" data-review-close-sort-sheet aria-label="Закрити сортування"></button>
                                <div class="profile-mobile-review-sheet__panel">
                                    <div class="profile-mobile-review-sheet__dialog">
                                        <div class="profile-mobile-review-sheet__handle" aria-hidden="true"></div>
                                        <header class="profile-mobile-review-sheet__head">
                                            <h3>Сортування <i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i></h3>
                                            <button type="button" class="profile-mobile-review-sheet__close" data-review-close-sort-sheet aria-label="Закрити">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </header>
                                        <div class="profile-mobile-review-sheet__body">
                                            <div class="profile-mobile-review-options">
                                                <button type="button" class="profile-mobile-review-option is-active" data-review-mobile-sort-option="newest">
                                                    <span>Нові спочатку</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="profile-mobile-review-option" data-review-mobile-sort-option="relevant">
                                                    <span>За актуальністю</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="profile-mobile-review-option" data-review-mobile-sort-option="highest">
                                                    <span>Найвищі</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="profile-mobile-review-option" data-review-mobile-sort-option="lowest">
                                                    <span>Найнижчі</span>
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        </div>
                        @endif
                        @if (session('review_submitted'))
                            <div class="profile-review-notice" role="status">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                <span>{{ session('review_submitted') }}</span>
                            </div>
                        @endif

                        <div
                            class="profile-reviews"
                            data-profile-reviews
                            data-reviews-slug="{{ $profile['slug'] }}"
                            data-reviews-loaded="{{ $initialReviewsLoaded }}"
                            data-reviews-total="{{ $reviewsTotal }}"
                            data-reviews-positive-count="{{ (int) ($profile['reviews_positive_count'] ?? 0) }}"
                            data-reviews-negative-count="{{ (int) ($profile['reviews_negative_count'] ?? 0) }}"
                            data-reviews-next-offset="{{ $initialReviewsLoaded }}"
                            data-reviews-batch-size="10"
                            data-review-authenticated="{{ auth()->check() ? '1' : '0' }}"
                            data-review-login-url="{{ route('login', ['next' => request()->fullUrl()]) }}"
                            data-review-csrf="{{ csrf_token() }}"
                            data-review-current-user-name="{{ $commentAuthorName }}"
                            data-review-current-user-avatar-url="{{ $commentAuthorAvatarUrl }}"
                            data-review-current-user-initial="{{ $commentAuthorInitial }}"
                        >
                            @foreach ($sampleReviews as $review)
                                @php
                                    $reviewFull = (int) floor($review['rating']);
                                    $reviewHalf = ($review['rating'] - $reviewFull) >= 0.5;
                                    $reviewEmpty = 5 - $reviewFull - ($reviewHalf ? 1 : 0);
                                    $reviewReplies = collect($review['replies'] ?? []);
                                @endphp
                                <article class="profile-review" data-review-id="{{ $review['id'] }}" data-review-author="{{ $review['author'] }}" data-review-rating="{{ (int) floor((float) $review['rating']) }}" data-review-index="{{ $loop->index }}" data-review-date="{{ $review['date_iso'] ?? ($review['date'] ?? '') }}">
                                    <div class="profile-review__top">
                                        <div class="profile-review__author">
                                            <div class="profile-review__avatar review-list-card__logo {{ empty($review['avatar_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $review['author'] }}">
                                                @if (!empty($review['avatar_url']))
                                                    <img src="{{ $review['avatar_url'] }}" alt="{{ $review['author'] }}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)">
                                                    <span class="review-list-card__fallback" aria-hidden="true">{{ $review['avatar'] ?? 'К' }}</span>
                                                @else
                                                    <span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">{{ $review['avatar'] ?? 'К' }}</span>
                                                @endif
                                            </div>
                                            <strong>{{ $review['author'] }}</strong>
                                        </div>
                                        <time>{{ $review['date'] }}</time>
                                    </div>
                                    <div class="profile-review__rating">
                                        <div class="rating-stars {{ $ratingToneClass((float) $review['rating']) }}">
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
                                        @if (!empty($review['external_source_label']))
                                            @php
                                                $sourceIcon = in_array(($review['external_source_type'] ?? ''), ['google_maps', 'google', 'google_business'], true)
                                                    ? 'fa-brands fa-google'
                                                    : 'fa-solid fa-link';
                                            @endphp
                                            {{-- Source is shown as a plain label — never a link (no outbound
                                                 redirects to top20.ua / external sources from our site). --}}
                                            <span class="profile-review__source-badge">
                                                <i class="{{ $sourceIcon }}" aria-hidden="true"></i>
                                                <span>{{ $review['external_source_label'] }}</span>
                                            </span>
                                        @endif
                                    </div>
                                    @if (!empty($review['title']))
                                        <p class="profile-review__title">{{ $review['title'] }}</p>
                                    @endif
                                    @if (trim((string) ($review['text'] ?? '')) !== '')
                                        <p>{{ $review['text'] }}</p>
                                    @endif
                                    @if (!empty($review['media']))
                                        <div class="profile-review__media">
                                            @foreach ($review['media'] as $mediaUrl)
                                                @php
                                                    $isVideo = str_contains((string) $mediaUrl, '.mp4')
                                                        || str_contains((string) $mediaUrl, '.webm')
                                                        || str_contains((string) $mediaUrl, '.mov');
                                                @endphp
                                                @if ($isVideo)
                                                    <video controls preload="metadata" src="{{ $mediaUrl }}"></video>
                                                @else
                                                    <a href="{{ $mediaUrl }}" target="_blank" rel="noopener noreferrer">
                                                        <img src="{{ $mediaUrl }}" alt="Вкладення до відгуку" loading="lazy">
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ((int) ($review['id'] ?? 0) > 0)
                                        @php
                                            $publicReviewRepliesCount = $reviewReplies->filter(fn ($reply) => empty($reply['is_official']))->count();
                                        @endphp
                                        <div class="profile-review__engagement" data-review-engagement>
                                            <div class="profile-review__actions" role="group" aria-label="Дії з відгуком">
                                                <button type="button" class="profile-review__reaction-btn {{ ($review['user_reaction'] ?? null) === 'like' ? 'is-active' : '' }}" data-review-reaction="like">
                                                    <span class="profile-review__reaction-icon" aria-hidden="true"><i class="fa-regular fa-thumbs-up"></i></span>
                                                    <span class="sr-only">Позитивна реакція</span>
                                                    <strong data-review-like-count>{{ (int) ($review['like_count'] ?? 0) }}</strong>
                                                </button>
                                                <button type="button" class="profile-review__reaction-btn {{ ($review['user_reaction'] ?? null) === 'dislike' ? 'is-active' : '' }}" data-review-reaction="dislike">
                                                    <span class="profile-review__reaction-icon" aria-hidden="true"><i class="fa-regular fa-thumbs-down"></i></span>
                                                    <span class="sr-only">Негативна реакція</span>
                                                    <strong data-review-dislike-count>{{ (int) ($review['dislike_count'] ?? 0) }}</strong>
                                                </button>
                                                <button type="button" class="profile-review__comments-add-action" data-review-root-toggle>
                                                    <i class="fa-regular fa-comment" aria-hidden="true"></i>
                                                    <span>Відповісти</span>
                                                </button>
                                                <button type="button" class="profile-review__report-action" data-review-report>
                                                    <i class="fa-regular fa-flag" aria-hidden="true"></i>
                                                    <span>Поскаржитися</span>
                                                </button>
                                            </div>
                                            <div class="profile-review__comments @if($reviewReplies->isEmpty()) is-hidden @endif" data-review-comments>
                                                <div class="profile-review__comments-list" data-review-comments-list>
                                                    @foreach ($reviewReplies as $reply)
                                                        <div class="profile-review-comment @if(!empty($reply['is_official'])) profile-review-comment--official @else profile-review-comment--user is-collapsed @endif" data-review-comment-id="{{ $reply['id'] }}" data-review-comment-author="{{ $reply['author'] }}">
                                                            <div class="profile-review-comment__top">
                                                                <div class="profile-review-comment__author">
                                                                    <div class="profile-review-comment__avatar review-list-card__logo {{ empty($reply['avatar_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $reply['author'] }}">
                                                                        @if (!empty($reply['avatar_url']))
                                                                            <img src="{{ $reply['avatar_url'] }}" alt="{{ $reply['author'] }}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)">
                                                                            <span class="review-list-card__fallback" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($reply['author'] ?? 'К', 0, 1)) }}</span>
                                                                        @else
                                                                            <span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($reply['author'] ?? 'К', 0, 1)) }}</span>
                                                                        @endif
                                                                    </div>
                                                                    <div>
                                                                        <div class="profile-review-comment__name-row">
                                                                            <strong>{{ $reply['author'] }}</strong>
                                                                            @if (!empty($reply['is_official']))
                                                                                <span class="profile-review-comment__badge"><i class="fa-solid fa-shield-check" aria-hidden="true"></i> Офіційна відповідь</span>
                                                                            @endif
                                                                        </div>
                                                                        <time datetime="{{ $reply['date_iso'] ?? '' }}">{{ $reply['date'] }}</time>
                                                                    </div>
                                                                </div>
                                                                <button type="button" class="profile-review-comment__menu" aria-label="Додаткові дії">
                                                                    <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                                </button>
                                                            </div>
                                                            @php
                                                                $replyTextHtml = preg_replace(
                                                                    '/(^|\\s)(@[\\p{L}\\p{N}_.-]+)/u',
                                                                    '$1<span class="profile-review-comment__mention">$2</span>',
                                                                    e((string) $reply['text'])
                                                                );
                                                            @endphp
                                                            <p>{!! nl2br($replyTextHtml ?? e((string) $reply['text'])) !!}</p>
                                                            <div class="profile-review-comment__actions" role="group" aria-label="Дії з коментарем">
                                                                @if (!empty($reply['is_official']))
                                                                    <button type="button" class="profile-review-comment__reaction-btn {{ ($reply['user_reaction'] ?? null) === 'like' ? 'is-active' : '' }}" data-review-official-reaction="like">
                                                                        <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>
                                                                        <span data-review-comment-like-count>{{ (int) ($reply['like_count'] ?? 0) }}</span>
                                                                    </button>
                                                                    <button type="button" class="profile-review-comment__reaction-btn {{ ($reply['user_reaction'] ?? null) === 'dislike' ? 'is-active' : '' }}" data-review-official-reaction="dislike">
                                                                        <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i>
                                                                        <span data-review-comment-dislike-count>{{ (int) ($reply['dislike_count'] ?? 0) }}</span>
                                                                    </button>
                                                                    <button type="button" class="profile-review-comment__reply-btn" data-review-root-toggle data-review-reply-author="{{ $reply['author'] }}">
                                                                        <i class="fa-regular fa-comment" aria-hidden="true"></i>
                                                                        <span>Відповісти</span>
                                                                    </button>
                                                                @else
                                                                    <button type="button" class="profile-review-comment__reaction-btn {{ ($reply['user_reaction'] ?? null) === 'like' ? 'is-active' : '' }}" data-review-comment-reaction="like">
                                                                        <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>
                                                                        <span data-review-comment-like-count>{{ (int) ($reply['like_count'] ?? 0) }}</span>
                                                                    </button>
                                                                    <button type="button" class="profile-review-comment__reaction-btn {{ ($reply['user_reaction'] ?? null) === 'dislike' ? 'is-active' : '' }}" data-review-comment-reaction="dislike">
                                                                        <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i>
                                                                        <span data-review-comment-dislike-count>{{ (int) ($reply['dislike_count'] ?? 0) }}</span>
                                                                    </button>
                                                                    <button type="button" class="profile-review-comment__reply-btn" data-review-reply-toggle="{{ $reply['id'] }}" data-review-reply-author="{{ $reply['author'] }}">
                                                                        <i class="fa-regular fa-comment" aria-hidden="true"></i>
                                                                        <span>Відповісти</span>
                                                                    </button>
                                                                @endif
                                                            </div>

                                                            @foreach (($reply['children'] ?? []) as $childReply)
                                                                <div class="profile-review-comment profile-review-comment--child" data-review-comment-id="{{ $childReply['id'] }}" data-review-comment-author="{{ $childReply['author'] }}">
                                                                    <div class="profile-review-comment__top">
                                                                        <div class="profile-review-comment__author">
                                                                            <div class="profile-review-comment__avatar review-list-card__logo {{ empty($childReply['avatar_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $childReply['author'] }}">
                                                                                @if (!empty($childReply['avatar_url']))
                                                                                    <img src="{{ $childReply['avatar_url'] }}" alt="{{ $childReply['author'] }}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)">
                                                                                    <span class="review-list-card__fallback" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($childReply['author'] ?? 'К', 0, 1)) }}</span>
                                                                                @else
                                                                                    <span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($childReply['author'] ?? 'К', 0, 1)) }}</span>
                                                                                @endif
                                                                            </div>
                                                                            <div>
                                                                                <div class="profile-review-comment__name-row">
                                                                                    <strong>{{ $childReply['author'] }}</strong>
                                                                                </div>
                                                                                <time datetime="{{ $childReply['date_iso'] ?? '' }}">{{ $childReply['date'] }}</time>
                                                                            </div>
                                                                        </div>
                                                                        <button type="button" class="profile-review-comment__menu" aria-label="Додаткові дії">
                                                                            <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                                        </button>
                                                                    </div>
                                                                    @php
                                                                        $childReplyTextHtml = preg_replace(
                                                                            '/(^|\\s)(@[\\p{L}\\p{N}_.-]+)/u',
                                                                            '$1<span class="profile-review-comment__mention">$2</span>',
                                                                            e((string) $childReply['text'])
                                                                        );
                                                                    @endphp
                                                                    <p>{!! nl2br($childReplyTextHtml ?? e((string) $childReply['text'])) !!}</p>
                                                                    <div class="profile-review-comment__actions" role="group" aria-label="Дії з коментарем">
                                                                        <button type="button" class="profile-review-comment__reaction-btn {{ ($childReply['user_reaction'] ?? null) === 'like' ? 'is-active' : '' }}" data-review-comment-reaction="like">
                                                                            <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>
                                                                            <span data-review-comment-like-count>{{ (int) ($childReply['like_count'] ?? 0) }}</span>
                                                                        </button>
                                                                        <button type="button" class="profile-review-comment__reaction-btn {{ ($childReply['user_reaction'] ?? null) === 'dislike' ? 'is-active' : '' }}" data-review-comment-reaction="dislike">
                                                                            <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i>
                                                                            <span data-review-comment-dislike-count>{{ (int) ($childReply['dislike_count'] ?? 0) }}</span>
                                                                        </button>
                                                                        <button type="button" class="profile-review-comment__reply-btn" data-review-reply-toggle="{{ $reply['id'] }}" data-review-reply-author="{{ $childReply['author'] }}">
                                                                            <i class="fa-regular fa-comment" aria-hidden="true"></i>
                                                                            <span>Відповісти</span>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                            @auth
                                                            @if (empty($reply['is_system']))
                                                                <form class="profile-review-comment-form is-hidden" data-review-comment-form data-review-parent-id="{{ $reply['id'] }}">
                                                                    <div class="profile-review-comment-form__body">
                                                                        <div class="profile-review-comment-form__head">
                                                                            <span class="profile-review-comment-form__title" data-review-comment-title>Відповісти на {{ $reply['author'] }}</span>
                                                                            <button type="button" class="profile-review-comment-form__close" data-review-comment-cancel aria-label="Закрити форму відповіді">
                                                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                            </button>
                                                                        </div>
                                                                        <div class="profile-review-comment-form__composer">
                                                                            <textarea name="body" rows="2" placeholder="Напишіть щось..."></textarea>
                                                                        </div>
                                                                        <div class="profile-review-comment-form__actions">
                                                                            <span class="profile-review-comment-form__meta" data-review-comment-meta>Коментар від 10 символів</span>
                                                                            <button type="submit" class="btn btn--primary" disabled>
                                                                                <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                                                                                <span>Додати коментар</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </form>
                                                            @endif
                                                            @endauth
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <div class="profile-review__comments-footer">
                                                    @if ($publicReviewRepliesCount > 0)
                                                        <button type="button" class="profile-review__comments-more" data-review-comments-more>
                                                            <span>Показати коментарі ({{ $publicReviewRepliesCount }})</span>
                                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                                @auth
                                                    <form class="profile-review-comment-form profile-review-comment-form--root is-hidden" data-review-comment-form>
                                                        <div class="profile-review-comment-form__body">
                                                            <div class="profile-review-comment-form__head">
                                                                <span class="profile-review-comment-form__title" data-review-comment-title>Відповісти на {{ $review['author'] }}</span>
                                                                <button type="button" class="profile-review-comment-form__close" data-review-comment-cancel aria-label="Закрити форму коментаря">
                                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                </button>
                                                            </div>
                                                            <div class="profile-review-comment-form__composer">
                                                                <textarea name="body" rows="2" placeholder="Напишіть щось..."></textarea>
                                                            </div>
                                                            <div class="profile-review-comment-form__actions">
                                                                <span class="profile-review-comment-form__meta" data-review-comment-meta>Коментар від 10 символів</span>
                                                                <button type="submit" class="btn btn--primary" disabled>
                                                                    <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                                                                    <span>Додати коментар</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                @endauth
                                            </div>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                        {{-- Loading skeletons for AJAX fetches — pointless on a profile with
                             zero reviews (nothing will ever load), so don't render them there. --}}
                        @if ($reviewsTotal > 0)
                        <div class="profile-reviews-skeleton is-hidden" data-reviews-skeleton aria-hidden="true">
                            @for ($i = 0; $i < 3; $i++)
                                <article class="profile-review profile-review--skeleton">
                                    <div class="profile-review--skeleton__head">
                                        <span class="profile-review--skeleton__avatar"></span>
                                        <div class="profile-review--skeleton__meta">
                                            <span class="profile-review--skeleton__line line-lg"></span>
                                            <span class="profile-review--skeleton__line line-sm"></span>
                                        </div>
                                    </div>
                                    <span class="profile-review--skeleton__line line-md"></span>
                                    <span class="profile-review--skeleton__line line-full"></span>
                                    <span class="profile-review--skeleton__line line-full"></span>
                                    <span class="profile-review--skeleton__line line-half"></span>
                                </article>
                            @endfor
                        </div>
                        @endif
                        <div class="profile-reviews-empty" data-reviews-empty @if ($initialReviewsLoaded > 0) hidden @endif>
                            <p class="profile-reviews-empty__text">
                                <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
                                <span data-reviews-empty-text>
                                    @if ($initialReviewsLoaded > 0)
                                        За цим фільтром відгуків немає.
                                    @else
                                        Тут ще немає відгуків. Поділіться досвідом — залиште перший відгук про цей профіль.
                                    @endif
                                </span>
                            </p>
                            {{-- CTA only for the "no reviews at all" case; JS hides it when the
                                 empty state is re-used for filter results. --}}
                            <button
                                type="button"
                                class="btn btn--primary profile-reviews-empty__cta"
                                data-reviews-empty-cta
                                data-open-review-popup
                                data-profile-slug="{{ $profile['slug'] }}"
                                data-profile-name="{{ $profile['name'] }}"
                                @if ($initialReviewsLoaded > 0) hidden @endif
                            >
                                <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                                <span>Залишити перший відгук</span>
                            </button>
                        </div>
                        <div class="profile-reviews-loadbar" @if ($initialReviewsLoaded === 0) hidden @endif>
                            <p class="profile-reviews-count" data-reviews-count>
                                Показано {{ $initialReviewsLoaded }} з {{ $reviewsTotal }}
                            </p>
                            <button
                                type="button"
                                class="btn btn--profile-contact profile-reviews-load-more @if(!$reviewsHasMore) is-hidden @endif"
                                data-reviews-load-more
                            >
                                Показати ще
                            </button>
                        </div>
                        <div class="profile-reviews-sentinel" data-reviews-sentinel aria-hidden="true"></div>

                        <div class="profile-reviews-mobile-extras" aria-label="Додаткові контакти профілю">
                            <div class="profile-side-card" id="profile-mobile-contacts">
                                <h3>Контакти</h3>
                                @include('static.partials.profile-contact-actions')
                            </div>

                            {{-- Мобільний дубль «Це ваш профіль?» прибрано — owner-CTA
                                 на сторінці один: банер «Це ваш бізнес?» зверху. --}}
                        </div>
                    </section>
                    </section>

                </div>

                <aside class="profile-side">
                    <div class="profile-side-card profile-side-card--contacts-desktop" id="profile-contacts-aside-anchor">
                        <h3>Контакти</h3>
                        @include('static.partials.profile-contact-actions')
                        <a class="profile-side-card__claim-link" href="{{ $claimProfileUrl }}">Володієте цією компанією?</a>
                    </div>
                    {{-- Картку «Маєте свій бізнес?» прибрано: на сторінці профілю
                         лишається один owner-CTA (банер «Це ваш бізнес?» зверху)
                         + тихий лінк «Володієте цією компанією?» в контактах. --}}

                </aside>
            </div>

            <section class="section best-lawyers" aria-labelledby="profile-related-title">
                <div class="home-intents__head">
                    <h2 id="profile-related-title" class="h2 home-section-title">Dovira рекомендує</h2>
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
                        @include('static.partials.profile-card-catalog', ['profile' => $related, 'profileUrl' => $relatedProfileUrl])
                    @endforeach
                </div>
            </section>
        </div>
    </section>
@endsection

@push('scripts')
@include('static.partials.profile-lead-popup', ['profile' => $profile])

<script>
(() => {
    const root = document.querySelector('.profile-page');
    if (!root) return;

    const heroTitle = root.querySelector('.profile-hero__title');
    if (heroTitle) {
        const heroTitleBaseSize = parseFloat(window.getComputedStyle(heroTitle).fontSize) || 22;
        const fitHeroTitle = () => {
            const isMobile = window.matchMedia('(max-width: 760px)').matches;

            if (!isMobile) {
                heroTitle.style.removeProperty('white-space');
                heroTitle.style.removeProperty('font-size');
                return;
            }

            heroTitle.style.whiteSpace = 'nowrap';
            heroTitle.style.fontSize = `${heroTitleBaseSize}px`;

            const fitTolerance = 3;

            if (heroTitle.scrollWidth <= heroTitle.clientWidth + fitTolerance) {
                return;
            }

            let low = 12;
            let high = heroTitleBaseSize;
            let best = low;

            for (let i = 0; i < 14; i += 1) {
                const mid = (low + high) / 2;
                heroTitle.style.fontSize = `${mid}px`;
                if (heroTitle.scrollWidth <= heroTitle.clientWidth + fitTolerance) {
                    best = mid;
                    low = mid + 0.1;
                } else {
                    high = mid - 0.1;
                }
            }

            heroTitle.style.fontSize = `${Math.max(12, Math.min(heroTitleBaseSize, best)).toFixed(2)}px`;
        };

        requestAnimationFrame(fitHeroTitle);
        window.addEventListener('resize', fitHeroTitle, { passive: true });
        window.addEventListener('pageshow', fitHeroTitle);
    }

    const triggers = Array.from(root.querySelectorAll('[data-profile-tab-trigger]'));
    const panels = Array.from(root.querySelectorAll('[data-profile-tab-panel]'));
            const setTab = (tab) => {
                triggers.forEach((btn) => {
                    const active = btn.dataset.profileTabTrigger === tab;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach((panel) => {
                    const active = panel.dataset.profileTabPanel === tab;
                    panel.classList.toggle('is-active', active);
                    panel.setAttribute('aria-hidden', active ? 'false' : 'true');
                });
                // Подія воронки: відкрили вкладку «Інформація» (будь-яким шляхом).
                if (tab === 'info') window.doviraProfileTrack?.('info_tab_view');
            };

    triggers.forEach((btn) => {
        btn.addEventListener('click', () => setTab(btn.dataset.profileTabTrigger || 'info'));
    });

    const tabJumpButtons = Array.from(root.querySelectorAll('[data-profile-tab-jump]'));
    tabJumpButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const targetTab = button.dataset.profileTabJump || 'info';
            if (!panels.some((panel) => panel.dataset.profileTabPanel === targetTab)) return;
            setTab(targetTab);
        });
    });

    // Подія воронки: досьє фактично потрапило у в'юпорт (переглянув досьє).
    const dossierBlock = document.querySelector('#profile-dossier');
    if (dossierBlock && 'IntersectionObserver' in window) {
        const dossierIO = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    window.doviraProfileTrack?.('dossier_view');
                    dossierIO.disconnect();
                }
            });
        }, { threshold: 0.2 });
        dossierIO.observe(dossierBlock);
    }

    const scrollButtons = Array.from(root.querySelectorAll('[data-scroll-to]'));
    scrollButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            const scrollTarget = button.getAttribute('data-scroll-to');
            if (!scrollTarget) return;

            event.preventDefault();

            const findVisibleElement = (selectors) => {
                const isVisible = (node) => !!node && node.getClientRects().length > 0 && window.getComputedStyle(node).display !== 'none';
                for (const selector of selectors) {
                    const node = document.querySelector(selector);
                    if (isVisible(node)) return node;
                }
                for (const selector of selectors) {
                    const node = document.querySelector(selector);
                    if (node) return node;
                }
                return null;
            };

            let target = null;
            if (scrollTarget === 'contacts') {
                setTab('info');
                target = findVisibleElement(['#profile-contacts-anchor', '#profile-contacts-aside-anchor', '#profile-mobile-contacts']);
            } else if (scrollTarget === 'reviews') {
                setTab('reviews');
                target = findVisibleElement(['#reviews']);
            } else if (scrollTarget === 'dossier') {
                setTab('info');
                // Подія воронки: натиснув «Досьє» (намір відкрити досьє).
                window.doviraProfileTrack?.('dossier_open');
                target = findVisibleElement(['#profile-dossier']);
            } else {
                target = document.querySelector(scrollTarget);
            }

            window.requestAnimationFrame(() => {
                if (!target) return;
                const header = document.querySelector('.header, .site-header, .main-header, header');
                const headerRect = header?.getBoundingClientRect();
                const headerOffset = headerRect?.height ? headerRect.height + 12 : 66;
                const bodyStyle = window.getComputedStyle(document.body);
                const bodyScrolls = document.body.scrollHeight > document.body.clientHeight + 5
                    && bodyStyle.overflowY !== 'visible';
                const scrollRoot = bodyScrolls ? document.body : (document.scrollingElement || document.documentElement);
                const currentTop = scrollRoot.scrollTop || window.pageYOffset;
                const scrollTop = target.getBoundingClientRect().top + currentTop - headerOffset;
                scrollRoot.scrollTo({ top: Math.max(0, scrollTop), behavior: 'smooth' });
            });
        });
    });

    const initMediaPopup = ({
        popupSelector,
        activeImageSelector,
        thumbSelector,
        openSelector,
        closeSelector,
        prevSelector,
        nextSelector,
        counterSelector,
        thumbUrlAttr,
        thumbTitleAttr,
        openIndexAttr,
        fallbackTitle,
    }) => {
        const popup = root.querySelector(popupSelector);
        if (!popup) return;
        if (popup.parentElement !== document.body) {
            document.body.appendChild(popup);
        }

        const activeImage = popup.querySelector(activeImageSelector);
        const thumbs = Array.from(popup.querySelectorAll(thumbSelector));
        const openButtons = Array.from(root.querySelectorAll(openSelector));
        const closeButtons = Array.from(popup.querySelectorAll(closeSelector));
        const prevButton = popup.querySelector(prevSelector);
        const nextButton = popup.querySelector(nextSelector);
        const counter = counterSelector ? popup.querySelector(counterSelector) : null;
        const totalItems = thumbs.length;
        if (!activeImage || !totalItems) return;

        let activeIndex = 0;

        const setActive = (index) => {
            const normalizedIndex = ((index % totalItems) + totalItems) % totalItems;
            activeIndex = normalizedIndex;
            const activeThumb = thumbs[normalizedIndex];
            if (!activeThumb) return;

            const imageUrl = activeThumb.getAttribute(thumbUrlAttr) || '';
            const imageTitle = activeThumb.getAttribute(thumbTitleAttr) || `${fallbackTitle} ${normalizedIndex + 1}`;
            if (!imageUrl) return;

            activeImage.src = imageUrl;
            activeImage.alt = imageTitle;
            if (counter) {
                counter.textContent = `${normalizedIndex + 1} / ${totalItems}`;
            }

            thumbs.forEach((thumb, thumbIndex) => {
                const isActive = thumbIndex === normalizedIndex;
                thumb.classList.toggle('is-active', isActive);
                if (isActive) {
                    thumb.setAttribute('aria-current', 'true');
                    thumb.scrollIntoView({ block: 'nearest', inline: 'center' });
                } else {
                    thumb.removeAttribute('aria-current');
                }
            });
        };

        const openPopup = (index = 0) => {
            setActive(index);
            popup.hidden = false;
            document.body.classList.add('is-profile-gallery-open');
        };

        const closePopup = () => {
            popup.hidden = true;
            document.body.classList.remove('is-profile-gallery-open');
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const rawIndex = Number(button.getAttribute(openIndexAttr) || 0);
                const index = Number.isFinite(rawIndex) ? rawIndex : 0;
                openPopup(index);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closePopup);
        });

        thumbs.forEach((thumb, thumbIndex) => {
            thumb.addEventListener('click', () => setActive(thumbIndex));
        });

        prevButton?.addEventListener('click', () => setActive(activeIndex - 1));
        nextButton?.addEventListener('click', () => setActive(activeIndex + 1));

        document.addEventListener('keydown', (event) => {
            if (popup.hidden) return;
            if (event.key === 'Escape') {
                closePopup();
                return;
            }
            if (event.key === 'ArrowLeft') {
                setActive(activeIndex - 1);
                return;
            }
            if (event.key === 'ArrowRight') {
                setActive(activeIndex + 1);
            }
        });
    };

    initMediaPopup({
        popupSelector: '[data-profile-gallery-popup]',
        activeImageSelector: '[data-profile-gallery-active-image]',
        thumbSelector: '[data-profile-gallery-thumb]',
        openSelector: '[data-open-profile-gallery]',
        closeSelector: '[data-profile-gallery-close]',
        prevSelector: '[data-profile-gallery-prev]',
        nextSelector: '[data-profile-gallery-next]',
        counterSelector: '[data-profile-gallery-counter]',
        thumbUrlAttr: 'data-gallery-url',
        thumbTitleAttr: 'data-gallery-title',
        openIndexAttr: 'data-gallery-index',
        fallbackTitle: 'Фото',
    });

    const filterButtons = Array.from(root.querySelectorAll('[data-review-filter]'));
    const sortSelect = root.querySelector('[data-review-sort]');
    const mobileReviewFilterToggle = root.querySelector('[data-review-open-filter-sheet]');
    const mobileReviewSortToggle = root.querySelector('[data-review-open-sort-sheet]');
    const mobileReviewFilterLabel = root.querySelector('[data-review-mobile-filter-label]');
    const mobileReviewSortLabel = root.querySelector('[data-review-mobile-sort-label]');
    const mobileReviewFilterSheet = root.querySelector('#profile-mobile-review-filter-sheet');
    const mobileReviewSortSheet = root.querySelector('#profile-mobile-review-sort-sheet');
    const mobileReviewFilterCloseButtons = Array.from(root.querySelectorAll('[data-review-close-filter-sheet]'));
    const mobileReviewSortCloseButtons = Array.from(root.querySelectorAll('[data-review-close-sort-sheet]'));
    const mobileReviewFilterOptions = Array.from(root.querySelectorAll('[data-review-mobile-filter-option]'));
    const mobileReviewSortOptions = Array.from(root.querySelectorAll('[data-review-mobile-sort-option]'));
    const reviewsContainer = root.querySelector('[data-profile-reviews]');
    const reviewsLoadMoreButton = root.querySelector('[data-reviews-load-more]');
    const reviewsCountLabel = root.querySelector('[data-reviews-count]');
    const reviewsEmptyState = root.querySelector('[data-reviews-empty]');
    const reviewsEmptyStateText = root.querySelector('[data-reviews-empty-text]');
    const reviewsSkeleton = root.querySelector('[data-reviews-skeleton]');
    const reviewsSentinel = root.querySelector('[data-reviews-sentinel]');
    if (reviewsContainer) {
        const reviewsSlug = String(reviewsContainer.dataset.reviewsSlug || root.dataset.profileSlug || '').trim();
        const batchSize = Math.max(1, Number(reviewsContainer.dataset.reviewsBatchSize || 10));
        let activeFilter = 'all';
        let isLoadingMore = false;
        let autoLoadsDone = 0;
        const maxAutoLoads = 2;
        let allReviews = [];
        let loadMoreObserver = null;
        let autoLoadArmed = false;
        const reviewFilterLabels = {
            all: 'Усі',
            positive: 'Позитивні',
            negative: 'Негативні',
        };
        const reviewSortLabels = {
            newest: 'Нові спочатку',
            relevant: 'За актуальністю',
            highest: 'Найвищі',
            lowest: 'Найнижчі',
        };
        const isReviewAuthenticated = reviewsContainer?.dataset.reviewAuthenticated === '1';
        const reviewLoginUrl = String(reviewsContainer?.dataset.reviewLoginUrl || '').trim();
        const reviewCsrfToken = String(reviewsContainer?.dataset.reviewCsrf || '').trim();

        // --- Чернетка скарги: гість пише скаргу до входу, текст зберігається
        // в localStorage і автоматично надсилається після авторизації. ---
        const REPORT_DRAFT_KEY = 'dovira:review-report-draft';
        const REPORT_DRAFT_TTL = 24 * 60 * 60 * 1000;

        const saveReportDraft = (reviewId, reason, details) => {
            try {
                window.localStorage.setItem(REPORT_DRAFT_KEY, JSON.stringify({
                    slug: reviewsSlug,
                    reviewId,
                    reason,
                    details,
                    savedAt: Date.now(),
                }));
            } catch (error) { /* приватний режим — просто без збереження */ }
        };

        const loadReportDraft = () => {
            try {
                const raw = window.localStorage.getItem(REPORT_DRAFT_KEY);
                if (!raw) return null;
                const draft = JSON.parse(raw);
                if (!draft || draft.slug !== reviewsSlug || !Number(draft.reviewId)) return null;
                if (Date.now() - Number(draft.savedAt || 0) > REPORT_DRAFT_TTL) {
                    window.localStorage.removeItem(REPORT_DRAFT_KEY);
                    return null;
                }
                return draft;
            } catch (error) {
                return null;
            }
        };

        const clearReportDraft = () => {
            try { window.localStorage.removeItem(REPORT_DRAFT_KEY); } catch (error) { /* ignore */ }
        };

        const showReportToast = (text) => {
            const toast = document.createElement('div');
            toast.className = 'profile-report-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML = `<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>${text}</span>`;
            document.body.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('is-visible'));
            window.setTimeout(() => {
                toast.classList.remove('is-visible');
                window.setTimeout(() => toast.remove(), 300);
            }, 4200);
        };

        const sendReviewReport = async (reviewId, reason, details) => {
            const response = await fetch(`/profiles/${encodeURIComponent(reviewsSlug)}/reviews/${reviewId}/reports`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': reviewCsrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ reason, details }),
            });
            return response;
        };

        const markReportSubmitted = (reviewId) => {
            const reviewElement = reviewsContainer.querySelector(`.profile-review[data-review-id="${reviewId}"]`);
            const trigger = reviewElement?.querySelector('[data-review-report]');
            if (trigger) {
                trigger.classList.add('is-submitted');
                const label = trigger.querySelector('span');
                if (label) label.textContent = 'Скаргу надіслано';
            }
            return reviewElement || null;
        };

        // Після входу: якщо є збережена чернетка для цього профілю —
        // надсилаємо її автоматично і показуємо підтвердження.
        if (isReviewAuthenticated) {
            const draft = loadReportDraft();
            if (draft) {
                (async () => {
                    try {
                        const response = await sendReviewReport(Number(draft.reviewId), draft.reason, draft.details);
                        if (response.ok) {
                            clearReportDraft();
                            const reviewElement = markReportSubmitted(Number(draft.reviewId));
                            showReportToast('Ви увійшли — вашу скаргу надіслано на модерацію.');
                            reviewElement?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        } else if (response.status !== 401) {
                            // Відгук міг зникнути (404) — чернетка більше не актуальна.
                            clearReportDraft();
                        }
                    } catch (error) {
                        // Мережа впала — чернетка лишається до наступного візиту.
                    }
                })();
            }
        }
        const currentUserName = String(reviewsContainer?.dataset.reviewCurrentUserName || 'Ви').trim() || 'Ви';
        const currentUserAvatarUrl = String(reviewsContainer?.dataset.reviewCurrentUserAvatarUrl || '').trim();
        const currentUserInitial = String(reviewsContainer?.dataset.reviewCurrentUserInitial || currentUserName.charAt(0) || 'В').trim() || 'В';

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const hashSeed = (input) => {
            let value = 0;
            for (let i = 0; i < input.length; i += 1) {
                value = (value << 5) - value + input.charCodeAt(i);
                value |= 0;
            }
            return Math.abs(value);
        };

        const avatarPalettes = [
            { bg1: '#eaf2ff', bg2: '#1f68ff' },
            { bg1: '#e8f5ff', bg2: '#0f4fb8' },
            { bg1: '#edf0ff', bg2: '#3b5bff' },
            { bg1: '#eefcff', bg2: '#1483ff' },
            { bg1: '#e8fffb', bg2: '#0d86c6' },
            { bg1: '#f1f0ff', bg2: '#5a4bff' },
            { bg1: '#eaf6ff', bg2: '#0b63d6' },
            { bg1: '#eff7ff', bg2: '#1b86ff' },
            { bg1: '#e9f3ff', bg2: '#2753d9' },
            { bg1: '#e8f0ff', bg2: '#3a66ff' },
        ];

        const applyReviewAvatarGradients = (scope = reviewsContainer) => {
            scope?.querySelectorAll?.('.review-list-card__logo.has-random-gradient[data-seed]').forEach((avatar) => {
                const value = hashSeed(String(avatar.dataset.seed || ''));
                const palette = avatarPalettes[value % avatarPalettes.length];
                const invert = ((value >> 8) & 1) === 1;
                avatar.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
                avatar.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
                avatar.style.setProperty('--av-text', '#ffffff');
            });
        };

        // Лічильник і кнопка «Показати ще» рахуються не з того, що вже
        // підвантажено (activeFilter застосовується лише клієнтськи, до
        // вже завантаженої партії), а з реальних серверних тоталів за
        // категорією — інакше «Негативні» показували б стару цифру
        // «22 з 100» навіть коли негативних відгуків узагалі нема.
        const updateReviewsCountLabel = () => {
            const loaded = Math.max(0, Number(reviewsContainer.dataset.reviewsLoaded || 0));
            const total = Math.max(loaded, Number(reviewsContainer.dataset.reviewsTotal || loaded));

            let filterTotal = total;
            let emptyText = 'Відгуків поки немає.';
            if (activeFilter === 'positive') {
                filterTotal = filterTotalFor('positive');
                emptyText = 'Позитивних відгуків поки немає.';
            } else if (activeFilter === 'negative') {
                filterTotal = filterTotalFor('negative');
                emptyText = 'Негативних відгуків немає.';
            }

            const visibleCount = Array.from(reviewsContainer.querySelectorAll('.profile-review'))
                .filter((review) => review.style.display !== 'none').length;
            const isEmpty = filterTotal === 0;

            if (reviewsCountLabel) {
                reviewsCountLabel.hidden = isEmpty;
                // Рахуємо по факту видимих карток: картки можуть приходити і
                // з загального потоку, і з фільтрованого.
                reviewsCountLabel.textContent = `Показано ${visibleCount} з ${filterTotal}`;
            }

            if (reviewsEmptyState) {
                reviewsEmptyState.hidden = !isEmpty;
                if (reviewsEmptyStateText) {
                    reviewsEmptyStateText.textContent = emptyText;
                }
                // «Залишити перший відгук» — лише коли відгуків немає взагалі,
                // а не коли порожній результат дав фільтр.
                const emptyCta = reviewsEmptyState.querySelector('[data-reviews-empty-cta]');
                if (emptyCta) {
                    emptyCta.hidden = !(isEmpty && activeFilter === 'all' && total === 0);
                }
            }

            if (reviewsLoadMoreButton) {
                // Під фільтром орієнтуємось лише на кількість карток цієї
                // категорії: фільтрований потік вантажиться незалежно від
                // загального.
                const hasMore = activeFilter === 'all'
                    ? loaded < total
                    : visibleCount < filterTotal;
                reviewsLoadMoreButton.classList.toggle('is-hidden', !hasMore);
                reviewsLoadMoreButton.disabled = isLoadingMore;
            }
        };

        const setSkeletonVisible = (visible) => {
            if (!reviewsSkeleton) return;
            reviewsSkeleton.classList.toggle('is-hidden', !visible);
        };

        const refreshReviewsList = () => {
            allReviews = Array.from(reviewsContainer.querySelectorAll('.profile-review'));
            allReviews.forEach((review, index) => {
                if (!review.dataset.reviewIndex) {
                    review.dataset.reviewIndex = String(index);
                }
            });
        };

        const renderStars = (rating) => {
            const score = Number(rating || 0);
            const full = Math.floor(score);
            const half = (score - full) >= 0.5 ? 1 : 0;
            const empty = Math.max(0, 5 - full - half);
            const tone = score >= 4
                ? 'rating-stars--excellent'
                : (score >= 3 ? 'rating-stars--fair' : 'rating-stars--poor');
            return (
                `<div class="rating-stars ${tone}">` +
                '<i class="fa-solid fa-star"></i>'.repeat(full) +
                (half ? '<i class="fa-solid fa-star-half-stroke"></i>' : '') +
                '<i class="fa-regular fa-star"></i>'.repeat(empty) +
                '</div>'
            );
        };

        const renderReviewMedia = (media) => {
            if (!Array.isArray(media) || media.length === 0) return '';
            const items = media.map((url) => {
                const mediaUrl = escapeHtml(url);
                const lower = String(url).toLowerCase();
                const isVideo = lower.endsWith('.mp4') || lower.endsWith('.webm') || lower.endsWith('.mov');
                if (isVideo) {
                    return `<video controls preload="metadata" src="${mediaUrl}"></video>`;
                }
                return `<a href="${mediaUrl}" target="_blank" rel="noopener noreferrer"><img src="${mediaUrl}" alt="Вкладення до відгуку" loading="lazy"></a>`;
            }).join('');
            return `<div class="profile-review__media">${items}</div>`;
        };

        const renderReviewSource = (review) => {
            if (!review.external_source_label) return '';
            const label = escapeHtml(review.external_source_label);
            const type = String(review.external_source_type || '');
            const icon = ['google_maps', 'google', 'google_business'].includes(type) ? 'fa-brands fa-google' : 'fa-solid fa-link';
            // Plain label only — never link out to the external source.
            return `<span class="profile-review__source-badge"><i class="${icon}" aria-hidden="true"></i><span>${label}</span></span>`;
        };

        const renderCommentForm = (parentId = null, hidden = false, replyAuthor = '') => {
            if (!isReviewAuthenticated) return '';
            const parentAttr = parentId ? ` data-review-parent-id="${Number(parentId)}"` : '';
            const hiddenClass = hidden ? ' is-hidden' : '';
            const placeholder = 'Напишіть щось...';
            const titleMarkup = replyAuthor
                ? `<div class="profile-review-comment-form__head"><span class="profile-review-comment-form__title" data-review-comment-title>Відповісти на ${escapeHtml(replyAuthor)}</span><button type="button" class="profile-review-comment-form__close" data-review-comment-cancel aria-label="Закрити форму коментаря"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>`
                : '';
            return `
                <form class="profile-review-comment-form${parentId ? '' : ' profile-review-comment-form--root'}${hiddenClass}" data-review-comment-form${parentAttr}>
                    <div class="profile-review-comment-form__body">
                        ${titleMarkup}
                        <div class="profile-review-comment-form__composer">
                            <textarea name="body" rows="2" placeholder="${placeholder}"></textarea>
                        </div>
                        <div class="profile-review-comment-form__actions">
                            <span class="profile-review-comment-form__meta" data-review-comment-meta>Коментар від 10 символів</span>
                            <button type="submit" class="btn btn--primary" disabled>
                                <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                                <span>Додати коментар</span>
                            </button>
                        </div>
                    </div>
                </form>
            `;
        };

        const renderCommentsLogin = () => {
            return '';
        };

        const formatCommentText = (text) => {
            const escaped = escapeHtml(text || '');
            return escaped.replace(/(^|\s)(@[\p{L}\p{N}_.-]+)/gu, '$1<span class="profile-review-comment__mention">$2</span>').replace(/\n/g, '<br>');
        };

        const renderCommentCard = (reply, isChild = false, index = 0) => {
            const author = escapeHtml(reply.author || 'Користувач DOVIRA');
            const avatarUrl = String(reply.avatar_url || '').trim();
            const avatarInitial = escapeHtml((reply.author || 'Користувач DOVIRA').trim().charAt(0).toUpperCase() || 'К');
            const avatarFallback = `<span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">${avatarInitial}</span>`;
            const avatarHtml = avatarUrl !== ''
                ? `<img src="${escapeHtml(avatarUrl)}" alt="${author}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"><span class="review-list-card__fallback" aria-hidden="true">${avatarInitial}</span>`
                : avatarFallback;
            const children = Array.isArray(reply.children) ? reply.children : [];
            const childMarkup = children.map((child) => renderCommentCard(child, true)).join('');
            const isOfficial = Boolean(reply.is_official);
            const isSystem = Boolean(reply.is_system);
            const isPublicRootComment = !isChild && !isOfficial;
            const replyId = escapeHtml(String(reply.id || ''));
            const likeCount = Number(reply.like_count || 0);
            const dislikeCount = Number(reply.dislike_count || 0);
            const userReaction = String(reply.user_reaction || '');
            const reactionAttr = isOfficial ? 'data-review-official-reaction' : 'data-review-comment-reaction';
            const replyButton = isSystem
                ? `<button type="button" class="profile-review-comment__reply-btn" data-review-root-toggle data-review-reply-author="${author}"><i class="fa-regular fa-comment" aria-hidden="true"></i><span>Відповісти</span></button>`
                : `<button type="button" class="profile-review-comment__reply-btn" data-review-reply-toggle="${replyId}" data-review-reply-author="${author}"><i class="fa-regular fa-comment" aria-hidden="true"></i><span>Відповісти</span></button>`;
            const replyForm = isSystem ? '' : renderCommentForm(reply.parent_id ? reply.parent_id : reply.id, true, author);
            const officialBadge = isOfficial ? '<span class="profile-review-comment__badge"><i class="fa-solid fa-shield-check" aria-hidden="true"></i> Офіційна відповідь</span>' : '';

            return `
                <div class="profile-review-comment${isChild ? ' profile-review-comment--child' : ''}${isOfficial ? ' profile-review-comment--official' : ''}${isPublicRootComment ? ' profile-review-comment--user is-collapsed' : ''}" data-review-comment-id="${replyId}" data-review-comment-author="${author}">
                    <div class="profile-review-comment__top">
                        <div class="profile-review-comment__author">
                            <div class="profile-review-comment__avatar review-list-card__logo ${avatarUrl ? '' : 'has-random-gradient'}" data-image-fallback-shell data-seed="${author}">${avatarHtml}</div>
                            <div>
                                <div class="profile-review-comment__name-row"><strong>${author}</strong>${officialBadge}</div>
                                <time datetime="${escapeHtml(reply.date_iso || '')}">${escapeHtml(reply.date || '')}</time>
                            </div>
                        </div>
                        <button type="button" class="profile-review-comment__menu" aria-label="Додаткові дії"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                    </div>
                    <p>${formatCommentText(reply.text || '')}</p>
                    <div class="profile-review-comment__actions" role="group" aria-label="Дії з коментарем">
                        <button type="button" class="profile-review-comment__reaction-btn ${userReaction === 'like' ? 'is-active' : ''}" ${reactionAttr}="like">
                            <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>
                            <span data-review-comment-like-count>${likeCount}</span>
                        </button>
                        <button type="button" class="profile-review-comment__reaction-btn ${userReaction === 'dislike' ? 'is-active' : ''}" ${reactionAttr}="dislike">
                            <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i>
                            <span data-review-comment-dislike-count>${dislikeCount}</span>
                        </button>
                        ${replyButton}
                    </div>
                    ${childMarkup}
                    ${replyForm}
                </div>
            `;
        };

        const renderCommentsSection = (review) => {
            const replies = Array.isArray(review.replies) ? review.replies : [];
            const publicRepliesCount = replies.filter((reply) => !reply.is_official).length;

            return `
                <div class="profile-review__comments ${replies.length > 0 ? '' : 'is-hidden'}" data-review-comments>
                    <div class="profile-review__comments-list" data-review-comments-list>
                        ${replies.map((reply, index) => renderCommentCard(reply, false, index)).join('')}
                    </div>
                    <div class="profile-review__comments-footer">
                        ${publicRepliesCount > 0 ? `<button type="button" class="profile-review__comments-more" data-review-comments-more><span>Показати коментарі (${publicRepliesCount})</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>` : ''}
                    </div>
                    ${renderCommentForm(null, true, review.author || '')}
                    ${renderCommentsLogin()}
                </div>
            `;
        };

        const renderReviewEngagement = (review) => {
            const likeCount = Number(review.like_count || 0);
            const dislikeCount = Number(review.dislike_count || 0);
            const userReaction = String(review.user_reaction || '');

            return `
                <div class="profile-review__engagement" data-review-engagement>
                    <div class="profile-review__actions" role="group" aria-label="Дії з відгуком">
                        <button type="button" class="profile-review__reaction-btn ${userReaction === 'like' ? 'is-active' : ''}" data-review-reaction="like">
                            <span class="profile-review__reaction-icon" aria-hidden="true"><i class="fa-regular fa-thumbs-up"></i></span>
                            <span class="sr-only">Позитивна реакція</span>
                            <strong data-review-like-count>${likeCount}</strong>
                        </button>
                        <button type="button" class="profile-review__reaction-btn ${userReaction === 'dislike' ? 'is-active' : ''}" data-review-reaction="dislike">
                            <span class="profile-review__reaction-icon" aria-hidden="true"><i class="fa-regular fa-thumbs-down"></i></span>
                            <span class="sr-only">Негативна реакція</span>
                            <strong data-review-dislike-count>${dislikeCount}</strong>
                        </button>
                        <button type="button" class="profile-review__comments-add-action" data-review-root-toggle><i class="fa-regular fa-comment" aria-hidden="true"></i><span>Відповісти</span></button>
                        <button type="button" class="profile-review__report-action" data-review-report><i class="fa-regular fa-flag" aria-hidden="true"></i><span>Поскаржитися</span></button>
                    </div>
                    ${renderCommentsSection(review)}
                </div>
            `;
        };

        const renderReviewCard = (review, index) => {
            const ratingValue = Number(review.rating || 0);
            const ratingRounded = Math.floor(ratingValue);
            const author = escapeHtml(review.author || 'Користувач DOVIRA');
            const dateLabel = escapeHtml(review.date || '');
            const dateIso = escapeHtml(review.date_iso || review.date || '');
            const title = review.title ? `<p class="profile-review__title">${escapeHtml(review.title)}</p>` : '';
            const avatarUrl = String(review.avatar_url || '').trim();
            const avatarInitial = escapeHtml((review.avatar || review.author || 'Користувач DOVIRA').trim().charAt(0).toUpperCase() || 'К');
            const avatarFallback = `<span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">${avatarInitial}</span>`;
            const avatarHtml = avatarUrl !== ''
                ? `<img src="${escapeHtml(avatarUrl)}" alt="${author}" loading="lazy" onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"><span class="review-list-card__fallback" aria-hidden="true">${avatarInitial}</span>`
                : avatarFallback;

            return `
                <article class="profile-review" data-review-id="${Number(review.id || 0)}" data-review-author="${author}" data-review-rating="${ratingRounded}" data-review-index="${index}" data-review-date="${dateIso}">
                    <div class="profile-review__top">
                        <div class="profile-review__author">
                            <div class="profile-review__avatar review-list-card__logo ${avatarUrl ? '' : 'has-random-gradient'}" data-image-fallback-shell data-seed="${author}">${avatarHtml}</div>
                            <strong>${author}</strong>
                        </div>
                        <time>${dateLabel}</time>
                    </div>
                    <div class="profile-review__rating">
                        ${renderStars(ratingValue)}
                        <strong>${ratingValue.toFixed(1)}</strong>
                        ${renderReviewSource(review)}
                    </div>
                    ${title}
                    <p>${escapeHtml(review.text || '')}</p>
                    ${renderReviewMedia(review.media)}
                    ${renderReviewEngagement(review)}
                </article>
            `;
        };

        const updateCommentsPreviewState = (reviewElement) => {
            const commentsList = reviewElement?.querySelector('[data-review-comments-list]');
            const commentsPanel = reviewElement?.querySelector('[data-review-comments]');
            if (!commentsList || !commentsPanel) return;

            const rootComments = Array.from(commentsList.querySelectorAll(':scope > .profile-review-comment'));
            const publicRootComments = rootComments.filter((comment) => !comment.classList.contains('profile-review-comment--official'));
            const isExpanded = commentsPanel.dataset.commentsExpanded === '1';
            publicRootComments.forEach((comment) => {
                comment.classList.toggle('is-collapsed', !isExpanded);
            });

            let moreButton = reviewElement.querySelector('[data-review-comments-more]');
            if (publicRootComments.length > 0) {
                if (!moreButton) {
                    const footer = reviewElement.querySelector('.profile-review__comments-footer');
                    const markup = `<button type="button" class="profile-review__comments-more" data-review-comments-more><span></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>`;
                    if (footer) {
                        footer.insertAdjacentHTML('beforeend', markup);
                    } else {
                        commentsList.insertAdjacentHTML('afterend', markup);
                    }
                    moreButton = reviewElement.querySelector('[data-review-comments-more]');
                }
                const label = moreButton?.querySelector('span');
                const icon = moreButton?.querySelector('i');
                if (label) {
                    label.textContent = isExpanded ? 'Сховати коментарі' : `Показати коментарі (${publicRootComments.length})`;
                }
                if (icon) {
                    icon.classList.toggle('fa-chevron-down', !isExpanded);
                    icon.classList.toggle('fa-chevron-up', isExpanded);
                }
                moreButton?.classList.toggle('is-expanded', isExpanded);
            } else if (moreButton) {
                moreButton.remove();
            }
        };

        const setCommentsPanelOpen = (reviewElement, open) => {
            const commentsPanel = reviewElement?.querySelector('[data-review-comments]');
            if (!commentsPanel) return;

            commentsPanel.classList.toggle('is-hidden', !open);
        };

        const setCommentFormTitle = (form, text = '') => {
            const title = form?.querySelector('[data-review-comment-title]');
            if (title) {
                title.textContent = text;
            }
        };

        const openRootCommentForm = (reviewElement, sourceButton = null) => {
            const rootForm = reviewElement?.querySelector('.profile-review-comment-form--root[data-review-comment-form]');
            if (!rootForm) return;
            const reviewAuthor = String(reviewElement?.dataset.reviewAuthor || '').trim();
            const replyAuthor = String(sourceButton?.dataset.reviewReplyAuthor || '').trim();
            const targetAuthor = replyAuthor || reviewAuthor;

            if (!rootForm.classList.contains('is-hidden') && rootForm.dataset.activeReplyAuthor === targetAuthor) {
                resetCommentForm(rootForm, { collapse: true });
                rootForm.classList.add('is-hidden');
                rootForm.dataset.activeReplyAuthor = '';
                return;
            }

            setCommentsPanelOpen(reviewElement, true);
            closeSiblingReplyForms(reviewElement, null);
            rootForm.dataset.activeReplyAuthor = targetAuthor;
            setCommentFormTitle(rootForm, targetAuthor ? `Відповісти на ${targetAuthor}` : 'Додати коментар');
            rootForm.classList.remove('is-hidden');
            rootForm.classList.add('is-expanded');
            const textarea = rootForm.querySelector('textarea[name="body"]');
            if (textarea && replyAuthor && !String(textarea.value || '').trim()) {
                textarea.value = `@${replyAuthor}, `;
            }
            textarea?.focus();
            textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
            updateCommentFormState(rootForm);
        };

        const autoResizeTextarea = (textarea) => {
            if (!textarea) return;
            textarea.style.height = 'auto';
            textarea.style.height = `${Math.min(textarea.scrollHeight, 220)}px`;
        };

        const updateCommentFormState = (form) => {
            if (!form) return;
            const textarea = form.querySelector('textarea[name="body"]');
            const submitButton = form.querySelector('button[type="submit"]');
            const meta = form.querySelector('[data-review-comment-meta]');
            if (!textarea || !submitButton) return;

            const value = String(textarea.value || '').trim();
            const isValid = value.length >= 10;
            submitButton.disabled = !isValid;
            form.classList.toggle('is-dirty', value.length > 0);
            form.classList.toggle('is-expanded', document.activeElement === textarea || value.length > 0 || !form.classList.contains('profile-review-comment-form--root'));

            if (meta) {
                meta.textContent = isValid ? `${value.length}/2000` : 'Коментар від 10 символів';
            }

            autoResizeTextarea(textarea);
        };

        const resetCommentForm = (form, { collapse = false } = {}) => {
            if (!form) return;
            const textarea = form.querySelector('textarea[name="body"]');
            if (textarea) {
                textarea.value = '';
                textarea.style.height = '';
            }
            form.classList.remove('is-dirty');
            if (collapse) {
                form.classList.remove('is-expanded');
                form.dataset.activeReplyAuthor = '';
                if (!form.classList.contains('profile-review-comment-form--root')) {
                    form.classList.add('is-hidden');
                } else {
                    setCommentFormTitle(form, 'Додати коментар');
                }
            }
            updateCommentFormState(form);
        };

        const closeSiblingReplyForms = (reviewElement, exceptForm = null) => {
            if (!reviewElement) return;
            const replyForms = reviewElement.querySelectorAll('[data-review-comment-form][data-review-parent-id]');
            replyForms.forEach((form) => {
                if (exceptForm && form === exceptForm) return;
                form.classList.add('is-hidden');
                resetCommentForm(form, { collapse: true });
            });
        };

        const bindCommentForm = (form) => {
            if (!form || form.dataset.boundCommentForm === '1') return;
            form.dataset.boundCommentForm = '1';

            const textarea = form.querySelector('textarea[name="body"]');
            const cancelButton = form.querySelector('[data-review-comment-cancel]');

            textarea?.addEventListener('focus', () => {
                form.classList.add('is-expanded');
                if (form.hasAttribute('data-review-parent-id')) {
                    closeSiblingReplyForms(form.closest('.profile-review'), form);
                }
                updateCommentFormState(form);
            });

            textarea?.addEventListener('input', () => {
                updateCommentFormState(form);
            });

            textarea?.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (form.hasAttribute('data-review-parent-id')) {
                        form.classList.add('is-hidden');
                        resetCommentForm(form, { collapse: true });
                    } else if (!String(textarea.value || '').trim()) {
                        form.classList.remove('is-expanded');
                        updateCommentFormState(form);
                    }
                }

                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    if (!form.querySelector('button[type="submit"]')?.disabled) {
                        form.requestSubmit();
                    }
                }
            });

            textarea?.addEventListener('blur', () => {
                window.setTimeout(() => {
                    if (document.activeElement === textarea) return;
                    const hasValue = String(textarea.value || '').trim().length > 0;
                    if (!hasValue && form.classList.contains('profile-review-comment-form--root')) {
                        form.classList.remove('is-expanded');
                    }
                    updateCommentFormState(form);
                }, 0);
            });

            cancelButton?.addEventListener('click', () => {
                const isRoot = form.classList.contains('profile-review-comment-form--root');
                if (isRoot) {
                    form.classList.add('is-hidden');
                    resetCommentForm(form, { collapse: true });
                    return;
                }
                form.classList.add('is-hidden');
                resetCommentForm(form, { collapse: true });
            });

            updateCommentFormState(form);
        };

        const bindAllCommentForms = (scope = reviewsContainer) => {
            scope?.querySelectorAll?.('[data-review-comment-form]').forEach(bindCommentForm);
        };

        const appendCommentToReview = (reviewElement, reply) => {
            const commentsList = reviewElement?.querySelector('[data-review-comments-list]');
            if (!commentsList) return;

            const parentId = Number(reply.parent_id || 0);
            if (parentId > 0) {
                const parentComment = commentsList.querySelector(`[data-review-comment-id="${parentId}"]`);
                if (parentComment) {
                    const replyForm = parentComment.querySelector('[data-review-comment-form]');
                    if (replyForm) {
                        replyForm.insertAdjacentHTML('beforebegin', renderCommentCard(reply, true));
                    } else {
                        parentComment.insertAdjacentHTML('beforeend', renderCommentCard(reply, true));
                    }
                }
            } else {
                commentsList.insertAdjacentHTML('beforeend', renderCommentCard(reply));
            }

            const commentsPanel = reviewElement?.querySelector('[data-review-comments]');
            if (commentsPanel) {
                commentsPanel.dataset.commentsExpanded = '1';
            }
            updateCommentsPreviewState(reviewElement);
            setCommentsPanelOpen(reviewElement, true);
            applyReviewAvatarGradients(reviewElement);
            bindAllCommentForms(reviewElement);
        };

        const applyReviewsState = () => {
            refreshReviewsList();
            const sortValue = sortSelect?.value || 'relevant';
            const visible = allReviews.filter((review) => {
                if (activeFilter === 'all') return true;
                const rating = Number(review.dataset.reviewRating || 0);
                if (activeFilter === 'positive') return rating >= 4;
                if (activeFilter === 'negative') return rating <= 3;
                return String(review.dataset.reviewRating || '') === activeFilter;
            });

            allReviews.forEach((review) => {
                review.style.display = visible.includes(review) ? '' : 'none';
            });

            const sorted = [...visible].sort((a, b) => {
                const ratingA = Number(a.dataset.reviewRating || 0);
                const ratingB = Number(b.dataset.reviewRating || 0);
                const idxA = Number(a.dataset.reviewIndex || 0);
                const idxB = Number(b.dataset.reviewIndex || 0);
                const dateA = Date.parse(a.dataset.reviewDate || '') || 0;
                const dateB = Date.parse(b.dataset.reviewDate || '') || 0;

                if (sortValue === 'highest') return ratingB - ratingA || idxA - idxB;
                if (sortValue === 'lowest') return ratingA - ratingB || idxA - idxB;
                if (sortValue === 'newest') return dateB - dateA || idxA - idxB;
                return idxA - idxB;
            });

            sorted.forEach((review) => reviewsContainer.appendChild(review));
            updateReviewsCountLabel();
        };

        const syncDesktopReviewFilters = () => {
            filterButtons.forEach((button) => {
                button.classList.toggle('is-active', (button.dataset.reviewFilter || 'all') === activeFilter);
            });
        };

        const updateMobileReviewFilterState = () => {
            if (mobileReviewFilterLabel) {
                mobileReviewFilterLabel.textContent = reviewFilterLabels[activeFilter] || reviewFilterLabels.all;
            }
            mobileReviewFilterOptions.forEach((option) => {
                option.classList.toggle('is-active', option.dataset.reviewMobileFilterOption === activeFilter);
            });
        };

        const updateMobileReviewSortState = () => {
            const sortValue = sortSelect?.value || 'newest';
            if (mobileReviewSortLabel) {
                mobileReviewSortLabel.textContent = reviewSortLabels[sortValue] || reviewSortLabels.newest;
            }
            mobileReviewSortOptions.forEach((option) => {
                option.classList.toggle('is-active', option.dataset.reviewMobileSortOption === sortValue);
            });
        };

        const setReviewSheetOpen = (sheet, toggle, open) => {
            if (!sheet || !toggle) return;
            if (open) {
                if (sheet !== mobileReviewFilterSheet && mobileReviewFilterSheet) {
                    mobileReviewFilterSheet.open = false;
                    mobileReviewFilterToggle?.setAttribute('aria-expanded', 'false');
                    mobileReviewFilterToggle?.classList.remove('is-active');
                }
                if (sheet !== mobileReviewSortSheet && mobileReviewSortSheet) {
                    mobileReviewSortSheet.open = false;
                    mobileReviewSortToggle?.setAttribute('aria-expanded', 'false');
                    mobileReviewSortToggle?.classList.remove('is-active');
                }
            }
            sheet.open = open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.classList.toggle('is-active', open);
        };

        // Пагінація фільтрів «Позитивні»/«Негативні» — окремим потоком:
        // сервер віддає вже відфільтровані сторінки, а тут ведемо власний
        // offset для кожного фільтра. Дублікати (картка могла прийти і
        // з загального потоку, і з фільтрованого) відсіюємо за review id.
        const filteredStreams = {
            positive: { offset: 0, total: null },
            negative: { offset: 0, total: null },
        };

        const countMatchingCards = (filter) => Array.from(reviewsContainer.querySelectorAll('.profile-review'))
            .filter((review) => {
                const rating = Number(review.dataset.reviewRating || 0);
                return filter === 'positive' ? rating >= 4 : rating <= 3;
            }).length;

        const filterTotalFor = (filter) => {
            const stream = filteredStreams[filter];
            if (stream && stream.total !== null) return stream.total;
            const attr = filter === 'positive' ? 'reviewsPositiveCount' : 'reviewsNegativeCount';
            return Math.max(0, Number(reviewsContainer.dataset[attr] || 0));
        };

        const insertReviewItems = (items) => {
            let added = 0;
            items.forEach((item) => {
                const id = Number(item.id || 0);
                if (id && reviewsContainer.querySelector(`.profile-review[data-review-id="${id}"]`)) return;
                const currentCount = reviewsContainer.querySelectorAll('.profile-review').length;
                reviewsContainer.insertAdjacentHTML('beforeend', renderReviewCard(item, currentCount));
                added += 1;
            });
            if (added > 0) {
                applyReviewAvatarGradients(reviewsContainer);
                bindAllCommentForms(reviewsContainer);
            }
            return added;
        };

        const loadMoreReviews = async ({ auto = false } = {}) => {
            if (isLoadingMore || !reviewsSlug) return;
            if (auto && autoLoadsDone >= maxAutoLoads) return;

            const isFiltered = activeFilter === 'positive' || activeFilter === 'negative';
            const loaded = Math.max(0, Number(reviewsContainer.dataset.reviewsLoaded || 0));
            const total = Math.max(loaded, Number(reviewsContainer.dataset.reviewsTotal || loaded));

            if (isFiltered) {
                if (countMatchingCards(activeFilter) >= filterTotalFor(activeFilter)) return;
            } else if (loaded >= total) {
                return;
            }

            isLoadingMore = true;
            if (reviewsLoadMoreButton) {
                reviewsLoadMoreButton.textContent = 'Завантаження...';
            }
            setSkeletonVisible(true);
            updateReviewsCountLabel();

            const stream = isFiltered ? filteredStreams[activeFilter] : null;
            const offset = isFiltered
                ? stream.offset
                : Math.max(0, Number(reviewsContainer.dataset.reviewsNextOffset || loaded));

            const fetchReviewsPage = async (pageOffset) => {
                const pageQuery = new URLSearchParams({
                    offset: String(pageOffset),
                    limit: String(batchSize),
                });
                if (isFiltered) {
                    pageQuery.set('filter', activeFilter);
                }
                const response = await fetch(`/profiles/${encodeURIComponent(reviewsSlug)}/reviews?${pageQuery.toString()}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            };

            try {
                if (isFiltered) {
                    // Частина фільтрованих сторінок може складатись з карток,
                    // які вже є в DOM (прийшли загальним потоком) — тягнемо
                    // сторінки ланцюжком, поки не набереться повна порція
                    // нових видимих або категорія не вичерпається.
                    let addedTotal = 0;
                    let guard = 0;
                    while (addedTotal < batchSize && guard < 8) {
                        const payload = await fetchReviewsPage(stream.offset);
                        const items = Array.isArray(payload.items) ? payload.items : [];
                        addedTotal += insertReviewItems(items);
                        stream.offset = Math.max(stream.offset + items.length, Number(payload.next_offset ?? 0));
                        stream.total = Math.max(0, Number(payload.total ?? filterTotalFor(activeFilter)));
                        guard += 1;
                        if (items.length === 0 || stream.offset >= stream.total) break;
                    }
                } else {
                    const payload = await fetchReviewsPage(offset);
                    const items = Array.isArray(payload.items) ? payload.items : [];
                    insertReviewItems(items);
                    const nextLoaded = Math.max(loaded, Number(payload.loaded ?? (offset + items.length)));
                    const nextTotal = Math.max(total, Number(payload.total ?? total));
                    const nextOffset = Math.max(nextLoaded, Number(payload.next_offset ?? nextLoaded));
                    reviewsContainer.dataset.reviewsLoaded = String(nextLoaded);
                    reviewsContainer.dataset.reviewsTotal = String(nextTotal);
                    reviewsContainer.dataset.reviewsNextOffset = String(nextOffset);
                }

                if (auto) {
                    autoLoadsDone += 1;
                }
                applyReviewsState();
            } catch (error) {
                console.error('Failed to load more reviews', error);
            } finally {
                isLoadingMore = false;
                setSkeletonVisible(false);
                if (reviewsLoadMoreButton) {
                    reviewsLoadMoreButton.textContent = 'Показати ще';
                }
                updateReviewsCountLabel();

            }
        };

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.reviewFilter || 'all';
                syncDesktopReviewFilters();
                updateMobileReviewFilterState();
                applyReviewsState();
            });
        });

        sortSelect?.addEventListener('change', () => {
            updateMobileReviewSortState();
            applyReviewsState();
        });
        reviewsLoadMoreButton?.addEventListener('click', () => {
            loadMoreReviews({ auto: false });
        });

        reviewsContainer.addEventListener('click', async (event) => {
            const commentsMoreButton = event.target.closest('[data-review-comments-more]');
            if (commentsMoreButton) {
                event.preventDefault();

                const reviewElement = commentsMoreButton.closest('.profile-review');
                const commentsPanel = reviewElement?.querySelector('[data-review-comments]');
                if (!reviewElement || !commentsPanel) return;

                commentsPanel.dataset.commentsExpanded = commentsPanel.dataset.commentsExpanded === '1' ? '0' : '1';
                updateCommentsPreviewState(reviewElement);
                return;
            }

            // --- report: cancel / submit inside the reason panel ---
            const reportCancel = event.target.closest('[data-report-cancel]');
            if (reportCancel) {
                event.preventDefault();
                reportCancel.closest('.profile-review-report-panel')?.remove();
                return;
            }

            const reportSubmit = event.target.closest('[data-report-submit]');
            if (reportSubmit) {
                event.preventDefault();

                const panel = reportSubmit.closest('.profile-review-report-panel');
                const reviewElement = reportSubmit.closest('.profile-review');
                const reviewId = Number(reviewElement?.dataset.reviewId || 0);
                if (!panel || !reviewElement || !reviewId) return;

                const reason = panel.querySelector('input[name="report-reason"]:checked')?.value || 'Інше';
                const details = (panel.querySelector('[data-report-details]')?.value || '').trim().slice(0, 1000);

                // Гість: зберігаємо чернетку і ведемо на вхід — після
                // авторизації скарга надішлеться автоматично.
                if (!isReviewAuthenticated) {
                    saveReportDraft(reviewId, reason, details);
                    if (reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                    }
                    return;
                }

                reportSubmit.disabled = true;
                try {
                    const response = await sendReviewReport(reviewId, reason, details);

                    if (response.status === 401 && reviewLoginUrl) {
                        // Сесія протухла — не втрачаємо написане.
                        saveReportDraft(reviewId, reason, details);
                        window.location.href = reviewLoginUrl;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    panel.remove();
                    markReportSubmitted(reviewId);
                    showReportToast('Скаргу надіслано на модерацію.');
                } catch (error) {
                    console.error('Failed to report review', error);
                    reportSubmit.disabled = false;
                }

                return;
            }

            // --- report: open the reason picker ---
            const reportButton = event.target.closest('[data-review-report]');
            if (reportButton) {
                event.preventDefault();

                if (reportButton.classList.contains('is-submitted')) return;

                const reviewElement = reportButton.closest('.profile-review');
                if (!reviewElement || !Number(reviewElement.dataset.reviewId || 0)) return;

                // Toggle: clicking again closes; only one open panel at a time.
                const existing = reviewElement.querySelector('.profile-review-report-panel');
                document.querySelectorAll('.profile-review-report-panel').forEach((node) => node.remove());
                if (existing) return;

                const reasons = ['Спам або реклама', 'Образи чи ненормативна лексика', 'Неправдива інформація', 'Інше'];
                const panel = document.createElement('div');
                panel.className = 'profile-review-report-panel';
                panel.innerHTML = `
                    <p class="profile-review-report-panel__title">Чому ви скаржитесь на цей відгук?</p>
                    <div class="profile-review-report-panel__options">
                        ${reasons.map((reason, index) => `
                            <label class="profile-review-report-panel__option">
                                <input type="radio" name="report-reason" value="${reason}" ${index === 0 ? 'checked' : ''}>
                                <span>${reason}</span>
                            </label>
                        `).join('')}
                    </div>
                    <textarea class="profile-review-report-panel__details" data-report-details rows="2" maxlength="1000" placeholder="Коментар для модератора (необовʼязково)"></textarea>
                    <div class="profile-review-report-panel__actions">
                        <button type="button" class="btn btn--primary profile-review-report-panel__submit" data-report-submit>${isReviewAuthenticated ? 'Надіслати скаргу' : 'Увійти і надіслати'}</button>
                        <button type="button" class="profile-review-report-panel__cancel" data-report-cancel>Скасувати</button>
                    </div>
                    ${isReviewAuthenticated ? '' : '<p class="profile-review-report-panel__hint"><i class="fa-solid fa-lock" aria-hidden="true"></i> Для скарги потрібен вхід. Написане збережеться й надішлеться автоматично після авторизації.</p>'}
                `;
                reportButton.parentElement.insertAdjacentElement('afterend', panel);
                return;
            }

            const rootToggle = event.target.closest('[data-review-root-toggle]');
            if (rootToggle) {
                event.preventDefault();

                if (!isReviewAuthenticated) {
                    if (reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                    }
                    return;
                }

                openRootCommentForm(rootToggle.closest('.profile-review'), rootToggle);
                return;
            }

            const reactionButton = event.target.closest('[data-review-reaction]');
            if (reactionButton) {
                event.preventDefault();

                if (!isReviewAuthenticated) {
                    if (reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                    }
                    return;
                }

                const reviewElement = reactionButton.closest('.profile-review');
                const reviewId = Number(reviewElement?.dataset.reviewId || 0);
                const reaction = String(reactionButton.dataset.reviewReaction || '').trim();
                if (!reviewElement || !reviewId || !reaction) return;

                const buttons = Array.from(reviewElement.querySelectorAll('[data-review-reaction]'));
                buttons.forEach((button) => {
                    button.disabled = true;
                });

                try {
                    const response = await fetch(`/profiles/${encodeURIComponent(reviewsSlug)}/reviews/${reviewId}/reaction`, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': reviewCsrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ reaction }),
                    });

                    if (response.status === 401 && reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    const activeReaction = String(payload.reaction || '');
                    reviewElement.querySelector('[data-review-like-count]').textContent = String(Number(payload.like_count || 0));
                    reviewElement.querySelector('[data-review-dislike-count]').textContent = String(Number(payload.dislike_count || 0));
                    buttons.forEach((button) => {
                        button.classList.toggle('is-active', button.dataset.reviewReaction === activeReaction);
                    });
                } catch (error) {
                    console.error('Failed to toggle review reaction', error);
                } finally {
                    buttons.forEach((button) => {
                        button.disabled = false;
                    });
                }

                return;
            }

            const commentReactionButton = event.target.closest('[data-review-comment-reaction], [data-review-official-reaction]');
            if (commentReactionButton) {
                event.preventDefault();

                if (!isReviewAuthenticated) {
                    if (reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                    }
                    return;
                }

                const reviewElement = commentReactionButton.closest('.profile-review');
                const commentCard = commentReactionButton.closest('.profile-review-comment');
                const commentActions = commentReactionButton.closest('.profile-review-comment__actions');
                const reviewId = Number(reviewElement?.dataset.reviewId || 0);
                const isOfficialReaction = commentReactionButton.hasAttribute('data-review-official-reaction');
                const reaction = String(
                    isOfficialReaction
                        ? commentReactionButton.dataset.reviewOfficialReaction
                        : commentReactionButton.dataset.reviewCommentReaction
                ).trim();
                const replyId = String(commentCard?.dataset.reviewCommentId || '').trim();
                if (!reviewElement || !commentCard || !commentActions || !reviewId || !reaction) return;
                if (!isOfficialReaction && !Number(replyId)) return;

                const buttons = Array.from(commentActions?.querySelectorAll('[data-review-comment-reaction], [data-review-official-reaction]') || []);
                buttons.forEach((button) => {
                    button.disabled = true;
                });

                const endpoint = isOfficialReaction
                    ? `/profiles/${encodeURIComponent(reviewsSlug)}/reviews/${reviewId}/official-reply/reaction`
                    : `/profiles/${encodeURIComponent(reviewsSlug)}/reviews/${reviewId}/replies/${encodeURIComponent(replyId)}/reaction`;

                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': reviewCsrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ reaction }),
                    });

                    if (response.status === 401 && reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    const activeReaction = String(payload.reaction || '');
                    commentActions.querySelector('[data-review-comment-like-count]').textContent = String(Number(payload.like_count || 0));
                    commentActions.querySelector('[data-review-comment-dislike-count]').textContent = String(Number(payload.dislike_count || 0));
                    buttons.forEach((button) => {
                        const buttonReaction = isOfficialReaction
                            ? button.dataset.reviewOfficialReaction
                            : button.dataset.reviewCommentReaction;
                        button.classList.toggle('is-active', buttonReaction === activeReaction);
                    });
                } catch (error) {
                    console.error('Failed to toggle comment reaction', error);
                } finally {
                    buttons.forEach((button) => {
                        button.disabled = false;
                    });
                }

                return;
            }

            const replyToggle = event.target.closest('[data-review-reply-toggle]');
            if (replyToggle) {
                event.preventDefault();

                if (!isReviewAuthenticated) {
                    if (reviewLoginUrl) {
                        window.location.href = reviewLoginUrl;
                    }
                    return;
                }

                const commentCard = replyToggle.closest('.profile-review-comment');
                const replyForm = commentCard?.querySelector('[data-review-comment-form]');
                if (!replyForm) return;
                const reviewElement = replyToggle.closest('.profile-review');
                const nextOpen = replyForm.classList.contains('is-hidden');
                setCommentsPanelOpen(reviewElement, true);
                closeSiblingReplyForms(reviewElement, nextOpen ? replyForm : null);
                replyForm.classList.toggle('is-hidden', !nextOpen);
                if (nextOpen) {
                    replyForm.classList.add('is-expanded');
                    const textarea = replyForm.querySelector('textarea');
                    const mentionAuthor = String(replyToggle.dataset.reviewReplyAuthor || commentCard?.dataset.reviewCommentAuthor || '').trim();
                    setCommentFormTitle(replyForm, mentionAuthor ? `Відповісти на ${mentionAuthor}` : 'Відповісти');
                    if (textarea && !String(textarea.value || '').trim() && mentionAuthor) {
                        textarea.value = `@${mentionAuthor}, `;
                    }
                    textarea?.focus();
                    textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
                    updateCommentFormState(replyForm);
                } else {
                    resetCommentForm(replyForm, { collapse: true });
                }
            }
        });

        reviewsContainer.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-review-comment-form]');
            if (!form) return;

            event.preventDefault();

            if (!isReviewAuthenticated) {
                if (reviewLoginUrl) {
                    window.location.href = reviewLoginUrl;
                }
                return;
            }

            const reviewElement = form.closest('.profile-review');
            const reviewId = Number(reviewElement?.dataset.reviewId || 0);
            const textarea = form.querySelector('textarea[name="body"]');
            const body = String(textarea?.value || '').trim();
            const parentId = Number(form.dataset.reviewParentId || 0) || null;

            if (!reviewElement || !reviewId || !textarea || body.length < 10) {
                textarea?.focus();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.dataset.originalLabel = submitButton.textContent || '';
                submitButton.textContent = parentId ? 'Надсилання...' : 'Публікація...';
            }
            form.classList.add('is-submitting');

            try {
                const response = await fetch(`/profiles/${encodeURIComponent(reviewsSlug)}/reviews/${reviewId}/replies`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': reviewCsrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        body,
                        parent_id: parentId,
                    }),
                });

                if (response.status === 401 && reviewLoginUrl) {
                    window.location.href = reviewLoginUrl;
                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();
                if (payload.reply) {
                    appendCommentToReview(reviewElement, payload.reply);
                    resetCommentForm(form, { collapse: true });
                }
            } catch (error) {
                console.error('Failed to submit review comment', error);
            } finally {
                if (submitButton) {
                    submitButton.textContent = submitButton.dataset.originalLabel || submitButton.textContent;
                    submitButton.disabled = false;
                }
                form.classList.remove('is-submitting');
                updateCommentFormState(form);
            }
        });

        if (mobileReviewFilterToggle && mobileReviewFilterSheet) {
            mobileReviewFilterToggle.addEventListener('click', (event) => {
                event.preventDefault();
                setReviewSheetOpen(mobileReviewFilterSheet, mobileReviewFilterToggle, !mobileReviewFilterSheet.open);
            });
            mobileReviewFilterCloseButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    setReviewSheetOpen(mobileReviewFilterSheet, mobileReviewFilterToggle, false);
                });
            });
            mobileReviewFilterOptions.forEach((button) => {
                button.addEventListener('click', () => {
                    activeFilter = button.dataset.reviewMobileFilterOption || 'all';
                    syncDesktopReviewFilters();
                    updateMobileReviewFilterState();
                    applyReviewsState();
                    setReviewSheetOpen(mobileReviewFilterSheet, mobileReviewFilterToggle, false);
                });
            });
        }

        if (mobileReviewSortToggle && mobileReviewSortSheet) {
            mobileReviewSortToggle.addEventListener('click', (event) => {
                event.preventDefault();
                setReviewSheetOpen(mobileReviewSortSheet, mobileReviewSortToggle, !mobileReviewSortSheet.open);
            });
            mobileReviewSortCloseButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    setReviewSheetOpen(mobileReviewSortSheet, mobileReviewSortToggle, false);
                });
            });
            mobileReviewSortOptions.forEach((button) => {
                button.addEventListener('click', () => {
                    if (!sortSelect) return;
                    sortSelect.value = button.dataset.reviewMobileSortOption || 'newest';
                    updateMobileReviewSortState();
                    applyReviewsState();
                    setReviewSheetOpen(mobileReviewSortSheet, mobileReviewSortToggle, false);
                });
            });
        }

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;

            if (mobileReviewFilterSheet?.open && !mobileReviewFilterSheet.contains(target) && !mobileReviewFilterToggle?.contains(target)) {
                setReviewSheetOpen(mobileReviewFilterSheet, mobileReviewFilterToggle, false);
            }

            if (mobileReviewSortSheet?.open && !mobileReviewSortSheet.contains(target) && !mobileReviewSortToggle?.contains(target)) {
                setReviewSheetOpen(mobileReviewSortSheet, mobileReviewSortToggle, false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (mobileReviewFilterSheet?.open) {
                setReviewSheetOpen(mobileReviewFilterSheet, mobileReviewFilterToggle, false);
            }
            if (mobileReviewSortSheet?.open) {
                setReviewSheetOpen(mobileReviewSortSheet, mobileReviewSortToggle, false);
            }
        });

        const armAutoLoad = () => {
            autoLoadArmed = true;
        };
        window.addEventListener('wheel', armAutoLoad, { passive: true });
        window.addEventListener('touchmove', armAutoLoad, { passive: true });
        window.addEventListener('scroll', armAutoLoad, { passive: true });

        if (reviewsSentinel && 'IntersectionObserver' in window) {
            loadMoreObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    if (!autoLoadArmed) return;
                    if (autoLoadsDone >= maxAutoLoads) return;
                    autoLoadArmed = false;
                    loadMoreReviews({ auto: true });
                });
            }, {
                root: null,
                threshold: 0.35,
                rootMargin: '0px 0px 120px 0px',
            });
            loadMoreObserver.observe(reviewsSentinel);
        }

        updateReviewsCountLabel();
        syncDesktopReviewFilters();
        updateMobileReviewFilterState();
        updateMobileReviewSortState();
        applyReviewAvatarGradients(reviewsContainer);
        applyReviewsState();
        bindAllCommentForms(reviewsContainer);
        reviewsContainer.querySelectorAll('.profile-review').forEach(updateCommentsPreviewState);
    }

    const aboutText = root.querySelector('[data-about-text]');
    const aboutToggle = root.querySelector('[data-about-toggle]');
    if (aboutText && aboutToggle) {
        const measureAboutOverflow = () => {
            const previousExpanded = aboutText.classList.contains('is-expanded');

            aboutText.classList.remove('is-expanded');
            aboutText.classList.add('is-collapsed');

            const visibleHeight = aboutText.clientHeight;
            const fullHeight = aboutText.scrollHeight;
            const hasOverflow = fullHeight > (visibleHeight + 1);

            if (hasOverflow) {
                aboutText.classList.toggle('is-expanded', previousExpanded);
                aboutText.classList.toggle('is-collapsed', !previousExpanded);
            } else {
                aboutText.classList.remove('is-expanded');
                aboutText.classList.remove('is-collapsed');
            }

            return hasOverflow;
        };

        const updateAboutToggle = () => {
            const hasOverflow = measureAboutOverflow();
            aboutToggle.classList.toggle('is-hidden', !hasOverflow);

            const expanded = hasOverflow && aboutText.classList.contains('is-expanded');
            aboutToggle.textContent = expanded ? 'Згорнути' : 'Читати більше';
            aboutToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        };

        aboutToggle.addEventListener('click', () => {
            if (aboutToggle.classList.contains('is-hidden')) return;
            const expanded = aboutText.classList.toggle('is-expanded');
            aboutText.classList.toggle('is-collapsed', !expanded);
            aboutToggle.textContent = expanded ? 'Згорнути' : 'Читати більше';
            aboutToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });

        requestAnimationFrame(updateAboutToggle);
        window.addEventListener('resize', updateAboutToggle);
        window.addEventListener('pageshow', updateAboutToggle);
    }

    // --- AI-аналіз/досьє на всю ширину: довгий контент згортається до
    // фіксованої висоти і розгортається кнопкою «Показати повністю». ---
    (() => {
        const aiPanel = root.querySelector('.profile-summary-overview .profile-summary-panel--ai');
        const clampBody = aiPanel ? aiPanel.querySelector('[data-ai-collapse-body]') : null;
        const collapseToggle = aiPanel ? aiPanel.querySelector('[data-ai-collapse-toggle]') : null;
        if (!aiPanel || !clampBody || !collapseToggle) return;

        const collapseLabel = collapseToggle.querySelector('[data-ai-toggle-label]');
        const collapsedHeight = 240;
        let aiCollapsed = true;
        let collapseAnimFallback = 0;

        const setCollapseUi = () => {
            aiPanel.classList.toggle('is-collapsed', aiCollapsed);
            collapseToggle.classList.toggle('is-open', !aiCollapsed);
            collapseToggle.setAttribute('aria-expanded', aiCollapsed ? 'false' : 'true');
            if (collapseLabel) collapseLabel.textContent = aiCollapsed ? 'Показати повністю' : 'Згорнути';
        };

        // Миттєве застосування стану (перший рендер, ресайз) — без анімації.
        // Кнопка зʼявляється лише коли контент помітно довший за поріг —
        // згортати заради пари прихованих рядків немає сенсу.
        const applyCollapse = () => {
            clampBody.style.transition = 'none';
            clampBody.style.maxHeight = '';
            const fits = clampBody.scrollHeight <= collapsedHeight + 80;
            if (fits) {
                aiPanel.classList.remove('is-collapsed');
                collapseToggle.hidden = true;
            } else {
                collapseToggle.hidden = false;
                setCollapseUi();
                if (aiCollapsed) clampBody.style.maxHeight = `${collapsedHeight}px`;
            }
            void clampBody.offsetHeight;
            clampBody.style.transition = '';
        };

        // Плавне перемикання: max-height анімується між конкретними px,
        // після розгортання обмеження знімається зовсім.
        collapseToggle.addEventListener('click', () => {
            aiCollapsed = !aiCollapsed;

            // Згортання великого блоку стискає контент над кнопкою — сторінку
            // «викидає» вниз до відгуків. Повертаємо користувача до самого
            // блоку: якщо його верх опинився за хедером, плавно скролимо назад.
            if (aiCollapsed) {
                const headerOffset = (parseFloat(
                    getComputedStyle(document.documentElement).getPropertyValue('--header-h')
                ) || 64) + 12;
                if (aiPanel.getBoundingClientRect().top < headerOffset) {
                    const targetY = aiPanel.getBoundingClientRect().top + window.pageYOffset - headerOffset;
                    window.scrollTo({ top: Math.max(0, targetY), behavior: 'smooth' });
                }
            }

            window.clearTimeout(collapseAnimFallback);
            const startHeight = clampBody.getBoundingClientRect().height;
            const targetHeight = aiCollapsed ? collapsedHeight : clampBody.scrollHeight;

            clampBody.style.maxHeight = `${startHeight}px`;
            void clampBody.offsetHeight;
            clampBody.style.maxHeight = `${targetHeight}px`;
            setCollapseUi();

            const finish = () => {
                clampBody.removeEventListener('transitionend', onEnd);
                if (!aiCollapsed) clampBody.style.maxHeight = '';
            };
            const onEnd = (event) => {
                if (event.propertyName !== 'max-height') return;
                finish();
            };
            clampBody.addEventListener('transitionend', onEnd);
            collapseAnimFallback = window.setTimeout(finish, 450);
        });

        let collapseResizeId = 0;
        window.addEventListener('resize', () => {
            window.clearTimeout(collapseResizeId);
            collapseResizeId = window.setTimeout(applyCollapse, 150);
        }, { passive: true });

        applyCollapse();
        // Вебфонт міг змінити висоти після першого прорахунку.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(applyCollapse).catch(() => {});
        }
    })();
})();
</script>
@endpush
