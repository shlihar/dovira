@php
    use App\Support\Plural;
    use Illuminate\Support\Carbon;

    $frCards = array_slice($freshReviews ?? [], 0, 3);
    $frToday = (int) ($reviewsToday ?? 0);

    // Відносний час — головна робота блоку «свіжість».
    $frTime = static function (?string $iso): ?string {
        if (! $iso) {
            return null;
        }
        $published = Carbon::parse($iso);
        $minutes = max(1, (int) $published->diffInMinutes(now()));
        if ($minutes < 60) {
            return $minutes.' хв тому';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours.' год тому';
        }
        $days = intdiv($hours, 24);
        if ($days < 30) {
            return $days.' дн тому';
        }

        return $published->translatedFormat('j M Y');
    };

    // «Клієнт ****5283» — слід імпорту; маску з зірочок/цифр прибираємо.
    $frAuthor = static function (string $name): string {
        $clean = trim((string) preg_replace('/\s*\*{2,}\s*\d*/u', '', $name));
        $clean = trim((string) preg_replace('/\s{2,}/u', ' ', $clean));

        return $clean !== '' ? $clean : 'Клієнт';
    };
@endphp

@if (! empty($frCards))
<section class="section fresh-reviews" aria-labelledby="fresh-reviews-title">
    <div class="container">
        <div class="fresh-reviews__head">
            <div class="fresh-reviews__head-text">
                <h2 id="fresh-reviews-title" class="fresh-reviews__title">Свіжі відгуки</h2>
                <p class="fresh-reviews__sub">Що пишуть про бізнес просто зараз — без фільтру на «тільки хороші»</p>
            </div>
            <a class="fresh-reviews__all" href="{{ route('catalog', ['sort' => 'reviews_desc']) }}">Всі відгуки <span aria-hidden="true">→</span></a>
        </div>

        <div class="fresh-reviews__grid">
            @foreach ($frCards as $frCard)
                @php
                    $frFull = (int) floor($frCard['rating']);
                    $frHalf = ($frCard['rating'] - $frFull) >= 0.5;
                    $frEmpty = max(0, 5 - $frFull - ($frHalf ? 1 : 0));
                    $frMeta = implode(' · ', array_filter([$frCard['profile_category'] ?? null, $frCard['profile_city'] ?? null]));
                    $frWhen = $frTime($frCard['published_at'] ?? null);
                @endphp
                <article class="platform-review-card">
                    <div class="platform-review-card__head">
                        <span class="platform-review-card__avatar">
                            @if (! empty($frCard['avatar_url']))
                                <img src="{{ $frCard['avatar_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                            @endif
                            <span aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></span>
                        </span>
                        <div class="platform-review-card__meta">
                            <strong>{{ $frAuthor($frCard['author']) }}</strong>
                            {{-- Лише зірки, без дубля цифрою. --}}
                            <span class="platform-review-card__stars" aria-label="Оцінка {{ number_format($frCard['rating'], 1) }} з 5">
                                @for ($i = 0; $i < $frFull; $i++)<i class="fa-solid fa-star"></i>@endfor
                                @if ($frHalf)<i class="fa-solid fa-star-half-stroke"></i>@endif
                                @for ($i = 0; $i < $frEmpty; $i++)<i class="fa-regular fa-star"></i>@endfor
                            </span>
                        </div>
                        @if ($frWhen)
                            <time class="fresh-reviews__time" datetime="{{ $frCard['published_at'] }}">{{ $frWhen }}</time>
                        @endif
                    </div>

                    <p class="platform-review-card__text">{{ $frCard['text'] }}</p>

                    <a
                        class="platform-review-card__profile"
                        href="{{ route('profile.show', ['slug' => $frCard['profile_slug']]) }}"
                        aria-label="Перейти до профілю {{ $frCard['profile_name'] }}"
                    >
                        <span class="platform-review-card__logo">
                            @if (! empty($frCard['profile_logo_url']))
                                <img src="{{ $frCard['profile_logo_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                            @endif
                            <span aria-hidden="true">{{ $frCard['profile_initials'] ?? 'DV' }}</span>
                        </span>
                        <span class="platform-review-card__profile-meta">
                            <strong>{{ $frCard['profile_name'] }}</strong>
                            {{-- Категорія · місто (домен тут не орієнтир). --}}
                            @if ($frMeta !== '')
                                <span>{{ $frMeta }}</span>
                            @endif
                        </span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </article>
            @endforeach

            {{-- Четверта плитка — дія: людина щойно прочитала чужі відгуки,
                 найкращий момент запропонувати написати свій. Єдина кольорова
                 плитка секції: синя сцена, інтерактивні зірки (клік по зірці
                 теж відкриває форму), соціальний доказ і біла кнопка. --}}
            <div class="fresh-reviews__cta">
                <span class="fresh-reviews__cta-glow" aria-hidden="true"></span>
                <i class="fa-solid fa-quote-right fresh-reviews__cta-mark" aria-hidden="true"></i>

                <div class="fresh-reviews__cta-stars" aria-hidden="true">
                    @for ($i = 0; $i < 5; $i++)
                        <button type="button" class="fresh-reviews__cta-star" data-open-review-popup tabindex="-1">
                            <i class="fa-solid fa-star"></i>
                        </button>
                    @endfor
                </div>

                <strong>Оцініть свій досвід</strong>
                <p>Дві хвилини — і наступна людина обере впевнено.</p>

                @if ($frToday > 0)
                    <span class="fresh-reviews__cta-proof">
                        <span class="fresh-reviews__cta-dot" aria-hidden="true"></span>
                        сьогодні вже +{{ $frToday }} {{ Plural::uk($frToday, 'відгук', 'відгуки', 'відгуків') }}
                    </span>
                @endif

                <a class="fresh-reviews__cta-btn" href="{{ route('catalog') }}" data-open-review-popup>
                    Залишити відгук <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>
</section>
@endif
