@extends('static.layout')

@section('title', 'DOVIRA — відгуки про компанії, магазини та спеціалістів')
@section('body_class', 'page-home-main')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/home.css') }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/home-main.css') }}">
@endpush

@section('content')
<section class="hero" id="top">
    <div class="hero__bg" aria-hidden="true"></div>
    <div class="container hero__inner">
        <div class="hero__content">
            <div class="hero__top">
                <div class="badge badge--soft">Репутація — це актив</div>

                <h1 class="hero__title">
                    <span class="hero__title-accent">Відгуки, рейтинги та репутація</span>  — в одному місці
                </h1>

                <p class="hero__lead">
                    На основі <span class="hero__title-accent">124358</span> реальних відгуків
                </p>

                <form class="hero__desktop-search" action="{{ route('catalog') }}" method="get" aria-label="Пошук профілю" data-search-form>
                    <input type="text" name="q" placeholder="Пошук компанії або категорії">
                    <button type="submit" aria-label="Шукати">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </button>
                    <div class="hero__search-suggest" data-search-suggest hidden>
                        <p class="hero__search-suggest-title">Популярні запити</p>
                        <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Стоматології Києва']) }}">
                            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                            <span>Стоматології Києва</span>
                        </a>
                        <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'СТО поруч']) }}">
                            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                            <span>СТО поруч</span>
                        </a>
                        <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Онлайн-магазини одягу']) }}">
                            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                            <span>Онлайн-магазини одягу</span>
                        </a>
                        <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Сервісні центри']) }}">
                            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                            <span>Сервісні центри</span>
                        </a>
                        <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Салони краси']) }}">
                            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                            <span>Салони краси</span>
                        </a>
                    </div>
                </form>

                <section class="home-review-cta home-review-cta--standalone home-review-cta--desktop-only" aria-label="Залишити відгук">
                    <div class="container">
                        <div class="home-review-cta__line">
                            <a href="{{ route('catalog') }}" class="home-review-cta__pill">
                                <span>Хочете поділитись досвідом? <strong>Залиште відгук</strong></span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <section class="home-intents home-intents--desktop-only" aria-labelledby="home-intents-title-desktop" data-intents-root>
                    <div class="container">
                        <div class="home-intents__head">
                            <h2 id="home-intents-title-desktop">Що ви шукаєте?</h2>
                            <div class="home-intents__actions">
                                <button type="button" class="home-intents__nav home-intents__nav--prev" aria-label="Попередні категорії" data-intents-prev>
                                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="home-intents__nav" aria-label="Наступні категорії" data-intents-next>
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </button>
                                <a href="{{ route('catalog') }}" class="home-intents__more">Всі категорії</a>
                            </div>
                        </div>

                        <div class="home-intents__track" data-intents-track>
                            <a href="{{ route('catalog', ['q' => 'Банки']) }}" class="home-intents__item">
                                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                                <span class="home-intents__name">Банки</span>
                                <span class="home-intents__count">1 284 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Туризм']) }}" class="home-intents__item">
                                <i class="fa-solid fa-plane" aria-hidden="true"></i>
                                <span class="home-intents__name">Туризм</span>
                                <span class="home-intents__count">968 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Автодилери']) }}" class="home-intents__item">
                                <i class="fa-solid fa-car-side" aria-hidden="true"></i>
                                <span class="home-intents__name">Автодилери</span>
                                <span class="home-intents__count">746 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Меблеві магазини']) }}" class="home-intents__item">
                                <i class="fa-solid fa-couch" aria-hidden="true"></i>
                                <span class="home-intents__name">Меблі</span>
                                <span class="home-intents__count">1 132 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Ювелірні магазини']) }}" class="home-intents__item">
                                <i class="fa-regular fa-gem" aria-hidden="true"></i>
                                <span class="home-intents__name">Ювелірні магазини</span>
                                <span class="home-intents__count">512 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Одяг']) }}" class="home-intents__item">
                                <i class="fa-solid fa-shirt" aria-hidden="true"></i>
                                <span class="home-intents__name">Одяг</span>
                                <span class="home-intents__count">2 046 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Техніка']) }}" class="home-intents__item">
                                <i class="fa-solid fa-laptop" aria-hidden="true"></i>
                                <span class="home-intents__name">Техніка</span>
                                <span class="home-intents__count">1 589 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Фітнес']) }}" class="home-intents__item">
                                <i class="fa-solid fa-dumbbell" aria-hidden="true"></i>
                                <span class="home-intents__name">Фітнес</span>
                                <span class="home-intents__count">674 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Ресторани']) }}" class="home-intents__item">
                                <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                                <span class="home-intents__name">Ресторани</span>
                                <span class="home-intents__count">1 823 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Аптеки']) }}" class="home-intents__item">
                                <i class="fa-solid fa-prescription-bottle-medical" aria-hidden="true"></i>
                                <span class="home-intents__name">Аптеки</span>
                                <span class="home-intents__count">903 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Доставка']) }}" class="home-intents__item">
                                <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                                <span class="home-intents__name">Доставка</span>
                                <span class="home-intents__count">1 117 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Освіта']) }}" class="home-intents__item">
                                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                <span class="home-intents__name">Освіта</span>
                                <span class="home-intents__count">1 256 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Краса']) }}" class="home-intents__item">
                                <i class="fa-solid fa-scissors" aria-hidden="true"></i>
                                <span class="home-intents__name">Краса</span>
                                <span class="home-intents__count">1 491 профілів</span>
                            </a>
                            <a href="{{ route('catalog', ['q' => 'Будівництво']) }}" class="home-intents__item">
                                <i class="fa-solid fa-helmet-safety" aria-hidden="true"></i>
                                <span class="home-intents__name">Будівництво</span>
                                <span class="home-intents__count">835 профілів</span>
                            </a>
                        </div>
                    </div>
                </section>

                <div class="hero__mobile-cta">
                    <form class="hero__mobile-search" action="{{ route('catalog') }}" method="get" aria-label="Мобільний пошук профілю" data-search-form>
                        <input type="text" name="q" placeholder="Пошук компанії або категорії">
                        <button type="submit" aria-label="Шукати">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        </button>
                        <div class="hero__search-suggest hero__search-suggest--mobile" data-search-suggest hidden>
                            <p class="hero__search-suggest-title">Популярні запити</p>
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Стоматології Києва']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Стоматології Києва</span>
                            </a>
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'СТО поруч']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>СТО поруч</span>
                            </a>
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Онлайн-магазини одягу']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Онлайн-магазини одягу</span>
                            </a>
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Сервісні центри']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Сервісні центри</span>
                            </a>
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Салони краси']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Салони краси</span>
                            </a>
                        </div>
                    </form>
                    
                </div>

                <div class="hero__top__wrap">
                    <a href="{{ route('catalog') }}" class="btn dovira-btn">
                        <span>Написати відгук</span>
                        <i class="fa-solid fa-pen-to-square"></i>
                    </a>

                    <a href="{{ route('pro') }}" class="btn btn--outline">
                        <span>PRO акаунт</span>
                        <img src="../static/assets/shield-check-svgrepo-com.svg" alt="PRO акаунт">
                    </a>
                </div>
            </div>

            <div class="hero__bottom">
                <h2>Про DOVIRA</h2>
                <div class="section-divider"></div>
                <p class="hero__bottom_subtitle">
                    <span>Незалежна платформа відгуків</span>
                    про компанії та спеціалістів.
                </p>

                <div class="hero__wrap">
                    <div class="hero__item">
                        <i class="fa-solid fa-file-circle-check"></i>
                        <p class="hero__item__title">Публікація відгуків</p>
                    </div>
                    <div class="hero__item">
                        <i class="fa-regular fa-address-book"></i>
                        <p class="hero__item__title">Перевірка досвіду</p>
                    </div>
                    <div class="hero__item">
                        <i class="fa-solid fa-ticket"></i>
                        <p class="hero__item__title">Врегулювання кейсів</p>
                    </div>
                    <div class="hero__item">
                        <i class="fa-regular fa-square-check"></i>
                        <p class="hero__item__title">Формування репутації</p>
                    </div>
                </div>

                <section class="home-review-cta home-review-cta--in-hero" aria-label="Залишити відгук">
                    <div class="home-review-cta__line">
                        <a href="{{ route('catalog') }}" class="home-review-cta__pill">
                            <span>Хочете поділитись досвідом? <strong>Залиште відгук</strong></span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>

<section class="home-review-cta home-review-cta--standalone home-review-cta--mobile-only" aria-label="Залишити відгук">
    <div class="container">
        <div class="home-review-cta__line">
            <a href="{{ route('catalog') }}" class="home-review-cta__pill">
                <span>Хочете поділитись досвідом? <strong>Залиште відгук</strong></span>
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>

<section class="home-intents home-intents--mobile-only" aria-labelledby="home-intents-title" data-intents-root>
    <div class="container">
        <div class="home-intents__head">
            <h2 id="home-intents-title">Що ви шукаєте?</h2>
            <div class="home-intents__actions">
                <button type="button" class="home-intents__nav home-intents__nav--prev" aria-label="Попередні категорії" data-intents-prev>
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button type="button" class="home-intents__nav" aria-label="Наступні категорії" data-intents-next>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
                <a href="{{ route('catalog') }}" class="home-intents__more">Всі категорії</a>
            </div>
        </div>

        <div class="home-intents__track" data-intents-track>
            <a href="{{ route('catalog', ['q' => 'Банки']) }}" class="home-intents__item">
                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                <span class="home-intents__name">Банки</span>
                <span class="home-intents__count">1 284 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Туризм']) }}" class="home-intents__item">
                <i class="fa-solid fa-plane" aria-hidden="true"></i>
                <span class="home-intents__name">Туризм</span>
                <span class="home-intents__count">968 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Автодилери']) }}" class="home-intents__item">
                <i class="fa-solid fa-car-side" aria-hidden="true"></i>
                <span class="home-intents__name">Автодилери</span>
                <span class="home-intents__count">746 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Меблеві магазини']) }}" class="home-intents__item">
                <i class="fa-solid fa-couch" aria-hidden="true"></i>
                <span class="home-intents__name">Меблі</span>
                <span class="home-intents__count">1 132 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Ювелірні магазини']) }}" class="home-intents__item">
                <i class="fa-regular fa-gem" aria-hidden="true"></i>
                <span class="home-intents__name">Ювелірні магазини</span>
                <span class="home-intents__count">512 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Одяг']) }}" class="home-intents__item">
                <i class="fa-solid fa-shirt" aria-hidden="true"></i>
                <span class="home-intents__name">Одяг</span>
                <span class="home-intents__count">2 046 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Техніка']) }}" class="home-intents__item">
                <i class="fa-solid fa-laptop" aria-hidden="true"></i>
                <span class="home-intents__name">Техніка</span>
                <span class="home-intents__count">1 589 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Фітнес']) }}" class="home-intents__item">
                <i class="fa-solid fa-dumbbell" aria-hidden="true"></i>
                <span class="home-intents__name">Фітнес</span>
                <span class="home-intents__count">674 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Ресторани']) }}" class="home-intents__item">
                <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                <span class="home-intents__name">Ресторани</span>
                <span class="home-intents__count">1 823 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Аптеки']) }}" class="home-intents__item">
                <i class="fa-solid fa-prescription-bottle-medical" aria-hidden="true"></i>
                <span class="home-intents__name">Аптеки</span>
                <span class="home-intents__count">903 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Доставка']) }}" class="home-intents__item">
                <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                <span class="home-intents__name">Доставка</span>
                <span class="home-intents__count">1 117 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Освіта']) }}" class="home-intents__item">
                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                <span class="home-intents__name">Освіта</span>
                <span class="home-intents__count">1 256 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Краса']) }}" class="home-intents__item">
                <i class="fa-solid fa-scissors" aria-hidden="true"></i>
                <span class="home-intents__name">Краса</span>
                <span class="home-intents__count">1 491 профілів</span>
            </a>
            <a href="{{ route('catalog', ['q' => 'Будівництво']) }}" class="home-intents__item">
                <i class="fa-solid fa-helmet-safety" aria-hidden="true"></i>
                <span class="home-intents__name">Будівництво</span>
                <span class="home-intents__count">835 профілів</span>
            </a>
        </div>
    </div>
</section>



<section class="home-pro-cta" aria-label="CTA PRO акаунта">
    <div class="container">
        <div class="home-pro-cta__card">
            <div class="home-pro-cta__content">
                <h3>Хочете масштабувати репутацію?</h3>
                <p>Підключіть PRO-акаунт та підвищуйте довіру до профілю.</p>
            </div>
            <a href="{{ route('pro') }}" class="btn dovira-btn home-pro-cta__btn">
                <span>Підключити PRO</span>
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            </a>
            <div class="home-pro-cta__bars" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </div>
</section>

<section class="section best-lawyers" aria-labelledby="best-lawyers-title">
    <div class="container">
        <div class="home-intents__head">
            <h2 id="best-lawyers-title" class="h2">Найкращі в категорії <span>Адвокати</span> </h2>
            <div class="home-intents__actions">
                <button type="button" class="home-intents__nav home-intents__nav--prev" aria-label="Попередні профілі" data-carousel-prev="best-lawyers">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button type="button" class="home-intents__nav" aria-label="Наступні профілі" data-carousel-next="best-lawyers">
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
                <a class="home-intents__more" href="{{ route('catalog') }}">Дивитись більше</a>
            </div>
        </div>

        <div class="best-lawyers__carousel reviews-carousel__track" data-carousel="best-lawyers">
            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/5668858/pexels-photo-5668858.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Лекс Прайм" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Адвокатське бюро "Лекс Прайм"</h3>
                <a class="best-lawyer-card__site" href="https://lex-prime.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>lex-prime.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.9 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                    </span>
                    <strong>4.9</strong>
                    <span>(1287)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/7841436/pexels-photo-7841436.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Право Плюс" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Юридична компанія "Право Плюс"</h3>
                <a class="best-lawyer-card__site" href="https://pravo-plus.com.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>pravo-plus.com.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.8 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                    </span>
                    <strong>4.8</strong>
                    <span>(964)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/3760263/pexels-photo-3760263.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Олена Мельник" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Адвокат Олена Мельник</h3>
                <a class="best-lawyer-card__site" href="https://melnyk-lawyer.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>melnyk-lawyer.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.8 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                    </span>
                    <strong>4.8</strong>
                    <span>(742)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/8112198/pexels-photo-8112198.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Гарант Партнери" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">ЮФ "Гарант Партнери"</h3>
                <a class="best-lawyer-card__site" href="https://garant-partners.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>garant-partners.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.7 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                    </span>
                    <strong>4.7</strong>
                    <span>(521)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/5668774/pexels-photo-5668774.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Дмитро Коваль" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Адвокат Дмитро Коваль</h3>
                <a class="best-lawyer-card__site" href="https://dk-legal.com.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>dk-legal.com.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.7 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                    </span>
                    <strong>4.7</strong>
                    <span>(438)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card result-card--carousel">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/7551646/pexels-photo-7551646.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Профі Право" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">ЮК "Профі Право"</h3>
                <a class="best-lawyer-card__site" href="https://profi-pravo.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>profi-pravo.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.6 з 5">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                    </span>
                    <strong>4.6</strong>
                    <span>(389)</span>
                </div>
                <span class="best-lawyer-card__category" title="Адвокати" aria-label="Категорія: Адвокати">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                </span>
            </article>
        </div>
    </div>
</section>

<section class="home-trust-explainer" aria-labelledby="home-trust-explainer-title">
    <div class="container">
        <div class="home-trust-explainer__wrap">
            <div class="home-trust-explainer__copy">
                
                <h2 id="home-trust-explainer-title">DOVIRA — платформа відгуків і публічної репутації</h2>
                <p class="home-trust-explainer__lead">
                    DOVIRA об’єднує профілі, відгуки, рейтинги та публічну комунікацію, щоб користувачі могли швидше зрозуміти, кому можна довіряти.
                </p>
                <div class="home-trust-explainer__actions">
                    <a href="{{ route('platform') }}" class="btn dovira-btn home-trust-explainer__btn">
                        <span>Про платформу</span>
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('register') }}" class="home-trust-explainer__btn home-trust-explainer__btn--register">
                        <span>Реєстрація</span>
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    </a>
                </div>
               
            </div>

            <div class="home-trust-explainer__cards" aria-label="Чому DOVIRA можна довіряти">
                <article class="home-trust-explainer__card">
                    <div class="home-trust-explainer__icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
                    <h3>Перевірені профілі</h3>
                    <p>Профілі можуть пройти перевірку та отримати публічний статус довіри.</p>
                </article>
                <article class="home-trust-explainer__card">
                    <div class="home-trust-explainer__icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></div>
                    <h3>Публічні відповіді</h3>
                    <p>Компанії та спеціалісти можуть офіційно відповідати на відгуки публічно.</p>
                </article>
                <article class="home-trust-explainer__card">
                    <div class="home-trust-explainer__icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></div>
                    <h3>Правила модерації</h3>
                    <p>Спірні випадки розглядаються за прозорими правилами платформи.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="section popular-companies" aria-labelledby="popular-companies-title">
    <div class="container">
        <div class="home-intents__head">
            <h2 id="popular-companies-title" class="h2">Найпопулярніші компанії</h2>
            <div class="home-intents__actions popular-companies__actions">
                <p class="popular-companies__views">
                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                    3 248 921 переглядів профілів
                </p>
                <a class="home-intents__more" href="{{ route('catalog') }}">Дивитись більше</a>
            </div>
        </div>

        <div class="popular-companies__grid">
            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/264636/pexels-photo-264636.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Nova Market" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Nova Market</h3>
                <a class="best-lawyer-card__site" href="https://novamarket.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>novamarket.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i></span>
                    <strong>4.9</strong>
                    <span>(8213)</span>
                </div>
                <span class="best-lawyer-card__category" title="Супермаркети" aria-label="Категорія: Супермаркети">
                    <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/373543/pexels-photo-373543.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль TECH HUB Store" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">TECH HUB Store</h3>
                <a class="best-lawyer-card__site" href="https://techhub.com.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>techhub.com.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i></span>
                    <strong>4.8</strong>
                    <span>(6137)</span>
                </div>
                <span class="best-lawyer-card__category" title="Техніка" aria-label="Категорія: Техніка">
                    <i class="fa-solid fa-laptop" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/3845810/pexels-photo-3845810.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль CityDent Clinic" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">CityDent Clinic</h3>
                <a class="best-lawyer-card__site" href="https://citydent.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>citydent.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i></span>
                    <strong>4.8</strong>
                    <span>(5024)</span>
                </div>
                <span class="best-lawyer-card__category" title="Клініки" aria-label="Категорія: Клініки">
                    <i class="fa-solid fa-tooth" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/3807329/pexels-photo-3807329.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль AutoCare Service" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">AutoCare Service</h3>
                <a class="best-lawyer-card__site" href="https://autocare-service.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>autocare-service.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i></span>
                    <strong>4.7</strong>
                    <span>(4390)</span>
                </div>
                <span class="best-lawyer-card__category" title="Автосервіси" aria-label="Категорія: Автосервіси">
                    <i class="fa-solid fa-car-side" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/7706457/pexels-photo-7706457.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Green Delivery" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Green Delivery</h3>
                <a class="best-lawyer-card__site" href="https://greendelivery.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>greendelivery.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i></span>
                    <strong>4.7</strong>
                    <span>(3986)</span>
                </div>
                <span class="best-lawyer-card__category" title="Доставка" aria-label="Категорія: Доставка">
                    <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/1552249/pexels-photo-1552249.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль FitPoint Club" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                        <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Перевірений</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">FitPoint Club</h3>
                <a class="best-lawyer-card__site" href="https://fitpoint.club" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>fitpoint.club</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i></span>
                    <strong>4.8</strong>
                    <span>(3579)</span>
                </div>
                <span class="best-lawyer-card__category" title="Фітнес" aria-label="Категорія: Фітнес">
                    <i class="fa-solid fa-dumbbell" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/356056/pexels-photo-356056.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль SmartHome Store" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">SmartHome Store</h3>
                <a class="best-lawyer-card__site" href="https://smarthome.store" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>smarthome.store</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i></span>
                    <strong>4.7</strong>
                    <span>(3348)</span>
                </div>
                <span class="best-lawyer-card__category" title="Електроніка" aria-label="Категорія: Електроніка">
                    <i class="fa-solid fa-microchip" aria-hidden="true"></i>
                </span>
            </article>

            <article class="best-lawyer-card popular-company-card">
                <div class="best-lawyer-card__top">
                    <div class="best-lawyer-card__logo review-list-card__logo">
                        <img src="https://images.pexels.com/photos/262978/pexels-photo-262978.jpeg?auto=compress&cs=tinysrgb&w=300&h=300&fit=crop" alt="Профіль Resto Family" loading="lazy">
                    </div>
                    <div class="best-lawyer-card__badges">
                        <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                    </div>
                </div>
                <h3 class="best-lawyer-card__name">Resto Family</h3>
                <a class="best-lawyer-card__site" href="https://restofamily.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>restofamily.ua</span></a>
                <div class="best-lawyer-card__rating">
                    <span class="best-lawyer-card__stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i></span>
                    <strong>4.6</strong>
                    <span>(3012)</span>
                </div>
                <span class="best-lawyer-card__category" title="Ресторани" aria-label="Категорія: Ресторани">
                    <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                </span>
            </article>
        </div>
    </div>
</section>
 <section class="section cta-hero-callout" aria-label="Заклик до дії">
        <div class="container">
            <div class="cta-hero-callout__card">
                <div class="cta-hero-callout__orb" aria-hidden="true"></div>

                <div class="cta-hero-callout__mockup" aria-hidden="true">
                    <div class="cta-hero-callout__phone-shadow"></div>
                    <img class="cta-hero-callout__phone-img" src="{{ asset('static/assets/phone-mc.png') }}" alt="">
                </div>

                <div class="cta-hero-callout__content">
                    <p class="cta-hero-callout__eyebrow">Поділіться досвідом</p>
                    <h2 class="cta-hero-callout__title">
                       Ваш відгук допомагає іншим бачити реальну репутацію
                    </h2>
                    <p class="cta-hero-callout__text">
                        Опишіть свій досвід. Ваш відгук допоможе іншим швидше зробити вибір.
                    </p>
                    <div class="cta-hero-callout__actions">
                        <a class="btn dovira-btn home-trust-explainer__btn" href="{{ route('register') }}">
                           <span>Написати відгук</span>
                           <i class="fa-solid fa-pen-to-square"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>


<section class="section tp-reviews" aria-labelledby="tp-reviews-title">
    <div class="container">
        <div class="home-intents__head">
            <h2 id="tp-reviews-title" class="h2">Останні відгуки</h2>
            <div class="home-intents__actions">
                <button type="button" class="home-intents__nav home-intents__nav--prev" aria-label="Попередні відгуки" data-carousel-prev="latest-reviews">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button type="button" class="home-intents__nav" aria-label="Наступні відгуки" data-carousel-next="latest-reviews">
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
                <a class="home-intents__more" href="{{ route('catalog') }}">Дивитись усі</a>
            </div>
        </div>

        <div class="latest-reviews__carousel reviews-carousel__track" data-carousel="latest-reviews">
            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=47" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Ірина Коваль</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Замовлення виконали швидко, менеджер дав чітку консультацію, а сервіс після покупки спрацював без затримок. Дуже позитивний досвід.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/264636/pexels-photo-264636.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>Nova Market</h4>
                        <p>novamarket.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=12" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Марко Дяченко</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Профіль компанії виглядає прозоро: є відповіді на відгуки, видно активність та історію. Вибрав їх саме завдяки цьому.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/3807329/pexels-photo-3807329.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>AutoCare Service</h4>
                        <p>autocare-service.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=32" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Олена Тихонюк</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 4 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-regular fa-star"></i></span><strong>4.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Чітко дотрималися домовленостей і термінів, комунікація була зрозуміла. Є куди рости у швидкості відповіді в пікові години.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/3845810/pexels-photo-3845810.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>CityDent Clinic</h4>
                        <p>citydent.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=15" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Владислав Мироненко</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Дуже сильний сервіс: швидко підтвердили замовлення, все було в наявності, а підтримка ввічлива і компетентна.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/373543/pexels-photo-373543.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>TECH HUB Store</h4>
                        <p>techhub.com.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=5" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Тетяна Сидорук</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 4 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-regular fa-star"></i></span><strong>4.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Профіль інформативний, багато актуальних відгуків, тому легко оцінити репутацію. Зручно порівнювати з іншими.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/7706457/pexels-photo-7706457.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>Green Delivery</h4>
                        <p>greendelivery.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=19" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Андрій Новак</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Сподобалось, що компанія офіційно відповідає на звернення у профілі. Це одразу додає довіри до сервісу.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/1552249/pexels-photo-1552249.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>FitPoint Club</h4>
                        <p>fitpoint.club</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=44" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Наталія Романчук</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 4 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-regular fa-star"></i></span><strong>4.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Зручний пошук і зрозумілий профіль компанії. Було б добре бачити ще більше деталей по доставці, але загалом супер.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/356056/pexels-photo-356056.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>SmartHome Store</h4>
                        <p>smarthome.store</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></div>
                    <div class="latest-review-card__meta">
                        <h3>Катерина Шевченко</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Швидко знайшла потрібну компанію через фільтри, відгуки допомогли прийняти рішення. Реальний корисний інструмент.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/262978/pexels-photo-262978.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>Resto Family</h4>
                        <p>restofamily.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=25" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Юлія Павленко</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Круто, що видно живі відгуки та офіційні відповіді компанії. Це допомогло швидко прийняти рішення перед покупкою.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/159711/books-bookstore-book-reading-159711.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="">
                    </div>
                    <div>
                        <h4>BookFlow</h4>
                        <p>bookflow.ua</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></div>
                    <div class="latest-review-card__meta">
                        <h3>Олег Рибак</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 4 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-regular fa-star"></i></span><strong>4.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Сервіс хороший, замовлення обробили вчасно. Хотілося б трохи детальніші статуси доставки в особистому кабінеті.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo has-random-gradient" data-seed="AutoLine Market">AL</div>
                    <div>
                        <h4>AutoLine Market</h4>
                        <p>autoline.market</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true">
                        <img src="https://i.pravatar.cc/128?img=20" alt="">
                    </div>
                    <div class="latest-review-card__meta">
                        <h3>Світлана Гордієнко</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 5 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><strong>5.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Дуже сподобалась прозорість профілю. Вся ключова інформація, рейтинг і коментарі зібрані в одному місці.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo has-random-gradient" data-seed="HomeTech">HT</div>
                    <div>
                        <h4>HomeTech</h4>
                        <p>hometech.store</p>
                    </div>
                </div>
            </article>

            <article class="latest-review-card result-card--carousel">
                <div class="latest-review-card__author">
                    <div class="latest-review-card__avatar" aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></div>
                    <div class="latest-review-card__meta">
                        <h3>Максим Бондар</h3>
                        <div class="latest-review-card__stars" aria-label="Рейтинг 4 з 5">
                            <span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-solid fa-star"></i></span><span><i class="fa-regular fa-star"></i></span><strong>4.0</strong>
                        </div>
                    </div>
                </div>
                <p class="latest-review-card__text">Платформа справді зручна: швидко знайшов компанію по категорії та рейтингу. Інтерфейс зрозумілий і без зайвого шуму.</p>
                <div class="latest-review-card__company">
                    <div class="latest-review-card__company-logo best-lawyer-card__logo review-list-card__logo has-random-gradient" data-seed="Delivery Plus">DP</div>
                    <div>
                        <h4>Delivery Plus</h4>
                        <p>deliveryplus.ua</p>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="section faq" id="faq" aria-label="Питання та відповіді">
  <div class="container">
    <div class="faq__head">
      <div class="badge faq__badge">FAQ</div>
      <h2 class="h2 faq__title">Питання та відповіді</h2>
      <p class="muted faq__lead">Коротко про те, як працює DOVIRA і як ми формуємо прозору репутацію.</p>
    </div>

    <div class="faq__list">
      <details class="faq__item is-open" open="" data-animating="0">
        <summary class="faq__question" aria-expanded="true">Що таке DOVIRA?</summary>
        <div class="faq__panel" style="height: auto;">
          <div class="faq__answer">
            DOVIRA — це цифрова платформа відгуків про компанії, магазини, сервіси та спеціалістів. Тут користувачі можуть переглядати профілі, читати відгуки, оцінювати досвід та формувати репутацію на основі публічної інформації.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Чи можна довіряти відгукам на DOVIRA?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Платформа поєднує публічність, модерацію, статуси профілів і право на відповідь. Це допомагає робити репутацію більш прозорою та зменшує вплив випадкових або маніпулятивних оцінок.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Хто може залишити відгук?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Відгук може залишити користувач, який хоче поділитися власним досвідом взаємодії з компанією, магазином, сервісом або спеціалістом.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Чи можуть профілі видаляти відгуки самостійно?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Ні, профілі не повинні самостійно керувати публічними відгуками без правил платформи. У спірних випадках діють модерація, перевірка та процедура звернення.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Чи можуть компанії або спеціалісти відповідати на відгуки?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Так. Профілі можуть публічно відповідати на відгуки, уточнювати обставини та пояснювати свою позицію. Це допомагає показати повнішу картину ситуації.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Що дає PRO-акаунт?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            PRO-акаунт дає розширені інструменти для роботи з профілем: оформлення сторінки, публічні відповіді, додаткове керування присутністю на платформі та доступ до репутаційних інструментів.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Чи можна користуватися DOVIRA без реєстрації?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Так, основна інформація про профілі та відгуки може бути доступна для перегляду. Для окремих дій, зокрема публікації відгуку або керування профілем, може знадобитися авторизація.
          </div>
        </div>
      </details>

      <details class="faq__item" data-animating="0">
        <summary class="faq__question" aria-expanded="false">Як знайти потрібний профіль або компанію?</summary>
        <div class="faq__panel" style="height: 0px;">
          <div class="faq__answer">
            Через каталог, пошук і фільтри за ім’ям, категорією або регіоном. Це допомагає швидко знайти профіль і переглянути репутацію в одному місці.
          </div>
        </div>
      </details>
    </div>
  </div>
</section>
@endsection
