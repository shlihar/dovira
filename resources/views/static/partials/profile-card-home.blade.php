@php
    $profileUrl = $profileUrl ?? fn (string $slug) => route('profile.show', ['slug' => $slug]);
    $profileLink = $profileUrl((string) ($profile['slug'] ?? ''));

    $name = trim((string) ($profile['name'] ?? ''));
    $initials = collect(preg_split('/\s+/', $name))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr((string) $part, 0, 1)))->implode('');
    $logoUrl = trim((string) ($profile['logo_image_url'] ?? $profile['logo_url'] ?? ''));

    $siteDisplay = \App\Support\WebsiteUrl::display($profile['website'] ?? null);
    $siteHref = \App\Support\WebsiteUrl::href($profile['website'] ?? null);

    $rating = (float) ($profile['rating'] ?? 0);
    $fullStars = (int) floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = max(0, 5 - $fullStars - ($hasHalfStar ? 1 : 0));
    $starToneClass = match (true) {
        $rating >= 4 => 'rating-stars--excellent',
        $rating >= 3 => 'rating-stars--fair',
        default => 'rating-stars--poor',
    };

    $reviewsCount = (int) ($profile['reviews_count'] ?? 0);
    $reviewsLabel = match (true) {
        $reviewsCount % 10 === 1 && $reviewsCount % 100 !== 11 => 'відгук',
        in_array($reviewsCount % 10, [2, 3, 4], true) && !in_array($reviewsCount % 100, [12, 13, 14], true) => 'відгуки',
        default => 'відгуків',
    };

    $categoryIcon = (string) ($profile['category_icon'] ?? 'fa-solid fa-building');
    $categoryLabel = (string) ($profile['category_label'] ?? '');
@endphp

<article class="dovira-home-card best-lawyer-card">
    <div class="dovira-home-card__top">
        <a href="{{ $profileLink }}" class="dovira-home-card__logo entity-logo review-list-card__logo {{ $logoUrl === '' ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $name }}">
            @if ($logoUrl !== '')
                <img
                    src="{{ $logoUrl }}"
                    alt="Профіль {{ $name }}"
                    loading="lazy"
                    onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"
                >
                <span class="review-list-card__fallback" aria-hidden="true">{{ $initials }}</span>
            @else
                {{ $initials }}
            @endif
        </a>

        <div class="dovira-home-card__badges" aria-label="Статуси профілю">
            @if (!empty($profile['verified']))
                <span class="dovira-home-card__badge dovira-home-card__badge--verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
            @endif
        </div>
    </div>

    <h3 class="dovira-home-card__name">
        <a href="{{ $profileLink }}">{{ $name }}</a>
        @if (!empty($profile['owner_verified']))
            <span
                class="owner-verified-mark owner-verified-mark--home"
                title="Профіль підтверджений власником або офіційним представником."
                aria-label="Підтверджений власником профіль"
            ></span>
        @endif
    </h3>

    @if ($siteDisplay && $siteHref)
        <a class="dovira-home-card__site" href="{{ $siteHref }}" target="_blank" rel="noopener noreferrer">
            <i class="fa-solid fa-globe" aria-hidden="true"></i>
            <span>{{ $siteDisplay }}</span>
        </a>
    @endif

    @if (\App\Support\RatingDisplay::visible($reviewsCount))
        <div class="dovira-home-card__rating" aria-label="Рейтинг {{ number_format($rating, 1) }} з 5">
            <span class="dovira-home-card__stars rating-stars {{ $starToneClass }}">
                @for ($i = 0; $i < $fullStars; $i++)<i class="fa-solid fa-star"></i>@endfor
                @if ($hasHalfStar)<i class="fa-solid fa-star-half-stroke"></i>@endif
                @for ($i = 0; $i < $emptyStars; $i++)<i class="fa-regular fa-star"></i>@endfor
            </span>
            <strong>{{ number_format($rating, 1) }}</strong>
            <span>({{ $reviewsCount }} {{ $reviewsLabel }})</span>
        </div>
    @else
        {{-- Мало відгуків для чесного середнього — цифру не показуємо. --}}
        <div class="dovira-home-card__rating dovira-home-card__rating--pending">
            <i class="fa-regular fa-star" aria-hidden="true"></i>
            <span>@if ($reviewsCount > 0){{ $reviewsCount }} {{ $reviewsLabel }} · @endif оцінка формується</span>
        </div>
    @endif

    @if ($categoryLabel !== '')
        <span class="dovira-home-card__category" title="{{ $categoryLabel }}" aria-label="Категорія: {{ $categoryLabel }}">
            <i class="{{ $categoryIcon }}" aria-hidden="true"></i>
        </span>
    @endif
</article>
