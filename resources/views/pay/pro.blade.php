@extends('static.layout')

@section('title', $product['name'] . ' — оплата')
@section('description', 'Оплата PRO-підписки DOVIRA через monopay.')
@section('robots', 'noindex, nofollow')

@push('head')
<style>
    /* Фокус-сторінка в одній мові з підтвердженням профілю (claim-verify):
       хедер сайту fixed, тому відступаємо на --header-h і центруємось. */
    .pay-wrap { display: flex; flex-direction: column; align-items: center; justify-content: center; box-sizing: border-box; min-height: 100dvh; padding: calc(var(--header-h, 76px) + 28px) 16px 56px; background: linear-gradient(180deg, #f4f7fc 0%, #fbfcfe 55%, #f6f8fc 100%); }
    .pay-card { width: 100%; max-width: 560px; overflow: hidden; background: var(--c-surface, #fff); border: 1px solid var(--c-border, rgba(15, 23, 42, .08)); border-radius: 26px; box-shadow: 0 24px 64px rgba(23, 42, 90, .10); }
    .pay-card__inner { padding: 30px 32px 30px; }

    .pay-profile { display: flex; align-items: center; gap: 14px; padding: 15px 18px; margin: 0 0 22px; background: #f6f8fc; border: 1px solid var(--c-border, rgba(15, 23, 42, .07)); border-radius: 16px; }
    .pay-profile__logo { flex: 0 0 auto; width: 52px; height: 52px; border-radius: 13px; object-fit: cover; background: #fff; border: 1px solid rgba(15, 23, 42, .08); }
    .pay-profile__logo--initial { display: inline-flex; align-items: center; justify-content: center; background: #e8f0ff; color: var(--c-primary, #2563eb); font-size: 21px; font-weight: 800; }
    .pay-profile__name { margin: 0; color: var(--c-heading, #1e293b); font-size: 16px; font-weight: 750; line-height: 1.3; }
    .pay-profile__meta { margin: 3px 0 0; color: var(--c-muted, #64748b); font-size: 13.5px; }

    /* Ціновий блок — єдине місце, де живуть ціна, знижка й умови когорти. */
    .pay-hero { position: relative; overflow: hidden; padding: 22px 24px 20px; margin: 0 0 22px; border-radius: 18px; color: #fff; background: linear-gradient(150deg, #2f6df6 0%, #1d4ed8 58%, #16309e 100%); }
    .pay-hero::after { content: ""; position: absolute; right: -46px; top: -66px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255, 255, 255, .10); pointer-events: none; }
    .pay-hero__badge { position: relative; display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px 6px 9px; border-radius: 999px; background: rgba(255, 255, 255, .16); font-size: 12px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
    .pay-hero__badge svg { width: 13px; height: 13px; }
    /* Явний білий: глобальна типографіка фарбує h1/b у --c-heading. */
    .pay-hero__title { position: relative; margin: 13px 0 0; color: #fff; font-size: 19px; font-weight: 800; line-height: 1.25; letter-spacing: -.01em; }
    .pay-hero__price { position: relative; display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px; margin: 12px 0 0; }
    .pay-hero__price b { color: #fff; font-size: 40px; font-weight: 850; line-height: 1; letter-spacing: -.02em; }
    .pay-hero__price s { color: rgba(255, 255, 255, .65); font-size: 17px; font-weight: 650; }
    .pay-hero__per { color: rgba(255, 255, 255, .85); font-size: 17px; font-weight: 650; }
    .pay-hero__price strong { display: inline-flex; align-items: center; min-height: 25px; padding: 0 10px; border-radius: 999px; background: rgba(34, 197, 94, .25); color: #dcfce7; font-size: 13px; font-weight: 800; }
    .pay-hero__period { position: relative; margin: 8px 0 0; color: rgba(255, 255, 255, .92); font-size: 14px; font-weight: 600; }

    .pay-features { display: grid; gap: 12px; margin: 0 0 22px; padding: 0; list-style: none; }
    .pay-features li { display: flex; align-items: center; gap: 12px; color: var(--c-heading, #1e293b); font-size: 15px; font-weight: 600; line-height: 1.4; }
    .pay-features li .pay-check { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 27px; height: 27px; border-radius: 9px; background: #e8f0ff; color: var(--c-primary, #2563eb); }
    .pay-features li .pay-check svg { width: 14px; height: 14px; }

    .pay-summary { display: grid; gap: 11px; margin: 0 0 22px; padding: 16px 18px; background: #f6f8fc; border: 1px solid var(--c-border, rgba(15, 23, 42, .07)); border-radius: 16px; }
    .pay-summary__row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; color: var(--c-muted, #64748b); font-size: 14px; }
    .pay-summary__row strong { color: var(--c-heading, #1e293b); font-weight: 700; text-align: right; }
    .pay-summary__row--total { padding-top: 11px; border-top: 1px solid var(--c-border, rgba(15, 23, 42, .09)); font-size: 15px; }
    .pay-summary__row--total strong { font-size: 17px; font-weight: 800; }

    .pay-submit { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; box-sizing: border-box; min-height: 56px; padding: 0 20px; border: 0; border-radius: 15px; background: var(--c-primary, #2563eb); color: #fff; font: inherit; font-size: 16.5px; font-weight: 780; letter-spacing: .01em; cursor: pointer; box-shadow: 0 12px 26px rgba(37, 99, 235, .3); transition: transform .12s ease, box-shadow .15s ease, background .15s ease; }
    .pay-submit:hover { background: var(--c-primary-dark, #1d4ed8); box-shadow: 0 14px 30px rgba(37, 99, 235, .38); }
    .pay-submit:active { transform: translateY(1px); }
    .pay-submit:focus-visible { outline: 3px solid rgba(37, 99, 235, .4); outline-offset: 3px; }
    .pay-submit[disabled] { cursor: wait; opacity: .75; box-shadow: none; }
    .pay-submit svg { width: 17px; height: 17px; }

    .pay-accepted { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 15px 0 0; color: var(--c-muted, #64748b); font-size: 13px; font-weight: 600; }
    .pay-accepted__logos { display: inline-flex; align-items: center; gap: 9px; }
    .pay-accepted__logos img { display: block; height: 21px; width: auto; object-fit: contain; opacity: .9; }

    .pay-trust { display: flex; align-items: center; justify-content: center; gap: 7px; margin: 14px 0 0; color: #16a34a; font-size: 13.5px; font-weight: 700; }
    .pay-trust svg { width: 14px; height: 14px; }

    .pay-note { margin: 10px 0 0; font-size: 13px; color: var(--c-muted, #64748b); line-height: 1.5; text-align: center; }

    .pay-flash { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 18px; padding: 13px 15px; background: #eff6ff; border: 1px solid rgba(37, 99, 235, .25); border-radius: 13px; color: #1e40af; font-size: 14px; line-height: 1.5; }
    .pay-flash svg { flex: 0 0 auto; width: 16px; height: 16px; margin-top: 2px; }
    .pay-inline-error { display: none; margin: 13px 0 0; padding: 12px 14px; border: 1px solid rgba(225, 29, 72, .24); border-radius: 13px; background: #fff1f2; color: #be123c; font-size: 14px; line-height: 1.45; text-align: center; }
    .pay-inline-error.is-visible { display: block; }

    @media (max-width: 480px) {
        .pay-wrap { justify-content: flex-start; padding-top: calc(var(--header-h, 76px) + 16px); }
        .pay-card { border-radius: 20px; }
        .pay-card__inner { padding: 22px 18px 22px; }
        .pay-hero { padding: 19px 18px 17px; }
        .pay-hero__price b { font-size: 34px; }
    }
</style>
@endpush

@section('content')
<div class="pay-wrap">
    @php
        $payFlash = [
            'profile-claimed' => 'Профіль підтверджено. Тепер оформіть підписку, щоб відкрити керування сторінкою.',
            'claim-submitted' => 'Заявку на перевірку прав прийнято. Після підтвердження підтримка надішле пряме посилання на оплату.',
            'claim-updated' => 'Заявку на перевірку прав оновлено. Після підтвердження підтримка надішле пряме посилання на оплату.',
            'pro-edit-requires-pro' => 'Редагування профілю доступне лише з PRO-підпискою. Активуйте її, щоб зберігати зміни.',
            'pro-billing-paused' => (string) config('payments.checkout.paused_message'),
            'pro-billing-provider-unavailable' => 'Оплата monopay тимчасово недоступна: платіжний ключ не налаштований на сервері.',
            'pro-billing-provider-error' => 'monopay не створив платіж. Спробуйте ще раз або зверніться в підтримку.',
            'pro-billing-invalid-amount' => 'Сума PRO-підписки налаштована некоректно. Зверніться в підтримку.',
        ][session('status')] ?? null;

        // Порядок переваг = позиціонування (спільне з лендінгом /pro):
        // місце у видачі → відповіді → сторінка → заявки → захист трафіку.
        // Телефон/сайт видимі всім, аналітика безкоштовна після підтвердження —
        // їх як PRO не продаємо.
        $payFeatures = [
            'Верхні позиції у видачі категорії — вище за всі безплатні профілі у звичайному списку й у «Популярних», з позначкою PRO',
            'Офіційні відповіді на відгуки — ваш голос поруч із кожним відгуком, включно з негативними',
            'Повне керування сторінкою — послуги, ціни, фото, FAQ і досьє',
            'Блок кейсів — до 10 історій «запит клієнта → результат» із підтвердженням відгуком',
            'Контакти клієнтів із заявок — телефон та email тих, хто звернувся',
            'Без конкурентів на вашій сторінці — клієнт не піде до «схожих профілів»',
        ];
        $pendingOwnershipPayment = (bool) ($pendingOwnershipPayment ?? false);
        $payCheckoutEnabled = (bool) config('payments.checkout.enabled', true);
        $payLogoUrl = \App\Support\MediaUrl::profileLogoUrl($profile, 96);
    @endphp

    <div class="pay-card">
        <div class="pay-card__inner">
            <div class="pay-profile">
                @if ($payLogoUrl)
                    <img class="pay-profile__logo" src="{{ $payLogoUrl }}" alt="" loading="lazy">
                @else
                    <span class="pay-profile__logo pay-profile__logo--initial" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) $profile->name), 0, 1)) ?: 'D' }}</span>
                @endif
                <div>
                    <p class="pay-profile__name">{{ $profile->name }}</p>
                    @if (filled($profile->city))
                        <p class="pay-profile__meta">{{ $profile->city }}</p>
                    @endif
                </div>
            </div>

            @if ($payFlash)
                <div class="pay-flash">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.2"/><path d="M12 11v5.4"/><path d="M12 7.6h.01"/></svg>
                    <span>{{ $payFlash }}</span>
                </div>
            @endif

            <div class="pay-hero">
                <span class="pay-hero__badge">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2 4 5.4v6c0 4.9 3.4 9.2 8 10.6 4.6-1.4 8-5.7 8-10.6v-6L12 2Zm0 2.2 6 2.5v4.7c0 3.9-2.6 7.4-6 8.6V4.2Z"/></svg>
                    DOVIRA PRO
                </span>
                <h1 class="pay-hero__title">{{ $product['name'] }}</h1>
                {{-- Головна цифра — місячний еквівалент; списання раз на 6 міс. --}}
                <p class="pay-hero__price">
                    <b>≈ {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::monthlyEquivalent((int) $product['amount'])) }}</b>
                    <span class="pay-hero__per">/ місяць</span>
                    @if (!empty($product['has_discount']))
                        <strong>−{{ (int) $product['discount_percent'] }}%</strong>
                    @endif
                </p>
                <p class="pay-hero__period">{{ $product['price_display'] }} раз на 6 місяців · підписка з автопродовженням</p>
            </div>

            <ul class="pay-features">
                @foreach ($payFeatures as $feature)
                    <li>
                        <span class="pay-check" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 5 5 10-11"/></svg>
                        </span>
                        <span>{{ $feature }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="pay-summary" aria-label="Деталі підписки">
                <div class="pay-summary__row">
                    <span>Профіль</span>
                    <strong>{{ $profile->name }}</strong>
                </div>
                <div class="pay-summary__row">
                    <span>Тариф</span>
                    <strong>{{ \App\Support\ProPricing::monthlyEquivalentDisplay((int) $product['amount']) }} · {{ $product['price_display'] }} / 6 місяців</strong>
                </div>
                @if (!empty($product['has_discount']))
                    <div class="pay-summary__row">
                        <span>Знижка</span>
                        <strong>−{{ (int) $product['discount_percent'] }}% · {{ $product['discount_amount_display'] }}</strong>
                    </div>
                @endif
                <div class="pay-summary__row pay-summary__row--total">
                    <span>До оплати</span>
                    <strong>{{ $product['price_display'] }}</strong>
                </div>
            </div>

            @if ($pendingOwnershipPayment)
                <div class="pay-flash">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.2"/><path d="M12 11v5.4"/><path d="M12 7.6h.01"/></svg>
                    <span>Заявка на права вже в роботі. Ви можете оплатити підписку зараз; керування профілем відкриється після підтвердження підтримкою.</span>
                </div>
            @endif
            @if ($payCheckoutEnabled)
                <form method="post" action="{{ route('pro.account.billing.pay.monopay', $profile) }}" data-mono-pay-form>
                    @csrf
                    <button type="submit" class="pay-submit" data-no-prefetch data-mono-pay-button aria-label="Перейти до оплати {{ $product['price_display'] }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/></svg>
                        <span data-mono-pay-label>Перейти до оплати</span>
                    </button>
                </form>
                <div class="pay-inline-error" role="alert" data-mono-pay-error></div>
            @else
                {{-- Плановий вимикач оплат: кнопки нема, ціна й умови лишаються видимими. --}}
                <div class="pay-flash" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.2"/><path d="M12 7.5V12l3 2"/></svg>
                    <span>{{ config('payments.checkout.paused_message') }}</span>
                </div>
            @endif

            <div class="pay-accepted">
                <span>Приймаємо</span>
                <span class="pay-accepted__logos" aria-hidden="true">
                    <img src="{{ asset('static/assets/payments/google-pay-light.svg') }}" alt="Google Pay" loading="lazy">
                    <img src="{{ asset('static/assets/payments/apple-pay-light.svg') }}" alt="Apple Pay" loading="lazy">
                </span>
            </div>

            <p class="pay-trust">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/></svg>
                Безпечна оплата через monopay
            </p>

            <p class="pay-note">
                PRO активується одразу після підтвердження платежу. Підписка продовжується автоматично:
                кожні 6 місяців списується {{ $product['price_display'] }} із збереженої картки.
                Вимкнути автопродовження можна будь-коли в кабінеті — доступ діє до кінця сплаченого періоду.
            </p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.querySelector('[data-mono-pay-form]');
        if (!form || !window.fetch) {
            return;
        }

        const button = form.querySelector('[data-mono-pay-button]');
        const label = form.querySelector('[data-mono-pay-label]');
        const errorBox = document.querySelector('[data-mono-pay-error]');
        const defaultLabel = label ? label.textContent : '';

        const showError = (message) => {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = message || 'Не вдалося перейти до оплати. Спробуйте ще раз.';
            errorBox.classList.add('is-visible');
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (errorBox) {
                errorBox.classList.remove('is-visible');
                errorBox.textContent = '';
            }

            if (button) {
                button.disabled = true;
            }

            if (label) {
                label.textContent = 'Створюємо платіж...';
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.redirected) {
                    window.location.assign(response.url);
                    return;
                }

                const payload = await response.json().catch(() => ({}));

                if (response.ok && payload.redirect_url) {
                    window.location.assign(payload.redirect_url);
                    return;
                }

                if (response.status === 419) {
                    window.location.reload();
                    return;
                }

                showError(payload.message);
            } catch (error) {
                showError('Не вдалося зʼєднатися з оплатою. Перевірте інтернет і спробуйте ще раз.');
            } finally {
                if (button) {
                    button.disabled = false;
                }

                if (label) {
                    label.textContent = defaultLabel;
                }
            }
        });
    })();
</script>
@endpush
