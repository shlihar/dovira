{{-- Картки «Свіжих відгуків» на головній. Рендеряться і SSR
     (home.blade.php), і GeoController-ом при персоналізації за містом. --}}
@foreach ($reviews as $review)
    @php
        $fullStars = (int) floor($review['rating']);
        $hasHalfStar = ($review['rating'] - $fullStars) >= 0.5;
        $emptyStars = max(0, 5 - $fullStars - ($hasHalfStar ? 1 : 0));
        $starToneClass = match (true) {
            (float) $review['rating'] >= 4 => 'rating-stars--excellent',
            (float) $review['rating'] >= 3 => 'rating-stars--fair',
            default => 'rating-stars--poor',
        };
    @endphp
    <article class="latest-review-card result-card--carousel">
        <div class="latest-review-card__author">
            <div class="latest-review-card__avatar review-list-card__logo {{ empty($review['avatar_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $review['author'] }}" aria-hidden="true">
                @if (!empty($review['avatar_url']))
                    <img
                        src="{{ $review['avatar_url'] }}"
                        alt=""
                        onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"
                    >
                    <span class="review-list-card__fallback" aria-hidden="true">{{ $review['author_initial'] ?? 'К' }}</span>
                @else
                    <span class="review-list-card__fallback review-list-card__fallback--visible" aria-hidden="true">{{ $review['author_initial'] ?? 'К' }}</span>
                @endif
            </div>
            <div class="latest-review-card__meta">
                <h3>{{ $review['author'] }}</h3>
                <div class="latest-review-card__stars rating-stars {{ $starToneClass }}" aria-label="Рейтинг {{ number_format($review['rating'], 1) }} з 5">
                    @for ($i = 0; $i < $fullStars; $i++)
                        <span><i class="fa-solid fa-star"></i></span>
                    @endfor
                    @if ($hasHalfStar)
                        <span><i class="fa-solid fa-star-half-stroke"></i></span>
                    @endif
                    @for ($i = 0; $i < $emptyStars; $i++)
                        <span><i class="fa-regular fa-star"></i></span>
                    @endfor
                    <strong>{{ number_format($review['rating'], 1) }}</strong>
                </div>
            </div>
        </div>
        <p class="latest-review-card__text">{{ $review['text'] }}</p>
        @if (!empty($review['profile_slug']))
            <a
                href="{{ route('profile.show', ['slug' => $review['profile_slug']]) }}"
                class="latest-review-card__company"
                aria-label="Перейти до профілю {{ $review['profile_name'] }}"
            >
                <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo {{ empty($review['profile_logo_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $review['profile_name'] }}" aria-hidden="true">
                    @if (!empty($review['profile_logo_url']))
                        <img
                            src="{{ $review['profile_logo_url'] }}"
                            alt=""
                            onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"
                        >
                        <span class="review-list-card__fallback" aria-hidden="true">{{ $review['profile_initials'] }}</span>
                    @else
                        {{ $review['profile_initials'] }}
                    @endif
                </div>
                <div>
                    <h4>{{ $review['profile_name'] }}</h4>
                    @if (!empty($review['profile_site']))
                        <p>{{ $review['profile_site'] }}</p>
                    @endif
                </div>
            </a>
        @else
            <div class="latest-review-card__company">
                <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo {{ empty($review['profile_logo_url']) ? 'has-random-gradient' : '' }}" data-image-fallback-shell data-seed="{{ $review['profile_name'] }}" aria-hidden="true">
                    @if (!empty($review['profile_logo_url']))
                        <img
                            src="{{ $review['profile_logo_url'] }}"
                            alt=""
                            onerror="window.DoviraHandleSeededImageError && window.DoviraHandleSeededImageError(this)"
                        >
                        <span class="review-list-card__fallback" aria-hidden="true">{{ $review['profile_initials'] }}</span>
                    @else
                        {{ $review['profile_initials'] }}
                    @endif
                </div>
                <div>
                    <h4>{{ $review['profile_name'] }}</h4>
                    @if (!empty($review['profile_site']))
                        <p>{{ $review['profile_site'] }}</p>
                    @endif
                </div>
            </div>
        @endif
    </article>
@endforeach
