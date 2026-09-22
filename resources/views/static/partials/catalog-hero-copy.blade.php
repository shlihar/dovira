@php
    // Шапка каталогу: якщо активна одна (під)категорія — її H1/крихти/опис,
    // інакше — загальні каталогу. Рендериться і на сервері, і в ajax-відповіді,
    // тож при зміні фільтра заголовок оновлюється разом із результатами.
    $hc = $heroCategory ?? null;
    $hcCity = $heroCityName ?? null;
    // Дата-факти посадкової (CatalogLandingSeo): числа роблять кожну сторінку
    // «категорія × місто» унікальною без AI-текстів.
    $hcSeo = $heroLandingSeo ?? null;
    $hcCityLocative = $hcCity ? \App\Support\RegionCityDirectory::cityLocative($hcCity) : null;
    $hcHeading = $hc
        ? $hc->name . ($hcCityLocative ? ' ' . $hcCityLocative : ' в Україні')
        : null;
    $hcProfiles = (int) ($hcSeo['profiles_count'] ?? 0);
    $hcReviews = (int) ($hcSeo['reviews_count'] ?? 0);
    $hcAvg = (float) ($hcSeo['avg_rating'] ?? 0);
    $hcVerified = (int) ($hcSeo['owner_verified_count'] ?? 0);
    $hcUpdated = ! empty($hcSeo['updated_at'])
        ? \Illuminate\Support\Carbon::parse($hcSeo['updated_at'])->format('d.m.Y')
        : null;
    $hcNum = fn (int $n): string => number_format($n, 0, '.', ' ');
@endphp

@if ($hc)
    <nav class="catalog-hero__breadcrumbs" aria-label="Хлібні крихти">
        <a href="{{ route('catalog') }}">Каталог</a>
        <span aria-hidden="true">›</span>
        @if ($hcCity)
            <a href="{{ route('catalog.landing', ['category' => $hc->slug]) }}">{{ $hc->name }}</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">{{ $hcCity }}</span>
        @else
            <span aria-current="page">{{ $hc->name }}</span>
        @endif
    </nav>
    <h1>{{ $hcHeading }}</h1>
    @if ($hcProfiles > 0)
        @php
            // Lead-абзац із фактами (AI-SEO: конкретика в перших рядках сторінки).
            $hcLead = ($hcCityLocative ? \Illuminate\Support\Str::ucfirst($hcCityLocative) : 'В Україні')
                . ' на DOVIRA — ' . $hcNum($hcProfiles) . ' ' . \App\Support\Plural::uk($hcProfiles, 'профіль', 'профілі', 'профілів')
                . ' у категорії «' . $hc->name . '»';
            if ($hcReviews > 0) {
                $hcLead .= ', ' . $hcNum($hcReviews) . ' ' . \App\Support\Plural::uk($hcReviews, 'відгук', 'відгуки', 'відгуків') . ' клієнтів';
                if ($hcAvg > 0) {
                    $hcLead .= ', середній рейтинг — ' . number_format($hcAvg, 1) . ' з 5';
                }
            }
            if ($hcVerified > 0) {
                $hcLead .= '; ' . $hcNum($hcVerified) . ' ' . \App\Support\Plural::uk($hcVerified, 'профіль підтверджено', 'профілі підтверджено', 'профілів підтверджено') . ' власниками';
            }
            $hcLead .= '.';
        @endphp
        <p>{{ $hcLead }} Порівнюйте рейтинги, читайте реальні відгуки та обирайте перевіреного виконавця.</p>
        @if ($hcUpdated)
            <p class="catalog-hero__updated">Дані оновлено {{ $hcUpdated }}</p>
        @endif
    @else
        <p>Реальні відгуки, рейтинги й перевірені профілі в категорії «{{ $hc->name }}»{{ $hcCity ? ' у місті ' . $hcCity : '' }}.</p>
    @endif
@else
    <p class="catalog-hero__eyebrow">DOVIRA каталог</p>
    <h1>Каталог компаній і спеціалістів</h1>
    <p>Знайдіть перевірених спеціалістів, компанії та послуги з реальними відгуками.</p>
@endif
