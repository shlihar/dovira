@php
    /**
     * Лінк-хаб унизу сторінки профілю: посилання на посадкові «категорія ×
     * місто». Дає категорійним сторінкам внутрішню вагу з десятків тисяч
     * профілів — раніше вони отримували лише одне посилання з хлібних крихт.
     * Дані кешовані по парі «категорія × місто» (CatalogLandingSeo).
     */
    $hubCategorySlug = $profile['category_slug'] ?? null;
    $hubCityName = $profile['city'] ?? null;
    $hub = ['cities' => [], 'categories' => []];

    if ($hubCategorySlug) {
        $hubCategory = rescue(
            fn () => \App\Models\Category::query()->where('slug', $hubCategorySlug)->first(),
            null,
            false
        );
        if ($hubCategory) {
            $hub = \App\Support\CatalogLandingSeo::profileLinkHub($hubCategory, $hubCityName, 8);
        }
    }

    $hubCategoryName = $profile['category_label'] ?? null;
    $hubCitySlug = $profile['city_slug'] ?? null;
    $hasHub = ! empty($hub['cities']) || ! empty($hub['categories']);
@endphp

@if ($hasHub)
    {{-- Компактно й текстом (не картка з пілюлями): лінки лишаються видимими
         і краулабельними — ховати їх (display:none) не можна, Google знецінює
         приховані посилання. Стиль — як «Див. також» у довідниках. --}}
    <nav class="profile-seo-links" aria-label="Схожі добірки">
        @if (! empty($hub['cities']) && $hubCategoryName)
            <p class="profile-seo-links__row">
                <span class="profile-seo-links__label">{{ $hubCategoryName }} в інших містах:</span>
                @foreach ($hub['cities'] as $hubCity)
                    <a href="{{ route('catalog.landing.city', ['category' => $hubCategorySlug, 'city' => $hubCity['slug']]) }}">{{ $hubCity['name'] }}</a>@if (! $loop->last)<span class="profile-seo-links__sep">·</span>@endif
                @endforeach
            </p>
        @endif
        @if (! empty($hub['categories']) && $hubCityName && $hubCitySlug)
            <p class="profile-seo-links__row">
                <span class="profile-seo-links__label">Інші послуги — {{ $hubCityName }}:</span>
                @foreach ($hub['categories'] as $hubOtherCategory)
                    <a href="{{ route('catalog.landing.city', ['category' => $hubOtherCategory['slug'], 'city' => $hubCitySlug]) }}">{{ $hubOtherCategory['name'] }}</a>@if (! $loop->last)<span class="profile-seo-links__sep">·</span>@endif
                @endforeach
            </p>
        @endif
        @if ($hubCategorySlug && $hubCategoryName)
            <p class="profile-seo-links__row">
                <a href="{{ route('catalog.landing', ['category' => $hubCategorySlug]) }}">Усі {{ mb_strtolower($hubCategoryName) }} України</a>
                <span class="profile-seo-links__sep">·</span>
                <a href="{{ route('catalog') }}">Каталог компаній</a>
            </p>
        @endif
    </nav>
@endif
