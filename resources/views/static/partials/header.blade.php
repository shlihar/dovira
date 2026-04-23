<header class="header" data-header>
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
            <a class="nav__link" href="{{ route('platform') }}">Про платформу</a>
            <span class="header_line"></span>
            <a class="nav__link" href="{{ route('catalog') }}">Каталог</a>
             <span class="header_line"></span>
            <a class="nav__link" href="{{ route('faq') }}">FAQ</a>
             <span class="header_line"></span>
            <a class="nav__link btn--primary" style="padding:5px 10px; border-radius: 12px;" href="{{ route('pro') }}">PRO акаунт</a>
	        </nav>

	        <div class="header__actions">
	            <a class="icon-btn" href="{{ route('catalog') }}" aria-label="Пошук">
	                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
	            </a>
	            <a class="icon-btn" href="{{ route('login') }}" aria-label="Увійти">
	                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
	            </a>

	            <button class="burger" type="button" aria-label="Відкрити меню" aria-expanded="false" data-burger>
	                <span class="burger__line"></span>
	                <span class="burger__line"></span>
	                <span class="burger__line"></span>
	            </button>
	        </div>
	    </div>

	    <div class="mobile" data-mobile hidden>
	        <div class="mobile__inner" role="dialog" aria-label="Меню">
	            

	            <div class="mobile__search">
	                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
	                <input type="text" placeholder="Пошук" aria-label="Пошук">
	            </div>

	            <nav class="mobile__nav" aria-label="Мобільне меню">
	                <a class="mobile__item" href="{{ route('home') }}">
	                    <i class="fa-regular fa-house" aria-hidden="true"></i>
	                    <span>Головна</span>
	                </a>
                <a class="mobile__item" href="{{ route('catalog') }}">
                    <i class="fa-regular fa-compass" aria-hidden="true"></i>
                    <span>Каталог</span>
                </a>
                <a class="mobile__item" href="{{ route('faq') }}">
                    <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                    <span>FAQ</span>
                </a>
                <a class="mobile__item" href="{{ route('platform') }}">
                    <i class="fa-regular fa-window-restore" aria-hidden="true"></i>
                    <span>Про платформу</span>
                </a>
	                <a class="mobile__item mobile__item--active" href="{{ route('pro') }}">
	                    <i class="fa-regular fa-bell" aria-hidden="true"></i>
	                    <span>PRO акаунт</span>
	                </a>
	                <a class="mobile__item" href="{{ route('login') }}">
	                    <i class="fa-regular fa-user" aria-hidden="true"></i>
	                    <span>Ваш профіль</span>
	                </a>
	            </nav>
	        </div>
	    </div>

	</header>
