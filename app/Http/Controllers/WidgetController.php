<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Profile;
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
     * SVG-бейдж «Рейтинг на DOVIRA» для вставки через <img> у
     * рекомендованому HTML-сніпеті (посилання + картинка).
     */
    public function badge(string $slug): Response
    {
        $profile = $this->resolveProfile($slug);

        $rating = max(0.0, min(5.0, (float) $profile->rating_avg));
        $reviewsCount = (int) $profile->reviews_count;
        $ratingLabel = number_format($rating, 1);
        $reviewsLabel = $reviewsCount . ' ' . $this->reviewsNoun($reviewsCount);

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
      <stop offset="0" stop-color="#175cf0"/>
      <stop offset="1" stop-color="#2d76ff"/>
    </linearGradient>
  </defs>
  <rect x="0.5" y="0.5" width="239" height="71" rx="14" fill="#ffffff" stroke="#e4e9f2"/>
  <rect x="16" y="11" width="76" height="23" rx="8" fill="url(#brand-grad)"/>
  <g transform="translate(24,16.5) scale(0.0244)" fill="#ffffff"><path d="{$shieldPath}"/></g>
  <text x="42" y="27" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="13" font-weight="650" fill="#ffffff">dovira</text>
  <text x="100" y="27" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="11.5" fill="#64748b">рейтинг довіри</text>
  <g transform="translate(16,40)" fill="#dbe3ee">{$starRow}</g>
  <g transform="translate(16,40)" fill="#f5a623" clip-path="url(#gold-clip)">{$starRow}</g>
  <text x="124" y="54" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="17" font-weight="800" fill="#1e293b">{$ratingLabel}</text>
  <text x="156" y="54" font-family="'Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif" font-size="11.5" fill="#64748b">{$reviewsLabel}</text>
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

        $rating = max(0.0, min(5.0, (float) $profile->rating_avg));
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
      + 'box-shadow:0 12px 34px rgba(16,45,110,.22);border:1px solid #e4e9f2;background:#fff;'
      + 'text-decoration:none;line-height:0;';
    var img = document.createElement('img');
    img.src = '{$badgeUrl}';
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
      + 'background:linear-gradient(135deg,#175cf0,#2d76ff);box-shadow:0 10px 24px rgba(23,92,240,.4);'
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

    private function resolveProfile(string $slug): Profile
    {
        return Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('slug', $slug)
            ->firstOrFail(['id', 'slug', 'name', 'rating_avg', 'reviews_count']);
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
