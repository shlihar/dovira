{{--
    Єдина ієрархія бейджів довіри. Показуємо НАЙВИЩИЙ застосовний рівень:
      1. «Керує власник»  — owner_verified: профіль підтвердив і веде власник бізнесу
                            (найвища довіра; передбачає, що бізнес реальний)
      2. «Бізнес підтверджено» — verified: платформа перевірила, що компанія існує,
                            але активного власника ще немає
    Параметри:
      $verified       (bool)   — is_verified (платформенна перевірка)
      $ownerVerified  (bool)   — похідний owner_verified (власник керує)
      $modifier       (string) — доп. клас, напр. 'owner-verified-badge--hero'
      $tooltip        (bool)   — показувати ховер-тултип із поясненням. На каталог-
                                 картках вимикаємо: бейдж дрібний, біля краю, і тултип
                                 вилазить за межі картки + «застрягає» на мобільному тапі.
                                 Пояснення лишається на сторінці профілю (hero).
--}}
@php
    $modifier = $modifier ?? '';
    $tooltip = $tooltip ?? true;

    if (!empty($ownerVerified)) {
        $tier = 'owner';
        $tierClass = '';
        $tierAria = 'Керує підтверджений власник';
        $tipTitle = 'Керує власник';
        $tipBody = 'Профіль веде підтверджений представник компанії';
    } elseif (!empty($verified)) {
        $tier = 'business';
        $tierClass = 'owner-verified-badge--business';
        $tierAria = 'Бізнес підтверджено платформою';
        $tipTitle = 'Бізнес підтверджено';
        $tipBody = 'Ми перевірили, що ця компанія реальна';
    } else {
        $tier = null;
    }
@endphp

@if ($tier)
    <span class="owner-verified-badge {{ $tierClass }} {{ $modifier }}" @if ($tooltip) tabindex="0" @endif aria-label="{{ $tierAria }}">
        <img src="{{ asset('static/assets/icons/owner-verified-badge-2.svg') }}" alt="" aria-hidden="true">
        @if ($tooltip)
            <span class="owner-verified-badge__tip" role="tooltip"><b>{{ $tipTitle }}</b><br>{{ $tipBody }}</span>
        @endif
    </span>
@endif
