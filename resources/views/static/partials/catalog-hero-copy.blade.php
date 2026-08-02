@php
    // Шапка каталогу: якщо активна одна (під)категорія — її H1/крихти/опис,
    // інакше — загальні каталогу. Рендериться і на сервері, і в ajax-відповіді,
    // тож при зміні фільтра заголовок оновлюється разом із результатами.
    $hc = $heroCategory ?? null;
    $hcCity = $heroCityName ?? null;
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
    <h1>{{ $hc->name }}{{ $hcCity ? ' — ' . $hcCity : '' }}</h1>
    <p>Реальні відгуки, рейтинги й перевірені профілі в категорії «{{ $hc->name }}»{{ $hcCity ? ' у місті ' . $hcCity : '' }}.</p>
@else
    <p class="catalog-hero__eyebrow">DOVIRA каталог</p>
    <h1>Каталог компаній і спеціалістів</h1>
    <p>Знайдіть перевірених спеціалістів, компанії та послуги з реальними відгуками.</p>
@endif
