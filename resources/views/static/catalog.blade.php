@extends('static.layout')

@section('title', 'Каталог — DOVIRA')
@section('body_class', 'page-catalog')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/catalog.css') }}">
@endpush

@section('content')
    @php
        $catalogLawyerUrl = fn (string $slug) => route('lawyer', [
            'slug' => $slug,
            'from' => 'catalog',
            'back' => request()->fullUrl(),
        ]);
    @endphp

    <section class="section reviews-page">
        <div class="container reviews-page__layout">
            <aside class="reviews-sidebar" aria-label="Фільтри">
                <div class="reviews-sidebar__search-wrap">
                    <form class="hero__desktop-search reviews-sidebar__search" action="{{ route('catalog') }}" method="get" aria-label="Пошук у каталозі" data-search-form>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="Пошук компанії або категорії">
                        <button type="submit" aria-label="Шукати">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        </button>
                        <div class="hero__search-suggest reviews-search__suggest" data-search-suggest hidden>
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
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Ресторани']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Ресторани</span>
                            </a>
                        </div>
                    </form>
                </div>

                @include('static.partials.catalog-filters')
            </aside>

            <div class="reviews-content">
                <section class="home-pro-cta reviews-pro-cta" aria-label="PRO акаунт">
                    <article class="home-pro-cta__card">
                        <div class="home-pro-cta__content">
                            <h3>Підсиліть профіль з PRO-акаунтом</h3>
                            <p>Офіційні відповіді, пріоритет у каталозі та інструменти керування репутацією.</p>
                        </div>
                        <a href="{{ route('pro') }}" class="btn dovira-btn home-pro-cta__btn">
                            <span>Дізнатись більше</span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <div class="home-pro-cta__bars" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                    </article>
                </section>

                <div class="reviews-mobile-search">
                    <form class="hero__desktop-search hero__mobile-search reviews-sidebar__search" action="{{ route('catalog') }}" method="get" aria-label="Пошук у каталозі" data-search-form>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="Пошук компанії або категорії">
                        <button type="submit" aria-label="Шукати">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        </button>
                        <div class="hero__search-suggest reviews-search__suggest" data-search-suggest hidden>
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
                            <a class="hero__search-suggest-item" href="{{ route('catalog', ['q' => 'Ресторани']) }}">
                                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
                                <span>Ресторани</span>
                            </a>
                        </div>
                    </form>
                </div>

                <div class="reviews-content__head">
                    <div class="reviews-tabs">
                        <button class="reviews-tabs__item is-active" type="button">Перевірені</button>
                        <button class="reviews-tabs__item" type="button">Усі</button>
                        <button class="reviews-tabs__item" type="button">Найбільше відгуків</button>
                    </div>
                    <div class="reviews-content__count">1–12 з 42 результатів</div>
                </div>

                <div class="reviews-mobile-filters">
                    <details class="reviews-mobile-filters__details">
                        <summary class="reviews-mobile-filters__toggle">
                            <span>Фільтрувати за</span>
                            <i class="fa-solid fa-arrow-up-short-wide"></i>
                        </summary>
                        <div class="reviews-mobile-filters__panel">
                            <div class="reviews-mobile-filters__panel-inner">
                                <div class="reviews-filter">
                                    @include('static.partials.catalog-filters')
                                </div>
                            </div>
                        </div>
                    </details>
                    <div class="reviews-mobile-filters__tabs">
                        <div class="reviews-tabs">
                            <button class="reviews-tabs__item is-active" type="button">Перевірені</button>
                            <button class="reviews-tabs__item" type="button">Усі</button>
                            <button class="reviews-tabs__item" type="button">Найбільше відгуків</button>
                        </div>
                    </div>
                </div>

                <div class="reviews-list">
                    <article class="review-list-card review-list-card--priority">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('nova-market') }}">
                                <div class="review-list-card__logo">
                                    <img src="https://images.pexels.com/photos/264636/pexels-photo-264636.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="Логотип Nova Market" loading="lazy">
                                </div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('nova-market') }}">Nova Market</a></h3>
                                <div class="review-list-card__badges">
                                    <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check"></i>Перевірений акаунт</span>
                                    <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                                </div>
                                <p class="review-list-card__region">Київ</p>
                                <a class="best-lawyer-card__site" href="https://novamarket.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>novamarket.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.9 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                        </span>
                                        <strong>4.9</strong>
                                        <span>(182)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('nova-market') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card review-list-card--priority">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('tech-hub-store') }}">
                                <div class="review-list-card__logo">
                                    <img src="https://images.pexels.com/photos/373543/pexels-photo-373543.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="Логотип TECH HUB Store" loading="lazy">
                                </div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('tech-hub-store') }}">TECH HUB Store</a></h3>
                                <div class="review-list-card__badges">
                                    <span class="best-lawyer-card__verified-badge"><i class="fa-solid fa-circle-check"></i>Перевірений акаунт</span>
                                    <span class="best-lawyer-card__pro-badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> PRO</span>
                                </div>
                                <p class="review-list-card__region">Львів</p>
                                <a class="best-lawyer-card__site" href="https://techhub.com.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>techhub.com.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.8 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.8</strong>
                                        <span>(136)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('tech-hub-store') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('citydent-clinic') }}">
                                <div class="review-list-card__logo" data-seed="CityDent Clinic">CD</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('citydent-clinic') }}">CityDent Clinic</a></h3>
                                <p class="review-list-card__region">Одеса</p>
                                <a class="best-lawyer-card__site" href="https://citydent.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>citydent.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.9 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                        </span>
                                        <strong>4.9</strong>
                                        <span>(94)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('citydent-clinic') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('autocare-service') }}">
                                <div class="review-list-card__logo">
                                    <img src="https://images.pexels.com/photos/3807329/pexels-photo-3807329.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="Логотип AutoCare Service" loading="lazy">
                                </div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('autocare-service') }}">AutoCare Service</a></h3>
                                <p class="review-list-card__region">Дніпро</p>
                                <a class="best-lawyer-card__site" href="https://autocare-service.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>autocare-service.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.7 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.7</strong>
                                        <span>(78)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('autocare-service') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('green-delivery') }}">
                                <div class="review-list-card__logo" data-seed="Green Delivery">GD</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('green-delivery') }}">Green Delivery</a></h3>
                                <p class="review-list-card__region">Київ</p>
                                <a class="best-lawyer-card__site" href="https://greendelivery.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>greendelivery.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.6 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.6</strong>
                                        <span>(59)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('green-delivery') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('smarthome-store') }}">
                                <div class="review-list-card__logo" data-seed="SmartHome Store">SS</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('smarthome-store') }}">SmartHome Store</a></h3>
                                <p class="review-list-card__region">Харків</p>
                                <a class="best-lawyer-card__site" href="https://smarthome.store" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>smarthome.store</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.8 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                        </span>
                                        <strong>4.8</strong>
                                        <span>(101)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('smarthome-store') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('resto-family') }}">
                                <div class="review-list-card__logo">
                                    <img src="https://images.pexels.com/photos/262978/pexels-photo-262978.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="Логотип Resto Family" loading="lazy">
                                </div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('resto-family') }}">Resto Family</a></h3>
                                <p class="review-list-card__region">Вінниця</p>
                                <a class="best-lawyer-card__site" href="https://restofamily.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>restofamily.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.7 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.7</strong>
                                        <span>(67)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('resto-family') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('bookflow') }}">
                                <div class="review-list-card__logo has-random-gradient" data-seed="BookFlow">BF</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('bookflow') }}">BookFlow</a></h3>
                                <p class="review-list-card__region">Черкаси</p>
                                <a class="best-lawyer-card__site" href="https://bookflow.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>bookflow.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.5 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.5</strong>
                                        <span>(43)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('bookflow') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('freshcare-pharmacy') }}">
                                <div class="review-list-card__logo has-random-gradient" data-seed="FreshCare Pharmacy">FP</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('freshcare-pharmacy') }}">FreshCare Pharmacy</a></h3>
                                <p class="review-list-card__region">Полтава</p>
                                <a class="best-lawyer-card__site" href="https://freshcare.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>freshcare.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.8 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.8</strong>
                                        <span>(88)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('freshcare-pharmacy') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('quickbox-delivery') }}">
                                <div class="review-list-card__logo">
                                    <img src="https://images.pexels.com/photos/7706457/pexels-photo-7706457.jpeg?auto=compress&cs=tinysrgb&w=200&h=200&fit=crop" alt="Логотип QuickBox Delivery" loading="lazy">
                                </div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('quickbox-delivery') }}">QuickBox Delivery</a></h3>
                                <p class="review-list-card__region">Івано-Франківськ</p>
                                <a class="best-lawyer-card__site" href="https://quickbox.delivery" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>quickbox.delivery</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.6 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.6</strong>
                                        <span>(52)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('quickbox-delivery') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('tutorspace-academy') }}">
                                <div class="review-list-card__logo has-random-gradient" data-seed="TutorSpace">TS</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('tutorspace-academy') }}">TutorSpace Academy</a></h3>
                                <p class="review-list-card__region">Тернопіль</p>
                                <a class="best-lawyer-card__site" href="https://tutorspace.ua" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>tutorspace.ua</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.9 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                        </span>
                                        <strong>4.9</strong>
                                        <span>(71)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('tutorspace-academy') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>

                    <article class="review-list-card">
                        <div class="review-list-card__main">
                            <a class="review-list-card__logo-link" href="{{ $catalogLawyerUrl('buildcraft-studio') }}">
                                <div class="review-list-card__logo has-random-gradient" data-seed="BuildCraft">BC</div>
                            </a>
                            <div>
                                <h3 class="review-list-card__name"><a class="review-list-card__name-link" href="{{ $catalogLawyerUrl('buildcraft-studio') }}">BuildCraft Studio</a></h3>
                                <p class="review-list-card__region">Чернігів</p>
                                <a class="best-lawyer-card__site" href="https://buildcraft.pro" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-globe" aria-hidden="true"></i><span>buildcraft.pro</span></a>
                                <div class="review-list-card__meta">
                                    <div class="best-lawyer-card__rating">
                                        <span class="best-lawyer-card__stars" aria-label="Рейтинг 4.4 з 5">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i><i class="fa-regular fa-star"></i>
                                        </span>
                                        <strong>4.4</strong>
                                        <span>(39)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="review-list-card__actions">
                            <a class="review-list-card__action-link" href="{{ $catalogLawyerUrl('buildcraft-studio') }}">
                                <span>Переглянути профіль</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </section>
@endsection
