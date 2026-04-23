@extends('static.layout')

@section('title', 'Про платформу — DOVIRA')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/home.css') }}">
@endpush

@section('content')
   <section class="hero" id="top">
  <div class="container hero__inner">
    <div class="hero__content">
      <!-- Верхня частина: заголовок, сабтайтл, кнопки -->
      <div class="hero__top">
        <div class="badge badge--soft">Платформа довіри</div>

        <h1 class="hero__title">
          <span class="hero__title-accent">Відгуки та репутація</span>
            в одному місці
        </h1>

        <p class="hero__lead">
          Знаходьте профілі, читайте реальний досвід, порівнюйте рейтинги та приймайте рішення впевнено.
        </p>

        <div class="hero__mobile-cta">
          <a href="#section__search" class="hero__mobile-input">
            <span>Написати відгук</span>
             <i class="fa-solid fa-pen-to-square"></i>
          </a>
        
          <a href="#section__search" class="hero__mobile-btn">
            <span>PRO АКАУНТ</span>
            <img src="../static/assets/shield-check-svgrepo-com-blue.svg" alt="PRO акаунт">
          </a>
        </div>

        <div class="hero__top__wrap">
          <!-- вигляд «інпуту» -->
          <a href="#section__search" class="btn dovira-btn">
            <span>Написати відгук</span>
            <i class="fa-solid fa-pen-to-square"></i>
          </a>

          <!-- синя кнопка -->
          <a href="#pro" class="btn btn--outline">
            <span>PRO акаунт</span>
            <img src="../static/assets/shield-check-svgrepo-com.svg" alt="PRO акаунт">
          </a>
        </div>

      
      </div>

      <!-- НИЖНІЙ блок Про DOVIRA: буде видно тільки на десктопі -->
      <div class="hero__bottom">
        <h2>Як працює DOVIRA</h2>
        <div class="section-divider"></div>
        <p class="hero__bottom_subtitle">
          <span>Єдина система відгуків і репутації</span>
          для бізнесів, сервісів і фахівців.
        </p>

        <div class="hero__wrap">
          <div class="hero__item">
            <i class="fa-solid fa-file-circle-check"></i>
            <p class="hero__item__title">Відгуки та оцінки</p>
          </div>
          <div class="hero__item">
            <i class="fa-regular fa-address-book"></i>
            <p class="hero__item__title">Перевірені профілі</p>
          </div>
          <div class="hero__item">
            <i class="fa-solid fa-ticket"></i>
            <p class="hero__item__title">Кейси та звернення</p>
          </div>
          <div class="hero__item">
            <i class="fa-regular fa-square-check"></i>
            <p class="hero__item__title">Публічна репутація</p>
          </div>
        </div>

        <div class="hero__ticker" aria-label="Ключові можливості">
          <div class="hero__ticker-track">
            <div class="hero__ticker-item"><i class="fa-solid fa-file-circle-check"></i><span>Відгуки та оцінки</span></div>
            <div class="hero__ticker-item"><i class="fa-regular fa-address-book"></i><span>Перевірені профілі</span></div>
            <div class="hero__ticker-item"><i class="fa-solid fa-ticket"></i><span>Кейси та звернення</span></div>
            <div class="hero__ticker-item"><i class="fa-regular fa-square-check"></i><span>Публічна репутація</span></div>
            <div class="hero__ticker-item" aria-hidden="true"><i class="fa-solid fa-file-circle-check"></i><span>Відгуки та оцінки</span></div>
            <div class="hero__ticker-item" aria-hidden="true"><i class="fa-regular fa-address-book"></i><span>Перевірені профілі</span></div>
            <div class="hero__ticker-item" aria-hidden="true"><i class="fa-solid fa-ticket"></i><span>Кейси та звернення</span></div>
            <div class="hero__ticker-item" aria-hidden="true"><i class="fa-regular fa-square-check"></i><span>Публічна репутація</span></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

  

 <section class="section trust-proof" aria-label="Чому відгукам на DOVIRA можна довіряти">
    <div class="container">
        <div class="trust-proof__panel">
            <div class="how__head how__head--center">
                <p class="badge how__badge">Інфраструктура довіри</p>
                <h2 class="h2 how__title">Ключові принципи <span>DOVIRA</span></h2>
                <div style="width: 60%;" class="section-divider"></div>
                <p class="muted how__lead">
                    Профілі, відгуки та публічна комунікація. Усе, щоб швидко зрозуміти, кому довіряти.
                </p>
            </div>

            <div class="trust-proof__features">

                {{-- 1) Відгуки користувачів --}}
                <article class="trust-proof__feature">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>1</span> Відгуки користувачів</p>
                        <h3 class="trust-proof__feature-title">Публічні відгуки з конкретикою</h3>
                        <p class="trust-proof__feature-text">
                            DOVIRA збирає відгуки про компанії, магазини, сервіси та спеціалістів.
                            Кожен відгук містить оцінку і короткий опис досвіду, тож репутація будується на фактах.
                        </p>
                        <a class="trust-proof__btn" href="{{ route('register') }}">
                            <span>Залишити відгук</span>
                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--analytics" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <img class="trust-showcase__image" src="{{ asset('static/assets/how1.jpg') }}" alt="Відгук користувача">
                            </div>
                            <div class="trust-showcase trust-showcase--photo ">
                                 <strong>{{ number_format($stats['reviews'] ?? 0, 0, '.', ' ') }}+</strong>
                                <span>відгуків</span>
                            </div>
                            <div class="trust-showcase trust-showcase--feature">
                                <i class="fa-solid fa-star" aria-hidden="true"></i>
                                <span>Реальний досвід</span>
                            </div>
                          
                        </div>
                    </div>
                </article>

                {{-- 2) PRO для профілю --}}
                <article class="trust-proof__feature trust-proof__feature--reverse">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>2</span> PRO для профілю</p>
                        <h3 class="trust-proof__feature-title">PRO — інструменти репутації для профілю</h3>
                        <p class="trust-proof__feature-text">
                            PRO дає більше контролю над профілем: оформлення сторінки, відповіді на відгуки та керування репутацією.
                            Це простий інструмент для тих, хто хоче працювати зі своєю присутністю на платформі.
                        </p>
                        <a class="trust-proof__btn" href="#pro">
                            <span>Детальніше про PRO</span>
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--flow" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <img class="trust-showcase__image" src="{{ asset('static/assets/how2.png') }}" alt="PRO кабінет">
                            </div>
                            <div class="trust-showcase trust-showcase--photo">
                                <strong>{{ number_format($stats['pro_accounts'] ?? 0, 0, '.', ' ') }}+</strong>
                                <span>PRO</span>
                            </div>
                            <div class="trust-showcase trust-showcase--feature">
                                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                                <span>PRO-керування</span>
                            </div>
                           
                        </div>
                    </div>
                </article>

                {{-- 3) Каталог --}}
                <article class="trust-proof__feature">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>3</span> Каталог і пошук</p>
                        <h3 class="trust-proof__feature-title">Каталог, де репутація читається за хвилину</h3>
                        <p class="trust-proof__feature-text">
                            Пошук і фільтри допомагають швидко знайти профіль, побачити рейтинг і прочитати відгуки.
                            Без зайвих кроків: усе потрібне видно одразу.
                        </p>
                        <a class="trust-proof__btn" href="{{ route('catalog') }}">
                            <span>Перейти в каталог</span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--stats" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <img class="trust-showcase__image" src="{{ asset('static/assets/how3.png') }}" alt="Каталог профілів">
                            </div>
                            <div class="trust-showcase trust-showcase--photo">
                              <strong>{{ number_format($stats['profiles'] ?? (($stats['lawyers'] ?? 0) + ($stats['companies'] ?? 0)), 0, '.', ' ') }}+</strong>
                                <span>профілів</span>
                            </div>
                            <div class="trust-showcase trust-showcase--feature">
                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                <span>Пошук і фільтри</span>
                            </div>
                          
                        </div>
                    </div>
                </article>

            </div>
        </div>
    </div>
</section>

  

  
<section class="section how" id="how">
  <div class="container">
    <div class="how__head how__head--center">
      <div class="badge how__badge">Як ми перевіряємо відгуки</div>
      <h2 class="h2 how__title">Чому відгукам на DOVIRA можна довіряти</h2>
      <div style="width: 60%;" class="section-divider"></div>
      <p class="muted how__lead">
        Ми поєднуємо публічність, перевірку профілів, модерацію та право на відповідь, щоб репутація формувалась на фактах, а не на випадкових оцінках.
      </p>
    </div>
  </div>

  <div class="how__bleed">
    <div class="how__timeline" aria-label="Чому відгукам на DOVIRA можна довіряти">
      <svg class="how__path" viewBox="0 0 1000 260" preserveAspectRatio="none" aria-hidden="true">
        <defs>
          <linearGradient id="howLine" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stop-color="rgba(31,104,255,0.20)" />
            <stop offset="50%" stop-color="rgba(31,104,255,0.85)" />
            <stop offset="100%" stop-color="rgba(76,179,255,0.45)" />
          </linearGradient>
        </defs>
        <path class="how__path-shadow"
              d="M-60,210 C120,250 240,10 370,70 C470,130 520,250 620,160 C730,70 840,10 1060,50" />
        <path class="how__path-line"
              d="M-60,210 C120,250 240,10 370,70 C470,130 520,250 620,160 C730,70 840,10 1060,50" />
      </svg>

      <!-- Крок 1 -->
      <div class="how-step how-step--1 is-up" data-step="1">
        <div class="how-step__marker" aria-hidden="true">
          <i class="fa-solid fa-comment-dots"></i>
        </div>
        <div class="how-step__card">
          <div class="how-step__title">Реальні відгуки користувачів</div>
          <div class="how-step__text">
            Користувачі діляться власним досвідом, а відгуки проходять базову модерацію перед публікацією.
          </div>
        </div>
      </div>

      <!-- Крок 2 -->
      <div class="how-step how-step--2 is-down" data-step="2">
        <div class="how-step__marker" aria-hidden="true">
          <i class="fa-solid fa-id-card-clip"></i>
        </div>
        <div class="how-step__card">
          <div class="how-step__title">Перевірка профілів і статусів</div>
          <div class="how-step__text">
            Профілі можуть мати статуси та підтверджені дані, щоб користувач бачив більше, ніж просто назву і фото.
          </div>
        </div>
      </div>

      <!-- Крок 3 -->
      <div class="how-step how-step--3 is-up" data-step="3">
        <div class="how-step__marker" aria-hidden="true">
          <i class="fa-solid fa-reply"></i>
        </div>
        <div class="how-step__card">
          <div class="how-step__title">Право на публічну відповідь</div>
          <div class="how-step__text">
            Представники профілів можуть відповідати на відгуки й публічно пояснювати свою позицію у спірних ситуаціях.
          </div>
        </div>
      </div>

      <!-- Крок 4 -->
      <div class="how-step how-step--4 is-down" data-step="4">
        <div class="how-step__marker" aria-hidden="true">
          <i class="fa-solid fa-chart-simple"></i>
        </div>
        <div class="how-step__card">
          <div class="how-step__title">Репутація, яку видно в динаміці</div>
          <div class="how-step__text">
            Оцінки, відгуки та активність профілю формують загальну картину, яку можна побачити без зайвих кроків.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- CTA під кроками -->
  <div class="container how__cta">
    <p class="how__cta-text">
      У результаті користувач швидко бачить, кому можна довіряти.
    </p>
    <div class="how__cta-actions">
      
      <a href="#pro" class="btn btn--primary ">
        <span>Дізнатися про PRO-акаунт</span>
        <img src="../static/assets/shield-check-svgrepo-com.svg" alt="PRO акаунт">
      </a>
    </div>
  </div>
</section>

    <section class="section pro-highlight" id="pro" aria-label="PRO-акаунт">
        <div class="container">
            <div class="pro-highlight__panel">
                <div class="pro-highlight__head">
                    <div class="badge pro-highlight__badge">Професійний кабінет</div>
                    <h2 class="h2 pro-highlight__title">PRO — інструменти управління репутацією</h2>
                    <p class="pro-highlight__text">
                        Для компаній, магазинів і спеціалістів, які хочуть професійно працювати з відгуками, керувати публічною комунікацією та бачити репутацію в цифрах з одного кабінету.
                    </p>
                </div>

                <div class="pro-highlight__body">
                    <div class="pro-highlight__content">
                        <div class="pro-highlight__grid">
                            <article class="pro-highlight__item pro-highlight__item--blue">
                                <span class="pro-highlight__icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="pro-highlight__item-title">Підтверджений профіль</h3>
                                    <p class="pro-highlight__item-text">Статус, який підсилює довіру до профілю з першого перегляду.</p>
                                </div>
                            </article>

                            <article class="pro-highlight__item pro-highlight__item--green">
                                <span class="pro-highlight__icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="pro-highlight__item-title">Офіційні відповіді</h3>
                                    <p class="pro-highlight__item-text">Відповідайте на відгуки від імені профілю та ведіть публічну комунікацію професійно.</p>
                                </div>
                            </article>

                            <article class="pro-highlight__item pro-highlight__item--amber">
                                <span class="pro-highlight__icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="pro-highlight__item-title">Спірні кейси під контролем</h3>
                                    <p class="pro-highlight__item-text">Окремі інструменти для звернень щодо сумнівних або конфліктних відгуків.</p>
                                </div>
                            </article>

                            <article class="pro-highlight__item pro-highlight__item--violet">
                                <span class="pro-highlight__icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="pro-highlight__item-title">Адмін-панель і статистика</h3>
                                    <p class="pro-highlight__item-text">Оцінки, динаміка, нові відгуки й сигнали для системної роботи з репутацією.</p>
                                </div>
                            </article>
                        </div>

                        <div class="pro-highlight__actions">
                            <a class="btn btn--primary pro-highlight__btn" href="{{ route('register') }}">
                                <span>Перейти на PRO</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>

                    <div class="pro-highlight__visual" aria-hidden="true">
                        <div class="pro-highlight__glow"></div>
                        <div class="pro-highlight__frame">
                            <div class="pro-highlight__screen">
                                <img class="pro-highlight__image" src="{{ asset('static/assets/pro-mc.png') }}" alt="">
                            </div>
                        </div>
                    </div>
                </div>
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
                        Готові залишити відгук
                        і допомогти іншим?
                    </h2>
                    <p class="cta-hero-callout__text">
                        Опишіть свій досвід. Ваш відгук допоможе іншим швидше зробити вибір.
                    </p>
                    <div class="cta-hero-callout__actions">
                        <a class="btn dovira-btn" href="{{ route('register') }}">
                           <span>Написати відгук</span>
                           <i class="fa-solid fa-pen-to-square"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section platform-contacts" aria-label="Контакти DOVIRA">
        <div class="container">
            <div class="platform-contacts__card">
                <div class="platform-contacts__intro">
                    <span class="badge platform-contacts__badge">Контакти</span>
                    <h2 class="platform-contacts__title">Зв’яжіться з командою DOVIRA</h2>
                    <p class="platform-contacts__lead">Відповідаємо на питання про платформу, модерацію та PRO-акаунт.</p>
                    <a class="trust-proof__btn" href="{{ route('login') }}">
                        <span>Написати в підтримку</span>
                        <i class="fa-solid fa-headset" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="platform-contacts__panel">
                    <div class="platform-contacts__row">
                        <span class="platform-contacts__label">Email</span>
                        <a class="platform-contacts__value" href="mailto:support@dovira.com.ua">
                            <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                            <span>support@dovira.com.ua</span>
                        </a>
                    </div>
                    <div class="platform-contacts__row">
                        <span class="platform-contacts__label">Телефон</span>
                        <a class="platform-contacts__value" href="tel:+380670000000">
                            <i class="fa-solid fa-phone" aria-hidden="true"></i>
                            <span>+38 (067) 000 00 00</span>
                        </a>
                    </div>
                    <div class="platform-contacts__row">
                        <span class="platform-contacts__label">Telegram</span>
                        <a class="platform-contacts__value" href="#" aria-label="Telegram підтримка DOVIRA">
                            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
                            <span>@dovira_support</span>
                        </a>
                    </div>
                    <div class="platform-contacts__row">
                        <span class="platform-contacts__label">Адреса</span>
                        <div class="platform-contacts__value">
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                            <span>м. Київ, вул. Хрещатик, 22</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
