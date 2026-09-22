@php
    // Лінк-хаб головної на SEO-посадкові «категорія × місто»: вага головної
    // тече на сторінки-джерела пошукового трафіку. Дані кешовані 12h.
    $homeLandings = rescue(fn () => \App\Support\CatalogLandingSeo::popularLandings(24), [], false);
@endphp

@if (! empty($homeLandings))
    <section class="section home-landing-links" aria-labelledby="home-landing-links-title">
        <div class="container">
            <div class="home-landing-links__head">
                <h2 id="home-landing-links-title" class="home-landing-links__title">Популярні рейтинги за містами</h2>
                <p class="home-landing-links__sub">Найбільші добірки компаній і спеціалістів з реальними відгуками клієнтів</p>
            </div>
            <nav class="home-landing-links__list" aria-label="Рейтинги за містами">
                @foreach ($homeLandings as $homeLanding)
                    <a class="home-landing-links__item" href="{{ route('catalog.landing.city', ['category' => $homeLanding['category_slug'], 'city' => $homeLanding['city_slug']]) }}">
                        <span>{{ $homeLanding['label'] }}</span>
                        <small>{{ $homeLanding['count'] }}</small>
                    </a>
                @endforeach
                <a class="home-landing-links__item home-landing-links__item--all" href="{{ route('catalog') }}">
                    <span>Весь каталог →</span>
                </a>
            </nav>
        </div>
    </section>
@endif
