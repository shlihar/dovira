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

    // City only — every profile is in Ukraine, the country suffix just eats
    // horizontal space the direction chips need.
    $locationText = collect([
        $profile['city'] ?? null,
        empty($profile['city']) ? ($profile['district'] ?? null) : null,
    ])->filter()->implode(', ');
    $locationText = $locationText !== '' ? $locationText : ((string) ($profile['address'] ?? ''));

    $serviceChips = collect($profile['services'] ?? [])
        ->map(fn ($s) => trim((string) $s))
        ->filter()
        ->unique()
        ->values();
    // Show the shortest chips first so a long service name never gets cut
    // mid-word in the preview — the full list is always in the "+N" tooltip.
    // Server renders up to 3 as a no-JS baseline; JS (card-tags-tooltip.js)
    // then measures the actual row width and trims to however many really
    // fit — the row itself never wraps to a second line.
    $visibleServices = $serviceChips->sortBy(fn ($s) => mb_strlen($s))->take(3)->values();
@endphp

@php
    $trustModifierClass = !empty($profile['recommended'])
        ? 'dovira-catalog-card--recommended'
        : (!empty($profile['not_recommended']) ? 'dovira-catalog-card--not-recommended' : '');
@endphp
<article class="dovira-catalog-card {{ $trustModifierClass }} {{ (!empty($profile['verified']) || !empty($profile['pro'])) ? 'review-list-card--priority' : '' }}">
    <div class="dovira-catalog-card__identity">
        <div class="dovira-catalog-card__logo-wrap">
            <a href="{{ $profileLink }}" class="dovira-catalog-card__logo entity-logo review-list-card__logo {{ $logoUrl === '' ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $name }}">
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
            @include('static.partials.verification-badge', [
                'verified' => $profile['verified'] ?? false,
                'ownerVerified' => $profile['owner_verified'] ?? false,
                'tooltip' => false,
            ])
        </div>

        <div class="dovira-catalog-card__main">
            <h3 class="dovira-catalog-card__name">
                <a href="{{ $profileLink }}">{{ $name }}</a>
            </h3>

            @if (!empty($profile['recommended']))
                <div class="dovira-catalog-card__badges" aria-label="Статуси профілю">
                    <span class="dovira-catalog-card__badge dovira-catalog-card__badge--recommend"><i class="fa-solid fa-award" aria-hidden="true"></i> Довіра рекомендує</span>
                </div>
            @elseif (!empty($profile['not_recommended']))
                <div class="dovira-catalog-card__badges" aria-label="Статуси профілю">
                    <span class="dovira-catalog-card__badge dovira-catalog-card__badge--not-recommend"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Довіра не рекомендує</span>
                </div>
            @endif

        </div>
    </div>

    <div class="dovira-catalog-card__body">
        @if (\App\Support\RatingDisplay::visible($reviewsCount))
            <div class="dovira-catalog-card__rating" aria-label="Рейтинг {{ number_format($rating, 1) }} з 5">
                <strong>{{ number_format($rating, 1) }}</strong>
                <span class="dovira-catalog-card__stars rating-stars {{ $starToneClass }}">
                    @for ($i = 0; $i < $fullStars; $i++)<i class="fa-solid fa-star"></i>@endfor
                    @if ($hasHalfStar)<i class="fa-solid fa-star-half-stroke"></i>@endif
                    @for ($i = 0; $i < $emptyStars; $i++)<i class="fa-regular fa-star"></i>@endfor
                </span>
                <span class="dovira-catalog-card__reviews-count">{{ $reviewsCount }} {{ $reviewsLabel }}</span>
            </div>
        @elseif ($reviewsCount > 0)
            {{-- Мало відгуків для чесного середнього — цифру не показуємо. --}}
            <p class="dovira-catalog-card__no-reviews">
                <i class="fa-regular fa-star" aria-hidden="true"></i>
                <span>{{ $reviewsCount }} {{ $reviewsLabel }} · оцінка формується</span>
            </p>
        @else
            {{-- A new profile isn't a bad profile: neutral empty state instead
                 of a punishing red "0.0", plus a first-review prompt. --}}
            <p class="dovira-catalog-card__no-reviews">
                <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
                <span>Ще немає відгуків — <a href="#" data-open-review-popup data-profile-slug="{{ $profile['slug'] ?? '' }}" data-profile-name="{{ $name }}">залишити перший</a></span>
            </p>
        @endif

        @if ($locationText !== '')
            <span class="dovira-catalog-card__location">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                {{ $locationText }}
            </span>
        @endif

        @if (!empty($profile['responds']))
            <span class="dovira-catalog-card__responds">
                <i class="fa-solid fa-reply" aria-hidden="true"></i>
                Відповідає на відгуки
            </span>
        @endif

        @if ($visibleServices->isNotEmpty())
            {{-- data-card-tags-source holds the full list for JS; JS measures
                 the row and decides at runtime how many actually fit (never
                 wraps), then toggles the interactive "+N" tooltip trigger. --}}
            <div
                class="dovira-catalog-card__tags"
                aria-label="Напрями"
                data-card-tags-source="{{ $serviceChips->implode('|') }}"
                data-total-services="{{ $serviceChips->count() }}"
            >
                @foreach ($visibleServices as $service)
                    <span class="dovira-catalog-card__tag">{{ $service }}</span>
                @endforeach
                <span class="dovira-catalog-card__tag dovira-catalog-card__tag--more" hidden></span>
            </div>
        @endif
    </div>

    <div class="dovira-catalog-card__actions">
        <a href="{{ $profileLink }}" class="dovira-catalog-card__profile-link">Переглянути профіль</a>
        <button type="button" class="dovira-catalog-card__save" data-favorite-toggle data-favorite-slug="{{ $profile['slug'] }}" aria-label="Зберегти профіль" aria-pressed="false">
            <i class="fa-regular fa-bookmark" aria-hidden="true"></i>
        </button>
    </div>
</article>
