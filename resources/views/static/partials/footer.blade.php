<footer class="footer">
    @php
        $footerContactEmail = config('site_contacts.email', 'info@mydovira.com');
        $footerContactPhone = config('site_contacts.phone', '+380937269578');
        $footerContactPhoneHref = '+' . preg_replace('/\D+/', '', $footerContactPhone);
        $footerSupportTelegram = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $footerSupportUrl = 'https://t.me/' . $footerSupportTelegram;
    @endphp
    <div class="container footer__inner">
        <div class="footer__brand">
            <p class="footer__mobile-title">DOVIRA для професійної репутації</p>
            <a class="footer__logo-link" href="{{ route('home') }}" aria-label="DOVIRA">
                <span class="brand" aria-hidden="true">
                    <span class="brand__icon-wrap">
                        <i class="fa-solid fa-shield-halved brand__icon"></i>
                    </span>
                    <span class="brand__text">dovira</span>
                </span>
            </a>
            <p class="footer__text">Платформа чесних відгуків про компанії, магазини, сервіси та спеціалістів.</p>
        </div>

        <div class="footer__nav-groups">
            <nav class="footer__nav" aria-label="Платформа">
                <p class="footer__nav-title">Платформа</p>
                <a class="footer__link" href="{{ route('platform') }}">Про нас</a>
                <a class="footer__link" href="{{ route('trust') }}">Чому нам довіряти</a>
                <a class="footer__link" href="{{ route('rating.method') }}">Як формується рейтинг</a>
                <a class="footer__link" href="{{ route('catalog') }}">Каталог</a>
                <a class="footer__link" href="{{ route('blog') }}">Блог</a>
            </nav>
            <nav class="footer__nav" aria-label="Допомога">
                <p class="footer__nav-title">Допомога</p>
                <a class="footer__link" href="{{ route('faq') }}">FAQ</a>
                <a class="footer__link" href="{{ route('platform') }}#contacts">Контакти</a>
                <a class="footer__link" href="{{ $footerSupportUrl }}" target="_blank" rel="noopener noreferrer">Підтримка Telegram</a>
                <a class="footer__link" href="mailto:{{ $footerContactEmail }}">{{ $footerContactEmail }}</a>
                <a class="footer__link" href="tel:{{ $footerContactPhoneHref }}">{{ $footerContactPhone }}</a>
                <a class="footer__link" href="{{ route('privacy') }}">Політика конфіденційності</a>
                <a class="footer__link" href="{{ route('terms') }}">Умови використання</a>
            </nav>
        </div>

        <div class="footer__socials" aria-label="Соцмережі">
            <a class="footer__social" href="{{ $footerSupportUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram підтримка"><i class="fa-brands fa-telegram" aria-hidden="true"></i></a>
            <a class="footer__social" href="mailto:{{ $footerContactEmail }}" aria-label="Email підтримка"><i class="fa-solid fa-envelope" aria-hidden="true"></i></a>
            <a class="footer__social" href="tel:{{ $footerContactPhoneHref }}" aria-label="Телефон підтримки"><i class="fa-solid fa-phone" aria-hidden="true"></i></a>
        </div>
    </div>

    <div class="container footer__bottom">
        <div class="footer__copy">© {{ date('Y') }} DOVIRA. Всі права захищені.</div>
        <div class="footer__unsubscribe">Бажаєте менше листів? Керуйте сповіщеннями в кабінеті.</div>
    </div>
</footer>
