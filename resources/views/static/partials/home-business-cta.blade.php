{{-- Бізнес-CTA одразу після лідерборда: власник щойно подивився рейтинг
     і думає «а де тут я?». Меседж — не «створіть профіль», а «знайдіть
     себе»: більшість профілів вже імпортовані, відгуки вже йдуть.
     Пошук — живий claim-сагест (scope=claim_profiles), клік по підказці
     веде одразу в привʼязку профілю в кабінеті. --}}
<section class="section business-cta" aria-labelledby="business-cta-title">
    <div class="container">
        <div class="business-cta__band">
            {{-- Примарний рядок рейтингу — «ваше порожнє місце» (desktop). --}}
            <div class="business-cta__ghost" aria-hidden="true">
                <span class="business-cta__ghost-rank">06</span>
                <span class="business-cta__ghost-logo"><i class="fa-regular fa-building"></i></span>
                <span class="business-cta__ghost-text">
                    <b>Тут може бути ваш бізнес</b>
                    <span>рейтинг чекає на ваші відгуки</span>
                </span>
            </div>

            <div class="business-cta__main">
                <h2 id="business-cta-title" class="business-cta__title">Ваш бізнес, ймовірно, вже на Dovira</h2>
                <p class="business-cta__sub">Клієнти читають відгуки про вас просто зараз. Знайдіть свій профіль, підтвердьте права безкоштовно — а PRO відкриє відповіді від імені компанії, контакти клієнтів і керування сторінкою.</p>

                <form
                    class="business-cta__search"
                    action="{{ route('pro') }}"
                    method="get"
                    aria-label="Пошук свого бізнесу"
                    data-search-form
                    data-search-mobile-app
                    data-search-scope="claim_profiles"
                >
                    <i class="fa-solid fa-magnifying-glass business-cta__search-icon" aria-hidden="true"></i>
                    <input type="text" name="q" placeholder="Назва вашої компанії..." autocomplete="off">
                    <button type="submit">Знайти</button>
                    <div class="hero__search-suggest" data-search-suggest hidden>
                        <p class="hero__search-suggest-title">Знайдіть свою компанію</p>
                    </div>
                </form>

                <ul class="business-cta__proofs" role="list">
                    <li><i class="fa-solid fa-reply" aria-hidden="true"></i>Офіційні відповіді на відгуки</li>
                    <li><i class="fa-solid fa-chart-simple" aria-hidden="true"></i>Статистика переглядів і звернень</li>
                    <li><i class="fa-brands fa-google" aria-hidden="true"></i>Профіль індексується в Google</li>
                </ul>

                <p class="business-cta__alt">Не знайшли свій бізнес? <a href="{{ auth()->check() ? route('pro.account', ['tab' => 'claims']) : route('pro') }}">Створити профіль →</a></p>
            </div>
        </div>
    </div>
</section>
