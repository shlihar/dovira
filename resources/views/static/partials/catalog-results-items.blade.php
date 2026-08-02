@php
    $catalogProfileUrl = $catalogProfileUrl ?? fn (string $slug) => route('profile.show', [
        'slug' => $slug,
    ]);
@endphp

@forelse ($profiles as $profile)
    @include('static.partials.profile-card-catalog', [
        'profile' => $profile,
        'profileUrl' => $catalogProfileUrl,
    ])
@empty
    <article class="review-list-card">
        <div class="review-list-card__main">
            <div>
                <h3 class="review-list-card__name">Нічого не знайдено</h3>
                <p class="review-list-card__region">Спробуйте змінити пошуковий запит або фільтри.</p>
            </div>
        </div>
    </article>
@endforelse
