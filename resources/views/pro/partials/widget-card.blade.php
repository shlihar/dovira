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

    @if (! $currentProfile->is_published)
        <p class="pro-widget-card__notice">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Віджет запрацює після публікації профілю в каталозі.
        </p>
    @endif

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
