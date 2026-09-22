@extends('static.layout')

@php
    /**
     * «Перевірка адвоката» — публічний лендінг під пошуковий попит
     * («перевірити адвоката», «перевірка юриста», «реєстр адвокатів») і
     * вхідна точка до профілів з досьє. Дизайн — мова головної: той самий
     * hero-градієнт, пошук-pill, скляні плаваючі картки, скромні шапки секцій.
     */
    $checkStatsProfiles = (int) ($stats['profiles'] ?? 0);
    $checkStatsReviews = (int) ($stats['reviews'] ?? 0);
    $checkDossiers = (int) ($dossiers ?? 0);
    $fmt = fn (int $n): string => number_format($n, 0, ',', ' ');

    $checkFaq = [
        [
            'q' => 'Як безкоштовно перевірити адвоката онлайн?',
            'a' => 'Введіть прізвище адвоката або назву компанії в пошук на DOVIRA. На сторінці спеціаліста ви побачите рейтинг, відгуки клієнтів і досьє з відкритих джерел — реєстри, судові справи, публікації. Перевірка безкоштовна й не потребує реєстрації.',
        ],
        [
            'q' => 'Що таке досьє адвоката на DOVIRA?',
            'a' => 'Це репутаційна довідка, зібрана з відкритих джерел: державні реєстри, судовий реєстр, згадки у ЗМІ та на сайтах. Досьє починається з вердикту-світлофора: записи чисті, є що перевірити або є критичні факти — і кожен факт має посилання на джерело.',
        ],
        [
            'q' => 'Чи можна перевірити адвоката в державному реєстрі?',
            'a' => 'Так, свідоцтво адвоката можна перевірити в Єдиному реєстрі адвокатів України. DOVIRA доповнює цю перевірку тим, чого в реєстрі немає: реальними відгуками клієнтів, рейтингом і фактами з судового реєстру та публікацій.',
        ],
        [
            'q' => 'Наскільки можна довіряти вердикту досьє?',
            'a' => 'Вердикт — це орієнтир, а не юридична оцінка. Ми вказуємо джерела фактів у самому досьє, тож ви завжди можете перевірити їх самостійно. Фінальне рішення — за вами.',
        ],
        [
            'q' => 'Що робити, якщо мого адвоката немає на платформі?',
            'a' => 'Скоріш за все він є: у каталозі ' . number_format(max($checkStatsProfiles, 1000), 0, ',', ' ') . ' профілів. Якщо не знайшли — напишіть нам, додамо. А якщо ви вже співпрацювали з адвокатом, залиште відгук: він допоможе наступним клієнтам.',
        ],
    ];

    $checkSteps = [
        [
            'icon' => 'fa-magnifying-glass',
            'title' => 'Знайдіть адвоката',
            'text' => 'Введіть ПІБ, назву компанії чи місто — відкриється сторінка з рейтингом, відгуками і контактами.',
        ],
        [
            'icon' => 'fa-scale-balanced',
            'title' => 'Відкрийте досьє',
            'text' => 'Факти з реєстрів, судових справ і публікацій — з вердиктом-світлофором на початку.',
        ],
        [
            'icon' => 'fa-comments',
            'title' => 'Почитайте відгуки',
            'text' => 'Реальні оцінки клієнтів і відповіді адвоката на критику скажуть більше за будь-яку рекламу.',
        ],
    ];

    $checkSchemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => 'Перевірка адвоката онлайн',
            'url' => route('lawyer.check'),
            'description' => 'Безкоштовна перевірка адвоката: відгуки клієнтів, рейтинг і досьє з відкритих джерел — реєстри, судові справи, публікації.',
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'DOVIRA', 'url' => route('home')],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Перевірка адвоката', 'item' => route('lawyer.check')],
            ],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($checkFaq)->map(fn (array $item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ])->all(),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => 'Як перевірити адвоката онлайн',
            'description' => 'Три кроки перевірки адвоката перед зверненням: пошук профілю, досьє з відкритих джерел і відгуки клієнтів.',
            'step' => collect($checkSteps)->values()->map(fn (array $step, int $i) => [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $step['title'],
                'text' => $step['text'],
            ])->all(),
        ],
    ];
@endphp

@section('title', 'Перевірка адвоката онлайн — відгуки, реєстри, судові справи | DOVIRA')
@section('description', 'Безкоштовна перевірка адвоката за хвилину: реальні відгуки клієнтів, рейтинг і репутаційне досьє з відкритих джерел — реєстри, судові справи, публікації. Без реєстрації.')
@section('canonical', route('lawyer.check'))
@section('body_class', 'page-perevirka')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/perevirka.css') }}?v={{ @filemtime(public_path('static/css/pages/perevirka.css')) }}">
    @foreach ($checkSchemas as $checkSchema)
        <script type="application/ld+json">@json($checkSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
@endpush

@section('content')
    {{-- ── Hero: градієнт і pill-пошук головної (search-first, без карток) ── --}}
    <section class="check-hero" aria-labelledby="check-title">
        <div class="check-hero__glow" aria-hidden="true"></div>
        <div class="container check-hero__inner">
            <div class="badge badge--soft check-hero__badge">
                <span class="badge__live" aria-hidden="true"></span>
                <span>Безкоштовно · без реєстрації</span>
            </div>

            <h1 class="check-hero__title" id="check-title">
                Перевірка адвоката <span class="check-hero__title-accent">онлайн</span>
            </h1>

            <p class="check-hero__lead">
                @if ($checkStatsReviews > 0 && $checkDossiers > 0)
                    <strong>{{ $fmt($checkStatsReviews) }}</strong> відгуків і <strong>{{ $fmt($checkDossiers) }}</strong> репутаційних досьє —
                    дізнайтесь про юриста все ще до першої консультації
                @else
                    Відгуки клієнтів, рейтинг і досьє з відкритих джерел — дізнайтесь про юриста все ще до першої консультації
                @endif
            </p>

            <form class="check-hero__search hero__mobile-search-field" action="{{ route('catalog') }}" method="get" data-search-form data-search-mobile-app>
                <i class="fa-solid fa-magnifying-glass check-hero__search-icon" aria-hidden="true"></i>
                <input type="text" name="q" placeholder="Прізвище адвоката, компанія або місто" autocomplete="off" aria-label="Пошук адвоката">
                <button type="submit" aria-label="Шукати">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span>Перевірити</span>
                </button>
                <div class="hero__search-suggest" data-search-suggest hidden>
                    <p class="hero__search-suggest-title">Популярні запити</p>
                </div>
            </form>

            <div class="check-hero__chips">
                <span class="check-hero__chips-label">Популярне:</span>
                <a class="check-hero__chip" href="{{ route('catalog', ['q' => 'Адвокат Київ']) }}">Київ</a>
                <a class="check-hero__chip" href="{{ route('catalog', ['q' => 'Адвокат Львів']) }}">Львів</a>
                <a class="check-hero__chip" href="{{ route('catalog', ['q' => 'Адвокат Одеса']) }}">Одеса</a>
                <a class="check-hero__chip" href="{{ route('catalog', ['q' => 'Адвокат Дніпро']) }}">Дніпро</a>
                <a class="check-hero__chip" href="{{ route('top.rating', ['category' => 'advokaty']) }}">Рейтинг →</a>
            </div>

            <div class="check-hero__proof">
                <span><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Реальні відгуки</span>
                <span><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Відкриті джерела</span>
                <span><i class="fa-solid fa-rotate" aria-hidden="true"></i> Оновлюється щодня</span>
            </div>
        </div>
    </section>

    {{-- ── Що покаже перевірка ── --}}
    <section class="section check-what" aria-labelledby="check-what-title">
        <div class="container">
            <div class="check-head">
                <div class="check-head__text">
                    <h2 class="check-head__title" id="check-what-title">Що покаже перевірка</h2>
                    <p class="check-head__sub">Уся публічна інформація про адвоката — на одній сторінці.</p>
                </div>
                <a class="check-head__all" href="{{ route('catalog', ['q' => 'Адвокат']) }}">Каталог адвокатів <span aria-hidden="true">→</span></a>
            </div>
            <div class="check-what__grid">
                <article class="check-what__card">
                    <span class="check-what__icon"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                    <h3 class="check-what__card-title">Рейтинг і відгуки</h3>
                    <p class="check-what__card-text">Оцінки реальних клієнтів — і те, як адвокат відповідає на критику.</p>
                </article>
                <article class="check-what__card">
                    <span class="check-what__icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                    <h3 class="check-what__card-title">Судові справи</h3>
                    <p class="check-what__card-text">Згадки в судовому реєстрі та участь у справах — із посиланнями на джерела.</p>
                </article>
                <article class="check-what__card">
                    <span class="check-what__icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                    <h3 class="check-what__card-title">Реєстри і свідоцтво</h3>
                    <p class="check-what__card-text">Чи є адвокат у реєстрі, чи чинне свідоцтво, чи не було дисциплінарних стягнень.</p>
                </article>
                <article class="check-what__card">
                    <span class="check-what__icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                    <h3 class="check-what__card-title">Досвід і контакти</h3>
                    <p class="check-what__card-text">Спеціалізації, місто, послуги та способи звʼязку з перевіреним спеціалістом.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- ── Приклад досьє (центральний блок-вітрина) ── --}}
    <section class="section check-demo" aria-labelledby="check-demo-title">
        <div class="container">
            <div class="check-head">
                <div class="check-head__text">
                    <h2 class="check-head__title" id="check-demo-title">Як виглядає досьє</h2>
                    <p class="check-head__sub">Фрагмент реальної структури: вердикт на початку, далі — факти з джерелами.</p>
                </div>
            </div>

            <div class="check-demo__card">
                <div class="check-demo__top">
                    <div class="check-demo__profile">
                        <span class="check-demo__ava" aria-hidden="true">АД</span>
                        <div>
                            <p class="check-demo__name">Досьє: адвокат (приклад)</p>
                            <p class="check-demo__src">Зібрано з відкритих джерел · оновлено цього місяця</p>
                        </div>
                    </div>
                    <div class="check-demo__verdict check-demo__verdict--yellow">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <span>Є що перевірити</span>
                    </div>
                </div>

                <div class="check-demo__callouts">
                    <div class="check-callout check-callout--positive">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        <p><b>Свідоцтво чинне.</b> Адвокат присутній у реєстрі, право на практику підтверджене, дисциплінарних стягнень не знайдено.</p>
                    </div>
                    <div class="check-callout check-callout--warning">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <p><b>Дві судові згадки.</b> У реєстрі судових рішень є справи за участю спеціаліста — варто переглянути деталі й поставити питання на консультації.</p>
                    </div>
                    <div class="check-callout check-callout--critical">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <p><b>Негативний відгук без відповіді.</b> Клієнт скаржився на зрив термінів — адвокат поки не відповів публічно.</p>
                    </div>
                </div>

                <div class="check-demo__foot">
                    <p class="check-demo__note">Вердикт — орієнтир, а не юридична оцінка: кожен факт має посилання на джерело.</p>
                    <a class="btn btn--primary check-demo__btn" href="{{ route('catalog', ['q' => 'Адвокат']) }}">
                        Дивитися живі профілі <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Як перевірити — 3 кроки (видимі, дзеркалять HowTo schema) ── --}}
    <section class="section check-how" aria-labelledby="check-how-title">
        <div class="container">
            <div class="check-head">
                <div class="check-head__text">
                    <h2 class="check-head__title" id="check-how-title">Як перевірити адвоката — 3 кроки</h2>
                    <p class="check-head__sub">Від пошуку до впевненого рішення — близько хвилини.</p>
                </div>
            </div>
            <ol class="check-how__steps">
                @foreach ($checkSteps as $i => $step)
                    <li class="check-how__step">
                        <span class="check-how__step-num" aria-hidden="true">{{ $i + 1 }}</span>
                        <span class="check-how__step-icon"><i class="fa-solid {{ $step['icon'] }}" aria-hidden="true"></i></span>
                        <h3 class="check-how__step-title">{{ $step['title'] }}</h3>
                        <p class="check-how__step-text">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ── SEO-текст: навіщо перевіряти ── --}}
    <section class="section check-seo" aria-labelledby="check-seo-title">
        <div class="container">
            <div class="check-seo__wrap">
                <div class="check-seo__col">
                    <h2 class="check-seo__title" id="check-seo-title">Навіщо перевіряти адвоката перед зверненням</h2>
                    <p>Юридична допомога — послуга, де ціна помилки найвища: програна справа, втрачені гроші й час. При цьому обрати адвоката «наосліп» найпростіше — реклама виглядає однаково переконливо в усіх.</p>
                    <p>Перевірка займає хвилину і відповідає на головні питання: чи справді це чинний адвокат, як він веде справи, що кажуть його клієнти і чи є факти, про які варто спитати до підписання договору.</p>
                    <p>DOVIRA збирає в одному місці те, що інакше довелося б шукати по кількох реєстрах: <a href="{{ route('rating.method') }}">чесний рейтинг</a>, відгуки з підтвердженим авторством і репутаційне досьє з відкритих джерел.</p>
                </div>
                <div class="check-seo__col">
                    <h3 class="check-seo__subtitle">5 ознак надійного адвоката</h3>
                    <ul class="check-seo__list">
                        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Є в реєстрі адвокатів, свідоцтво чинне</li>
                        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Відповідає на відгуки — навіть критичні</li>
                        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Рейтинг тримається на багатьох відгуках, а не на двох</li>
                        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Досьє без критичних фактів або з поясненими</li>
                        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Чітко називає ціну й межі послуги до початку роботи</li>
                    </ul>
                    <p class="check-seo__more">Більше про принципи платформи — <a href="{{ route('trust') }}">чому нам довіряти</a> та <a href="{{ route('faq') }}">відповіді на часті питання</a>.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ── --}}
    <section class="section check-faq" aria-labelledby="check-faq-title">
        <div class="container">
            <div class="check-head">
                <div class="check-head__text">
                    <h2 class="check-head__title" id="check-faq-title">Часті питання про перевірку</h2>
                </div>
                <a class="check-head__all" href="{{ route('faq') }}">Всі питання <span aria-hidden="true">→</span></a>
            </div>
            <div class="check-faq__list">
                @foreach ($checkFaq as $item)
                    <details class="check-faq__item" @if ($loop->first) open @endif>
                        <summary class="check-faq__q">
                            <span>{{ $item['q'] }}</span>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <p class="check-faq__a">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA для адвокатів: синій градієнт-банер, як business-блоки головної ── --}}
    <section class="section check-cta" aria-labelledby="check-cta-title">
        <div class="container">
            <div class="check-cta__wrap">
                <div class="check-cta__text">
                    <h2 class="check-cta__title" id="check-cta-title">Ви адвокат?</h2>
                    <p class="check-cta__lead">Клієнти вже можуть переглядати вашу сторінку, знаходити її в Google і читати досьє. Оформіть підписку, щоб відповідати на відгуки, редагувати інформацію та керувати репутацією; перед оплатою ми безкоштовно перевіримо права. {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }} За бейдж DOVIRA на сайті або в соцмережах діє знижка 20%.</p>
                </div>
                <div class="check-cta__actions">
                    <a class="btn check-cta__btn" href="{{ route('pro') }}">Знайти свій профіль <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>
    </section>
@endsection
