@extends('static.layout')

@section('title', 'PRO акаунт — DOVIRA')
@section('body_class', 'page-pro')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/pro.css') }}">
@endpush

@section('content')
    <section class="section pro-page">
        <div class="container">
            <div class="pro-page__stack">
                <section class="pro-hero" aria-labelledby="pro-hero-title">

                    <div class="pro-hero__content">
                        <span class="pro-hero__badge">Професійний кабінет</span>
                        <h1 class="pro-hero__title" id="pro-hero-title">
                            Керуйте репутацією професійно <br>
                            з PRO-акаунтом
                        </h1>
                        <p class="pro-hero__subtitle">
                            Підтверджуйте профіль, відповідайте на відгуки, працюйте зі зверненнями
                            та будуйте публічну довіру в одному кабінеті.
                        </p>

                        <div class="pro-hero__actions">
                            <a class="btn dovira-btn pro-hero__btn" href="{{ route('login') }}">
                                <span>Підключити PRO</span>
                                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                            </a>
                            <a class="btn dovira-btn pro-hero__btn pro-hero__btn--alt" href="#pro-features">
                                <span>Переглянути можливості</span>
                                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>

                    <img
                        class="pro-hero__side pro-hero__side--left"
                        src="https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1.webp"
                        srcset="https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1-p-500.webp 500w, https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1.webp 606w"
                        sizes="(max-width: 606px) 100vw, 606px"
                        loading="lazy"
                        alt=""
                        aria-hidden="true"
                    >
                    <img
                        class="pro-hero__side pro-hero__side--right"
                        src="https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1.webp"
                        srcset="https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1-p-500.webp 500w, https://cdn.prod.website-files.com/66e3cafc52638c58d5c746f1/66e7b492babda97095c5f01a_boxes1.webp 606w"
                        sizes="(max-width: 606px) 100vw, 606px"
                        loading="lazy"
                        alt=""
                        aria-hidden="true"
                    >

                    <div class="pro-hero__cards" aria-hidden="true">
                        <article class="pro-stat-card pro-stat-card--left">
                            <div class="pro-benefit-card__head">
                                <span class="pro-benefit-card__icon">
                                    <i class="fa-solid fa-circle-check"></i>
                                </span>
                                <span class="pro-benefit-card__tag">PRO STATUS</span>
                            </div>
                            <h3 class="pro-benefit-card__title">Підтверджений профіль</h3>
                            <p class="pro-benefit-card__text">Бейдж довіри, верифікація реквізитів та офіційний статус у каталозі.</p>
                            <div class="pro-benefit-card__visual pro-benefit-card__visual--verified">
                                <span class="profile-chip profile-chip--verified">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений акаунт
                                </span>
                                <span class="profile-chip profile-chip--pro">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO
                                </span>
                            </div>
                        </article>

                        <article class="pro-stat-card pro-stat-card--center">
                            <div class="pro-benefit-card__head">
                                <span class="pro-benefit-card__icon">
                                    <i class="fa-solid fa-arrow-trend-up"></i>
                                </span>
                                <span class="pro-benefit-card__tag">SEARCH BOOST</span>
                            </div>
                            <h3 class="pro-benefit-card__title">Вища позиція в пошуку</h3>
                            <p class="pro-benefit-card__text">PRO-профілі ранжуються вище в релевантній видачі та картках регіону.</p>
                            <div class="pro-benefit-card__visual pro-benefit-card__visual--reviews">
                                <picture class="pro-benefit-card__reviews-picture" aria-hidden="true">
                                    <source media="(max-width: 760px)" srcset="{{ asset('static/assets/rev-list-mb.png') }}">
                                    <img src="{{ asset('static/assets/rev-list-full.png') }}" alt="" class="pro-benefit-card__reviews-image" loading="lazy">
                                </picture>
                            </div>
                        </article>

                        <article class="pro-stat-card pro-stat-card--right">
                            <div class="pro-benefit-card__head">
                                <span class="pro-benefit-card__icon">
                                    <i class="fa-solid fa-comments"></i>
                                </span>
                                <span class="pro-benefit-card__tag">REPUTATION</span>
                            </div>
                            <h3 class="pro-benefit-card__title">Модерація і відповідь на відгуки</h3>
                            <p class="pro-benefit-card__text">Швидке опрацювання звернень, публічні відповіді та керування репутацією.</p>
                            <div class="pro-benefit-card__visual pro-benefit-card__visual--moderation">
                                <div class="pro-moderation-card">
                                    <span class="pro-moderation-card__icon-wrap">
                                        <i class="fa-solid fa-comment-dots"></i>
                                        <span class="pro-moderation-card__badge">12</span>
                                    </span>
                                    <span class="pro-moderation-card__text-wrap">
                                        <strong class="pro-moderation-card__title">Нові відгуки</strong>
                                        <span class="pro-moderation-card__meta">чекають відповіді</span>
                                    </span>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="pro-unlock" id="pro-features" aria-labelledby="pro-unlock-title">
                    <div class="container">
                    <div class="pro-unlock__head">
                        <h2 class="pro-unlock__title" id="pro-unlock-title">
                            Розкрийте силу вашого
                            <span>PRO-профілю</span>
                        </h2>
                        <a class="btn dovira-btn pro-unlock__cta" href="{{ route('login') }}">
                            <span>Підключити PRO</span>
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="pro-unlock__stats">
                        <article class="pro-unlock-card">
                            <div class="pro-unlock-card__icon">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <div class="pro-unlock-card__content">
                                <strong>2500<span>+</span></strong>
                                <p> Користувачів щомісяця переглядають профілі</p>
                            </div>
                        </article>

                        <article class="pro-unlock-card">
                            <div class="pro-unlock-card__icon">
                                <i class="fa-solid fa-comments-dollar"></i>
                            </div>
                            <div class="pro-unlock-card__content">
                                <strong>1200<span>+</span></strong>
                                <p> Нових звернень через відгуки і каталог</p>
                            </div>
                        </article>

                        <article class="pro-unlock-card">
                            <div class="pro-unlock-card__icon">
                                <i class="fa-solid fa-star"></i>
                            </div>
                            <div class="pro-unlock-card__content">
                                <strong>4.9<span>/5.0</span></strong>
                                <p> Середня оцінка активних PRO-профілів</p>
                            </div>
                        </article>
                    </div>
                    </div>
                </section>

                <section class="section trust-proof pro-flow" id="pro-flow" aria-label="Як працює PRO кабінет">
                    <div class="container">
                        <div class="trust-proof__panel">
                            <div class="pro-flow__head">
                                <h2 class="pro-flow__main-title">
                                    Як PRO-кабінет DOVIRA
                                    <span>спрощує вашу роботу</span>
                                </h2>
                                <div class="section-divider pro-flow__divider"></div>
                                <p class="pro-flow__sub muted">
                                    Три прості кроки, які перетворюють профіль на повноцінний інструмент репутації.
                                </p>
                            </div>

                            <div class="trust-proof__features">
                                <article class="trust-proof__feature pro-flow__feature">
                                    <div class="trust-proof__visual" aria-hidden="true">
                                        <div class="trust-stack trust-stack--showcase">
                                            <div class="trust-showcase trust-showcase--screen">
                                                <img class="trust-showcase__image" src="{{ asset('static/assets/how1.jpg') }}" alt="">
                                            </div>
                                            <div class="trust-showcase trust-showcase--mini-image">
                                                <div class="pro-flow-mini">
                                                    <div class="pro-flow-mini__icon"><i class="fa-solid fa-users"></i></div>
                                                    <div class="pro-flow-mini__body">
                                                        <p>Total users</p>
                                                        <strong>25k+</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="trust-proof__copy">
                                        <div class="pro-flow__num">1</div>
                                        <h3 class="trust-proof__feature-title">Профіль, якому довіряють</h3>
                                        <p class="trust-proof__feature-text">
                                            PRO-акаунт додає сторінці професійний статус, підтверджені елементи профілю та публічну ознаку активної присутності на платформі.
                                        </p>
                                        <div class="pro-flow__chips">
                                            <span class="pro-flow__pill"><i class="fa-solid fa-circle-check"></i> Підтверджений статус</span>
                                            <span class="pro-flow__pill"><i class="fa-solid fa-shield-halved"></i> Офіційна сторінка</span>
                                        </div>
                                        <a class="trust-proof__btn" href="{{ route('login') }}">
                                            <span>Дізнатись більше</span>
                                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </article>

                                <article class="trust-proof__feature pro-flow__feature">
                                    <div class="trust-proof__visual" aria-hidden="true">
                                        <div class="trust-stack trust-stack--showcase">
                                            <div class="trust-showcase trust-showcase--screen">
                                                <img class="trust-showcase__image" src="{{ asset('static/assets/how2.png') }}" alt="">
                                            </div>
                                            <div class="trust-showcase trust-showcase--mini-image">
                                                <div class="pro-flow-mini">
                                                    <div class="pro-flow-mini__icon"><i class="fa-solid fa-circle-check"></i></div>
                                                    <div class="pro-flow-mini__body">
                                                        <p>Import data</p>
                                                        <strong>Успішно</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="trust-proof__copy">
                                        <div class="pro-flow__num">2</div>
                                        <h3 class="trust-proof__feature-title">Відповідайте на відгуки</h3>
                                        <p class="trust-proof__feature-text">
                                            Публічні відповіді допомагають пояснювати позицію профілю, коректно реагувати на відгуки та показувати відповідальний підхід до комунікації.
                                        </p>
                                        <div class="pro-flow__chips">
                                            <span class="pro-flow__pill"><i class="fa-solid fa-comments"></i> Офіційні відповіді</span>
                                            <span class="pro-flow__pill"><i class="fa-solid fa-bullhorn"></i> Публічна позиція</span>
                                        </div>
                                        <a class="trust-proof__btn btn" href="{{ route('pro') }}#pro-features">
                                            <span>Дізнатись більше</span>
                                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </article>

                                <article class="trust-proof__feature pro-flow__feature">
                                    <div class="trust-proof__visual" aria-hidden="true">
                                        <div class="trust-stack trust-stack--showcase">
                                            <div class="trust-showcase trust-showcase--screen">
                                                <img class="trust-showcase__image" src="{{ asset('static/assets/how3.png') }}" alt="">
                                            </div>
                                            <div class="trust-showcase trust-showcase--mini-image">
                                                <div class="pro-flow-mini">
                                                    <div class="pro-flow-mini__icon"><i class="fa-solid fa-chart-line"></i></div>
                                                    <div class="pro-flow-mini__body">
                                                        <p>Amount</p>
                                                        <strong>$50,782</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="trust-proof__copy">
                                        <div class="pro-flow__num">3</div>
                                        <h3 class="trust-proof__feature-title">Усе в одному кабінеті</h3>
                                        <p class="trust-proof__feature-text">
                                            Керуйте зверненнями, перевірками, документами та іншими репутаційними процесами з одного професійного кабінету без зайвого хаосу.
                                        </p>
                                        <div class="pro-flow__chips">
                                            <span class="pro-flow__pill"><i class="fa-solid fa-briefcase"></i> Кейси і звернення</span>
                                            <span class="pro-flow__pill"><i class="fa-solid fa-folder-open"></i> Документи і статуси</span>
                                        </div>
                                        <a class="trust-proof__btn" href="{{ route('pro') }}#pro-flow">
                                            <span>Дізнатись більше</span>
                                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="section pro-demo" id="pro-demo" aria-labelledby="pro-demo-title">
                    <div class="container">
                        <div class="pro-demo__head">
                            <h2 class="h2 pro-demo__title" id="pro-demo-title">
                                Демонстрація <span>PRO-панелі</span> 
                            </h2>
                            <div class="section-divider pro-demo__divider"></div>
                            <p class="pro-demo__sub muted">
                                Перегляньте, як виглядає робота з репутацією в інтерфейсі PRO-кабінету.
                            </p>
                        </div>

                        <div class="pro-demo__stage">
                            <span class="pro-demo__tag pro-demo__tag--live">
                                <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                                Live demo
                            </span>

                            <div class="pro-demo__screen">
                                <video
                                    class="pro-demo__video"
                                    autoplay
                                    muted
                                    loop
                                    playsinline
                                    preload="metadata"
                                    poster="{{ asset('static/assets/pro-demo-poster.jpg') }}"
                                >
                                    <source src="{{ asset('static/assets/pro-demo-panel.mp4') }}" type="video/mp4">
                                </video>
                            </div>

                            <span class="pro-demo__tag pro-demo__tag--pro">
                                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                PRO Dashboard
                            </span>
                        </div>
                    </div>
                </section>

                <section class="section pro-pricing" id="pro-pricing" aria-labelledby="pro-pricing-title">
                    <div class="container">
                        <div class="pro-pricing__head">
                            <h2 class="h2 pro-pricing__title" id="pro-pricing-title">
                                Тарифи <span>PRO-акаунту</span>
                            </h2>
                            <div class="section-divider pro-pricing__divider"></div>
                            <p class="pro-pricing__sub muted">
                                Оберіть формат підключення під ваш обсяг роботи та рівень публічної присутності.
                            </p>

                            <div class="pro-pricing__switch" role="tablist" aria-label="Період оплати">
                                <button class="pro-pricing__switch-btn is-active" type="button" role="tab" aria-selected="true" data-billing-toggle="monthly">Щомісяця</button>
                                <button class="pro-pricing__switch-btn" type="button" role="tab" aria-selected="false" data-billing-toggle="yearly">Щороку</button>
                            </div>
                        </div>

                        <div class="pro-pricing__grid">
                            <article class="pro-price-card">
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-star"></i></span>
                                    <span class="pro-benefit-card__tag">FREE</span>
                                </div>
                                <p class="pro-price-card__price">
                                    <span data-price-amount data-monthly="0" data-yearly="0">0</span>
                                    <small data-price-period data-monthly="грн" data-yearly="грн">грн</small>
                                </p>
                                <p class="pro-price-card__text">Базовий тариф для старту: офіційна присутність профілю та ключова підтримка.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Підтверджений статус</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Базова статистика профілю</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Email-підтримка</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Публічна сторінка в каталозі</li>
                                </ul>
                                <a class="btn pro-price-card__btn pro-price-card__btn--ghost" href="{{ route('login') }}">
                                    <span>Почати безкоштовно</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </article>

                            <article class="pro-price-card pro-price-card--featured">
                               
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-crown"></i></span>
                                    <span class="pro-benefit-card__tag">BUSINESS</span>
                                     <div class="pro-price-card__badge">Рекомендовано</div>
                                </div>
                                <p class="pro-price-card__price">
                                    <span data-price-amount data-monthly="2990" data-yearly="29900">2990</span>
                                    <small data-price-period data-monthly="грн / міс" data-yearly="грн / рік">грн / міс</small>
                                </p>
                                <p class="pro-price-card__text">Для команд і компаній з великим потоком звернень та складними кейсами.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Пріоритетна модерація</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Документи та статуси в кабінеті</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Модерація та видалення відгуків</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Персональна підтримка</li>
                                </ul>
                                <a class="btn pro-price-card__btn pro-price-card__btn--primary" href="{{ route('login') }}">
                                    <span>Підключити Business</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </article>

                            <article class="pro-price-card">
                                <div class="pro-benefit-card__head pro-price-card__head">
                                    <span class="pro-benefit-card__icon"><i class="fa-solid fa-shield-halved"></i></span>
                                    <span class="pro-benefit-card__tag">PRO</span>
                                </div>
                                <p class="pro-price-card__price">
                                    <span data-price-amount data-monthly="1490" data-yearly="14900">1490</span>
                                    <small data-price-period data-monthly="грн / міс" data-yearly="грн / рік">грн / міс</small>
                                </p>
                                <p class="pro-price-card__text">Тариф для активної репутаційної роботи з видимістю та публічною комунікацією.</p>
                                <ul class="pro-price-card__list">
                                    <li><i class="fa-solid fa-circle-check"></i> Пріоритет у пошуку</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Публічні відповіді на відгуки</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Статус PRO</li>
                                    <li><i class="fa-solid fa-circle-check"></i> Розширена статистика профілю</li>
                                </ul>
                                <a class="btn pro-price-card__btn pro-price-card__btn--ghost" href="{{ route('login') }}">
                                    <span>Підключити PRO</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </article>
                        </div>
                    </div>
                </section>

                <section class="section faq pro-faq" id="pro-faq" aria-label="Питання та відповіді про PRO-акаунт">
                    <div class="container">
                        <div class="faq__head">
                            <div class="badge faq__badge">FAQ PRO</div>
                            <h2 class="h2 faq__title">Поширені питання про PRO-акаунт</h2>
                            <p class="muted faq__lead">Коротко про підключення, модерацію, відповіді на відгуки та умови тарифів.</p>
                        </div>

                        <div class="faq__list">
                            <details class="faq__item" open>
                                <summary class="faq__question">Що дає PRO-акаунт у порівнянні з безкоштовним профілем?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        PRO відкриває розширені інструменти: пріоритет у пошуку, публічні відповіді на відгуки, модераційні функції та додаткову статистику для системної роботи з репутацією.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Як працює пріоритет у пошуку?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Профілі з PRO-статусом мають вищу видимість у релевантних результатах каталогу. Це допомагає користувачам швидше знаходити активні та офіційно керовані сторінки.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи можна відповідати на негативні або спірні відгуки?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Так. У PRO-тарифах доступні публічні офіційні відповіді, щоб коректно пояснювати позицію, уточнювати контекст та вести відкриту комунікацію з аудиторією.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Що входить у модерацію та видалення відгуків?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Для BUSINESS-тарифу доступні пріоритетні модераційні звернення, робота з кейсами та процедура перевірки сумнівних публікацій згідно правил платформи.
                                    </div>
                                </div>
                            </details>

                            <details class="faq__item">
                                <summary class="faq__question">Чи можу я змінити тариф після підключення?</summary>
                                <div class="faq__panel">
                                    <div class="faq__answer">
                                        Так, тариф можна змінити в особистому кабінеті. Під час зміни зберігаються ваші дані профілю, історія звернень та ключові налаштування сторінки.
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </section>
@endsection
