@php
    use App\Support\CategoryVisuals;
    use App\Support\Plural;

    $catCards = $categoryCards ?? [];
    $tickerItems = $categoryTicker['items'] ?? [];

    // Показуємо 6 категорій (чистий ряд 3+3). Решта лишаються в DOM (для
    // індексації), приховані CSS; кнопку показуємо лише коли ховаємо кілька.
    $visibleLimit = 6;
    $hiddenCount = max(0, count($catCards) - $visibleLimit);
    $showMore = $hiddenCount >= 3;

    $nb = "\u{00A0}"; // нерозривний пробіл як роздільник тисяч

    $minLabel = static function (int $m): string {
        if ($m < 60) return $m.' хв тому';
        $hours = intdiv($m, 60);
        if ($hours < 24) return $hours.' год тому';
        return intdiv($hours, 24).' дн тому';
    };

    $tickerFirst = $tickerItems[0] ?? null;
@endphp

@if (! empty($catCards))
<section class="section categories" aria-labelledby="categories-title">
    <div class="container">
        <div class="categories__head">
            <div class="categories__head-text">
                <h2 id="categories-title" class="categories__title">Категорії відгуків</h2>
                <p class="categories__sub">Обирайте напрям — і читайте реальні відгуки про компанії та спеціалістів</p>
            </div>
            {{-- Правий стовпчик: стрічка активності над «Весь каталог». --}}
            <div class="categories__head-aside">
                @if ($tickerFirst)
                    <a class="categories__ticker"
                       href="{{ route('catalog.landing', ['category' => $tickerFirst['category_slug']]) }}"
                       data-cat-ticker
                       data-items="{{ json_encode($tickerItems, JSON_UNESCAPED_UNICODE) }}">
                        <span class="categories__ticker-dot" aria-hidden="true"></span>
                        <span class="categories__ticker-text" data-cat-ticker-text>{{ $minLabel($tickerFirst['minutes_ago']) }} · новий відгук у категорії «{{ $tickerFirst['category_name'] }}»</span>
                    </a>
                @endif
                <a class="categories__all" href="{{ route('catalog') }}">Весь каталог <span aria-hidden="true">→</span></a>
            </div>
        </div>

        {{-- Мобільний app-like перемикач: горизонтальні пігулки категорій,
             видима одна активна картка-панель. На десктопі схований CSS. --}}
        <div class="categories__switch" data-cat-switch role="tablist" aria-label="Категорії">
            @foreach ($catCards as $i => $card)
                <button
                    type="button"
                    class="categories__switch-pill {{ $i === 0 ? 'is-active' : '' }}"
                    data-cat-pill="{{ $i }}"
                    role="tab"
                    aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                >{{ $card['name'] }}</button>
            @endforeach
        </div>

        {{-- Усі категорії в DOM одразу; зайві ховаються CSS-класом (для індексації). --}}
        <div class="categories__grid" data-cat-grid>
            @foreach ($catCards as $i => $card)
                @php
                    $color = CategoryVisuals::color($card['slug']);

                    $pos = (int) $card['dist_positive'];
                    $neu = (int) $card['dist_neutral'];
                    $neg = (int) $card['dist_negative'];
                    $distTotal = $pos + $neu + $neg;
                    $showBar = $card['reviews_count'] >= 20 && $distTotal > 0;

                    $segPos = $segNeu = $segNeg = 0.0;
                    $barTitle = '';
                    if ($showBar) {
                        $raw = [
                            'pos' => $pos > 0 ? $pos / $distTotal * 100 : 0.0,
                            'neu' => $neu > 0 ? $neu / $distTotal * 100 : 0.0,
                            'neg' => $neg > 0 ? $neg / $distTotal * 100 : 0.0,
                        ];
                        // Мінімум 3% для непорожнього сегмента; дефіцит знімаємо з найбільшого.
                        $seg = [];
                        foreach ($raw as $k => $v) {
                            $seg[$k] = $v > 0 ? max(3.0, $v) : 0.0;
                        }
                        $deficit = ($seg['pos'] + $seg['neu'] + $seg['neg']) - 100.0;
                        if ($deficit > 0.001) {
                            $largest = array_keys($seg, max($seg))[0];
                            $seg[$largest] = max(3.0, $seg[$largest] - $deficit);
                        }
                        $segPos = round($seg['pos'], 2);
                        $segNeu = round($seg['neu'], 2);
                        $segNeg = round($seg['neg'], 2);

                        $barTitle = round($raw['pos']).'% позитивних, '
                            .round($raw['neu']).'% нейтральних, '
                            .round($raw['neg']).'% негативних';
                    }

                    $companiesFmt = number_format($card['companies_count'], 0, '', $nb);
                    $reviewsFmt = number_format($card['reviews_count'], 0, '', $nb);
                    $avgFmt = number_format($card['avg_rating'], 1);

                    $companiesWord = Plural::uk($card['companies_count'], 'компанія', 'компанії', 'компаній');
                    $reviewsWord = Plural::uk($card['reviews_count'], 'відгук', 'відгуки', 'відгуків');

                    $ariaLabel = $card['name'].', '.$companiesFmt.' '.$companiesWord.', середня оцінка '.$avgFmt;
                    $subs = array_slice($card['subcategories'], 0, 6);
                    $catHref = route('catalog.landing', ['category' => $card['slug']]);
                @endphp
                <div class="cat-card {{ $i >= $visibleLimit ? 'cat-card--extra' : '' }} {{ $i === 0 ? 'is-active' : '' }}" data-cat-panel="{{ $i }}">
                    <a class="cat-card__lead" href="{{ $catHref }}" aria-label="{{ $ariaLabel }}">
                        <span class="cat-card__icon" style="background: {{ $color['bg'] }}; color: {{ $color['ink'] }};">
                            {!! CategoryVisuals::iconSvg($card['slug']) !!}
                        </span>
                        <span class="cat-card__name">{{ $card['name'] }}</span>
                    </a>

                    @if ($showBar)
                        <div class="cat-card__bar" aria-hidden="true" title="{{ $barTitle }}">
                            @if ($segPos > 0)<span class="cat-card__seg cat-card__seg--pos" style="width: {{ $segPos }}%;"></span>@endif
                            @if ($segNeu > 0)<span class="cat-card__seg cat-card__seg--neu" style="width: {{ $segNeu }}%;"></span>@endif
                            @if ($segNeg > 0)<span class="cat-card__seg cat-card__seg--neg" style="width: {{ $segNeg }}%;"></span>@endif
                        </div>
                    @else
                        <div class="cat-card__forming">рейтинг формується</div>
                    @endif

                    @if (! empty($subs))
                        <div class="cat-card__subs">
                            @foreach ($subs as $sub)
                                <a class="cat-card__sub" href="{{ route('catalog.landing', ['category' => $sub['slug']]) }}">{{ $sub['name'] }}</a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Футер: метрики + «Усі напрями» одним рядком — вирівнює висоти. --}}
                    <div class="cat-card__foot">
                        <span class="cat-card__metrics">{{ $companiesFmt }} {{ $companiesWord }} · {{ $reviewsFmt }} {{ $reviewsWord }} · <b>{{ $avgFmt }}</b> <span class="cat-card__star" aria-hidden="true">★</span></span>
                        <a class="cat-card__all-dirs" href="{{ $catHref }}" aria-label="Усі напрями категорії {{ $card['name'] }}">Усі напрями <span aria-hidden="true">→</span></a>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($showMore)
            <button type="button" class="categories__more" data-cat-more>
                Показати ще {{ $hiddenCount }} {{ Plural::uk($hiddenCount, 'категорію', 'категорії', 'категорій') }}
            </button>
        @endif
    </div>
</section>
@endif
