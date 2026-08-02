@php
    use App\Support\Plural;

    $lbTabs = array_values(array_filter($leaderboard ?? [], fn ($tab) => ! empty($tab['rows'])));
@endphp

@if (! empty($lbTabs))
<section class="section leaderboard" aria-labelledby="leaderboard-title">
    <div class="container">
        <div class="leaderboard__head">
            <div class="leaderboard__head-text">
                <h2 id="leaderboard-title" class="leaderboard__title">Хто найкращий у своїй справі</h2>
                <p class="leaderboard__sub">Топ профілів за оцінками з поправкою на кількість відгуків</p>
            </div>
            <a class="leaderboard__how" href="{{ route('rating.method') }}">Як формується рейтинг <span aria-hidden="true">→</span></a>
        </div>

        {{-- Таби-підкатегорії: той самий app-like патерн, що в категоріях. --}}
        <div class="leaderboard__tabs" role="tablist" aria-label="Категорії рейтингу">
            @foreach ($lbTabs as $i => $lbTab)
                <button
                    type="button"
                    class="leaderboard__tab {{ $i === 0 ? 'is-active' : '' }}"
                    data-lb-tab="{{ $i }}"
                    role="tab"
                    aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                >{{ $lbTab['name'] }}</button>
            @endforeach
        </div>

        @foreach ($lbTabs as $i => $lbTab)
            <div class="leaderboard__board {{ $i === 0 ? 'is-active' : '' }}" data-lb-panel="{{ $i }}">
                @foreach ($lbTab['rows'] as $r => $row)
                    <a
                        class="leaderboard__row {{ $r < 3 ? 'leaderboard__row--top' : '' }} {{ $r === 0 ? 'leaderboard__row--first' : '' }}"
                        href="{{ route('profile.show', ['slug' => $row['slug']]) }}"
                    >
                        <span class="leaderboard__rank" aria-hidden="true">{{ str_pad($r + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="leaderboard__logo">
                            @if (! empty($row['logo_url']))
                                <img src="{{ $row['logo_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                            @endif
                            <span aria-hidden="true">{{ $row['initials'] }}</span>
                        </span>
                        <span class="leaderboard__main">
                            <span class="leaderboard__name">
                                <strong>{{ $row['name'] }}</strong>
                                @if ($row['verified'])
                                    <i class="fa-solid fa-circle-check leaderboard__check" aria-hidden="true" title="Верифікований профіль"></i>
                                @endif
                                @if ($row['recommended'])
                                    <span class="leaderboard__badge">Dovira рекомендує</span>
                                @endif
                            </span>
                            <span class="leaderboard__meta">{{ implode(' · ', array_filter([$lbTab['name'], $row['city']])) }}</span>
                        </span>
                        <span class="leaderboard__score">
                            <span class="leaderboard__score-rating"><i class="fa-solid fa-star" aria-hidden="true"></i>{{ number_format($row['rating'], 1) }}</span>
                            <span class="leaderboard__score-count">{{ number_format($row['reviews_count'], 0, '', "\u{00A0}") }} {{ Plural::uk($row['reviews_count'], 'відгук', 'відгуки', 'відгуків') }}</span>
                        </span>
                        <i class="fa-solid fa-arrow-right leaderboard__go" aria-hidden="true"></i>
                    </a>
                @endforeach

                <a class="leaderboard__all" href="{{ route('catalog', ['sub' => $lbTab['name'], 'sort' => 'rating_desc']) }}">
                    Весь рейтинг «{{ $lbTab['name'] }}» <span aria-hidden="true">→</span>
                </a>
            </div>
        @endforeach
    </div>
</section>
@endif
