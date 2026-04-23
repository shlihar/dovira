<footer class="footer">
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

        <nav class="footer__nav" aria-label="Посилання футера">
            <a class="footer__link" href="{{ route('platform') }}">Про платформу</a>
            <a class="footer__link" href="{{ route('faq') }}">FAQ</a>
            <a class="footer__link" href="{{ route('catalog') }}">Відгуки</a>
            <a class="footer__link" href="#">Контакти</a>
            <a class="footer__link" href="#">Політика конфіденційності</a>
        </nav>

        <div class="footer__socials" aria-label="Соцмережі">
            <a class="footer__social" href="#" aria-label="Telegram"><i class="fa-brands fa-telegram" aria-hidden="true"></i></a>
            <a class="footer__social" href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>
            <a class="footer__social" href="#" aria-label="Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>
        </div>
    </div>

    <div class="container footer__bottom">
        <div class="footer__copy">© {{ date('Y') }} DOVIRA. Всі права захищені.</div>
        <div class="footer__unsubscribe">Бажаєте менше листів? Керуйте сповіщеннями в кабінеті.</div>
    </div>
</footer>
