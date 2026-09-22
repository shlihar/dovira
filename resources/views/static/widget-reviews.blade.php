<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Відгуки про {{ $profile->name }} на DOVIRA</title>
<style>
    /* Standalone-сторінка для iframe: жодних зовнішніх ассетів, системні
       шрифти — блок вантажиться миттєво і не залежить від хост-сайту. */
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: transparent; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: var(--rw-text); }

    /* Токени теми. Світла — дефолт; .rw--dark перевизначає нейтральні кольори;
       акцент власник задає атрибутом data-accent на лоадері (інлайн-змінні на .rw). */
    .rw {
        --rw-bg: #fff; --rw-border: #e4e9f2; --rw-text: #1e293b; --rw-body: #334155;
        --rw-muted: #64748b; --rw-faint: #94a3b8;
        --rw-card-bg: #fafbfd; --rw-card-border: #e9edf5; --rw-foot-border: #eef2f8;
        --rw-nav-bg: #fff; --rw-nav-text: #334155; --rw-shadow: rgba(16, 45, 110, .14);
        --rw-star-empty: #dbe3ee;
        --rw-accent: #175cf0; --rw-accent-2: #2d76ff; --rw-accent-hover: #1d4ed8;
        --rw-accent-soft: #e8f0ff; --rw-on-accent: #fff;
        border: 1px solid var(--rw-border); border-radius: 18px; background: var(--rw-bg); padding: 22px 22px 18px; color: var(--rw-text);
    }
    .rw--dark {
        --rw-bg: #16171b; --rw-border: #2b2d34; --rw-text: #f3f4f6; --rw-body: #d5d8de;
        --rw-muted: #a3a9b5; --rw-faint: #7d8494;
        --rw-card-bg: #1f2127; --rw-card-border: #30333b; --rw-foot-border: #2b2d34;
        --rw-nav-bg: #26282f; --rw-nav-text: #e5e7eb; --rw-shadow: rgba(0, 0, 0, .5);
        --rw-star-empty: #3b3e47; --rw-accent-soft: rgba(255, 255, 255, .08);
    }
    .rw--dark .rw-card__avatar--initial { color: var(--rw-text); }
    /* Порожні зірки в розмітці мають fill="#dbe3ee" — перекриваємо під тему. */
    .rw svg[fill="#dbe3ee"] { fill: var(--rw-star-empty); }

    .rw-head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 0 0 18px; }
    .rw-brand { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px 7px 9px; border-radius: 999px; background: linear-gradient(135deg, var(--rw-accent), var(--rw-accent-2)); color: var(--rw-on-accent); font-size: 13px; font-weight: 700; letter-spacing: .02em; text-decoration: none; }
    .rw-brand svg { width: 14px; height: 14px; }
    .rw-score { display: flex; align-items: center; gap: 10px; }
    .rw-score__num { font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1; }
    .rw-score__meta { display: grid; gap: 3px; }
    .rw-stars { position: relative; display: inline-block; line-height: 0; }
    .rw-stars svg { display: block; }
    .rw-stars__fill { position: absolute; inset: 0; overflow: hidden; }
    .rw-score__count { color: var(--rw-muted); font-size: 12.5px; font-weight: 600; }
    .rw-cta { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; border-radius: 11px; background: var(--rw-accent); color: var(--rw-on-accent); font-size: 13.5px; font-weight: 750; text-decoration: none; white-space: nowrap; }
    .rw-cta:hover { background: var(--rw-accent-hover); }

    .rw-body { position: relative; }
    .rw-track { display: grid; grid-auto-flow: column; grid-auto-columns: min(300px, 82%); gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth; padding: 2px; margin: -2px; scrollbar-width: none; }
    .rw-track::-webkit-scrollbar { display: none; }
    .rw--grid .rw-track { grid-auto-flow: row; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); grid-auto-columns: unset; overflow: visible; }

    .rw-card { scroll-snap-align: start; display: flex; flex-direction: column; gap: 10px; padding: 16px; border: 1px solid var(--rw-card-border); border-radius: 14px; background: var(--rw-card-bg); }
    .rw-card__top { display: flex; align-items: center; gap: 10px; }
    .rw-card__avatar { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
    .rw-card__avatar--initial { display: inline-flex; align-items: center; justify-content: center; background: var(--rw-accent-soft); color: var(--rw-accent); font-size: 16px; font-weight: 800; }
    .rw-card__author { font-size: 14px; font-weight: 750; line-height: 1.25; }
    .rw-card__date { margin-top: 2px; color: var(--rw-faint); font-size: 12px; }
    .rw-card__stars { display: inline-flex; gap: 2px; }
    .rw-card__stars svg { width: 14px; height: 14px; }
    .rw-card__text { margin: 0; color: var(--rw-body); font-size: 13.5px; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 6; -webkit-box-orient: vertical; overflow: hidden; }
    .rw-card__more { margin-top: auto; align-self: flex-start; color: var(--rw-accent); font-size: 12.5px; font-weight: 700; text-decoration: none; }
    .rw-card__more:hover { text-decoration: underline; }

    .rw-nav { position: absolute; top: 50%; transform: translateY(-50%); z-index: 2; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border: 1px solid var(--rw-border); border-radius: 50%; background: var(--rw-nav-bg); color: var(--rw-nav-text); cursor: pointer; box-shadow: 0 6px 18px var(--rw-shadow); transition: opacity .15s ease; }
    .rw-nav svg { width: 15px; height: 15px; }
    .rw-nav--prev { left: -10px; }
    .rw-nav--next { right: -10px; }
    .rw-nav[disabled] { opacity: 0; pointer-events: none; }
    .rw--grid .rw-nav { display: none; }

    .rw-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--rw-foot-border); }
    .rw-foot__note { display: inline-flex; align-items: center; gap: 7px; color: var(--rw-faint); font-size: 12px; font-weight: 600; }
    .rw-foot__note svg { width: 12px; height: 12px; color: var(--rw-accent); }
    .rw-foot__all { color: var(--rw-accent); font-size: 13px; font-weight: 750; text-decoration: none; white-space: nowrap; }
    .rw-foot__all:hover { text-decoration: underline; }

    .rw-empty { padding: 26px 10px; color: var(--rw-muted); font-size: 14px; text-align: center; }

    @media (max-width: 560px) {
        .rw { padding: 16px 14px 14px; }
        .rw-cta { margin-left: 0; }
        .rw-nav { display: none; }
    }
</style>
</head>
<body>
@php
    // Зірки в шапці: сірий ряд + золотий, обрізаний по частці рейтингу.
    $starPath = 'M8 .6l2.1 4.6 5 .5-3.8 3.4 1.1 5-4.4-2.6-4.4 2.6 1.1-5L.9 5.7l5-.5z';
    $starsRow = '';
    for ($i = 0; $i < 5; $i++) {
        $starsRow .= '<path d="' . $starPath . '" transform="translate(' . ($i * 18) . ',0)"/>';
    }
@endphp

<div class="rw rw--{{ $layout }} rw--{{ $theme ?? 'light' }}" data-rw @if (!empty($accent)) style="--rw-accent:{{ $accent }};--rw-accent-2:{{ $accent }};--rw-accent-hover:{{ $accent }};--rw-on-accent:{{ $accentText ?? '#fff' }};"@endif>
    <div class="rw-head">
        <a class="rw-brand" href="{{ $allReviewsUrl }}" target="_blank" rel="noopener">
            <svg viewBox="0 0 512 512" fill="currentColor" aria-hidden="true"><path d="M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0z"/></svg>
            dovira
        </a>
        <div class="rw-score">
            <span class="rw-score__num">{{ $ratingLabel }}</span>
            <span class="rw-score__meta">
                <span class="rw-stars" role="img" aria-label="Рейтинг {{ $ratingLabel }} з 5">
                    <svg width="88" height="16" viewBox="0 0 88 16" fill="#dbe3ee" aria-hidden="true">{!! $starsRow !!}</svg>
                    <span class="rw-stars__fill" style="width: {{ $ratingPercent }}%;">
                        <svg width="88" height="16" viewBox="0 0 88 16" fill="#f5a623" aria-hidden="true">{!! $starsRow !!}</svg>
                    </span>
                </span>
                <span class="rw-score__count">{{ $reviewsLabel }}</span>
            </span>
        </div>
        <a class="rw-cta" href="{{ $leaveReviewUrl }}" target="_blank" rel="noopener">Залишити відгук</a>
    </div>

    <div class="rw-body">
        @if (empty($reviews))
            <p class="rw-empty">Тут зʼявляться відгуки клієнтів — станьте першим, хто поділиться досвідом.</p>
        @else
            <button type="button" class="rw-nav rw-nav--prev" data-rw-prev aria-label="Попередні відгуки" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </button>
            <div class="rw-track" data-rw-track>
                @foreach ($reviews as $review)
                    <article class="rw-card">
                        <div class="rw-card__top">
                            @if ($review['avatar_url'])
                                <img class="rw-card__avatar" src="{{ $review['avatar_url'] }}" alt="" loading="lazy">
                            @else
                                <span class="rw-card__avatar rw-card__avatar--initial" aria-hidden="true">{{ $review['initial'] }}</span>
                            @endif
                            <div>
                                <div class="rw-card__author">{{ $review['author'] }}</div>
                                <div class="rw-card__date">{{ $review['date'] }}</div>
                            </div>
                        </div>
                        <span class="rw-card__stars" role="img" aria-label="Оцінка {{ $review['rating'] }} з 5">
                            @for ($i = 1; $i <= 5; $i++)
                                <svg viewBox="0 0 16 16" fill="{{ $i <= $review['rating'] ? '#f5a623' : '#dbe3ee' }}" aria-hidden="true"><path d="{{ $starPath }}"/></svg>
                            @endfor
                        </span>
                        <p class="rw-card__text">{{ $review['text'] }}</p>
                        @if (mb_strlen($review['text']) > 260)
                            <a class="rw-card__more" href="{{ $allReviewsUrl }}" target="_blank" rel="noopener">Читати повністю</a>
                        @endif
                    </article>
                @endforeach
            </div>
            <button type="button" class="rw-nav rw-nav--next" data-rw-next aria-label="Наступні відгуки">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
            </button>
        @endif
    </div>

    <div class="rw-foot">
        <span class="rw-foot__note">
            <svg viewBox="0 0 512 512" fill="currentColor" aria-hidden="true"><path d="M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0z"/></svg>
            Відгуки з платформи DOVIRA
        </span>
        <a class="rw-foot__all" href="{{ $allReviewsUrl }}" target="_blank" rel="noopener">Всі відгуки →</a>
    </div>
</div>

<script>
    (() => {
        const track = document.querySelector('[data-rw-track]');
        const prev = document.querySelector('[data-rw-prev]');
        const next = document.querySelector('[data-rw-next]');

        if (track && prev && next) {
            const step = () => {
                const card = track.querySelector('.rw-card');
                return card ? card.getBoundingClientRect().width + 12 : 300;
            };
            const sync = () => {
                prev.disabled = track.scrollLeft <= 4;
                next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 4;
            };
            prev.addEventListener('click', () => track.scrollBy({ left: -step() }));
            next.addEventListener('click', () => track.scrollBy({ left: step() }));
            track.addEventListener('scroll', sync, { passive: true });
            window.addEventListener('resize', sync);
            sync();
        }

        // Авто-висота iframe: повідомляємо батьківській сторінці фактичну
        // висоту документа (лоадер reviews.js слухає це повідомлення).
        const reportHeight = () => {
            const height = document.documentElement.scrollHeight;
            window.parent.postMessage({ doviraWidget: 'reviews', height }, '*');
        };
        if (window.parent !== window) {
            reportHeight();
            window.addEventListener('load', reportHeight);
            if (window.ResizeObserver) {
                new ResizeObserver(reportHeight).observe(document.documentElement);
            }
        }
    })();
</script>
</body>
</html>
