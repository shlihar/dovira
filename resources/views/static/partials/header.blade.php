<header class="header" data-header>
    @php
        // Гість спершу бачить лендінг з цінністю і тарифами, авторизований — кабінет.
        $headerProUrl = auth()->check() ? route('pro.account') : route('pro');
        $headerProLabel = auth()->check() ? 'PRO кабінет' : 'DOVIRA PRO';
        $headerSupportTelegram = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $headerSupportUrl = 'https://t.me/' . $headerSupportTelegram;
        $headerContactsUrl = route('platform') . '#contacts';
    @endphp
    <div class="container header__inner">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand__icon-wrap" aria-hidden="true">
                <i class="fa-solid fa-shield-halved brand__icon"></i>
            </span>
            <span class="brand__text">dovira</span>
        </a>

    
        <nav class="nav" aria-label="Головне меню" data-nav>
            <a class="nav__link" href="{{ route('home') }}">Головна</a>
            <span class="header_line"></span>
            <a class="nav__link" href="{{ route('platform') }}">Про нас</a>
            <span class="header_line"></span>
            <a class="nav__link" href="{{ route('catalog') }}">Каталог</a>
            <span class="header_line"></span>
            <a class="nav__link" href="{{ route('pro') }}">Для бізнесу</a>
            <span class="header_line"></span>
            <a class="nav__link" href="{{ route('blog') }}">Блог</a>

            <a class="nav__link" href="{{ route('faq') }}">FAQ</a>
            <span class="header_line"></span>
            {{-- «Написати відгук» замість PRO-кнопки: синій = головна ДІЯ
                 користувача (наповнює базу). PRO лишається в «Для бізнесу»,
                 бізнес-CTA головної, user-menu і футері. --}}
            <a class="nav__link btn--primary" style="padding:5px 10px; border-radius: 12px;" href="{{ route('catalog') }}" data-open-review-popup>Написати відгук</a>
	        </nav>

	        <div class="header__actions">
                <div class="header-search" data-header-search>
                    <form
                        class="header-search__form"
                        action="{{ route('catalog') }}"
                        method="get"
                        aria-label="Пошук по каталогу"
                        data-search-form
                        data-search-open-on-input
                    >
                        <div class="header-search__field">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input
                                type="search"
                                name="q"
                                placeholder="Пошук компанії, спеціаліста або послуги..."
                                autocomplete="off"
                                aria-label="Пошуковий запит"
                            >
                        </div>
                        <button class="header-search__trigger" type="submit" aria-label="Відкрити пошук" data-header-search-trigger>
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        </button>
                        <div class="hero__search-suggest header-search__suggest" data-search-suggest hidden>
                            <p class="hero__search-suggest-title">Популярні запити</p>
                        </div>
                    </form>
                </div>
                <a class="icon-btn header-fav" href="{{ route('favorites.index') }}" aria-label="Обране">
                    <i class="fa-regular fa-bookmark" aria-hidden="true"></i>
                    <span class="account-nav__badge header-fav__badge" data-favorites-count hidden>0</span>
                </a>
                <button class="icon-btn header__msearch-trigger" type="button" data-mobile-search-open aria-label="Пошук">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
                @auth
                    @php
                        $currentUser = auth()->user();
                        // Лічильник на аватарці: непрочитані сповіщення (без дубля
                        // заявок) + непрочитані заявки клієнтів по профілях власника.
                        $notificationCount = $currentUser
                            ->platformNotifications()
                            ->whereNull('read_at')
                            ->where('type', '!=', 'pro_lead_new')
                            ->count();
                        if (\Illuminate\Support\Facades\Schema::hasTable('profile_leads')) {
                            $notificationCount += (int) rescue(fn () => \App\Models\ProfileLead::query()
                                ->whereIn('profile_id', $currentUser->ownedProfiles()->select('id'))
                                ->where('is_read', false)
                                ->count(), 0, false);
                        }
                        $proWorkspaceUrl = route('pro.account');
                        $avatarUrl = \App\Support\MediaUrl::thumbUrl($currentUser->avatar_url, 96);
                        $avatarSource = $avatarUrl !== null ? 'user' : null;

                        if ($avatarUrl === null) {
                            $profileLogo = $currentUser->ownedProfiles()
                                ->whereNotNull('logo_url')
                                ->where('logo_url', '<>', '')
                                ->orderByDesc('is_pro')
                                ->orderBy('name')
                                ->first();

                            $avatarUrl = $profileLogo
                                ? \App\Support\MediaUrl::profileLogoUrl($profileLogo, 96)
                                : null;
                            $avatarSource = $avatarUrl !== null ? 'profile-logo' : null;
                        }

                        if ($avatarUrl === null) {
                            $avatarUrl = \App\Support\MediaUrl::avatarUrl(null, $currentUser->name ?: $currentUser->email, 96);
                            $avatarSource = $avatarUrl !== null ? 'user' : null;
                        }

                        $avatarImageClass = $avatarSource === 'profile-logo'
                            ? 'user-menu__avatar user-menu__avatar--logo'
                            : 'user-menu__avatar';
                        $profileAvatarShellClass = $avatarSource === 'profile-logo'
                            ? 'user-menu__profile-avatar user-menu__profile-avatar--logo'
                            : 'user-menu__profile-avatar';
                    @endphp
                    <div class="user-menu" data-user-menu>
                        <button class="icon-btn user-menu__toggle" type="button" aria-label="Профіль" aria-expanded="false" data-user-menu-toggle>
                            @if ($avatarUrl)
                                <img src="{{ $avatarUrl }}" alt="{{ $currentUser->name }}" class="{{ $avatarImageClass }}">
                            @else
                                <i class="fa-regular fa-user" aria-hidden="true"></i>
                            @endif
                            @if ($notificationCount > 0)
                                <span class="account-nav__badge user-menu__notify-badge">{{ min($notificationCount, 99) }}</span>
                            @endif
                        </button>

                        <div class="user-menu__dropdown" data-user-menu-dropdown hidden>
                            <a class="user-menu__profile" href="{{ route('profile.edit') }}">
                                <span class="{{ $profileAvatarShellClass }}">
                                    @if ($avatarUrl)
                                        <img src="{{ $avatarUrl }}" alt="{{ $currentUser->name }}" class="{{ $avatarImageClass }}">
                                    @else
                                        <i class="fa-regular fa-user" aria-hidden="true"></i>
                                    @endif
                                </span>
                                <span class="user-menu__profile-text">
                                    <strong>{{ $currentUser->name }}</strong>
                                    <small>{{ $currentUser->email }}</small>
                                </span>
                                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </a>

                            <div class="user-menu__section">
                                <a href="{{ route('profile.edit') }}">Налаштування профілю</a>
                                <a href="{{ $proWorkspaceUrl }}">PRO кабінет</a>
                                <a href="{{ $headerContactsUrl }}">Контакти</a>
                                <a href="{{ $headerSupportUrl }}" target="_blank" rel="noopener noreferrer">Підтримка Telegram</a>
                            </div>

                            <div class="user-menu__section">
                                <form method="POST" action="{{ route('logout') }}" class="user-menu__logout-form">
                                    @csrf
                                    <button type="submit" class="user-menu__logout-btn">Вийти</button>
                                </form>
                            </div>

                            <div class="user-menu__pro">
                                <p class="user-menu__pro-title">Отримайте більше з PRO</p>
                                <p class="user-menu__pro-text">Пріоритет у каталозі, публічні відповіді та інструменти репутації.</p>
                                <a class="user-menu__pro-btn" href="{{ $proWorkspaceUrl }}">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                    <span>Відкрити PRO кабінет</span>
                                </a>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Текстове «Увійти» замість іконки-дверей: зрозуміліше
                         з першого погляду (як у мокапі hero). --}}
                    <a class="header-login" href="{{ route('login') }}">Увійти</a>
                @endauth
	            <button class="burger" type="button" aria-label="Відкрити меню" aria-expanded="false" data-burger>
	                <span class="burger__line"></span>
	                <span class="burger__line"></span>
	                <span class="burger__line"></span>
	            </button>
	        </div>
	    </div>
	    <div class="mobile" data-mobile hidden>
	        <div class="mobile__inner" role="dialog" aria-label="Меню">
                <div class="mobile__top">
                    <a class="mobile__brand" href="{{ route('home') }}" aria-label="DOVIRA">
                        <span class="brand__icon-wrap" aria-hidden="true">
                            <i class="fa-solid fa-shield-halved brand__icon"></i>
                        </span>
                        <span>dovira</span>
                    </a>
                    <button class="mobile__close" type="button" aria-label="Закрити меню" data-close>
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <a class="mobile__cta" href="#" data-open-review-popup>
                    <span class="mobile__cta-icon">
                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                    </span>
                    <span>
                        <strong>Написати відгук</strong>
                        <small>Поділіться досвідом за хвилину</small>
                    </span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>

	            <nav class="mobile__nav" aria-label="Мобільне меню">
	                <a class="mobile__item" href="{{ route('home') }}">
	                    <i class="fa-solid fa-house" aria-hidden="true"></i>
	                    <span>Головна</span>
	                </a>
                <a class="mobile__item" href="{{ route('catalog') }}">
                    <i class="fa-regular fa-compass" aria-hidden="true"></i>
                    <span>Каталог</span>
                </a>
                <a class="mobile__item" href="{{ route('platform') }}">
                    <i class="fa-regular fa-window-restore" aria-hidden="true"></i>
                    <span>Про нас</span>
                </a>
                <a class="mobile__item" href="{{ route('pro') }}">
                    <i class="fa-regular fa-star" aria-hidden="true"></i>
                    <span>Для бізнесу</span>
                </a>
	                <a class="mobile__item mobile__item--active" href="{{ $headerProUrl }}">
	                    <i class="fa-regular fa-gem" aria-hidden="true"></i>
	                    <span>{{ $headerProLabel }}</span>
	                </a>
                <a class="mobile__item" href="{{ route('blog') }}">
                    <i class="fa-regular fa-newspaper" aria-hidden="true"></i>
                    <span>Блог</span>
                </a>
                <a class="mobile__item" href="{{ route('faq') }}">
                    <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                    <span>FAQ</span>
                </a>
                <a class="mobile__item" href="{{ $headerSupportUrl }}" target="_blank" rel="noopener noreferrer">
                    <i class="fa-brands fa-telegram" aria-hidden="true"></i>
                    <span>Підтримка</span>
                </a>
                    @auth
                        <a class="mobile__item" href="{{ route('profile.edit') }}">
                            <i class="fa-regular fa-user" aria-hidden="true"></i>
                            <span>Кабінет</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="mobile__logout-form">
                            @csrf
                            <button class="mobile__item mobile__item--button" type="submit">
                                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                                <span>Вийти</span>
                            </button>
                        </form>
                    @else
                        <a class="mobile__item" href="{{ route('login') }}">
                            <i class="fa-regular fa-user" aria-hidden="true"></i>
                            <span>Увійти</span>
                        </a>
                    @endauth
	            </nav>

                <div class="mobile__note">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <span>Публічні відгуки, перевірені профілі та прозора репутація.</span>
                </div>
	        </div>
	    </div>
</header>
