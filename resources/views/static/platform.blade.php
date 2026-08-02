@extends('static.layout')

@section('title', 'Про нас — DOVIRA, платформа відгуків і публічної репутації')
@section('description', 'DOVIRA — каталог компаній, сервісів і спеціалістів України з реальними відгуками клієнтів. Дізнайтеся, як ми перевіряємо профілі, модеруємо відгуки та захищаємо право на публічну відповідь.')
@section('canonical', route('platform'))
@section('body_class', 'page-platform')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/home.css') }}?v={{ @filemtime(public_path('static/css/pages/home.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/platform.css') }}?v={{ @filemtime(public_path('static/css/pages/platform.css')) }}">
    @php
        // Answers must mirror the visible FAQ content on this page —
        // Google penalizes FAQPage markup that differs from what users see.
        $platformSeoSchema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'AboutPage',
                    '@id' => route('platform') . '#aboutpage',
                    'url' => route('platform'),
                    'name' => 'Про нас — DOVIRA',
                    'description' => 'DOVIRA — каталог компаній, сервісів і спеціалістів України з реальними відгуками клієнтів.',
                    'inLanguage' => 'uk',
                    'isPartOf' => ['@id' => url('/') . '#website'],
                    'about' => ['@id' => url('/') . '#organization'],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => route('platform') . '#breadcrumbs',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Про нас', 'item' => route('platform')],
                    ],
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => route('platform') . '#faq',
                    'mainEntity' => [
                        [
                            '@type' => 'Question',
                            'name' => 'Що таке DOVIRA?',
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => 'DOVIRA — це каталог компаній, сервісів і спеціалістів України з реальними відгуками клієнтів. Тут можна знайти виконавця, перевірити його репутацію за відгуками та рейтингом і поділитися власним досвідом.',
                            ],
                        ],
                        [
                            '@type' => 'Question',
                            'name' => 'Це безкоштовно?',
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => 'Так. Пошук у каталозі, перегляд профілів, читання і публікація відгуків — безкоштовні. Платні лише PRO-інструменти для компаній, які хочуть керувати своїм профілем.',
                            ],
                        ],
                        [
                            '@type' => 'Question',
                            'name' => 'Чи може компанія видалити мій відгук?',
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => 'Ні. Компанія не може видалити чи викупити негативний відгук — вона може лише публічно на нього відповісти. Спірні випадки розглядає модерація за прозорими правилами платформи.',
                            ],
                        ],
                        [
                            '@type' => 'Question',
                            'name' => 'Я представляю компанію. Як керувати своїм профілем?',
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => 'Зареєструйтесь і підтвердьте права на профіль — це безкоштовно й закріплює сторінку за вами. Керування профілем відкриває PRO-підписка: оновлення контактів і послуг, офіційні відповіді на відгуки, контакти клієнтів із заявок, аналітика та пріоритет у каталозі.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    @endphp
    <script type="application/ld+json">@json($platformSeoSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
@endpush

@section('content')
@php
    $stats = $stats ?? [];
    $statReviews = (int) ($stats['reviews'] ?? 0);
    $statProfiles = (int) ($stats['profiles'] ?? 0);
    // Малі числа PRO працюють як антиреклама — показуємо лише від 10.
    $statPro = (int) ($stats['pro_accounts'] ?? 0) >= 10 ? (int) $stats['pro_accounts'] : 0;
@endphp

<section class="platform-hero" id="top" aria-label="Про DOVIRA">
  <div class="container platform-hero__inner">
    <div class="platform-hero__copy">
      <span class="platform-hero__badge">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <span>Незалежна платформа відгуків</span>
      </span>

      <h1 class="platform-hero__title">
        <span class="platform-hero__title-accent">Публічна репутація</span><br>
        компаній і спеціалістів України
      </h1>

      <p class="platform-hero__lead">
        DOVIRA — каталог компаній, сервісів і фахівців з реальними відгуками клієнтів.
        Відгуки не можна видалити чи купити, а компанії відповідають на них публічно.
      </p>

      <div class="platform-hero__actions">
        <a href="{{ route('catalog') }}" class="btn btn--primary">
          <span>Перейти в каталог</span>
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>

        <a href="#" class="platform-hero__btn-secondary" data-open-review-popup>
          <span>Написати відгук</span>
          <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
        </a>
      </div>

      <div class="platform-hero__stats" aria-label="Платформа в цифрах">
        @if ($statReviews > 0)
        <div class="platform-hero__stat">
          <strong>{{ number_format($statReviews, 0, '', ' ') }}+</strong>
          <span>відгуків клієнтів</span>
        </div>
        @endif
        @if ($statProfiles > 0)
        <div class="platform-hero__stat">
          <strong>{{ number_format($statProfiles, 0, '', ' ') }}</strong>
          <span>профілів у каталозі</span>
        </div>
        @endif
        <div class="platform-hero__stat">
          <strong class="platform-hero__stat-lock"><i class="fa-solid fa-lock" aria-hidden="true"></i></strong>
          <span>відгуки не видаляються</span>
        </div>
      </div>
    </div>

    <div class="platform-hero__visual" aria-hidden="true">
      <div class="platform-hero__glow"></div>

      <article class="ph-card ph-card--review">
        <div class="ph-card__head">
          <span class="ph-card__avatar">О</span>
          <div class="ph-card__author">
            <strong>Олена М.</strong>
            <span class="ph-card__stars">
              <i class="fa-solid fa-star"></i>
              <i class="fa-solid fa-star"></i>
              <i class="fa-solid fa-star"></i>
              <i class="fa-solid fa-star"></i>
              <i class="fa-solid fa-star"></i>
              <b>5.0</b>
            </span>
          </div>
        </div>
        <p class="ph-card__text">
          Обрала майстра за відгуками на DOVIRA — все зробили вчасно і якісно. Тепер завжди перевіряю компанії тут.
        </p>
        <span class="ph-card__verified">
          <i class="fa-solid fa-circle-check"></i>
          Опубліковано після модерації
        </span>
      </article>

      <div class="ph-card ph-card--rating">
        <span class="ph-card__mini-icon"><i class="fa-solid fa-star"></i></span>
        <div>
          <strong>4.9 із 5</strong>
          <span>рейтинг профілю</span>
        </div>
      </div>

      <div class="ph-card ph-card--reply">
        <span class="ph-card__mini-icon"><i class="fa-solid fa-reply"></i></span>
        <div>
          <strong>Офіційна відповідь</strong>
          <span>компанія відповіла публічно</span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="platform-steps section" aria-labelledby="platform-steps-title">
  <div class="container">
    <div class="platform-steps__head">
      <h2 id="platform-steps-title">Як працює DOVIRA</h2>
      <p>Чотири кроки від пошуку до впевненого вибору</p>
    </div>

    <div class="platform-steps__grid">
      <article class="platform-step">
        <span class="platform-step__num">01</span>
        <span class="platform-step__icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
        <h3>Знаходите профіль</h3>
        <p>Шукайте компанію чи спеціаліста за назвою, категорією або містом.</p>
      </article>

      <article class="platform-step">
        <span class="platform-step__num">02</span>
        <span class="platform-step__icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
        <h3>Читаєте відгуки</h3>
        <p>Дивіться рейтинг і реальний досвід клієнтів — з відповідями компаній.</p>
      </article>

      <article class="platform-step">
        <span class="platform-step__num">03</span>
        <span class="platform-step__icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
        <h3>Обираєте впевнено</h3>
        <p>Порівнюйте профілі та обирайте перевіреного виконавця без ризику.</p>
      </article>

      <article class="platform-step">
        <span class="platform-step__num">04</span>
        <span class="platform-step__icon"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i></span>
        <h3>Ділитесь досвідом</h3>
        <p>Залишайте власний відгук — він допоможе іншим зробити правильний вибір.</p>
      </article>
    </div>
  </div>
</section>

<section class="section trust-proof" aria-label="Що ви можете робити на DOVIRA">
    <div class="container">
        <div class="trust-proof__panel">
            <div class="how__head how__head--center">
                <p class="badge how__badge">Що таке DOVIRA</p>
                <h2 class="h2 how__title">Що ви можете робити <span>на DOVIRA</span></h2>
                <div style="width: 60%;" class="section-divider"></div>
                <p class="muted how__lead">
                    Знаходьте виконавців, перевіряйте їхню репутацію за реальними відгуками і діліться власним досвідом.
                </p>
            </div>

            <div class="trust-proof__features">

                {{-- 1) Знайти виконавця --}}
                <article class="trust-proof__feature">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>1</span> Знайти виконавця</p>
                        <h3 class="trust-proof__feature-title">Каталог компаній і спеціалістів</h3>
                        <p class="trust-proof__feature-text">
                            Шукайте за назвою, категорією, містом чи послугою. У кожного профілю — рейтинг,
                            відгуки, контакти та статуси перевірки, тож порівняти виконавців можна без зайвих кроків.
                        </p>
                        <a class="trust-proof__btn" href="{{ route('catalog') }}">
                            <span>Перейти в каталог</span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--stats" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <div class="ts-mock ts-mock--catalog" aria-hidden="true">
                                    <div class="ts-mock-card">
                                        <div class="ts-mock-card__head">
                                            <span class="ts-mock-card__logo ts-mock-card__logo--blue">МП</span>
                                            <div class="ts-mock-card__title">
                                                <strong>Мережа права</strong>
                                            </div>
                                        </div>
                                        <div class="ts-mock-rating-row">
                                            <b>4.5</b>
                                            <span class="ts-mock-stars">
                                                <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                            </span>
                                            <em>93 відгуки</em>
                                        </div>
                                        <div class="ts-mock-chips"><span>адвокат</span><span>спадкове право</span><span>+8</span></div>
                                        <div class="ts-mock-btn">Переглянути профіль</div>
                                    </div>
                                    <div class="ts-mock-card ts-mock-card--ghost">
                                        <div class="ts-mock-card__head">
                                            <span class="ts-mock-card__logo ts-mock-card__logo--green">ВЦ</span>
                                            <div class="ts-mock-card__title">
                                                <strong>Ветклініка «Друг»</strong>
                                            </div>
                                        </div>
                                        <div class="ts-mock-rating-row">
                                            <b>4.9</b>
                                            <span class="ts-mock-stars">
                                                <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                            </span>
                                            <em>101 відгук</em>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @if ($statProfiles > 0)
                            <div class="trust-showcase trust-showcase--photo">
                              <strong>{{ number_format($statProfiles, 0, '.', ' ') }}+</strong>
                                <span>профілів</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </article>

                {{-- 2) Перевірити репутацію --}}
                <article class="trust-proof__feature trust-proof__feature--reverse">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>2</span> Перевірити репутацію</p>
                        <h3 class="trust-proof__feature-title">Реальний досвід замість реклами</h3>
                        <p class="trust-proof__feature-text">
                            Читайте відгуки з оцінками та конкретикою, дивіться відповіді компаній і статуси перевірки.
                            Відгук не може бути видалений компанією, тож картина чесна.
                        </p>
                        <a class="trust-proof__btn" href="#how">
                            <span>Як ми перевіряємо відгуки</span>
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--analytics" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <div class="ts-mock ts-mock--reputation" aria-hidden="true">
                                    <div class="ts-mock-card">
                                        <div class="ts-mock-score">
                                            <div class="ts-mock-score__value">
                                                <b>4.5</b>
                                                <span class="ts-mock-stars">
                                                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                                                </span>
                                                <em>93 відгуки</em>
                                            </div>
                                            <div class="ts-mock-bars">
                                                <div class="ts-mock-bar"><span>5</span><div class="ts-mock-bar__line"><i style="width:85%"></i></div><em>85%</em></div>
                                                <div class="ts-mock-bar"><span>4</span><div class="ts-mock-bar__line"><i style="width:8%"></i></div><em>8%</em></div>
                                                <div class="ts-mock-bar"><span>3</span><div class="ts-mock-bar__line"><i style="width:4%"></i></div><em>4%</em></div>
                                                <div class="ts-mock-bar"><span>2</span><div class="ts-mock-bar__line"><i style="width:2%"></i></div><em>2%</em></div>
                                                <div class="ts-mock-bar"><span>1</span><div class="ts-mock-bar__line"><i style="width:1%"></i></div><em>1%</em></div>
                                            </div>
                                        </div>
                                        <div class="ts-mock-reply">
                                            <i class="fa-solid fa-reply"></i>
                                            <div>
                                                <strong>Офіційна відповідь</strong>
                                                <span>компанія відповіла публічно</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @if ($statReviews > 0)
                            <div class="trust-showcase trust-showcase--photo">
                                 <strong>{{ number_format($statReviews, 0, '.', ' ') }}+</strong>
                                <span>відгуків</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </article>

                {{-- 3) Поділитись досвідом --}}
                <article class="trust-proof__feature">
                    <div class="trust-proof__copy">
                        <p class="trust-proof__step"><span>3</span> Поділитись досвідом</p>
                        <h3 class="trust-proof__feature-title">Ваш відгук допомагає обрати іншим</h3>
                        <p class="trust-proof__feature-text">
                            Скористалися послугою — розкажіть, як усе пройшло. Відгук займає 2 хвилини,
                            проходить модерацію і стає частиною публічної репутації компанії.
                        </p>
                        <a class="trust-proof__btn" href="#" data-open-review-popup>
                            <span>Написати відгук</span>
                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div class="trust-proof__visual trust-proof__visual--flow" aria-hidden="true">
                        <div class="trust-stack trust-stack--showcase">
                            <div class="trust-showcase trust-showcase--screen">
                                <div class="ts-mock ts-mock--form" aria-hidden="true">
                                    <div class="ts-mock-card">
                                        <div class="ts-mock-form__head">
                                            <strong>Ваш відгук</strong>
                                            <span class="ts-mock-form__step">Крок 2 з 3</span>
                                        </div>
                                        <div class="ts-mock-form__rate">
                                            <span class="ts-mock-stars ts-mock-stars--big">
                                                <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                            </span>
                                            <b>5.0</b>
                                        </div>
                                        <div class="ts-mock-form__lines">
                                            <i></i>
                                            <i></i>
                                            <i style="width: 62%"></i>
                                        </div>
                                        <div class="ts-mock-btn ts-mock-btn--solid">Опублікувати відгук</div>
                                    </div>
                                </div>
                            </div>
                            <div class="trust-showcase trust-showcase--photo">
                                <strong>2 хв</strong>
                                <span>на відгук</span>
                            </div>
                        </div>
                    </div>
                </article>

            </div>
        </div>
    </div>
</section>

<section class="platform-why section" id="how" aria-labelledby="platform-why-title">
  <div class="container">
    <div class="platform-why__head">
      <span class="platform-why__eyebrow">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        Інфраструктура довіри
      </span>
      <h2 id="platform-why-title">Чому відгукам на DOVIRA можна довіряти</h2>
      <p>Публічність, перевірка профілів, модерація і право на відповідь — репутація формується на фактах, а не на випадкових оцінках.</p>
    </div>

    <div class="platform-why__grid">
      <article class="platform-why-card">
        <span class="platform-why-card__icon platform-why-card__icon--blue"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span>
        <h3>Модерація перед публікацією</h3>
        <p>Кожен відгук перевіряється на спам і фейки, перш ніж з'явитися на сторінці профілю.</p>
      </article>

      <article class="platform-why-card">
        <span class="platform-why-card__icon platform-why-card__icon--green"><i class="fa-solid fa-id-card-clip" aria-hidden="true"></i></span>
        <h3>Перевірені профілі</h3>
        <p>Компанії підтверджують права на профіль, тож ви бачите, хто реально стоїть за сторінкою.</p>
      </article>

      <article class="platform-why-card">
        <span class="platform-why-card__icon platform-why-card__icon--violet"><i class="fa-solid fa-reply" aria-hidden="true"></i></span>
        <h3>Право на публічну відповідь</h3>
        <p>Компанія відповідає на відгук прямо на сторінці профілю — ви бачите обидві сторони ситуації.</p>
      </article>

      <article class="platform-why-card">
        <span class="platform-why-card__icon platform-why-card__icon--amber"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
        <h3>Відгук не можна видалити</h3>
        <p>Ні прибрати, ні викупити негатив. Спірні випадки розглядає модерація за прозорими правилами.</p>
      </article>
    </div>

    <div class="platform-why__cta">
      <a href="{{ route('catalog') }}" class="btn btn--primary">
        <span>Знайти перевіреного виконавця</span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
      <a href="{{ route('faq') }}" class="platform-why__cta-link">
        <span>Як працює модерація — у FAQ</span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </div>
</section>

@if (!empty($latestReviews))
<section class="platform-reviews section" aria-labelledby="platform-reviews-title">
  <div class="container">
    <div class="platform-why__head">
      <span class="platform-why__eyebrow">
        <i class="fa-regular fa-comments" aria-hidden="true"></i>
        Живі відгуки
      </span>
      <h2 id="platform-reviews-title">Свіжі відгуки на платформі</h2>
      <p>Останні враження користувачів про компанії та спеціалістів у каталозі.</p>
    </div>

    <div class="platform-reviews__nav" aria-hidden="false">
      <button type="button" class="platform-reviews__nav-btn" aria-label="Попередні відгуки" data-carousel-prev="platform-reviews">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </button>
      <button type="button" class="platform-reviews__nav-btn" aria-label="Наступні відгуки" data-carousel-next="platform-reviews">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
    </div>

    <div class="platform-reviews__track" data-carousel="platform-reviews">
      @foreach ($latestReviews as $review)
        @php
            $prFullStars = (int) floor($review['rating']);
            $prHasHalfStar = ($review['rating'] - $prFullStars) >= 0.5;
            $prEmptyStars = max(0, 5 - $prFullStars - ($prHasHalfStar ? 1 : 0));
        @endphp
        <article class="platform-review-card result-card--carousel">
          <div class="platform-review-card__head">
            <span class="platform-review-card__avatar">
              @if (!empty($review['avatar_url']))
                <img src="{{ $review['avatar_url'] }}" alt="" loading="lazy" onerror="this.remove()">
              @endif
              <span aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></span>
            </span>
            <div class="platform-review-card__meta">
              <strong>{{ $review['author'] }}</strong>
              <span class="platform-review-card__stars" aria-label="Рейтинг {{ number_format($review['rating'], 1) }} з 5">
                @for ($i = 0; $i < $prFullStars; $i++)<i class="fa-solid fa-star"></i>@endfor
                @if ($prHasHalfStar)<i class="fa-solid fa-star-half-stroke"></i>@endif
                @for ($i = 0; $i < $prEmptyStars; $i++)<i class="fa-regular fa-star"></i>@endfor
                <b>{{ number_format($review['rating'], 1) }}</b>
              </span>
            </div>
          </div>

          <p class="platform-review-card__text">{{ $review['text'] }}</p>

          @if (!empty($review['profile_slug']))
            <a class="platform-review-card__profile" href="{{ route('profile.show', ['slug' => $review['profile_slug']]) }}" aria-label="Перейти до профілю {{ $review['profile_name'] }}">
              <span class="platform-review-card__logo">
                @if (!empty($review['profile_logo_url']))
                  <img src="{{ $review['profile_logo_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                @endif
                <span aria-hidden="true">{{ $review['profile_initials'] }}</span>
              </span>
              <span class="platform-review-card__profile-meta">
                <strong>{{ $review['profile_name'] }}</strong>
                @if (!empty($review['profile_site']))
                  <span>{{ $review['profile_site'] }}</span>
                @endif
              </span>
              <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
          @endif
        </article>
      @endforeach
    </div>

    <div class="platform-why__cta">
      <a href="{{ route('catalog', ['sort' => 'reviews_desc']) }}" class="platform-why__cta-link">
        <span>Дивитись всі відгуки в каталозі</span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </div>
</section>
@endif

    <section class="section cta-hero-callout" aria-label="Заклик до дії">
        <div class="container">
            <div class="cta-hero-callout__card">
                <div class="cta-hero-callout__orb" aria-hidden="true"></div>

                <div class="cta-hero-callout__mockup" aria-hidden="true">
                    <div class="cta-hero-callout__phone-shadow"></div>
                    <img class="cta-hero-callout__phone-img" src="{{ asset('static/assets/dovira-phone-transparent.webp') }}" alt="" loading="lazy">
                </div>

                <div class="cta-hero-callout__content">
                    <p class="cta-hero-callout__eyebrow">Поділіться досвідом</p>
                    <h2 class="cta-hero-callout__title">
                        Ваш відгук допоможе іншим зробити правильний вибір
                    </h2>
                    <p class="cta-hero-callout__text">
                        Опишіть свій досвід чесно — це безкоштовно і займає 2 хвилини.
                    </p>
                    <div class="cta-hero-callout__actions">
                        <a class="btn dovira-btn" href="#" data-open-review-popup>
                           <span>Написати відгук</span>
                           <i class="fa-solid fa-pen-to-square"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pro-highlight" id="pro" aria-label="PRO-акаунт">
        <div class="container">
            <div class="pro-highlight__panel">
                <div class="pro-highlight__head">
                    <div class="badge pro-highlight__badge">Для компаній і спеціалістів</div>
                    <h2 class="h2 pro-highlight__title">PRO — інструменти управління репутацією</h2>
                    <p class="pro-highlight__text">
                        Якщо на DOVIRA пишуть про ваш бізнес — керуйте цим: підтверджуйте профіль, відповідайте на відгуки
                        та перетворюйте перегляди сторінки на звернення клієнтів.
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
                                    <h3 class="pro-highlight__item-title">Аналітика профілю</h3>
                                    <p class="pro-highlight__item-text">Перегляди, кліки на контакти й сайт — бачите, що саме приводить звернення.</p>
                                </div>
                            </article>
                        </div>

                        <div class="pro-highlight__actions">
                            <a class="btn btn--primary pro-highlight__btn" href="{{ route('pro') }}">
                                <span>Можливості PRO</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                            <a class="platform-why__cta-link" href="{{ route('pro') }}#pro-pricing">
                                <span>Переглянути тарифи</span>
                                <i class="fa-solid fa-tags" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>

                    <div class="pro-highlight__visual" aria-hidden="true">
                        <div class="pro-highlight__glow"></div>
                        <img class="pro-highlight__laptop" src="{{ asset('static/assets/dovira-laptop-transparent.webp') }}" alt="" loading="lazy">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section faq platform-faq" aria-label="Питання та відповіді про платформу">
        <div class="container">
            <div class="faq__head">
                <div class="badge faq__badge">FAQ</div>
                <h2 class="h2 faq__title">Коротко про головне</h2>
                <p class="muted faq__lead">Найчастіші питання про те, як працює DOVIRA.</p>
            </div>

            <div class="faq__list">
                <details class="faq__item is-open" open>
                    <summary class="faq__question">Що таке DOVIRA?</summary>
                    <div class="faq__panel">
                        <div class="faq__answer">
                            DOVIRA — це каталог компаній, сервісів і спеціалістів України з реальними відгуками клієнтів.
                            Тут можна знайти виконавця, перевірити його репутацію за відгуками та рейтингом і поділитися власним досвідом.
                        </div>
                    </div>
                </details>

                <details class="faq__item">
                    <summary class="faq__question">Це безкоштовно?</summary>
                    <div class="faq__panel">
                        <div class="faq__answer">
                            Так. Пошук у каталозі, перегляд профілів, читання і публікація відгуків — безкоштовні.
                            Платні лише PRO-інструменти для компаній, які хочуть керувати своїм профілем.
                        </div>
                    </div>
                </details>

                <details class="faq__item">
                    <summary class="faq__question">Чи може компанія видалити мій відгук?</summary>
                    <div class="faq__panel">
                        <div class="faq__answer">
                            Ні. Компанія не може видалити чи викупити негативний відгук — вона може лише публічно на нього відповісти.
                            Спірні випадки розглядає модерація за прозорими правилами платформи.
                        </div>
                    </div>
                </details>

                <details class="faq__item">
                    <summary class="faq__question">Я представляю компанію. Як керувати своїм профілем?</summary>
                    <div class="faq__panel">
                        <div class="faq__answer">
                            Зареєструйтесь і підтвердьте права на профіль — це безкоштовно. Після підтвердження ви зможете оновлювати
                            контакти й послуги, відповідати на відгуки, а PRO додає пріоритет у каталозі та аналітику звернень.
                        </div>
                    </div>
                </details>
            </div>

            <div class="platform-why__cta">
                <a href="{{ route('faq') }}" class="platform-why__cta-link">
                    <span>Всі питання та відповіді</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </section>

    @php
        $platformContactEmail = config('site_contacts.email', 'info@mydovira.com');
        $platformContactPhone = config('site_contacts.phone', '+380937269578');
        $platformContactPhoneHref = '+' . preg_replace('/\D+/', '', $platformContactPhone);
        $platformSupportTelegram = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $platformSupportUrl = 'https://t.me/' . $platformSupportTelegram;
    @endphp

    <section class="section platform-contacts" id="contacts" aria-label="Контакти DOVIRA">
        <div class="container">
            <div class="platform-contacts__card">
                <div class="platform-contacts__intro">
                    <span class="badge platform-contacts__badge">Контакти</span>
                    <h2 class="platform-contacts__title">Зв’яжіться з командою DOVIRA</h2>
                    <p class="platform-contacts__lead">Відповідаємо на питання про платформу, модерацію та PRO-акаунт.</p>
                </div>
                <div class="platform-contacts__panel">
                    <div class="profile-contact-actions">
                        <a class="profile-contact-action profile-contact-action--primary" href="{{ $platformSupportUrl }}" target="_blank" rel="noopener noreferrer">
                            <span class="profile-contact-action__icon"><i class="fa-brands fa-telegram" aria-hidden="true"></i></span>
                            <span class="profile-contact-action__text">
                                <strong>Написати в Telegram</strong>
                                <span>{{ '@' . $platformSupportTelegram }}</span>
                            </span>
                        </a>
                        <a class="profile-contact-action" href="mailto:{{ $platformContactEmail }}">
                            <span class="profile-contact-action__icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                            <span class="profile-contact-action__text">
                                <strong>Email</strong>
                                <span>{{ $platformContactEmail }}</span>
                            </span>
                        </a>
                        <a class="profile-contact-action" href="tel:{{ $platformContactPhoneHref }}">
                            <span class="profile-contact-action__icon"><i class="fa-solid fa-phone" aria-hidden="true"></i></span>
                            <span class="profile-contact-action__text">
                                <strong>Телефон</strong>
                                <span>{{ $platformContactPhone }}</span>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
