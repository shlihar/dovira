@extends('static.layout')

@php
    $stats = $stats ?? [];

    // Гість спершу реєструється, авторизований — одразу в кабінет.
    $isAuthed = auth()->check();
    $proPrimaryUrl = $isAuthed ? route('pro.account') : route('register');
    $proClaimUrl = $isAuthed ? route('pro.account', ['tab' => 'claims']) : route('register');
    $proPrimaryLabel = $isAuthed ? 'Відкрити PRO кабінет' : 'Підключити PRO';
@endphp

@section('title', 'Для бізнесу — DOVIRA PRO: ваша картка вище за всі безплатні')
@section('description', 'DOVIRA PRO ставить вашу картку на верхні позиції категорії — у каталозі, у пошуку та в результатах за послугою. Плюс офіційні відповіді на відгуки, керування сторінкою й контакти клієнтів із заявок.')
@section('canonical', route('pro'))
@section('body_class', 'page-pro')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/pro.css') }}?v={{ @filemtime(public_path('static/css/pages/pro.css')) }}">
    @php
        $proOfferSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'DOVIRA PRO',
            'description' => 'PRO-підписка для компаній і спеціалістів на платформі DOVIRA: верхні позиції у видачі каталогу й пошуку, офіційні відповіді на відгуки, керування сторінкою профілю та контакти клієнтів із заявок.',
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
                    'description' => 'Повний PRO-доступ на 6 місяців за стартовою ціною: верхні позиції у видачі, офіційні відповіді на відгуки, керування сторінкою та контакти клієнтів із заявок.',
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
                            Ваша картка — вище за всі безплатні
                        </h1>
                        <p class="pro-hero__subtitle">
                            Клієнт відкриває категорію і дивиться перших. PRO ставить вашу картку на верхні
                            позиції у видачі каталогу й пошуку — у тому вигляді, в якому її бачить більшість,
                            з позначкою PRO. Разом із місцем ви отримуєте офіційні відповіді на відгуки,
                            керування сторінкою та контакти клієнтів із заявок.
                        </p>
                        <p class="pro-hero__note muted">
                            Чесно про межі: якщо платних у категорії кілька, верхні місця щодня чергуються
                            між ними — першим буває кожен. А коли відвідувач сам обирає «Найбільше відгуків»,
                            «Нові» чи «Найвищий рейтинг», список чесно шикується за цією ознакою, без
                            платних позицій. Рейтинги /top підписка не зачіпає взагалі.
                        </p>
                        <p class="pro-pricing__promo">
                            <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                            <span>{{ \App\Support\ProPricing::launchCohortPriceLine() }}</span>
                        </p>

                        {{-- Пошук компанії ПЕРЕД реєстрацією: людина обирає свій
                             профіль тут, і його id їде далі через `next`, тож після
                             створення акаунта вона потрапляє одразу на підтвердження
                             прав, а не на головну. --}}
                        <form
                            class="pro-claim-search"
                            data-pro-claim-search
                            data-authed="{{ $isAuthed ? '1' : '0' }}"
                            data-account-url="{{ route('pro.account') }}"
                            data-register-url="{{ route('register') }}"
                            action="{{ route('pro.account', ['tab' => 'claims']) }}"
                            method="get"
                        >
                            <label class="sr-only" for="pro-claim-input">Знайдіть свою компанію в каталозі</label>
                            <div class="pro-claim-search__field">
                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                <input
                                    id="pro-claim-input"
                                    type="search"
                                    name="q"
                                    autocomplete="off"
                                    placeholder="Назва компанії, місто або сайт"
                                    aria-expanded="false"
                                    data-pro-claim-input
                                >
                                <button type="submit" class="pro-claim-search__submit">
                                    <span>Знайти</span>
                                </button>
                            </div>
                            <div class="pro-claim-search__results" data-pro-claim-results hidden></div>
                            <p class="pro-claim-search__note">
                                Ваша компанія, найімовірніше, вже в каталозі. Підтвердження прав — безкоштовне.
                            </p>
                        </form>

                        <div class="pro-hero__actions">
                            <a class="btn dovira-btn pro-hero__btn" href="{{ $proPrimaryUrl }}">
                                <span>{{ $isAuthed ? 'Відкрити PRO кабінет' : 'Створити акаунт' }}</span>
                            </a>
                            <a class="btn dovira-btn pro-hero__btn pro-hero__btn--alt" href="#pro-pricing">
                                <span>Переглянути тарифи</span>
                            </a>
                        </div>
                    </div>

                    <div class="pro-hero__cards" aria-hidden="true">
                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                            <h3 class="pro-hero-card__title">Перші позиції категорії</h3>
                            <p class="pro-hero-card__text">Вас бачать до того, як клієнт дійде до конкурентів.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--you">
                                    <span class="pro-rank-row__pos">1</span>
                                    <span class="pro-rank-row__name">Ваша компанія</span>
                                    <span class="pro-rank-row__pro">PRO</span>
                                </div>
                                <div class="pro-rank-row">
                                    <span class="pro-rank-row__pos">2</span>
                                    <span class="pro-rank-row__name">Профіль без підписки</span>
                                </div>
                            </div>
                        </article>

                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-sliders"></i></span>
                            <h3 class="pro-hero-card__title">Ваш голос і ваша сторінка</h3>
                            <p class="pro-hero-card__text">Офіційні відповіді на відгуки, послуги, ціни й фото — під вашим контролем.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--you">
                                    <span class="pro-rank-row__pos"><i class="fa-solid fa-check"></i></span>
                                    <span class="pro-rank-row__name">Послуги, ціни й фото — оформлено</span>
                                    <span class="pro-rank-row__pro">PRO</span>
                                </div>
                            </div>
                        </article>

                        <article class="pro-hero-card">
                            <span class="pro-hero-card__icon"><i class="fa-solid fa-chart-line"></i></span>
                            <h3 class="pro-hero-card__title">Видно, що дала оплата</h3>
                            <p class="pro-hero-card__text">Окремий лічильник показів на платних позиціях — у кабінеті.</p>
                            <div class="pro-hero-card__visual">
                                <div class="pro-rank-row pro-rank-row--stat">
                                    <span class="pro-rank-row__name">Показів на платних позиціях</span>
                                    <b class="pro-rank-row__value"><i class="fa-solid fa-eye"></i> 214</b>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="prol-steps" aria-labelledby="prol-steps-title" id="pro-features">
                    <div class="prol-section-head">
                        <h2 id="prol-steps-title">Три кроки — і ви вгорі категорії</h2>
                        <p>Старт безкоштовний — PRO вмикається, коли будете готові.</p>
                    </div>

                    <div class="prol-steps__row">
                        <article class="prol-step">
                            <span class="prol-step__num">1</span>
                            <h3>Знайдіть свій профіль</h3>
                            <p>Ваша компанія, ймовірно, вже в каталозі — клієнти бачать сторінку і без вас. Знайдіть її через пошук у кабінеті.</p>
                        </article>

                        <article class="prol-step">
                            <span class="prol-step__num">2</span>
                            <h3>Підтвердьте права — безкоштовно</h3>
                            <p>Код на email або телефон із профілю. Одразу після підтвердження в кабінеті відкривається аналітика переглядів — ви побачите, скільки людей уже дивиться на вас.</p>
                        </article>

                        <article class="prol-step">
                            <span class="prol-step__num">3</span>
                            <h3>Увімкніть PRO</h3>
                            <p>Картка одразу піднімається на верхні позиції категорії з позначкою PRO. Разом із нею відкриваються офіційні відповіді на відгуки, керування сторінкою й контакти клієнтів із заявок.</p>
                        </article>
                    </div>
                </section>

                <section class="prol-features" aria-labelledby="prol-features-title">
                    <div class="prol-features__panel">
                        <div class="prol-section-head">
                            <h2 id="prol-features-title">Що дає PRO</h2>
                            <p>Головне — місце у видачі. Решта працює на те саме рішення клієнта.</p>
                        </div>

                        {{-- Порядок переваг = позиціонування (спільне зі сторінкою оплати):
                             відповіді → сторінка → заявки → захист трафіку → пріоритет.
                             Телефон і сайт видимі всім безкоштовно; аналітика відкривається
                             безкоштовно після підтвердження прав — не продаємо їх як PRO. --}}
                        <div class="prol-features__grid">
                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--blue"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Верхні позиції у видачі</h3>
                                    <p>Картка стоїть вище за всі безплатні профілі категорії — у видачі за замовчуванням, у режимі «Популярні» та в результатах пошуку, з позначкою PRO. Між платними профілями верхні місця чергуються щодня. Якщо відвідувач сам обирає сортування за відгуками, датою чи оцінкою — список шикується чесно за цим критерієм, як і рейтинги /top.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--violet"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Офіційні відповіді на відгуки</h3>
                                    <p>Ваша відповідь від імені компанії стоїть поруч із кожним відгуком — включно з негативним. Про нові відгуки ви дізнаєтеся одразу, а на фейкові є публічний процес оскарження.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--violet"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Ваша позиція поруч із досьє</h3>
                                    <p>Досьє збирає ШІ з відкритих джерел і показує лише один бік. З PRO ви публікуєте свою відповідь прямо під ним: що неточне, що змінилось, який контекст важливий. Біля вердикту зʼявляється позначка «Компанія надала свою позицію», а текст проходить модерацію.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--blue"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Сторінка, якою керуєте ви</h3>
                                    <p>Послуги, ціни, фото, FAQ і переваги — ви вирішуєте, як виглядає сторінка в момент, коли на неї дивиться клієнт. Самі факти досьє редагує команда Dovira за вашим запитом.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--amber"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Кейси з підтвердженням</h3>
                                    <p>До 10 історій «з чим прийшов клієнт → що зробили → результат» на вашій сторінці. Кейс можна привʼязати до реального відгуку, і відвідувач розгорне його текст прямо в картці — це доказ, а не обіцянка.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--green"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Контакти клієнтів із заявок</h3>
                                    <p>Людина натискає «Залишити заявку» — її телефон та email падають у ваш кабінет. Без PRO заявки видно, але контакти замасковані.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--amber"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Без конкурентів на вашій сторінці</h3>
                                    <p>На PRO-сторінці немає блоку «схожих профілів» — людина, яка вже дійшла до вас, не піде порівнювати далі.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--blue"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Видно результат оплати</h3>
                                    <p>У кабінеті — окремий лічильник: скільки разів картку показали саме на платних позиціях. Поруч перегляди сторінки, кліки на контакти й джерела переходів.</p>
                                </div>
                            </article>

                            <article class="prol-feature">
                                <span class="prol-feature__icon prol-feature__icon--green"><i class="fa-solid fa-code" aria-hidden="true"></i></span>
                                <div>
                                    <h3>Бонус: віджет рейтингу</h3>
                                    <p>Бейдж і блок відгуків із живим рейтингом для вашого сайту — готовий код у кабінеті. За розміщений бейдж — знижка 20% на підписку.</p>
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
                        <p>Клієнт перевіряє — ви відповідаєте — цифри показують результат.</p>
                    </div>

                    <div class="prol-demo__row">
                        <div class="prol-demo__copy">
                            <p class="prol-pill"><span>1</span> Перевірка</p>
                            <h3>Клієнт перевіряє вас</h3>
                            <p class="prol-demo__text">
                                Перед зверненням людина гуглить назву компанії або порівнює виконавців у каталозі — і потрапляє на вашу сторінку.
                            </p>
                            <ul class="prol-demo__list">
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Сторінку знаходять у Google — за назвою і послугами</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Рейтинг, відгуки та відповіді видно одразу</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Профіль публічний — навіть якщо ви ним ще не керуєте</span></li>
                            </ul>
                            <div class="prol-demo__actions">
                                <a class="btn btn--primary" href="{{ $proClaimUrl }}">
                                    <span>Знайти свій профіль</span>
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
                            <p class="prol-pill"><span>2</span> Відповідь</p>
                            <h3>Ви відповідаєте від імені компанії</h3>
                            <p class="prol-demo__text">
                                Офіційна відповідь лишається поруч із відгуком і показує кожному наступному відвідувачу, що вам не байдуже.
                            </p>
                            <ul class="prol-demo__list">
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Відповідь бачать усі, хто читає відгук після вас</span></li>
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Сторінка оформлена: послуги, фото, переваги й контакти</span></li>
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
                            <p class="prol-pill"><span>3</span> Цифри</p>
                            <h3>Ви бачите, скільки людей вас перевіряють</h3>
                            <p class="prol-demo__text">
                                Кабінет показує шлях клієнта до вас — без здогадок. Аналітика переглядів
                                відкривається безкоштовно одразу після підтвердження прав.
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
                                Профіль може вже бути публічним у каталозі й Google, а підтвердження прав — безкоштовне й одразу відкриває аналітику переглядів. PRO додає головне: місце вгорі категорії, офіційні відповіді на відгуки, керування сторінкою та контакти клієнтів із заявок.
                            </p>

                            <p class="pro-pricing__promo">
                                <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                <span>{{ \App\Support\ProPricing::launchCohortPriceLine() }}</span>
                            </p>
                            <p class="pro-pricing__promo">
                                <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                <span>Розмістіть бейдж DOVIRA на сайті або в соцмережах — і отримаєте знижку 20%.</span>
                            </p>
                        </div>

                        <div class="pro-pricing__grid">
                            <article class="pro-price-card">
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-star"></i></span>
                                    <span class="pro-benefit-card__tag">START</span>
                                </div>
                                <p class="pro-price-card__price">
                                    <span>Безкоштовно</span>
                                </p>
                                <p class="pro-price-card__text">Базова присутність у каталозі DOVIRA: сторінка профілю, рейтинг і відгуки.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Сторінка профілю в каталозі</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Телефон і сайт видимі на сторінці</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Підтвердження прав на профіль</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Аналітика переглядів після підтвердження прав</li>
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
                                {{-- Головна цифра — місячний еквівалент; списання раз на 6 міс. --}}
                                <p class="pro-price-card__price">
                                    <s class="pro-price-card__old">{{ \App\Support\ProPricing::monthlyEquivalentDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}</s>
                                    <span>≈ {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::monthlyEquivalent(\App\Support\ProPricing::DISPLAY_CURRENT)) }}</span>
                                    <small>/ місяць</small>
                                </p>
                                <p class="pro-price-card__text">{{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }} раз на 6 місяців, підписка з автопродовженням — вимкнути можна будь-коли. Далі тариф — {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> <b>Усе зі START</b>, плюс:</li>
                                    <li><i class="fa-solid fa-circle-check"></i> <b>Верхні позиції у видачі категорії</b> — вище за всі безплатні профілі</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Позначка PRO на картці й лічильник показів на платних позиціях</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Офіційні відповіді на відгуки від імені компанії</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Повне керування сторінкою: послуги, ціни, фото, FAQ</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Публічна позиція компанії під досьє + запит на перевірку фактів</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Блок кейсів (до 10) із підтвердженням відгуком клієнта</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Контакти клієнтів із заявок — телефон та email тих, хто звернувся</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Email і соцмережі на сторінці профілю</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Без блоку конкурентів на вашій сторінці</li>
                                    <li><i class="fa-solid fa-circle-check"></i> −20% за розміщення бейджа DOVIRA на сайті або в соцмережах</li>
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
                                        Головна різниця — голос. З PRO ви офіційно відповідаєте на відгуки від імені компанії,
                                        повністю керуєте сторінкою (послуги, ціни, фото, FAQ, досьє), бачите контакти клієнтів
                                        із заявок і прибираєте блок конкурентів зі своєї сторінки. Безкоштовний профіль лишається
                                        видимим у каталозі, з телефоном, сайтом і аналітикою переглядів — але без цих інструментів.
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
                                        профілів, включно з безкоштовним тарифом. Якщо розмістите бейдж DOVIRA на своєму сайті
                                        або в соцмережах, можете отримати знижку 20% на підписку.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Як працює прив'язка профілю?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        У кабінеті можна знайти потрібний публічний профіль через пошук і пройти перевірку прав:
                                        кодом на email/телефон із профілю або через заявку з доказами. Профіль уже можуть бачити
                                        клієнти на DOVIRA та знаходити в Google, аналітика переглядів відкривається одразу після
                                        підтвердження, а повне керування сторінкою (редагування, офіційні відповіді, контакти
                                        клієнтів із заявок) — після оформлення PRO-підписки.
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
                                        доступні офіційні публічні відповіді від імені компанії. PRO не дає кнопки для
                                        приховування або видалення відгуків. Якщо відгук порушує правила
                                        (спам, образи, персональні дані, очевидна накрутка або фейк), власник може подати
                                        скаргу з аргументами й матеріалами. Рішення ухвалює команда Dovira за
                                        <a href="{{ route('trust') }}#review-appeals">публічним процесом оскарження</a>:
                                        первинна відповідь — до 3 робочих днів, складні кейси — до 10 робочих днів.
                                        Негативні відгуки не видаляються й не ховаються за оплату.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи можна скасувати підписку PRO?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Так, підписку можна скасувати будь-коли в кабінеті. Профіль при цьому не зникає —
                                        він залишається в каталозі на безкоштовному тарифі: сторінка, рейтинг, відгуки, телефон
                                        і сайт видимі, аналітика працює. Редагування, офіційні відповіді та контакти клієнтів
                                        із заявок знову відкриються з PRO.
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
                            <h2 id="prol-final-title">Верхні місця у вашій категорії вже зайняті</h2>
                            <p>
                                Їх щодня ділять між собою ті, хто платить. Підписка ставить вашу картку в цю ж чергу — вище за всі безплатні профілі.
                                {{ \App\Support\ProPricing::launchCohortPriceLine() }}
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
                                <span class="prol-final__mini-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                                <div>
                                    <strong>1 місце в категорії</strong>
                                    <span>вище за всі безплатні профілі</span>
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
