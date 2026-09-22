<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Публічний віджет рейтингу для сайтів власників профілів.
 *
 * Кожен вставлений бейдж — це зворотне посилання на сторінку профілю,
 * тому віджет доступний усім власникам без PRO-обмежень: беклінки з
 * сайтів бізнесів працюють на видимість і профілю, і платформи.
 */
final class WidgetController extends Controller
{
    /**
     * До цієї межі відгуків віджети показують ПРОСТУ середню опублікованих
     * оцінок, а не зважений rating_avg: байєсівський рейтинг при малій
     * вибірці тягне до категорійної середньої (13 п'ятірок → 4.76), і бізнес
     * бачить на власному сайті «занижену» цифру. Вітрина відгуків чесно
     * показує фактичну середню; зважений рейтинг лишається для ранжування.
     */
    private const PLAIN_AVG_BELOW_REVIEWS = 20;

    /**
     * SVG-бейдж «Рейтинг на DOVIRA» для вставки через <img> у
     * рекомендованому HTML-сніпеті (посилання + картинка).
     */
    public function badge(Request $request, string $slug): Response
    {
        $profile = $this->resolveProfile($slug);

        $rating = $this->widgetRating($profile);
        $reviewsCount = (int) $profile->reviews_count;
        $ratingLabel = number_format($rating, 1);
        $reviewsLabel = $reviewsCount . ' ' . $this->reviewsNoun($reviewsCount);

        // Тема під сайт власника (?theme=dark&accent=#hex) — ті самі токени,
        // що й у блоку відгуків, щоб бейдж і блок виглядали як один комплект.
        $dark = $request->query('theme') === 'dark';
        $accent = $this->sanitizeHexColor((string) $request->query('accent', ''));
        $c = [
            'bg' => $dark ? '#16171b' : '#ffffff',
            'stroke' => $dark ? '#2b2d34' : '#e4e9f2',
            'text' => $dark ? '#f3f4f6' : '#1e293b',
            'muted' => $dark ? '#a3a9b5' : '#64748b',
            'star_empty' => $dark ? '#3b3e47' : '#dbe3ee',
            'grad_a' => $accent ?: '#175cf0',
            'grad_b' => $accent ?: '#2d76ff',
            'on_accent' => $accent ? $this->contrastTextFor($accent) : '#ffffff',
        ];

        // Зірки: сірий ряд повністю + золотий ряд, обрізаний кліпом по частці рейтингу.
        $starPath = 'M8 0l2.2 4.9 5.2.5-4 3.6 1.2 5.2L8 11.5 3.4 14.2 4.6 9 0.6 5.4l5.2-.5z';
        $starRow = '';
        for ($i = 0; $i < 5; $i++) {
            $starRow .= '<path d="' . $starPath . '" transform="translate(' . ($i * 19) . ',0)"/>';
        }
        $starsWidth = 4 * 19 + 16;
        $goldWidth = round($starsWidth * ($rating / 5), 1);

        // Font Awesome 6 Free shield-halved — той самий знак бренду, що в шапці сайту.
        $shieldPath = 'M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0zm0 66.8l0 378.1C394 378 431.1 230.1 432 141.4L256 66.8s0 0 0 0z';

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="240" height="72" viewBox="0 0 240 72" role="img" aria-label="Рейтинг {$ratingLabel} з 5 на DOVIRA, {$reviewsLabel}">
  <defs>
    <clipPath id="gold-clip"><rect x="0" y="0" width="{$goldWidth}" height="16"/></clipPath>
    <linearGradient id="brand-grad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{$c['grad_a']}"/>
      <stop offset="1" stop-color="{$c['grad_b']}"/>
    </linearGradient>
  </defs>
  <rect x="0.5" y="0.5" width="239" height="71" rx="14" fill="{$c['bg']}" stroke="{$c['stroke']}"/>
  <rect x="16" y="11" width="76" height="23" rx="8" fill="url(#brand-grad)"/>
  <g transform="translate(24,16.5) scale(0.0244)" fill="{$c['on_accent']}"><path d="{$shieldPath}"/></g>
  <text x="42" y="27" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="13" font-weight="650" fill="{$c['on_accent']}">dovira</text>
  <text x="100" y="27" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="11.5" fill="{$c['muted']}">рейтинг довіри</text>
  <g transform="translate(16,40)" fill="{$c['star_empty']}">{$starRow}</g>
  <g transform="translate(16,40)" fill="#f5a623" clip-path="url(#gold-clip)">{$starRow}</g>
  <text x="124" y="54" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="17" font-weight="800" fill="{$c['text']}">{$ratingLabel}</text>
  <text x="156" y="54" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="11.5" fill="{$c['muted']}">{$reviewsLabel}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            // Бейдж не має жити в індексі картинок — це службова графіка.
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Iframe-версія віджета: живий рейтинг + клікабельний перехід на профіль.
     * SecurityHeaders пропускає X-Frame-Options для widget.* маршрутів,
     * щоб сторонні сайти могли вбудовувати цю сторінку.
     */
    public function show(string $slug): Response
    {
        $profile = $this->resolveProfile($slug);

        $rating = $this->widgetRating($profile);
        $reviewsCount = (int) $profile->reviews_count;

        return response()
            ->view('static.widget', [
                'profile' => $profile,
                'rating' => $rating,
                'ratingLabel' => number_format($rating, 1),
                'reviewsLabel' => $reviewsCount . ' ' . $this->reviewsNoun($reviewsCount),
                'profileUrl' => route('profile.show', ['slug' => $profile->slug])
                    . '?utm_source=dovira_widget&utm_medium=iframe',
            ])
            ->header('Cache-Control', 'public, max-age=600')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Content-Security-Policy', 'frame-ancestors *');
    }

    /**
     * Плаваючий кутовий віджет: self-contained JS, який власник підключає
     * одним рядком <script>. Скрипт вставляє fixed-бейдж у обраному куті
     * (data-position), з кнопкою згортання; згорнутий стан памʼятається в
     * localStorage, тож не «стрибає» між сторінками сайту.
     */
    public function float(string $slug): Response
    {
        $profile = $this->resolveProfile($slug);

        $badgeUrl = route('widget.badge', ['slug' => $profile->slug]);
        $profileUrl = route('profile.show', ['slug' => $profile->slug])
            . '?utm_source=dovira_widget&utm_medium=floating';

        $js = <<<JS
(function () {
  if (window.__doviraFloatLoaded) return;
  window.__doviraFloatLoaded = true;

  var script = document.currentScript
    || document.querySelector('script[src*="/widget/{$profile->slug}/float.js"]');
  var position = (script && script.getAttribute('data-position')) || 'bottom-right';
  var STORAGE_KEY = 'doviraFloatCollapsed:{$profile->slug}';
  // Тема під сайт: data-theme="dark" + data-accent="#hex" — бейдж-картинка
  // отримує ті самі параметри, рамка/бульбашка перефарбовуються тут.
  var theme = (script && script.getAttribute('data-theme')) === 'dark' ? 'dark' : 'light';
  var accentRaw = (script && script.getAttribute('data-accent')) || '';
  var accent = /^#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/.test(accentRaw) ? (accentRaw[0] === '#' ? accentRaw : '#' + accentRaw) : '';
  var badgeQuery = [];
  if (theme === 'dark') badgeQuery.push('theme=dark');
  if (accent) badgeQuery.push('accent=' + encodeURIComponent(accent));
  var badgeSrc = '{$badgeUrl}' + (badgeQuery.length ? '?' + badgeQuery.join('&') : '');
  var cardBg = theme === 'dark' ? '#16171b' : '#fff';
  var cardBorder = theme === 'dark' ? '#2b2d34' : '#e4e9f2';
  var bubbleBg = accent ? accent : 'linear-gradient(135deg,#175cf0,#2d76ff)';

  var corners = {
    'bottom-right': 'bottom:20px;right:20px;',
    'bottom-left': 'bottom:20px;left:20px;',
    'top-right': 'top:20px;right:20px;',
    'top-left': 'top:20px;left:20px;'
  };
  var cornerCss = corners[position] || corners['bottom-right'];

  function build() {
    var wrap = document.createElement('div');
    wrap.setAttribute('data-dovira-float', '');
    wrap.style.cssText = 'position:fixed;z-index:2147483000;' + cornerCss
      + 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;'
      + 'transition:transform .25s ease,opacity .25s ease;';

    var collapsed = false;
    try { collapsed = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}

    // Розгорнутий бейдж
    var card = document.createElement('a');
    card.href = '{$profileUrl}';
    card.target = '_blank';
    card.rel = 'noopener';
    card.style.cssText = 'display:block;position:relative;border-radius:16px;overflow:hidden;'
      + 'box-shadow:0 12px 34px rgba(0,0,0,' + (theme === 'dark' ? '.5' : '.18') + ');border:1px solid ' + cardBorder + ';background:' + cardBg + ';'
      + 'text-decoration:none;line-height:0;';
    var img = document.createElement('img');
    img.src = badgeSrc;
    img.alt = 'Рейтинг на DOVIRA';
    img.width = 240; img.height = 72;
    img.style.cssText = 'display:block;width:240px;height:72px;';
    card.appendChild(img);

    // Кнопка згортання
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Згорнути');
    close.innerHTML = '&times;';
    close.style.cssText = 'position:absolute;top:-8px;right:-8px;width:22px;height:22px;'
      + 'border:0;border-radius:50%;background:#334;color:#fff;font-size:15px;line-height:22px;'
      + 'cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25);padding:0;z-index:2;';

    // Згорнутий стан — маленька кругла кнопка-щит
    var bubble = document.createElement('button');
    bubble.type = 'button';
    bubble.setAttribute('aria-label', 'Показати рейтинг на DOVIRA');
    bubble.innerHTML = '<svg width="22" height="22" viewBox="0 0 512 512" fill="#fff" aria-hidden="true"><path d="M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0z"/></svg>';
    bubble.style.cssText = 'width:48px;height:48px;border:0;border-radius:50%;cursor:pointer;'
      + 'background:' + bubbleBg + ';box-shadow:0 10px 24px rgba(0,0,0,.35);'
      + 'display:none;align-items:center;justify-content:center;padding:0;';

    var badgeBox = document.createElement('div');
    badgeBox.style.cssText = 'position:relative;';
    badgeBox.appendChild(card);
    badgeBox.appendChild(close);

    function setCollapsed(state) {
      collapsed = state;
      try { localStorage.setItem(STORAGE_KEY, state ? '1' : '0'); } catch (e) {}
      badgeBox.style.display = state ? 'none' : 'block';
      bubble.style.display = state ? 'flex' : 'none';
    }

    close.addEventListener('click', function (e) { e.preventDefault(); setCollapsed(true); });
    bubble.addEventListener('click', function () { setCollapsed(false); });

    wrap.appendChild(badgeBox);
    wrap.appendChild(bubble);
    setCollapsed(collapsed);
    document.body.appendChild(wrap);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Блок відгуків для вбудовування на сайт власника (iframe-сторінка):
     * шапка з живим рейтингом + карусель/сітка останніх відгуків. Дефолтний
     * мінімальний рейтинг 4 — бізнес свідомо вставляє собі соціальний доказ.
     */
    public function reviews(Request $request, string $slug): Response
    {
        $profile = $this->resolveProfile($slug);

        $layout = $request->query('layout') === 'grid' ? 'grid' : 'carousel';
        $minRating = min(5, max(1, (int) $request->query('min', 4)));
        $limit = min(12, max(3, (int) $request->query('limit', 9)));
        // Тема під сайт власника: theme=dark + accent=#hex (лише валідний hex —
        // значення йде в інлайн-стиль). Текст на акценті добираємо за яскравістю.
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';
        $accent = $this->sanitizeHexColor((string) $request->query('accent', ''));
        $accentText = $accent ? $this->contrastTextFor($accent) : '#fff';

        $reviews = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->where('rating', '>=', $minRating)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'author_name', 'rating', 'body', 'published_at', 'created_at', 'external_review_author_avatar_url']);

        $profileUrl = route('profile.show', ['slug' => $profile->slug]);

        return response()
            ->view('static.widget-reviews', [
                'profile' => $profile,
                'layout' => $layout,
                'theme' => $theme,
                'accent' => $accent,
                'accentText' => $accentText,
                'ratingLabel' => number_format($this->widgetRating($profile), 1),
                'ratingPercent' => round($this->widgetRating($profile) / 5 * 100, 1),
                'reviewsLabel' => (int) $profile->reviews_count . ' ' . $this->reviewsNoun((int) $profile->reviews_count),
                'allReviewsUrl' => $profileUrl . '?utm_source=dovira_widget&utm_medium=reviews_block#reviews',
                'leaveReviewUrl' => $profileUrl . '?utm_source=dovira_widget&utm_medium=reviews_block&review=1#reviews',
                'reviews' => $reviews->map(function (ProfileReview $review): array {
                    $author = trim((string) $review->author_name) ?: 'Користувач DOVIRA';
                    $publishedAt = $review->published_at ?: $review->created_at;

                    return [
                        'author' => $author,
                        'initial' => mb_strtoupper(mb_substr($author, 0, 1)) ?: 'D',
                        'avatar_url' => MediaUrl::avatarUrl(
                            MediaUrl::publicImageUrl($review->external_review_author_avatar_url),
                            $author,
                            96
                        ),
                        'rating' => max(0, min(5, (int) round((float) $review->rating))),
                        'text' => (string) $review->body,
                        'date' => optional($publishedAt)?->translatedFormat('d F Y') ?? '',
                    ];
                })->values()->all(),
            ])
            ->header('Cache-Control', 'public, max-age=600')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Content-Security-Policy', 'frame-ancestors *');
    }

    /**
     * Лоадер блоку відгуків: власник вставляє один <script>, той на своєму
     * місці створює iframe і авторозмірює його висоту через postMessage —
     * стилі сайту й віджета повністю ізольовані один від одного.
     */
    public function reviewsLoader(string $slug): Response
    {
        $profile = $this->resolveProfile($slug);
        $frameUrl = route('widget.reviews', ['slug' => $profile->slug]);

        $js = <<<JS
(function () {
  var script = document.currentScript
    || document.querySelector('script[src*="/widget/{$profile->slug}/reviews.js"]');
  if (!script || script.__doviraReviewsDone) return;
  script.__doviraReviewsDone = true;

  var params = [];
  var layout = script.getAttribute('data-layout');
  var min = script.getAttribute('data-min-rating');
  var limit = script.getAttribute('data-limit');
  var theme = script.getAttribute('data-theme');
  var accent = script.getAttribute('data-accent');
  if (layout) params.push('layout=' + encodeURIComponent(layout));
  if (min) params.push('min=' + encodeURIComponent(min));
  if (limit) params.push('limit=' + encodeURIComponent(limit));
  if (theme) params.push('theme=' + encodeURIComponent(theme));
  if (accent) params.push('accent=' + encodeURIComponent(accent));

  var iframe = document.createElement('iframe');
  iframe.src = '{$frameUrl}' + (params.length ? '?' + params.join('&') : '');
  iframe.title = 'Відгуки на DOVIRA';
  iframe.loading = 'lazy';
  iframe.setAttribute('scrolling', 'no');
  iframe.style.cssText = 'width:100%;border:0;display:block;height:420px;';

  window.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.doviraWidget !== 'reviews' || event.source !== iframe.contentWindow) return;
    var height = parseInt(data.height, 10);
    if (height > 0) iframe.style.height = height + 'px';
  });

  script.parentNode.insertBefore(iframe, script);
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Лише валідний hex (#abc або #aabbcc) — значення потрапляє в інлайн-стиль/SVG. */
    private function sanitizeHexColor(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $value, $m)) {
            return null;
        }
        $hex = strtolower($m[1]);
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /** Темний чи білий текст поверх акценту — за відносною яскравістю (WCAG). */
    private function contrastTextFor(string $hex): string
    {
        [$r, $g, $b] = array_map(fn (string $c): float => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $lin = fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luma = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        // 0.179 — поріг, за якого темний текст дає контраст ≥4.5:1 (золото,
        // жовтий, світлі бренд-кольори отримують темний напис, сині — білий).
        return $luma > 0.179 ? '#111827' : '#ffffff';
    }

    private function resolveProfile(string $slug): Profile
    {
        return Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('slug', $slug)
            ->firstOrFail(['id', 'slug', 'name', 'rating_avg', 'reviews_count']);
    }

    /** @var array<int, float> кеш на запит: virtual-рейтинг рахується раз */
    private array $widgetRatingCache = [];

    /**
     * Оцінка для вітрини віджетів: при малій кількості відгуків — фактична
     * середня опублікованих оцінок (див. PLAIN_AVG_BELOW_REVIEWS), інакше —
     * зважений rating_avg. Фолбек — rating_avg, якщо оцінок ще немає.
     */
    private function widgetRating(Profile $profile): float
    {
        return $this->widgetRatingCache[$profile->id] ??= (function () use ($profile): float {
            $rating = (float) $profile->rating_avg;

            if ((int) $profile->reviews_count < self::PLAIN_AVG_BELOW_REVIEWS) {
                $plain = rescue(fn () => ProfileReview::query()
                    ->where('profile_id', $profile->id)
                    ->where('status', 'published')
                    ->avg('rating'), null, false);

                if ($plain !== null) {
                    $rating = (float) $plain;
                }
            }

            return max(0.0, min(5.0, round($rating, 1)));
        })();
    }

    private function reviewsNoun(int $count): string
    {
        if ($count % 10 === 1 && $count % 100 !== 11) {
            return 'відгук';
        }

        if (in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true)) {
            return 'відгуки';
        }

        return 'відгуків';
    }
}
