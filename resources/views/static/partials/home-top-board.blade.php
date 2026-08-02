{{-- Колонки лідерборду «Топ-рейтинги»: зліва топ адвокатів, справа профілі,
     що набирають популярність (свіжі події й нові відгуки). Рендериться і
     SSR (home.blade.php), і GeoController-ом для персоналізації за містом,
     тому вся логіка побудови колонок живе тут. --}}
@php
    $profileUrl = $profileUrl ?? fn (string $slug) => route('profile.show', [
        'slug' => $slug,
    ]);

    $reviewsWord = function (int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'відгук';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'відгуки';
        }
        return 'відгуків';
    };

    $topLawyers = collect($bestProfiles ?? [])->take(5);
    $topLawyerSlugs = $topLawyers->pluck('slug')->all();
    // Праворуч — без дублів із лівою колонкою.
    $topTrending = collect($trendingProfiles ?? [])
        ->reject(fn ($profile) => in_array($profile['slug'] ?? '', $topLawyerSlugs, true))
        ->take(5);

    $topColumns = [
        [
            'title' => 'Адвокати',
            'icon' => 'fa-solid fa-scale-balanced',
            'href' => route('catalog', ['sub' => 'Адвокати', 'sort' => 'popular']),
            'items' => $topLawyers,
            'trending' => false,
        ],
        [
            'title' => 'Набирають популярність',
            'icon' => 'fa-solid fa-arrow-trend-up',
            'href' => route('catalog', ['sort' => 'popular']),
            'items' => $topTrending,
            'trending' => true,
        ],
    ];
@endphp

@foreach ($topColumns as $column)
    @if ($column['items']->isNotEmpty())
        <div class="home-top__col">
            <div class="home-top__col-head">
                <h3><i class="{{ $column['icon'] }}" aria-hidden="true"></i>{{ $column['title'] }}</h3>
                <a href="{{ $column['href'] }}">Дивитись більше</a>
            </div>
            <ol class="home-top__list">
                @foreach ($column['items'] as $profile)
                    @php
                        $recentReviews = (int) ($profile['recent_reviews_count'] ?? 0);
                    @endphp
                    <li>
                        <a class="home-top__row" href="{{ $profileUrl($profile['slug']) }}">
                            <span class="home-top__rank home-top__rank--{{ min($loop->iteration, 4) }}">{{ $loop->iteration }}</span>
                            <span class="home-top__logo {{ empty($profile['logo_url']) ? 'has-random-gradient' : '' }}" data-seed="{{ $profile['name'] }}" aria-hidden="true">
                                @if (!empty($profile['logo_url']))
                                    <img src="{{ $profile['logo_url'] }}" alt="" loading="lazy">
                                @else
                                    {{ $profile['initials'] }}
                                @endif
                            </span>
                            <span class="home-top__info">
                                <strong>{{ $profile['name'] }}</strong>
                                <span>{{ $profile['city'] ?: ($profile['category_label'] ?? '') }}</span>
                            </span>
                            <span class="home-top__score">
                                @if (\App\Support\RatingDisplay::visible((int) $profile['reviews_count']))
                                    <span class="home-top__stars"><i class="fa-solid fa-star" aria-hidden="true"></i>{{ number_format((float) $profile['rating'], 1) }}</span>
                                @else
                                    <span class="home-top__stars home-top__stars--pending">формується</span>
                                @endif
                                @if ($column['trending'] && $recentReviews > 0)
                                    <em class="home-top__growth">+{{ $recentReviews }} {{ $reviewsWord($recentReviews) }} за місяць</em>
                                @else
                                    <em>{{ number_format((int) $profile['reviews_count'], 0, '', ' ') }} {{ $reviewsWord((int) $profile['reviews_count']) }}</em>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
@endforeach
