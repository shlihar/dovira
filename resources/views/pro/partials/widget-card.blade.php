@php
    /**
     * Картка «Віджет і посилання»: готові сніпети для сайту та соцмереж власника.
     * Код прихований за «Показати код» — основна дія одна: скопіювати.
     * Посилання ведуть на публічний профіль з utm-мітками — переходи видно
     * в аналітиці кабінету (utm_source = dovira_widget).
     */
    $widgetProfileUrl = route('profile.show', ['slug' => $currentProfile->slug]);
    $widgetBadgeUrl = route('widget.badge', ['slug' => $currentProfile->slug]);
    $widgetProfileName = (string) $currentProfile->name;

    $widgetBadgeSnippet = '<a href="' . $widgetProfileUrl . '?utm_source=dovira_widget&utm_medium=badge" target="_blank" rel="noopener">'
        . '<img src="' . $widgetBadgeUrl . '" alt="Рейтинг ' . e($widgetProfileName) . ' на DOVIRA" width="240" height="72" loading="lazy"></a>';

    $widgetFloatUrl = route('widget.float', ['slug' => $currentProfile->slug]);
    $widgetFloatSnippet = '<!-- DOVIRA плаваючий віджет -->' . "\n"
        . '<script src="' . $widgetFloatUrl . '" data-position="bottom-right" async></script>';

    $widgetReviewsFrameUrl = route('widget.reviews', ['slug' => $currentProfile->slug]);
    $widgetReviewsLoaderUrl = route('widget.reviews-loader', ['slug' => $currentProfile->slug]);
    $widgetReviewsSnippet = '<!-- DOVIRA блок відгуків -->' . "\n"
        . '<script src="' . $widgetReviewsLoaderUrl . '" data-layout="carousel" data-min-rating="4" async></script>';

    $widgetSocialLink = $widgetProfileUrl . '?utm_source=dovira_widget&utm_medium=social';
@endphp

<section class="card pro-overview-card pro-widget-card">
    <div class="pro-overview-card__head">
        <h2>Віджет і посилання на профіль</h2>
    </div>

    <p class="pro-widget-card__lead">
        Покажіть свій рейтинг на сайті й у соцмережах — клієнти бачать живі відгуки,
        а посилання підсилює профіль у Google. Переходи видно в аналітиці.
    </p>

    <div style="margin: 16px 0; padding: 12px 14px; background: #dcfce7; border: 1px solid #86efac; border-radius: 10px; color: #166534;">
        <div style="display: flex; gap: 10px; align-items: flex-start;">
            <i class="fa-solid fa-gift" aria-hidden="true" style="margin-top: 2px; flex-shrink: 0;"></i>
            <div>
                <strong style="display: block; margin-bottom: 4px;">Бонус: −20% за розміщення бейджа</strong>
                <p style="margin: 0; font-size: 0.9rem; line-height: 1.4;">Розмістіть бейдж DOVIRA на своєму сайті або в соцмережах у межах стартової когорти — отримаєте 20% знижку на всі подальші продовження підписки.</p>
            </div>
        </div>
    </div>

    @if (! $currentProfile->is_published)
        <p class="pro-widget-card__notice">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Віджет запрацює після публікації профілю в каталозі.
        </p>
    @endif

    {{-- Блок відгуків — головний віджет, тому на всю ширину над сіткою. --}}
    <div class="pro-widget-card__option" data-widget-reviews-option style="margin-bottom: 16px;">
        <h4 class="pro-widget-card__option-title">
            Блок відгуків із каруселлю
            <span class="pro-widget-card__tag">нове</span>
        </h4>
        <p class="pro-widget-card__option-note">
            Повноцінна секція для вашого сайту: живий рейтинг, останні відгуки клієнтів
            і кнопка «Залишити відгук». Висота підлаштовується автоматично.
        </p>
        <div class="pro-widget-card__preview" style="padding: 12px; background: #f1f4f9; border-radius: 14px;">
            <iframe
                src="{{ $widgetReviewsFrameUrl }}"
                title="Попередній перегляд блоку відгуків"
                loading="lazy"
                scrolling="no"
                data-widget-reviews-preview
                data-base-src="{{ $widgetReviewsFrameUrl }}"
                style="display: block; width: 100%; height: 420px; border: 0; border-radius: 12px; background: #fff;"
            ></iframe>
        </div>
        <div class="pro-widget-float-corners" role="group" aria-label="Мінімальний рейтинг відгуків у блоці" style="gap: 8px;">
            @foreach (['4' => 'Відгуки 4★ і вище', '5' => 'Лише 5★', '1' => 'Всі відгуки'] as $minValue => $minLabel)
                <button
                    type="button"
                    class="pro-widget-card__copy {{ $minValue === '4' ? 'is-copied' : '' }}"
                    data-widget-reviews-min="{{ $minValue }}"
                    aria-pressed="{{ $minValue === '4' ? 'true' : 'false' }}"
                >{{ $minLabel }}</button>
            @endforeach
        </div>
        <div class="pro-widget-card__actions">
            <button type="button" class="pro-widget-card__copy pro-widget-card__copy--primary" data-widget-copy data-widget-copy-target="widget-snippet-reviews">
                <i class="fa-regular fa-copy" aria-hidden="true"></i>
                <span data-widget-copy-label>Копіювати код</span>
            </button>
            <details class="pro-widget-card__guide" data-widget-guide>
                <summary>
                    <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                    <span>Як встановити</span>
                    <i class="fa-solid fa-chevron-down pro-widget-card__guide-chevron" aria-hidden="true"></i>
                </summary>
                <div class="pro-widget-card__guide-body" data-widget-guide-body>
                <div class="pro-widget-card__guide-inner">
                <ol class="pro-widget-card__steps">
                    <li>Оберіть, які відгуки показувати, і натисніть <strong>«Копіювати код»</strong>.</li>
                    <li>Вставте рядок туди, де має бути блок — наприклад, у секцію «Відгуки» вашої сторінки. На <strong>WordPress</strong> — блок «HTML», на <strong>Tilda</strong> — «T123 (HTML-код)», на <strong>Wix</strong> — «Вбудувати HTML».</li>
                    <li>Готово: блок сам оновлюється, коли зʼявляються нові відгуки. Замініть <code>data-layout="carousel"</code> на <code>"grid"</code>, якщо хочете сітку замість каруселі.</li>
                    <li>Під стиль сайту: додайте до тегу <code>data-theme="dark"</code> для темного фону і <code>data-accent="#c9a45c"</code> з вашим фірмовим кольором кнопок (будь-який hex).</li>
                </ol>
                <textarea id="widget-snippet-reviews" class="pro-widget-card__code" rows="3" readonly aria-label="Код блоку відгуків" data-widget-reviews-snippet data-base-src="{{ $widgetReviewsLoaderUrl }}">{{ $widgetReviewsSnippet }}</textarea>
                </div>
                </div>
            </details>
        </div>
    </div>

    <div class="pro-widget-card__grid">
        <div class="pro-widget-card__option">
            <h4 class="pro-widget-card__option-title">
                Бейдж для сайту
                <span class="pro-widget-card__tag">рекомендовано</span>
            </h4>
            <div class="pro-widget-card__preview">
                <img src="{{ $widgetBadgeUrl }}" alt="Попередній перегляд бейджа {{ $widgetProfileName }}" width="240" height="72" loading="lazy">
            </div>
            <div class="pro-widget-card__actions">
                <button type="button" class="pro-widget-card__copy pro-widget-card__copy--primary" data-widget-copy data-widget-copy-target="widget-snippet-badge">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    <span data-widget-copy-label>Копіювати код</span>
                </button>
                <details class="pro-widget-card__guide" data-widget-guide>
                    <summary>
                        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                        <span>Як встановити</span>
                        <i class="fa-solid fa-chevron-down pro-widget-card__guide-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="pro-widget-card__guide-body" data-widget-guide-body>
                    <div class="pro-widget-card__guide-inner">
                    <ol class="pro-widget-card__steps">
                        <li>Натисніть <strong>«Копіювати код»</strong> вище.</li>
                        <li>Відкрийте редактор своєї сторінки й вставте код туди, де має бути бейдж — зазвичай у футер або блок «Про нас».</li>
                        <li>Збережіть зміни. На <strong>WordPress</strong> — блок «HTML», на <strong>Tilda</strong> — блок «T123 (HTML-код)», на <strong>Wix</strong> — елемент «Вбудувати HTML».</li>
                    </ol>
                    <textarea id="widget-snippet-badge" class="pro-widget-card__code" rows="4" readonly aria-label="HTML-код бейджа">{{ $widgetBadgeSnippet }}</textarea>
                    </div>
                    </div>
                </details>
            </div>
        </div>

        <div class="pro-widget-card__option" data-widget-float-option>
            <h4 class="pro-widget-card__option-title">Плаваючий віджет</h4>

            {{-- Мокап «ваш сайт»: бейдж у вибраному куті, кути перемикаються. --}}
            <div class="pro-widget-card__preview pro-widget-float-preview">
                <div class="pro-widget-float-mock" data-widget-float-mock data-corner="bottom-right">
                    <span class="pro-widget-float-mock__bar"></span>
                    <span class="pro-widget-float-mock__line"></span>
                    <span class="pro-widget-float-mock__line pro-widget-float-mock__line--short"></span>
                    <img class="pro-widget-float-mock__badge" src="{{ $widgetBadgeUrl }}" alt="Плаваючий бейдж {{ $widgetProfileName }}" width="120" height="36" loading="lazy">
                </div>
            </div>

            <div class="pro-widget-float-corners" role="group" aria-label="Кут розміщення">
                @foreach (['top-left' => 'Зверху зліва', 'top-right' => 'Зверху справа', 'bottom-left' => 'Знизу зліва', 'bottom-right' => 'Знизу справа'] as $corner => $cornerLabel)
                    <button
                        type="button"
                        class="pro-widget-float-corners__btn {{ $corner === 'bottom-right' ? 'is-active' : '' }}"
                        data-widget-float-corner="{{ $corner }}"
                        aria-label="{{ $cornerLabel }}"
                        aria-pressed="{{ $corner === 'bottom-right' ? 'true' : 'false' }}"
                        title="{{ $cornerLabel }}"
                    >
                        <span class="pro-widget-float-corners__dot"></span>
                    </button>
                @endforeach
            </div>

            <div class="pro-widget-card__actions">
                <button type="button" class="pro-widget-card__copy pro-widget-card__copy--primary" data-widget-copy data-widget-copy-target="widget-snippet-float">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    <span data-widget-copy-label>Копіювати код</span>
                </button>
                <details class="pro-widget-card__guide" data-widget-guide>
                    <summary>
                        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                        <span>Як встановити</span>
                        <i class="fa-solid fa-chevron-down pro-widget-card__guide-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="pro-widget-card__guide-body" data-widget-guide-body>
                    <div class="pro-widget-card__guide-inner">
                    <ol class="pro-widget-card__steps">
                        <li>Оберіть кут вище й натисніть <strong>«Копіювати код»</strong>.</li>
                        <li>Вставте один рядок <code>&lt;script&gt;</code> перед закриваючим тегом <code>&lt;/body&gt;</code> вашого сайту.</li>
                        <li>Готово — бейдж зʼявиться в кутку на всіх сторінках. Відвідувач може згорнути його, і вибір запамʼятається.</li>
                        <li>Під стиль сайту: додайте до тегу <code>data-theme="dark"</code> і <code>data-accent="#c9a45c"</code> (ваш фірмовий колір) — бейдж перефарбується.</li>
                    </ol>
                    <textarea id="widget-snippet-float" class="pro-widget-card__code" rows="3" readonly aria-label="Код плаваючого віджета" data-widget-float-snippet data-base-src="{{ $widgetFloatUrl }}">{{ $widgetFloatSnippet }}</textarea>
                    </div>
                    </div>
                </details>
            </div>
        </div>

        <div class="pro-widget-card__option">
            <h4 class="pro-widget-card__option-title">Посилання для соцмереж</h4>
            <p class="pro-widget-card__option-note">
                Instagram-біо, Facebook, Telegram-канал або підпис у листах.
            </p>
            <div class="pro-invite__linkbox pro-widget-card__linkbox">
                <i class="fa-solid fa-link" aria-hidden="true"></i>
                <input
                    id="widget-snippet-link"
                    class="pro-invite__input"
                    type="text"
                    readonly
                    value="{{ $widgetSocialLink }}"
                    aria-label="Посилання на профіль для соцмереж"
                >
            </div>
            <div class="pro-widget-card__actions">
                <button type="button" class="pro-widget-card__copy pro-widget-card__copy--primary" data-widget-copy data-widget-copy-target="widget-snippet-link">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    <span data-widget-copy-label>Копіювати</span>
                </button>
            </div>
        </div>
    </div>
</section>
