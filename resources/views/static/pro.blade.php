@extends('static.layout')

@php
    $stats = $stats ?? [];

    // Гість спершу реєструється, авторизований — одразу в кабінет.
    $isAuthed = auth()->check();
    $proPrimaryUrl = $isAuthed ? route('pro.account') : route('register');
    $proClaimUrl = $isAuthed ? route('pro.account', ['tab' => 'claims']) : route('register');
    $proPrimaryLabel = $isAuthed ? 'Відкрити PRO кабінет' : 'Підключити PRO';
@endphp

@section('title', 'Для бізнесу — DOVIRA PRO: клієнти з каталогу відгуків')
@section('description', 'PRO-профіль на DOVIRA: пріоритет у каталозі, офіційні відповіді на відгуки та аналітика звернень. Профіль індексується в Google — люди знаходять вас за назвою і послугами.')
@section('canonical', route('pro'))
@section('body_class', 'page-pro')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/pro.css') }}?v={{ @filemtime(public_path('static/css/pages/pro.css')) }}">
    @php
        $proOfferSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'DOVIRA PRO',
            'description' => 'PRO-підписка для компаній і спеціалістів на платформі DOVIRA: пріоритет у каталозі, офіційні відповіді на відгуки та аналітика звернень.',
            'brand' => ['@type' => 'Brand', 'name' => 'DOVIRA'],
            'url' => route('pro'),
            'offers' => [
                [
                    '@type' => 'Offer',
                    'name' => 'START',
                    'description' => 'Базова присутність у каталозі: профіль, рейтинг, відгуки та форма заявки від клієнтів — безкоштовно.',
                    'price' => '0',
                    'priceCurrency' => 'UAH',
                    'url' => route('pro') . '#pro-pricing',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'PRO — стартова пропозиція',
                    'description' => 'Повний PRO-доступ на 6 місяців за стартовою ціною: пріоритет у каталозі, офіційні відповіді, аналітика та захист репутації. Ціна фіксується назавжди при продовженні.',
                    'price' => (string) \App\Support\ProPricing::DISPLAY_CURRENT,
                    'priceCurrency' => \App\Support\ProPricing::DISPLAY_CURRENCY,
                    'url' => route('pro') . '#pro-pricing',
                ],
            ],
        ];
    @endphp
    <script type="application/ld+json">@json($proOfferSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
@endpush

@section('content')
    <section class="section pro-page">
        <div class="container">
            <div class="pro-page__stack">
                <section class="pro-hero" aria-labelledby="pro-hero-title">

                    <div class="pro-hero__content">
                        <span class="pro-hero__badge">Для компаній і спеціалістів</span>
                        <h1 class="pro-hero__title" id="pro-hero-title">
                            Клієнти вже шукають вас
                        </h1>
                        <p class="pro-hero__subtitle">
                            PRO піднімає профіль у видачі каталогу й дає офіційний голос у кожному відгуку.
                            А сторінка профілю індексується в Google — вас знаходять і поза DOVIRA.
                        </p>

                        <div class="pro-hero__actions">
                            <a class="btn dovira-btn pro-hero__btn" href="{{ $proPrimaryUrl }}">
                                <span>{{ $isAuthed ? 'Відкрити PRO кабінет' : 'Створити профіль' }}</span>
                            </a>
                            <a class="btn dovira-btn pro-hero__btn pro-hero__btn--alt" href="#pro-pricing">
                                <span>Переглянути тарифи</span>
                            </a>
                        </div>
                    </div>

                    <div class="pro-hero__cards" aria-hidden="true">
                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                            <h3 class="pro-hero-card__title">Вище за конкурентів</h3>
                            <p class="pro-hero-card__text">PRO-профілі — першими у видачі за категорією і містом.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--you">
                                    <span class="pro-rank-row__pos">1</span>
                                    <span class="pro-rank-row__name">Ваша компанія</span>
                                    <span class="pro-rank-row__pro">PRO</span>
                                </div>
                                <div class="pro-rank-row">
                                    <span class="pro-rank-row__pos">2</span>
                                    <span class="pro-rank-row__name">Конкурент</span>
                                </div>
                            </div>
                        </article>

                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-comments"></i></span>
                            <h3 class="pro-hero-card__title">Ваш голос у відгуках</h3>
                            <p class="pro-hero-card__text">Офіційна відповідь компанії на кожен відгук.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--reply">
                                    <span class="pro-rank-row__reply-icon"><i class="fa-solid fa-reply"></i></span>
                                    <span class="pro-rank-row__name">Офіційна відповідь<em>від представника компанії</em></span>
                                </div>
                            </div>
                        </article>

                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-chart-line"></i></span>
                            <h3 class="pro-hero-card__title">Видно результат</h3>
                            <p class="pro-hero-card__text">Перегляди, кліки на контакти та джерела звернень.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--stat">
                                    <span class="pro-rank-row__name">Кліки на контакти</span>
                                    <b class="pro-rank-row__value"><i class="fa-solid fa-arrow-up"></i> +38%</b>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="prol-steps" aria-labelledby="prol-steps-title" id="pro-features">
                    <div class="prol-section-head">
                        <h2 id="prol-steps-title">Три кроки до перших звернень</h2>
                        <p>Старт безкоштовний — PRO вмикається, коли будете готові.</p>
                    </div>

                    <div class="prol-steps__row">
                        <article class="prol-step">
                            <span class="prol-step__num">1</span>
                            <h3>Знайдіть свій профіль</h3>
                            <p>Ваша компанія може вже бути в каталозі — люди бачать її і без вас. Підтвердьте права безкоштовно — а керування сторінкою відкриє PRO.</p>
                        </article>

                        <article class="prol-step">
                            <span class="prol-step__num">2</span>
                            <h3>Оформіть сторінку</h3>
                            <p>Додайте послуги, фото, контакти й переваги — профіль почне працювати як вітрина вашого бізнесу.</p>
                        </article>

                        <article class="prol-step">
                            <span class="prol-step__num">3</span>
                            <h3>Перетворюйте перегляди на звернення</h3>
                            <p>Люди порівнюють виконавців і звертаються до тих, кому довіряють. PRO підніме вас у видачі й покаже, звідки приходять клієнти.</p>
                        </article>
                    </div>
                </section>

                <section class="prol-features" aria-labelledby="prol-features-title">
                    <div class="prol-features__panel">
                        <div class="prol-section-head">
                            <h2 id="prol-features-title">Що дає PRO</h2>
                            <p>Не просто позначка біля назви — інструменти, які перетворюють профіль на канал продажів.</p>
                        </div>

                        <div class="prol-features__grid">
                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--blue"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Вас знаходять першими</h3>
                                    <p>PRO-профілі показуються вище у видачі каталогу та в підказках пошуку. Профіль також індексується Google і видимий для AI-пошуку — ChatGPT, Gemini, Perplexity.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--green"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Ваш голос у кожному відгуку</h3>
                                    <p>Офіційна відповідь компанії — подяка чи аргументована позиція — працює на довіру публічно.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--violet"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Рішення на цифрах</h3>
                                    <p>Перегляди, кліки на телефон і сайт, джерела трафіку — видно, що саме приводить клієнтів.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--amber"><i class="fa-regular fa-id-card" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Повноцінна вітрина</h3>
                                    <p>Фото, галерея, послуги, переваги, FAQ і SEO-дані публічної сторінки.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--green"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Захист від несправедливого</h3>
                                    <p>Окремий механізм звернень щодо фейкових чи образливих відгуків — розгляд за правилами.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--violet"><i class="fa-solid fa-code" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Віджет рейтингу на ваш сайт</h3>
                                    <p>Бейдж із живим рейтингом і відгуками для вашого сайту та соцмереж — готовий код у кабінеті. Довіра з DOVIRA працює й на вашій сторінці.</p>
                                </div>
                            </article>
                        </div>

                        <div class="prol-features__cta">
                            <a class="btn btn--primary" href="{{ $proPrimaryUrl }}">
                                <span>{{ $proPrimaryLabel }}</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                            <a class="prol-link" href="#pro-pricing">
                                <span>Переглянути тарифи</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <section class="prol-demo" aria-label="Як PRO виглядає в дії">
                    <div class="prol-section-head">
                        <h2>Як це працює для вашого бізнесу</h2>
                        <p>Вас знаходять, вам довіряють — і ви бачите результат.</p>
                    </div>

                    <div class="prol-demo__row">
                        <div class="prol-demo__copy">
                            <p class="prol-pill"><span>1</span> Каталог</p>
                            <h3>Вас знаходять у каталозі</h3>
                            <p class="prol-demo__text">
                                Люди шукають виконавців за категорією і містом — і бачать ваш профіль.
                            </p>
                            <ul class="prol-demo__list">
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Профіль у видачі каталогу та пошуку</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Сторінка індексується в Google — вас знаходять за назвою і послугами</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Рейтинг і кількість відгуків видно одразу</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>PRO піднімає вас вище за конкурентів</span></li>
                            </ul>
                            <div class="prol-demo__actions">
                                <a class="btn btn--primary" href="{{ $proClaimUrl }}">
                                    <span>Додати свою компанію</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                            <p class="prol-demo__note">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                Підтвердження прав — безкоштовне
                            </p>
                        </div>

                        <div class="prol-demo__visual" aria-hidden="true">
                            <div class="prol-photo">
                                <img src="{{ asset('static/assets/fp1.jpg') }}" alt="" loading="lazy">
                            </div>
                            <div class="prol-chip prol-chip--left">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>PRO — вище у видачі</span>
                            </div>
                        </div>
                    </div>

                    <div class="prol-demo__row prol-demo__row--reverse">
                        <div class="prol-demo__copy">
                            <p class="prol-pill"><span>2</span> Ваша сторінка</p>
                            <h3>Клієнт бачить, чому вам можна довіряти</h3>
                            <p class="prol-demo__text">
                                Одна сторінка відповідає на всі питання клієнта перед зверненням.
                            </p>
                            <ul class="prol-demo__list">
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Послуги, фото, переваги й контакти</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Відгуки з вашими офіційними відповідями</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Статуси довіри — «Перевірений акаунт» і PRO</span></li>
                            </ul>
                            <div class="prol-demo__actions">
                                <a class="btn btn--primary" href="{{ $proPrimaryUrl }}">
                                    <span>Оформити свою сторінку</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                                <a class="prol-link" href="{{ route('catalog') }}">
                                    <span>Подивитись приклади</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <div class="prol-demo__visual" aria-hidden="true">
                            <div class="prol-photo">
                                <img src="{{ asset('static/assets/fp2.webp') }}" alt="" loading="lazy">
                            </div>
                            <div class="prol-chip prol-chip--right">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                <span>Перевірений акаунт</span>
                            </div>
                        </div>
                    </div>

                    <div class="prol-demo__row">
                        <div class="prol-demo__copy">
                            <p class="prol-pill"><span>3</span> Аналітика</p>
                            <h3>Ви бачите, що приводить звернення</h3>
                            <p class="prol-demo__text">
                                PRO-кабінет показує шлях клієнта до вас — без здогадок.
                            </p>
                            <ul class="prol-demo__list">
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Перегляди й унікальні відвідувачі</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Кліки на телефон, сайт і email</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Джерела трафіку та нові відгуки</span></li>
                            </ul>
                            <div class="prol-demo__actions">
                                <a class="btn btn--primary" href="{{ $proPrimaryUrl }}">
                                    <span>{{ $proPrimaryLabel }}</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                                <a class="prol-link" href="#pro-pricing">
                                    <span>Тарифи</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                            <p class="prol-demo__note">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                Скасування підписки будь-коли
                            </p>
                        </div>

                        <div class="prol-demo__visual" aria-hidden="true">
                            <div class="prol-photo">
                                <img src="{{ asset('static/assets/fp3.jpg') }}" alt="" loading="lazy">
                            </div>
                            <div class="prol-chip prol-chip--right">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Кліки на контакти</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="section pro-pricing" id="pro-pricing" aria-labelledby="pro-pricing-title">
                    <div class="container">
                        <div class="pro-pricing__head">
                            <h2 class="h2 pro-pricing__title" id="pro-pricing-title">
                                Формати <span>доступу</span>
                            </h2>
                            <div class="section-divider pro-pricing__divider"></div>
                            <p class="pro-pricing__sub muted">
                                Профіль у каталозі, відгуки та підтвердження прав — безкоштовно. Керування сторінкою, контакти й аналітика відкриваються з PRO.
                            </p>

                            <p class="pro-pricing__promo">
                                <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                <span>Стартова пропозиція діє лише <strong>3 дні</strong> — далі вартість буде {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}</span>
                            </p>
                        </div>

                        <div class="pro-pricing__grid">
                            <article class="pro-price-card">
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-star"></i></span>
                                    <span class="pro-benefit-card__tag">START</span>
                                </div>
                                <p class="pro-price-card__price">
                                    <span>{{ \App\Support\ProPricing::formatDisplay(0) }}</span>
                                    <small>назавжди</small>
                                </p>
                                <p class="pro-price-card__text">Базова присутність у каталозі DOVIRA: сторінка профілю, рейтинг і відгуки.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Сторінка профілю в каталозі</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Підтвердження прав на профіль</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Рейтинг і відгуки клієнтів</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Форма «Залишити заявку» від клієнтів</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Видимість у каталозі та індексація в Google</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Віджет рейтингу для вашого сайту й соцмереж</li>
                                </ul>
                                <a class="btn pro-price-card__btn pro-price-card__btn--ghost" href="{{ $proPrimaryUrl }}">
                                    <span>Почати безкоштовно</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </article>

                            <article class="pro-price-card pro-price-card--featured">
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-shield-halved"></i></span>
                                    <span class="pro-benefit-card__tag">PRO</span>
                                    <div class="pro-price-card__badge">Стартова пропозиція</div>
                                </div>
                                <p class="pro-price-card__price">
                                    <s class="pro-price-card__old">{{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}</s>
                                    <span>{{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }}</span>
                                    <small>/ 6 місяців</small>
                                </p>
                                <p class="pro-price-card__text">Стартова ціна на запуску платформи, разовий платіж за 6 місяців. <strong>Фіксуємо її назавжди:</strong> при продовженні ви платите ті самі {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }}, навіть коли тариф коштуватиме {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Повне керування сторінкою профілю</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Контакти на сторінці: телефон, сайт, соцмережі</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Контакти клієнтів із заявок</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Пріоритет у каталозі й пошуку</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Публічні відповіді на відгуки</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Аналітика переглядів, кліків і CTR</li>
                                </ul>
                                <a class="btn pro-price-card__btn pro-price-card__btn--primary" href="{{ $proPrimaryUrl }}">
                                    <span>{{ $proPrimaryLabel }}</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </article>
                        </div>

                        <p class="muted" style="text-align: center; margin-top: 18px; font-size: 14px;">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                            Скасувати підписку можна будь-коли в кабінеті — профіль залишиться в каталозі на безкоштовному тарифі.
                        </p>
                    </div>
                </section>

                <section class="section faq pro-faq" id="pro-faq" aria-label="Питання та відповіді про PRO-акаунт">
                    <div class="container">
                        <div class="faq__head">
                            <div class="badge faq__badge">FAQ PRO</div>
                            <h2 class="h2 faq__title">Поширені питання про PRO-акаунт</h2>
                            <p class="muted faq__lead">Коротко про каталог, нових клієнтів, контактні дії, аналітику і підписку.</p>
                        </div>

                        <div class="faq__list">
                            <details class="faq__item" open>
                                <summary class="faq__question">Що дає PRO-акаунт у порівнянні з безкоштовним профілем?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        PRO посилює профіль як канал залучення клієнтів: дає пріоритет у каталозі та пошуку,
                                        публічні відповіді на відгуки, детальну аналітику переглядів і контактних дій та сповіщення по ключових подіях.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи знаходять мій профіль у Google та AI-пошуку?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Так. Кожна публічна сторінка профілю на DOVIRA індексується Google та іншими пошуковиками:
                                        вона потрапляє в sitemap, має структуровані дані Schema.org (рейтинг, відгуки, контакти),
                                        тож у видачі профіль може показуватися із зірочками рейтингу. Сторінки також відкриті для
                                        AI-пошуку — ChatGPT, Gemini і Perplexity можуть посилатися на ваш профіль, коли людина шукає
                                        виконавця. Що повніший профіль і що більше в нього відгуків, то легше вас знайти.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи гарантує DOVIRA нових клієнтів?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Ні, платформа не може гарантувати продажі. Але DOVIRA дає власнику PRO-профілю видимість у каталозі,
                                        повну публічну сторінку з контактами й аналітику, щоб люди, які вже шукають послугу,
                                        могли перейти до сайту, телефону, email або адреси чи залишити заявку.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Що таке віджет рейтингу і навіщо він моєму сайту?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Віджет — це бейдж або інтерактивна картка з вашим живим рейтингом на DOVIRA,
                                        яку можна вставити на власний сайт чи додати посиланням у соцмережі. Готовий код —
                                        у кабінеті на вкладці «Огляд». Відвідувачі вашого сайту бачать підтверджений рейтинг
                                        і реальні відгуки — це знімає сумніви перед зверненням. А посилання між вашим сайтом
                                        і профілем допомагає обом сторінкам у пошуку Google. Віджет доступний усім власникам
                                        профілів, включно з безкоштовним тарифом.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Як працює прив'язка профілю?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        У кабінеті можна знайти потрібний профіль через пошук, подати заявку на прив'язку
                                        і відстежувати її статус. Для підтвердження прав на профіль система підтримує дозавантаження доказів.
                                        Підтвердження прав безкоштовне; керування сторінкою (редагування, відповіді, контакти)
                                        відкривається з PRO-підпискою.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Що саме можна редагувати в публічному профілі?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        З активною PRO-підпискою — назву, slug, категорію, послуги, короткий і повний опис,
                                        досьє, контакти, сайт, соцмережі, місто, фото, галерею, FAQ, переваги та SEO-дані сторінки.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Що саме доступно для роботи з відгуками?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        У кабінеті є список відгуків із фільтрами, пошуком і сортуванням. Для PRO-профілю
                                        доступні офіційні відповіді, а також швидка зміна видимості відгуку в інтерфейсі.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи можна скасувати підписку PRO?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Так, підписку можна скасувати будь-коли в кабінеті. Профіль при цьому не зникає —
                                        він залишається в каталозі на безкоштовному тарифі: сторінка, рейтинг і відгуки видимі,
                                        але контакти на сторінці, редагування й аналітика знову відкриються з PRO.
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>
                </section>

                <section class="prol-final" aria-labelledby="prol-final-title">
                    <div class="prol-final__card">
                        <div class="prol-final__glow" aria-hidden="true"></div>

                        <div class="prol-final__copy">
                            <span class="prol-final__eyebrow">
                                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                DOVIRA PRO
                            </span>
                            <h2 id="prol-final-title">Ваші клієнти вже читають відгуки</h2>
                            <p>
                                Питання лише в тому, що вони бачать про вас.
                                Створіть профіль безкоштовно — PRO увімкнете, коли будете готові.
                            </p>
                            <div class="prol-final__actions">
                                <a class="btn prol-final__btn" href="{{ $proPrimaryUrl }}">
                                    <span>{{ $proPrimaryLabel }}</span>
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </a>
                                <a class="prol-final__link" href="#pro-pricing">
                                    <span>Переглянути тарифи</span>
                                    <i class="fa-solid fa-tags" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <div class="prol-final__visual" aria-hidden="true">
                            <div class="prol-final__mini prol-final__mini--rating">
                                <span class="prol-final__mini-icon"><i class="fa-solid fa-star"></i></span>
                                <div>
                                    <strong>4.9 із 5</strong>
                                    <span>рейтинг вашого профілю</span>
                                </div>
                            </div>
                            <div class="prol-final__mini prol-final__mini--review">
                                <div class="prol-final__mini-stars">
                                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                </div>
                                <p>«Обрала за відгуками — все зробили вчасно і якісно»</p>
                            </div>
                            <div class="prol-final__mini prol-final__mini--reply">
                                <span class="prol-final__mini-icon prol-final__mini-icon--reply"><i class="fa-solid fa-reply"></i></span>
                                <div>
                                    <strong>Офіційна відповідь</strong>
                                    <span>ваш голос у кожному відгуку</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </section>
@endsection
