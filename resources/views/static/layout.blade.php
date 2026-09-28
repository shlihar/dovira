<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#3a67f2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Microsoft Clarity: теплові карти й записи сесій. Вмикається самим
         наявним ідентифікатором (env CLARITY_ID): на бойовому сервері він є,
         локально й на тестах його немає — і скрипт не підключається.
         Перевірку на APP_ENV навмисно НЕ робимо: бойовий сервер працює з
         APP_ENV=local, тож вона б назавжди вимикала лічильник. --}}
    @if (filled(config('services.clarity.id')))
        <script type="text/javascript">
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "{{ config('services.clarity.id') }}");
        </script>
    @endif
    @php
        // yieldContent на inline-секціях (@section('title', $value)) уже застосовує
        // e() до значення. Розкодовуємо один раз, щоб {{ }} нижче екранувало рівно
        // раз — інакше лапки в назвах виходили подвійно екранованими (&amp;quot;).
        $seoTitle = html_entity_decode(trim($__env->yieldContent('title', 'Dovira — реальні відгуки, рейтинги та перевірені профілі бізнесу')), ENT_QUOTES, 'UTF-8');
        $seoDescription = html_entity_decode(trim($__env->yieldContent('description', 'Відгуки про компанії, магазини й спеціалістів України. Обирайте бізнес за реальними оцінками клієнтів, перевіреними профілями та рейтингами.')), ENT_QUOTES, 'UTF-8');
        $seoCanonical = trim($__env->yieldContent('canonical', url()->current()));
        $seoOgImage = trim($__env->yieldContent('og_image', asset('static/assets/dovira_banner_transparent_full_head.png')));
        $seoOgType = trim($__env->yieldContent('og_type', 'website'));
        $seoRobots = trim($__env->yieldContent('robots', 'index, follow, max-image-preview:large, max-snippet:-1'));
        $siteContactEmail = config('site_contacts.email', 'info@mydovira.com');
        $siteContactPhone = trim((string) config('site_contacts.phone'));
        $siteSupportTelegramUsername = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $siteSupportTelegramUrl = 'https://t.me/' . $siteSupportTelegramUsername;
        $seoContactPoint = [
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'email' => $siteContactEmail,
            'url' => $siteSupportTelegramUrl,
            'availableLanguage' => 'uk',
        ];
        if ($siteContactPhone !== '') {
            $seoContactPoint['telephone'] = $siteContactPhone;
        }

        $seoSiteSchema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => url('/') . '#organization',
                    'name' => 'DOVIRA',
                    'url' => url('/'),
                    'logo' => asset('static/assets/dovira_banner_transparent_full_head.png'),
                    'description' => 'DOVIRA — українська платформа відгуків про компанії, магазини, сервіси та спеціалістів. Каталог перевірених профілів із рейтингами й реальними відгуками клієнтів.',
                    'contactPoint' => $seoContactPoint,
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => url('/') . '#website',
                    'name' => 'DOVIRA',
                    'url' => url('/'),
                    'inLanguage' => 'uk',
                    'publisher' => ['@id' => url('/') . '#organization'],
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => route('catalog') . '?q={search_term_string}',
                        ],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ];
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="robots" content="{{ $seoRobots }}">
    <link rel="canonical" href="{{ $seoCanonical }}">
    <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('static/favicon-96.png') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('static/favicon.svg') }}?v={{ @filemtime(public_path('static/favicon.svg')) }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('static/favicon-32.png') }}?v={{ @filemtime(public_path('static/favicon-32.png')) }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ @filemtime(public_path('favicon.ico')) }}" sizes="any">
    <link rel="manifest" href="{{ asset('static/site.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('static/apple-touch-icon.png') }}?v={{ @filemtime(public_path('static/apple-touch-icon.png')) }}">
    <meta property="og:site_name" content="DOVIRA">
    <meta property="og:locale" content="uk_UA">
    <meta property="og:type" content="{{ $seoOgType }}">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:image" content="{{ $seoOgImage }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoOgImage }}">
    <script type="application/ld+json">@json($seoSiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    {{-- Три базові стилі підключаємо прямими лінками з версією файлу, а не через
         @import у styles.css: @import не мав ?v, тому Cloudflare і браузери
         віддавали серпневу копію base.css місяцями (правки просто не доїжджали).
         Прямі лінки ще й вантажаться паралельно, а не ланцюжком. --}}
    @foreach (['css/base.css', 'css/layout.css', 'css/components.css'] as $baseStyle)
        <link rel="stylesheet" href="{{ asset('static/' . $baseStyle) }}?v={{ @filemtime(public_path('static/' . $baseStyle)) }}">
    @endforeach
    {{-- Self-hosted, subset FontAwesome (only the ~165 icons the site uses) —
         fonts trimmed from ~300 KB to ~32 KB, no external CDN request. --}}
    <link rel="stylesheet" href="{{ asset('static/vendor/fontawesome/css/all.min.css') }}?v={{ @filemtime(public_path('static/vendor/fontawesome/css/all.min.css')) }}">
    {{-- Livewire assets auto-injected (config.inject_assets=true) only on pages with a component, e.g. /pro/account --}}
    @stack('head')
    <link rel="stylesheet" href="{{ asset('static/css/typography.css') }}?v={{ @filemtime(public_path('static/css/typography.css')) }}">
</head>
<body class="@yield('body_class')" data-authenticated="{{ auth()->check() ? '1' : '0' }}">
    @php
        // Сторінки входу/реєстрації (page-auth) без повного хедера й футера —
        // лишаємо лише лого зверху, щоб фокус був на формі.
        $isAuthPage = str_contains((string) $__env->yieldContent('body_class'), 'page-auth');
        // На сторінці профілю тост не показуємо: там уже є свій owner-CTA
        // (банер «Це ваш бізнес?»), а плаваючий блок перекривав контент.
        // pro.account* (кабінет, оплата, аналітика, заявки) — власник уже
        // всередині, плаваючий «Створіть профіль» тут недоречний і перекривав
        // кнопку оплати. Раніше виключався лише точний pro.account.
        $showProfileToast = ! $isAuthPage
            && ! request()->routeIs('pro.account*')
            && ! request()->routeIs('profile.show')
            && ! str_starts_with(request()->path(), 'admin');
        $profileToastUrl = route('pro.account', ['tab' => 'claims']);
    @endphp
    <div class="route-progress" data-route-progress aria-hidden="true">
        <span class="route-progress__bar"></span>
    </div>
    @if ($isAuthPage)
        <header class="auth-topbar">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand__icon-wrap" aria-hidden="true">
                    <i class="fa-solid fa-shield-halved brand__icon"></i>
                </span>
                <span class="brand__text">dovira</span>
            </a>
        </header>
    @else
        @include('static.partials.header')
    @endif
    <div class="menu-overlay" data-overlay hidden></div>
    <div class="user-menu-overlay" data-user-menu-overlay></div>
    <div class="search-focus-overlay" data-search-overlay hidden></div>
    <div class="msearch" data-mobile-search hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Пошук">
        <div class="msearch__bar">
            <button type="button" class="msearch__back" data-mobile-search-close aria-label="Закрити пошук">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            </button>
            <form class="msearch__form" action="{{ route('catalog') }}" method="get" role="search" data-mobile-search-form>
                <i class="fa-solid fa-magnifying-glass msearch__icon" aria-hidden="true"></i>
                <input
                    type="search"
                    name="q"
                    class="msearch__input"
                    placeholder="Пошук компанії, спеціаліста або послуги..."
                    autocomplete="off"
                    autocapitalize="off"
                    autocorrect="off"
                    enterkeyhint="search"
                    aria-label="Пошуковий запит"
                    data-mobile-search-input
                >
                <button type="button" class="msearch__clear" data-mobile-search-clear hidden aria-label="Очистити запит">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </form>
        </div>
        <div class="msearch__results" data-mobile-search-results></div>
    </div>
    <div class="page-skeleton" data-page-skeleton hidden aria-hidden="true">
        <div class="page-skeleton__header container">
            <span class="page-skeleton__line page-skeleton__line--logo"></span>
            <span class="page-skeleton__line page-skeleton__line--nav"></span>
            <span class="page-skeleton__line page-skeleton__line--action"></span>
        </div>
        <div class="page-skeleton__body container">
            <div class="page-skeleton__hero">
                <span class="page-skeleton__line page-skeleton__line--title"></span>
                <span class="page-skeleton__line page-skeleton__line--subtitle"></span>
            </div>
            <div class="page-skeleton__cards">
                <div class="page-skeleton__card"></div>
                <div class="page-skeleton__card"></div>
                <div class="page-skeleton__card"></div>
                <div class="page-skeleton__card"></div>
            </div>
        </div>
    </div>
    @include('static.partials.review-popup')

    <main>
        @yield('content')
    </main>

    @if ($showProfileToast)
        <aside class="site-profile-toast" data-site-profile-toast hidden aria-live="polite">
            <button type="button" class="site-profile-toast__close" data-site-profile-toast-close aria-label="Закрити">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <div class="site-profile-toast__top">
                <span class="site-profile-toast__icon">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                </span>
                <span class="site-profile-toast__badge">Безкоштовно</span>
            </div>
            <strong class="site-profile-toast__title">Керуєте бізнесом?</strong>
            <p class="site-profile-toast__text">Ваш профіль може вже бути на Dovira та в Google. PRO — {{ \App\Support\ProPricing::launchCohortLabel() }}. {{ \App\Support\ProPricing::launchCohortLockLine() }}</p>
            <a class="site-profile-toast__cta" href="{{ $profileToastUrl }}">
                <span>Керувати профілем</span>
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </aside>
    @endif

    @unless ($isAuthPage)
        @include('static.partials.footer')
    @endunless

    @if ($showProfileToast)
        <script>
            (() => {
                const toast = document.querySelector('[data-site-profile-toast]');
                const storageKey = 'doviraSiteProfileToastDismissed';
                const storage = {
                    get() {
                        try {
                            return window.sessionStorage.getItem(storageKey);
                        } catch (error) {
                            return null;
                        }
                    },
                    set() {
                        try {
                            window.sessionStorage.setItem(storageKey, '1');
                        } catch (error) {
                            // Ignore storage restrictions; the toast still closes in the current view.
                        }
                    },
                };

                if (!toast || storage.get() === '1') {
                    return;
                }

                const closeButton = toast.querySelector('[data-site-profile-toast-close]');
                const hideToast = () => {
                    toast.classList.remove('is-visible');
                    storage.set();
                    window.setTimeout(() => {
                        toast.hidden = true;
                    }, 220);
                };

                window.setTimeout(() => {
                    toast.hidden = false;
                    window.requestAnimationFrame(() => toast.classList.add('is-visible'));
                }, 1400);

                // Плаваючий тост поступається місцем: щойно на екрані зʼявляється
                // той самий заклик (in-page business-CTA) або футер — ховаємо його,
                // щоб не перекривати контент і не дублювати повідомлення поруч.
                if ('IntersectionObserver' in window) {
                    const rivals = document.querySelectorAll('.business-cta, .footer');
                    if (rivals.length) {
                        const io = new IntersectionObserver((entries) => {
                            if (entries.some((entry) => entry.isIntersecting)) {
                                hideToast();
                                io.disconnect();
                            }
                        }, { threshold: 0.2 });
                        rivals.forEach((el) => io.observe(el));
                    }
                }

                window.setTimeout(hideToast, 14000);
                closeButton?.addEventListener('click', hideToast);
            })();
        </script>
    @endif

    {{-- Speculative prefetch: same-origin pages load in the background on hover/tap,
         so the click serves from cache instead of freezing until the server responds.
         (Prefetch is verified to fire; prerender was dropped — Chrome suppressed the
         working prefetch when a prerender rule was present, and prerender re-renders
         the whole page server-side on every hover.) --}}
    <script type="speculationrules">
    {
        "prefetch": [
            {
                "source": "document",
                "where": {
                    "and": [
                        { "href_matches": "/*" },
                        { "not": { "href_matches": "/admin/*" } },
                        { "not": { "href_matches": "/login" } },
                        { "not": { "href_matches": "/logout" } },
                        { "not": { "href_matches": "/register" } },
                        { "not": { "selector_matches": "[data-no-prefetch]" } },
                        { "not": { "selector_matches": "[data-open-review-popup]" } },
                        { "not": { "selector_matches": "[target=_blank]" } }
                    ]
                },
                "eagerness": "moderate"
            }
        ]
    }
    </script>
    <script type="module" src="{{ asset('static/app.js') }}?v={{ @filemtime(public_path('static/app.js')) }}"></script>
    @guest
        @if (\App\Support\Turnstile::isEnabled())
            {{-- Антиспам гостьових відгуків: віджет у попапі відгуку. --}}
            <script src="https://challenges.cloudflare.com/turnstile/api.js" async defer></script>
        @endif
    @endguest
    @stack('scripts')
@include('partials.plerdy')
</body>
</html>
