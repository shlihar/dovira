@extends('static.layout')

@section('title', 'Dovira — реальні відгуки, рейтинги та перевірені профілі бізнесу')
@section('description', 'Відгуки про компанії, магазини й спеціалістів України. Обирайте бізнес за реальними оцінками клієнтів, перевіреними профілями та рейтингами.')
@section('canonical', route('home'))
@section('body_class', 'page-home-main')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/home.css') }}?v={{ @filemtime(public_path('static/css/pages/home.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/home-main.css') }}?v={{ @filemtime(public_path('static/css/pages/home-main.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}?v={{ @filemtime(public_path('static/css/profile-card-catalog.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/blog.css') }}?v={{ @filemtime(public_path('static/css/pages/blog.css')) }}">
@endpush

@section('content')
@php
    $popularQueries = $popularQueries ?? [];
    $intents = $intents ?? [];
    $recommendedProfiles = $recommendedProfiles ?? [];
    $bestProfiles = $bestProfiles ?? [];
    $popularProfiles = $popularProfiles ?? [];
    $latestReviews = $latestReviews ?? [];
    $trendingProfiles = $trendingProfiles ?? [];
    $blogPosts = $blogPosts ?? [];
    $totalReviews = $totalReviews ?? 0;
    $heroVariant = in_array(($heroVariant ?? 'base'), ['base','c','f','i','j'], true) ? $heroVariant : 'base';
    $categoryTree = $categoryTree ?? [];
    $totalViews = $totalViews ?? 0;
    $totalProfiles = $totalProfiles ?? 0;
    $heroMobileIntents = $intents;
    $faqCategoryNames = collect($intents)->pluck('name')->filter()->take(12)->implode(', ');

    $profileUrl = fn (string $slug) => route('profile.show', [
        'slug' => $slug,
    ]);
    $categoryUrl = fn (string $categoryLabel) => route('catalog', ['sub' => $categoryLabel]);
    // Гостя ведемо на лендінг PRO (цінність і тарифи), авторизованого — одразу в кабінет.
    $homeProUrl = auth()->check() ? route('pro.account', ['tab' => 'claims']) : route('pro');
    $homeProLabel = auth()->check() ? 'Створити профіль' : 'Дізнатися більше';
    // Міста для селектора в пошуку (той самий довідник, що й у гео-персоналізації).
    $cityOptions = array_values(\App\Support\RegionCityDirectory::citySlugMap());
    // Правильна форма «відгук/відгуки/відгуків» за українськими правилами.
    $reviewWord = function (int $n): string {
        $n100 = abs($n) % 100; $n10 = $n % 10;
        if ($n10 === 1 && $n100 !== 11) return 'відгук';
        if ($n10 >= 2 && $n10 <= 4 && !($n100 >= 12 && $n100 <= 14)) return 'відгуки';
        return 'відгуків';
    };
    // Дві картки-айдентика по краях hero (desktop) — КУРОВАНА пара, зібрана
    // з реальних ассетів платформи (аватарки з catalog-media, справжні
    // компанії з їхніми лого), блок декоративний (aria-hidden). Негатив —
    // реальний відгук про юридичну компанію: доказ нефільтрованості.
    // Якщо файлу нема на середовищі, thumbUrl віддасть null і картка
    // покаже фолбек-ініціали.
    $heroCards = collect([
        [
            'author' => 'Анастасія Д.',
            'rating' => 5.0,
            'card_text' => 'Склала іспит з першого разу! Інструктори чудові, рекомендую',
            'avatar_url' => \App\Support\MediaUrl::thumbUrl('catalog-media/review-avatars/00/anastasiia-dovgal-004e67b5077ea24654a7.jpg', 128),
            'profile_name' => 'Драйвер, автошкола',
            'profile_site' => 'driver.lutsk.ua',
            'profile_logo_url' => \App\Support\MediaUrl::thumbUrl('catalog-media/profiles/85/draiver-avtoskola-853048888e1bd64ada4f.jpg', 160),
            'profile_initials' => 'ДА',
        ],
        [
            'author' => 'Антон Т.',
            'rating' => 2.0,
            'card_text' => 'Не довели справу до кінця. На повідомлення не відповідають.',
            'avatar_url' => \App\Support\MediaUrl::thumbUrl('catalog-media/review-avatars/00/dmitrii-prokopcuk-00236e36027b0a0e43c0.jpg', 128),
            'profile_name' => 'Правова позиція, юридична компанія',
            'profile_site' => '',
            'profile_logo_url' => \App\Support\MediaUrl::thumbUrl('catalog-media/profiles/9c/pravova-poziciia-iuridicna-kompaniia-9c7972a9946606124fed.jpg', 160),
            'profile_initials' => 'ПП',
        ],
    ]);
    // Чипи hero — куровані КОРОТКІ запити: назви підкатегорій з дерева були
    // задовгі й ламали ритм рядка (беклог «Світанку» закрито).
    $heroChips = ['Адвокат', 'Стоматолог', 'Автосервіс', 'Салон краси', 'Ремонт'];
@endphp
<section class="hero hero--v-{{ $heroVariant }}" id="top" data-hero-variant="{{ $heroVariant }}">
    <div class="hero__bg" aria-hidden="true"></div>
    <div class="hero__halo" data-hero-halo aria-hidden="true"></div>

    {{-- Дві картки-відгуки по краях (desktop, айдентика) — та сама
         .platform-review-card, що на «Про нас»: аватар, зірки з оцінкою,
         текст і чіп профілю. Стилі — hero-скоуп у home-main.css
         (оригінал скоуплений під body.page-platform). Блок декоративний
         (aria-hidden), тому футер — div, а не посилання. --}}
    @if ($heroCards->count() >= 2)
        <div class="hero__cards" aria-hidden="true">
            @foreach ($heroCards->take(2) as $heroCard)
                @php
                    $hcFull = (int) floor($heroCard['rating']);
                    $hcHalf = ($heroCard['rating'] - $hcFull) >= 0.5;
                    $hcEmpty = max(0, 5 - $hcFull - ($hcHalf ? 1 : 0));
                @endphp
                <div class="hero__card hero__card--{{ $loop->first ? 'l' : 'r' }}" data-hero-card data-parallax="{{ $loop->first ? 14 : -14 }}">
                    <article class="platform-review-card">
                        <div class="platform-review-card__head">
                            <span class="platform-review-card__avatar">
                                @if (!empty($heroCard['avatar_url']))
                                    <img src="{{ $heroCard['avatar_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                                @endif
                                <span aria-hidden="true"><i class="fa-regular fa-user" aria-hidden="true"></i></span>
                            </span>
                            <div class="platform-review-card__meta">
                                <strong>{{ $heroCard['author'] }}</strong>
                                <span class="platform-review-card__stars">
                                    @for ($i = 0; $i < $hcFull; $i++)<i class="fa-solid fa-star"></i>@endfor
                                    @if ($hcHalf)<i class="fa-solid fa-star-half-stroke"></i>@endif
                                    @for ($i = 0; $i < $hcEmpty; $i++)<i class="fa-regular fa-star"></i>@endfor
                                    <b>{{ number_format($heroCard['rating'], 1) }}</b>
                                </span>
                            </div>
                        </div>

                        <p class="platform-review-card__text">{{ $heroCard['card_text'] ?: 'Рекомендую!' }}</p>

                        @if (!empty($heroCard['profile_name']))
                            <div class="platform-review-card__profile">
                                <span class="platform-review-card__logo">
                                    @if (!empty($heroCard['profile_logo_url']))
                                        <img src="{{ $heroCard['profile_logo_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                                    @endif
                                    <span aria-hidden="true">{{ $heroCard['profile_initials'] ?? 'DV' }}</span>
                                </span>
                                <span class="platform-review-card__profile-meta">
                                    <strong>{{ $heroCard['profile_name'] }}</strong>
                                    {{-- Punycode-домени (xn--…) в декоративній картці — шум, ховаємо. --}}
                                    @if (!empty($heroCard['profile_site']) && !str_contains($heroCard['profile_site'], 'xn--'))
                                        <span>{{ $heroCard['profile_site'] }}</span>
                                    @endif
                                </span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        @endif
                    </article>
                </div>
            @endforeach
        </div>
    @endif

    @if ($heroVariant === 'f')
        {{-- Варіант F: стіна справжніх відгуків повільно дрейфує за контентом. --}}
        <div class="hero__wall" aria-hidden="true">
            @foreach (collect($latestReviews)->take(12)->chunk(3) as $wallColumn)
                <div class="hero__wall-col">
                    @for ($wallPass = 0; $wallPass < 2; $wallPass++)
                        @foreach ($wallColumn as $wallReview)
                            <div class="hero__wall-card">
                                <b>{{ $wallReview['author'] }}</b>
                                <span class="hero__wall-stars">{{ str_repeat('★', max(1, min(5, (int) round($wallReview['rating'] ?? 5)))) }}</span>
                                <p>{{ \Illuminate\Support\Str::limit(trim((string) ($wallReview['text'] ?? '')), 92) ?: 'Рекомендую!' }}</p>
                            </div>
                        @endforeach
                    @endfor
                </div>
            @endforeach
        </div>
        <div class="hero__wall-veil" aria-hidden="true"></div>
    @endif

    @if ($heroVariant === 'j')
        {{-- Варіант J: темна сцена з авроровим світінням. --}}
        <div class="hero__aurora hero__aurora--1" aria-hidden="true"></div>
        <div class="hero__aurora hero__aurora--2" aria-hidden="true"></div>
        <div class="hero__aurora hero__aurora--3" aria-hidden="true"></div>
    @endif
    <div class="container hero__inner">
        <div class="hero__content">
            <div class="hero__top">
                <div class="hero__intro">
                    <div class="hero__copy">
                        <div class="badge badge--soft"><span class="badge__live" aria-hidden="true"></span><span>
                            @if ($totalReviews > 0)
                                <strong>{{ number_format($totalReviews, 0, '', ' ') }}</strong> {{ $reviewWord((int) $totalReviews) }} про бізнес України
                            @else
                                Платформа перевірених відгуків
                            @endif
                        </span></div>

                        <h1 class="hero__title">
                            Знайдіть, <span class="hero__title-accent">кому довіряти</span>
                        </h1>

                        <p class="hero__lead hero__lead--mobile-only">
                            Реальні відгуки про спеціалістів, компанії та сервіси — щоб ваш вибір був впевненим
                        </p>
                        {{-- Десктопний лід — чиста цінність без цифр: числа несуть
                             badge (загальна к-сть) і рядок доказу під пошуком
                             (свіжість + охоплення), щоб не дублювати одне число. --}}
                        <p class="hero__lead hero__lead--desktop-only">
                            Реальні відгуки про компанії та спеціалістів — щоб ваш вибір був впевненим
                        </p>

                        @if ($heroVariant === 'c')
                            {{-- Варіант C: зірки як бренд відгуків (замінюють лід на десктопі). --}}
                            <div class="hero__stars-row" aria-label="Рейтинг платформи">
                                <span class="hero__stars-row-stars" aria-hidden="true">★★★★★</span>
                                <span><strong>{{ number_format($totalReviews ?? 0, 0, '', ' ') }}</strong> реальних відгуків
                                    про <strong>{{ number_format($totalProfiles ?? 0, 0, '', ' ') }}</strong> компаній</span>
                            </div>
                        @endif
                    </div>

                    {{-- Фото-сцену прибрано (2026-08-02): постановочний профіль
                         на платформі справжності — ризик, плюс зіштовхував
                         пошук за фолд. --}}
                </div>

                <div class="hero__mobile-utility">
                    <div class="hero__mobile-cta">
                        <div class="hero__mobile-search-shell" data-search-mobile-shell>
                            <form
                                class="hero__mobile-search"
                                action="{{ route('catalog') }}"
                                method="get"
                                aria-label="Мобільний пошук профілю"
                                data-search-form
                                data-search-mobile-app
                            >
                                <div class="hero__mobile-search-field">
                                    <i class="fa-solid fa-magnifying-glass hero__mobile-search-icon" aria-hidden="true"></i>
                                    <input type="text" name="q" placeholder="Адвокат, клініка, агентство, майстер...">
                                    <button type="submit" aria-label="Шукати">
                                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    </button>
                                </div>
                                @include('static.partials.home-city-picker', ['cityOptions' => $cityOptions, 'pickerClass' => 'home-city-picker--mobile'])
                                <input type="hidden" name="regions[]" value="" disabled data-city-input>
                                <div class="hero__search-suggest hero__search-suggest--mobile" data-search-suggest hidden>
                                    <p class="hero__search-suggest-title">Популярні запити</p>
                                </div>
                            </form>
                        </div>
                        {{-- Одна вторинна дія: «Знайти спеціаліста» дублювала
                             синю кнопку самого пошуку. --}}
                        <div class="hero__mobile-actions">
                            <a href="#" class="btn btn--outline hero__mobile-action hero__mobile-action--secondary" data-open-review-popup>
                                <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                                <span>Написати відгук</span>
                            </a>
                        </div>
                    </div>

                    {{-- Мобільну hero-карусель категорій прибрано: категорії тепер
                         в єдиній секції .categories під hero (адаптивна, 1 колонка). --}}


                </div>



                @if ($heroVariant === 'i')
                    {{-- Варіант I: bento-мозаїка (десктоп). Стандартні блоки ховає CSS. --}}
                    @php
                        $bentoReview = collect($latestReviews)->first();
                        $bentoIntents = collect($intents)->take(4);
                    @endphp
                    <div class="hero__bento" aria-label="Головне про платформу">
                        <div class="hero__bento-tile hero__bento-main">
                            <h2>Знайдіть, кому довіряти</h2>
                            <p>Пошук серед <strong>{{ number_format($totalProfiles ?? 0, 0, '', ' ') }}</strong> компаній
                               за <strong>{{ number_format($totalReviews ?? 0, 0, '', ' ') }}</strong> реальними відгуками</p>
                            <form class="hero__bento-search" action="{{ route('catalog') }}" method="get" aria-label="Пошук профілю" data-search-form>
                                <input type="text" name="q" placeholder="Адвокат, клініка, майстер...">
                                <button type="submit" aria-label="Шукати">
                                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    <span>Знайти</span>
                                </button>
                            </form>
                        </div>
                        <div class="hero__bento-tile hero__bento-stat">
                            <b>{{ number_format($totalReviews ?? 0, 0, '', ' ') }}</b>
                            <span>реальних відгуків @if (($reviewsToday ?? 0) > 0) · +{{ $reviewsToday }} за добу @endif</span>
                        </div>
                        <div class="hero__bento-tile hero__bento-review">
                            @if ($bentoReview)
                                <span class="hero__bento-review-badge">свіжий відгук</span>
                                <span class="hero__bento-review-who">
                                    <i>{{ $bentoReview['author_initial'] ?? mb_substr($bentoReview['author'], 0, 1) }}</i>
                                    {{ $bentoReview['author'] }}
                                    <em aria-hidden="true">{{ str_repeat('★', max(1, min(5, (int) round($bentoReview['rating'] ?? 5)))) }}</em>
                                </span>
                                <p>«{{ \Illuminate\Support\Str::limit(trim((string) ($bentoReview['text'] ?? '')), 110) }}»</p>
                                <small>{{ $bentoReview['profile_name'] ?? '' }}</small>
                            @endif
                        </div>
                        @foreach ($bentoIntents as $bentoIntent)
                            <a class="hero__bento-tile hero__bento-cat" href="{{ route('catalog', ['q' => $bentoIntent['name']]) }}">
                                <i class="{{ $bentoIntent['icon'] }} hero__bento-cat-ic hero__bento-cat-ic--{{ $loop->iteration }}" aria-hidden="true"></i>
                                <b>{{ $bentoIntent['name'] }}</b>
                                <small>{{ number_format($bentoIntent['count'], 0, '', ' ') }} профілів</small>
                            </a>
                        @endforeach
                        <div class="hero__bento-tile hero__bento-geo">
                            <span class="hero__bento-geo-dot" aria-hidden="true"></span>
                            <span><b>180+ міст України</b><small>рейтинги вашого міста — <a href="{{ route('catalog') }}">всі категорії →</a></small></span>
                        </div>
                        <a class="hero__bento-tile hero__bento-pro" href="{{ route('pro') }}">
                            <span><b>Маєте бізнес?</b><small>Клієнти вже шукають вас на Dovira</small></span>
                            <span class="hero__bento-pro-btn">Створити профіль</span>
                        </a>
                    </div>
                @endif

                <form class="hero__desktop-search" action="{{ route('catalog') }}" method="get" aria-label="Пошук профілю" data-search-form>
                    <input type="text" name="q" placeholder="Адвокат, клініка, агентство, майстер...">
                    @include('static.partials.home-city-picker', ['cityOptions' => $cityOptions])
                    <input type="hidden" name="regions[]" value="" disabled data-city-input>
                    <button type="submit" aria-label="Шукати">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <span>Знайти</span>
                    </button>
                    <div class="hero__search-suggest" data-search-suggest hidden>
                        <p class="hero__search-suggest-title">Популярні запити</p>
                    </div>
                </form>

                {{-- Чипи популярних запитів під пошуком: один клік = вже дія.
                     Чотири + посилання «Інші». Тільки десктоп. --}}
                <div class="hero__chips hero__chips--desktop-only" aria-label="Популярні напрями">
                    @foreach ($heroChips as $chip)
                        <a class="hero__chip" href="{{ route('catalog', ['q' => $chip]) }}">{{ $chip }}</a>
                    @endforeach
                    <a class="hero__chips-more" href="{{ route('catalog') }}">Інші →</a>
                </div>
            </div>
        </div>
    </div>
</section>

@include('static.partials.home-categories')

@include('static.partials.home-how-it-works')

@include('static.partials.home-fresh-reviews')

<div data-home-leaderboard>
    @include('static.partials.home-leaderboard')
</div>

@include('static.partials.home-business-cta')

{{-- «Dovira рекомендує» (карусель) і «Топ-рейтинги» (борд) замінив лідерборд
     home-leaderboard: позначка «Dovira рекомендує» тепер бейдж на його рядках. --}}

@include('static.partials.home-trust-principles')

{{-- Стару карусель «Свіжі відгуки користувачів» замінила секція
     home-fresh-reviews одразу після «Як працює DOVIRA». --}}

@if (!empty($blogPosts))
<section class="section home-blog" aria-labelledby="home-blog-title">
    <div class="container">
        <div class="home-intents__head">
            <h2 id="home-blog-title" class="h2 home-section-title">Корисні статті</h2>
            <div class="home-intents__actions">
                <a class="home-intents__more" href="{{ route('blog') }}">Всі статті</a>
            </div>
        </div>

        <div class="blog-grid home-blog__grid">
            @foreach ($blogPosts as $post)
                <a class="blog-card" href="{{ route('blog.show', ['slug' => $post['slug']]) }}">
                    <div class="blog-card__cover blog-cover blog-cover--{{ $post['accent'] }}" aria-hidden="true">
                        <i class="{{ $post['icon'] }}"></i>
                    </div>
                    <div class="blog-card__body">
                        <div class="blog-card__meta">
                            <span class="blog-card__chip">{{ $post['category'] }}</span>
                            <span class="blog-card__dot" aria-hidden="true"></span>
                            <time datetime="{{ $post['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($post['published_at'])->translatedFormat('j F Y') }}</time>
                        </div>
                        <h3 class="blog-card__title">{{ $post['title'] }}</h3>
                        <p class="blog-card__excerpt">{{ $post['excerpt'] }}</p>
                        <div class="blog-card__foot">
                            <span class="blog-card__read">{{ $post['reading_minutes'] }} хв читання</span>
                            <span class="blog-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- Стару «Ви спеціаліст або компанія?» замінила секція home-business-cta
     одразу після лідерборда (момент «а де тут я?»). --}}

<section class="section faq" id="faq" aria-label="Питання та відповіді">
  <div class="container">
    {{-- Шапка в єдиному «главному» патерні секцій головної (без бейджа,
         без центрування): заголовок + сабтайтл зліва, лінк справа. --}}
    <div class="faq__head faq__head--chapter">
      <div class="faq__head-text">
        <h2 class="h2 faq__title">Питання та відповіді</h2>
        <p class="muted faq__lead">Коротко про те, як працює DOVIRA і як ми формуємо прозору репутацію.</p>
      </div>
      <a class="faq__all" href="{{ route('faq') }}">Всі питання <span aria-hidden="true">→</span></a>
    </div>

    <div class="faq__list">
      <details class="faq__item is-open" open="">
        <summary class="faq__question" aria-expanded="true">Що таке платформа відгуків DOVIRA?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            DOVIRA — це платформа перевірених відгуків про спеціалістів, компанії та сервіси в Україні. Тут можна переглядати профілі, читати реальні відгуки, порівнювати рейтинг і обирати виконавця або бізнес на основі публічної репутації.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Як перевірити чи відгук справжній?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Звертайте увагу на дату публікації, повноту тексту, рейтинг, публічний профіль компанії чи спеціаліста та відповіді на відгуки. На DOVIRA також діють правила модерації, а спірні публікації можуть бути додатково перевірені.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Як залишити відгук про компанію або спеціаліста?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Знайдіть потрібний профіль на платформі, відкрийте його сторінку і натисніть кнопку додавання відгуку. Опишіть свій реальний досвід, поставте оцінку і надішліть відгук на публікацію.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Чи може компанія видалити негативний відгук?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Ні. Компанія або спеціаліст не можуть самостійно видаляти негативні відгуки. Якщо є спірна ситуація, вона розглядається через правила платформи та модерацію.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Як компанія може відповісти на відгук клієнта?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Після підтвердження прав на профіль компанія або спеціаліст можуть публічно відповідати на відгуки клієнтів. Це допомагає пояснити ситуацію, дати зворотний зв’язок і показати рівень сервісу.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Які переваги PRO-акаунту для бізнесу?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            PRO-акаунт посилює профіль як канал залучення клієнтів: дає пріоритет у каталозі та пошуку, кращу презентацію послуг, контактів і фото, відповіді на відгуки та аналітику переглядів, кліків на сайт і контактних дій. А оскільки сторінки профілів індексуються Google, заповнений профіль з відгуками допомагає людям знаходити вас і через звичайний пошук.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Чи можна користуватися DOVIRA без реєстрації?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Так, основна інформація про профілі та відгуки може бути доступна для перегляду. Для окремих дій, зокрема публікації відгуку або керування профілем, може знадобитися авторизація.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Як знайти перевіреного спеціаліста чи компанію в Україні?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Скористайтеся пошуком на головній сторінці або відкрийте каталог DOVIRA. Ви можете шукати за назвою, категорією, містом або типом послуги, а потім порівнювати профілі, рейтинги та відгуки.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Які категорії є на платформі DOVIRA?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            На DOVIRA можна знайти профілі в різних категоріях, зокрема: {{ $faqCategoryNames }}. Список категорій постійно розширюється, щоб охоплювати більше спеціалістів, компаній і сервісів по всій Україні.
          </div>
        </div>
      </details>

      <details class="faq__item">
        <summary class="faq__question" aria-expanded="false">Як додати свою компанію на DOVIRA?</summary>
        <div class="faq__panel">
          <div class="faq__answer">
            Щоб додати свою компанію або профіль спеціаліста на DOVIRA, зареєструйтесь, прив'яжіть або створіть профіль і заповніть послуги, місто, контакти, сайт, фото та опис. Після цього профіль працює як сторінка в каталозі, через яку користувачі можуть перейти до зв'язку з вами, а також індексується в Google — вас можна знайти за назвою компанії чи послугою.
          </div>
        </div>
      </details>
    </div>
  </div>
</section>

{{-- Нижній SEO-грід «Категорії відгуків» прибрано: нову секцію .categories
     (реальні агрегати + усі категорії в DOM через «Показати ще») винесено
     нагору під hero — вона покриває і SEO-перелінковку, і візуальний браузинг. --}}
@endsection

@push('scripts')
<script>
(() => {
    const hero = document.querySelector('.page-home-main .hero');
    if (!hero) return;

    const motionOk = window.matchMedia('(prefers-reduced-motion: no-preference)').matches;
    const desktop = window.matchMedia('(min-width: 681px)');

    // Курсор-світло + паралакс крайніх карток (лише десктоп + дозволений рух).
    const halo = hero.querySelector('[data-hero-halo]');
    const cards = hero.querySelectorAll('[data-hero-card]');
    if (motionOk && halo) {
        hero.addEventListener('pointermove', (e) => {
            if (!desktop.matches) return;
            const r = hero.getBoundingClientRect();
            halo.style.setProperty('--hx', ((e.clientX - r.left) / r.width * 100) + '%');
            halo.style.setProperty('--hy', ((e.clientY - r.top) / r.height * 100) + '%');
            hero.classList.add('hero--halo');
            const dx = (e.clientX - r.left) / r.width - 0.5;
            const dy = (e.clientY - r.top) / r.height - 0.5;
            cards.forEach((card) => {
                const k = parseFloat(card.dataset.parallax || '0');
                card.style.setProperty('--phx', (dx * k).toFixed(1) + 'px');
                card.style.setProperty('--phy', (dy * k).toFixed(1) + 'px');
                // «Привидність»: картка проявляється, лише коли курсор поруч
                // (--near 0..1 за відстанню до центру картки).
                const cr = card.getBoundingClientRect();
                const dist = Math.hypot(e.clientX - (cr.left + cr.width / 2), e.clientY - (cr.top + cr.height / 2));
                card.style.setProperty('--near', Math.max(0, 1 - dist / 420).toFixed(2));
            });
        }, { passive: true });
        hero.addEventListener('pointerleave', () => {
            hero.classList.remove('hero--halo');
            cards.forEach((card) => card.style.setProperty('--near', '0'));
        });
    }

    // Друкуючий плейсхолдер десктоп-пошуку: сам показує, що сюди вписати
    // (відповідь на ступор «що вписати» прямо в полі). На фокусі друк
    // зупиняється і повертається статичний плейсхолдер — далі веде сагест.
    const input = hero.querySelector('.hero__desktop-search input[name="q"]');
    if (input && motionOk && desktop.matches) {
        const fallback = input.getAttribute('placeholder') || '';
        const queries = ['Адвокат у Києві', 'Стоматологія поруч', 'СТО з чесними цінами', 'Майстер манікюру', 'Ремонт квартири під ключ'];
        let qi = 0, ci = 0, deleting = false, timer = 0, active = true;

        const tick = () => {
            if (!active) return;
            const word = queries[qi];
            ci += deleting ? -1 : 1;
            input.setAttribute('placeholder', word.slice(0, ci));
            let delay = deleting ? 34 : 62;
            if (!deleting && ci === word.length) { delay = 1900; deleting = true; }
            if (deleting && ci === 0) { deleting = false; qi = (qi + 1) % queries.length; delay = 340; }
            timer = window.setTimeout(tick, delay);
        };

        const stopTyping = () => {
            if (!active) return;
            active = false;
            window.clearTimeout(timer);
            input.setAttribute('placeholder', fallback);
        };

        input.addEventListener('focus', stopTyping);
        input.addEventListener('input', stopTyping);
        tick();
    }
})();
</script>
@endpush
