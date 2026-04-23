@extends('static.layout')

@section('title', 'DOVIRA — платформа чесних відгуків')

@section('content')
    <section class="hero" id="top">
       
        <div class="container hero__inner">
            <div class="hero__content">
               <div class="hero__top">
                 <div class="badge badge--soft">Репутація — це актив</div>
                 <h1 class="hero__title">Цифрова платформа чесних відгуків</h1>
                 <div class="hero__top__wrap">
                 <a  href="#section__search" class="btn dovira-btn"><span>Пошук відгуків</span><i class="fa-solid fa-magnifying-glass"></i> </a>
                    <a href="#pro" class="btn btn--outline"><span>PRO акаунт</span> <img src="../static/assets/shield-check-svgrepo-com.svg" alt=""></a>
                 </div>
               </div>
               <div class="hero__bottom">
                <h2>Про DOVIRA</h2>
                <div class="section-divider"></div>
                <p><span>Незалежна платформа чесних відгуків</span> про компанії та спеціалістів.</p>
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
            </div>
               </div>
        </div>
    </section>

    <section class="section search-section" id="section__search">
        <div class="container">
            <div class="search-card">
                <div class="search-head">
                    <div class="search-head__icon">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h2 class="search-title">Знайдіть перевіреного спеціаліста</h2>
                        <p class="search-subtitle">Пошук адвокатів та компаній з підтвердженою репутацією</p>
                    </div>
                </div>

                <div class="search-bar">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input class="search-input" type="text" placeholder="ПІБ адвоката або назва компанії" aria-label="Пошук">
                    <button class="btn btn--primary search-btn" type="button">Знайти</button>
                </div>

                <div class="filters">
                    <div class="filters__row">
                        <label class="filters__label">Регіон:</label>
                        <div class="select-wrap">
                            <select class="select filters__select">
                                <option>Вся Україна</option>
                                <option>Київська область</option>
                                <option>Львівська область</option>
                                <option>Одеська область</option>
                                <option>Дніпропетровська область</option>
                            </select>
                        </div>
                        <div class="filters__pills">
                            <button class="pill-btn is-active" type="button"><i class="fa-solid fa-check"></i>Адвокат</button>
                            <button class="pill-btn" type="button"><i class="fa-regular fa-building"></i>Компанія</button>
                            <label class="toggle pill-btn pill-btn--toggle">
                                <input type="checkbox" checked>
                                <span class="toggle__ui"></span>
                                <span class="toggle__text">Тільки PRO</span>
                            </label>
                        </div>
                    </div>
                    <div class="filters__hint-row">
                        <i class="fa-regular fa-circle-check"></i>
                        <span>Профілі з підтвердженням та аналітикою</span>
                    </div>
                </div>
            </div>

            <div class="search-catalog">
                <h3 class="search-catalog__title">Популярні категорії</h3>
                <div class="category-chips">
                    <button class="category-chip" type="button"><i class="fa-solid fa-people-roof"></i>Сімейне право</button>
                    <button class="category-chip" type="button"><i class="fa-solid fa-gavel"></i>Кримінальне право</button>
                    <button class="category-chip" type="button"><i class="fa-solid fa-briefcase"></i>Корпоративні спори</button>
                    <button class="category-chip" type="button"><i class="fa-solid fa-house"></i>Нерухомість</button>
                    <button class="category-chip" type="button"><i class="fa-solid fa-file-invoice"></i>Податки</button>
                    <button class="category-chip" type="button"><i class="fa-solid fa-laptop-code"></i>IT / бізнес</button>
                </div>
            </div>

           
            <div class="results-grid">
                <div class="results-col">
                    <div class="results-col__title">Популярні адвокати</div>
                    <div class="search-results">
                        <div class="result-card result-card--pro">
                            <div class="result-card__info">
                                <div class="avatar avatar--lg avatar--placeholder avatar--verified" data-seed="Олександр Мельник">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="result-card__name">Олександр Мельник</div>
                                    <div class="result-card__meta">Київ · Львів</div>
                                    <div class="rating rating--sm">
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star-half-stroke"></i>
                                        <span class="rating__num">4,8</span>
                                    </div>
                                </div>
                            </div>
                            <div class="result-card__actions">
                                <span class="pro-badge pro-badge--shield"><span class="pro-badge__icon" aria-hidden="true"></span><span class="pro-badge__text">PRO</span></span>
                                <a class="btn btn--primary btn--sm" href="{{ route('lawyer') }}">Переглянути відгуки</a>
                            </div>
                            </div>
                            <div class="result-card result-card--pro">
                            <div class="result-card__info">
                                <div class="avatar avatar--lg avatar--placeholder avatar--verified" data-seed="Юридична компанія Захист">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="result-card__name">Юридична компанія “Захист”</div>
                                    <div class="result-card__meta">Львів · Львів</div>
                                    <div class="rating rating--sm">
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star-half-stroke"></i>
                                        <span class="rating__num">4,9</span>
                                    </div>
                                </div>
                            </div>
                            <div class="result-card__actions">
                                <span class="pro-badge pro-badge--shield"><span class="pro-badge__icon" aria-hidden="true"></span><span class="pro-badge__text">PRO</span></span>
                                <a class="btn btn--primary btn--sm" href="{{ route('lawyer') }}">Переглянути відгуки</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="results-col">
                    <div class="results-col__title">Найкращий рейтинг</div>
                    <div class="search-results">
                        <div class="result-card result-card--pro">
                            <div class="result-card__info">
                                <div class="avatar avatar--lg avatar--placeholder avatar--verified" data-seed="Анна Ковальчук">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="result-card__name">Анна Ковальчук</div>
                                    <div class="result-card__meta">Дніпро · Дрогобич</div>
                                    <div class="rating rating--sm">
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star-half-stroke"></i>
                                        <i class="fa-regular fa-star"></i>
                                        <span class="rating__num">4,6</span>
                                    </div>
                                </div>
                            </div>
                            <div class="result-card__actions">
                                <span class="pro-badge pro-badge--shield"><span class="pro-badge__icon" aria-hidden="true"></span><span class="pro-badge__text">PRO</span></span>
                                <a class="btn btn--primary btn--sm" href="{{ route('lawyer') }}">Переглянути відгуки</a>
                            </div>
                        </div>
                        <div class="result-card">
                            <div class="result-card__info">
                                <div class="avatar avatar--lg avatar--placeholder" data-seed="Дмитро Олійник">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="result-card__name">Дмитро Олійник</div>
                                    <div class="result-card__meta">Харків · Полтава</div>
                                    <div class="rating rating--sm">
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <span class="rating__num">5,0</span>
                                    </div>
                                </div>
                            </div>
                            <div class="result-card__actions">
                                <span class="pro-badge pro-badge--shield"><span class="pro-badge__icon" aria-hidden="true"></span><span class="pro-badge__text">PRO</span></span>
                                <a class="btn btn--primary btn--sm" href="{{ route('lawyer') }}">Переглянути відгуки</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <a class="search-catalog__link" href="{{ route('catalog') }}">Перейти до повного каталогу →</a>
        </div>
    </section>

    <section class="section how" id="how">
        <div class="container">
            <div class="how__head how__head--center">
                <div class="badge how__badge">Інфраструктура довіри</div>
                <h2 class="h2 how__title">Як працює DOVIRA</h2>
                <div style="width: 60%;" class="section-divider"></div>
                <p class="muted how__lead">4 кроки, щоб репутація була прозорою та вимірюваною.</p>
            </div>
        </div>

        <div class="how__bleed">
            <div class="how__timeline" aria-label="Як працює DOVIRA: кроки">
                <svg class="how__path" viewBox="0 0 1000 260" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="howLine" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="rgba(31,104,255,0.20)"/>
                            <stop offset="50%" stop-color="rgba(31,104,255,0.85)"/>
                            <stop offset="100%" stop-color="rgba(76,179,255,0.45)"/>
                        </linearGradient>
                    </defs>
                    <path class="how__path-shadow" d="M-60,210 C120,250 240,10 370,70 C470,130 520,250 620,160 C730,70 840,10 1060,50" stroke="rgba(31,104,255,0.14)" />
                    <path class="how__path-line" d="M-60,210 C120,250 240,10 370,70 C470,130 520,250 620,160 C730,70 840,10 1060,50" stroke="url(#howLine)" />
                </svg>

              

                <div class="how-step how-step--1 is-up" data-step="1">
                    <div class="how-step__marker" aria-hidden="true">
                        <i class="fa-regular fa-message"></i>
                    </div>
                    <div class="how-step__card">
                        <div class="how-step__title">Реальні відгуки користувачів</div>
                        <div class="how-step__text">Користувачі діляться досвідом, а профілі накопичують історію довіри.</div>
                    </div>
                </div>

                <div class="how-step how-step--2 is-down" data-step="2">
                    <div class="how-step__marker" aria-hidden="true">
                        <i class="fa-solid fa-shield-check"></i>
                    </div>
                    <div class="how-step__card">
                        <div class="how-step__title">Перевірка і статуси</div>
                        <div class="how-step__text">Позначаємо верифіковані дані та показуємо статуси для прозорості.</div>
                    </div>
                </div>

                <div class="how-step how-step--3 is-up" data-step="3">
                    <div class="how-step__marker" aria-hidden="true">
                        <i class="fa-regular fa-handshake"></i>
                    </div>
                    <div class="how-step__card">
                        <div class="how-step__title">Публічне врегулювання</div>
                        <div class="how-step__text">Механіка відповідей, уточнень та вирішення спорів у відкритому форматі.</div>
                    </div>
                </div>

                <div class="how-step how-step--4 is-down" data-step="4">
                    <div class="how-step__marker" aria-hidden="true">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <div class="how-step__card">
                        <div class="how-step__title">Репутація як вимірюваний актив</div>
                        <div class="how-step__text">Рейтинг, динаміка та аналітика перетворюють репутацію на актив.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--alt" id="pro">
        <div class="container">
            <h2 class="h2">Переваги PRO-акаунту</h2>
            <p class="muted">Розширені можливості для підтверджених профілів.</p>

            <div class="grid grid--3">
                <div class="card">
                    <div class="card__title">Підтверджений профіль</div>
                    <div class="muted">Бейдж, довіра і вища конверсія.</div>
                </div>
                <div class="card">
                    <div class="card__title">Офіційні відповіді</div>
                    <div class="muted">Відповідайте на відгуки прозоро.</div>
                </div>
                <div class="card">
                    <div class="card__title">Аналітика</div>
                    <div class="muted">Перегляди, конверсії, динаміка рейтингу.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="process">
        <div class="container">
            <h2 class="h2">Як це працює</h2>
            <div class="steps">
                <div class="step"><span class="step__num">1</span> Реєстрація</div>
                <div class="step"><span class="step__num">2</span> Вибір профілю</div>
                <div class="step"><span class="step__num">3</span> Підтвердження даних</div>
                <div class="step"><span class="step__num">4</span> Верифікація</div>
            </div>
        </div>
    </section>
@endsection
