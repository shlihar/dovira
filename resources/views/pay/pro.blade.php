@extends('static.layout')

@section('title', $product['name'] . ' — оплата')
@section('description', 'Оплата PRO-підписки DOVIRA через monopay.')
@section('robots', 'noindex, nofollow')

@push('head')
<style>
    .pay-page { max-width: 480px; margin: 40px auto 96px; padding: 0 16px; }
    .pay-card { overflow: hidden; background: #fff; border: 1px solid rgba(15, 23, 42, .08); border-radius: 22px; box-shadow: 0 24px 60px rgba(23, 42, 90, .12); }

    /* Брендований хедер з PRO-бейджем і ціною-героєм. */
    .pay-hero { position: relative; padding: 26px 26px 24px; color: #fff; background: linear-gradient(150deg, #2f6df6 0%, #1d4ed8 58%, #16309e 100%); }
    .pay-hero::after { content: ""; position: absolute; right: -40px; top: -60px; width: 190px; height: 190px; border-radius: 50%; background: rgba(255, 255, 255, .10); pointer-events: none; }
    .pay-hero__badge { position: relative; display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px 6px 8px; border-radius: 999px; background: rgba(255, 255, 255, .16); color: #fff; font-size: .78rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .pay-hero__badge i { font-size: .82rem; }
    .pay-hero__title { position: relative; margin: 14px 0 0; font-size: 1.34rem; font-weight: 800; line-height: 1.2; letter-spacing: -.01em; }
    .pay-hero__price { position: relative; display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .pay-hero__price b { font-size: 2.4rem; font-weight: 850; line-height: 1; letter-spacing: -.02em; }
    .pay-hero__price span { font-size: .98rem; font-weight: 600; color: rgba(255, 255, 255, .85); }
    .pay-hero__meta { position: relative; display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 12px; }
    .pay-hero__meta span { display: inline-flex; align-items: center; gap: 6px; font-size: .8rem; font-weight: 600; color: rgba(255, 255, 255, .9); }
    .pay-hero__meta i { font-size: .74rem; color: #a9f0c9; }

    .pay-body { padding: 22px 26px 26px; }

    .pay-features { display: grid; gap: 12px; margin: 0 0 22px; padding: 0; list-style: none; }
    .pay-features li { display: grid; grid-template-columns: 26px 1fr; align-items: center; gap: 11px; color: var(--c-heading, #1e293b); font-size: .96rem; font-weight: 600; }
    .pay-features li i { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 8px; background: #e8f0ff; color: var(--c-primary, #2563eb); font-size: .82rem; }

    .pay-summary { display: grid; gap: 10px; margin: 0 0 20px; padding: 15px 16px; background: #f6f8fc; border: 1px solid rgba(15, 23, 42, .07); border-radius: 14px; }
    .pay-summary__row { display: flex; align-items: center; justify-content: space-between; gap: 16px; color: var(--c-muted, #64748b); font-size: .9rem; }
    .pay-summary__row strong { color: var(--c-heading, #1e293b); font-weight: 700; text-align: right; }

    .pay-submit { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 56px; padding: 0 20px; border: 0; border-radius: 14px; background: var(--c-primary, #2563eb); color: #fff; font: inherit; font-size: 1.02rem; font-weight: 800; letter-spacing: .01em; cursor: pointer; box-shadow: 0 12px 26px rgba(37, 99, 235, .32); transition: transform .12s ease, box-shadow .15s ease, background .15s ease; }
    .pay-submit:hover { background: var(--c-primary-dark, #1d4ed8); box-shadow: 0 14px 30px rgba(37, 99, 235, .4); }
    .pay-submit:active { transform: translateY(1px); }
    .pay-submit:focus-visible { outline: 3px solid rgba(37, 99, 235, .4); outline-offset: 3px; }
    .pay-submit[disabled] { cursor: wait; opacity: .75; box-shadow: none; }
    .pay-submit i { font-size: .9rem; }

    .pay-accepted { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 14px 0 0; color: var(--c-muted, #64748b); font-size: .78rem; font-weight: 600; }
    .pay-accepted__logos { display: inline-flex; align-items: center; gap: 8px; }
    .pay-accepted__logos img { display: block; height: 20px; width: auto; object-fit: contain; opacity: .9; }

    .pay-trust { display: flex; align-items: center; justify-content: center; gap: 7px; margin: 16px 0 0; color: #16a34a; font-size: .82rem; font-weight: 700; }
    .pay-trust i { font-size: .8rem; }

    .pay-note { margin: 12px 0 0; font-size: .8rem; color: var(--c-muted, #64748b); line-height: 1.5; text-align: center; }

    .pay-flash { display: flex; gap: 10px; align-items: flex-start; margin: 20px 26px 0; padding: 12px 14px; background: #eff6ff; border: 1px solid rgba(37, 99, 235, .25); border-radius: 12px; color: #1e40af; font-size: .9rem; line-height: 1.5; }
    .pay-flash i { margin-top: 3px; }
    .pay-inline-error { display: none; margin: 12px 0 0; padding: 11px 13px; border: 1px solid rgba(225, 29, 72, .24); border-radius: 12px; background: #fff1f2; color: #be123c; font-size: .88rem; line-height: 1.45; text-align: center; }
    .pay-inline-error.is-visible { display: block; }

    @media (max-width: 480px) {
        .pay-hero { padding: 22px 20px 20px; }
        .pay-hero__price b { font-size: 2.1rem; }
        .pay-body { padding: 20px 20px 22px; }
        .pay-flash { margin: 16px 20px 0; }
    }
</style>
@endpush

@section('content')
<div class="pay-page">
    @php
        $payFlash = [
            'pro-edit-requires-pro' => 'Редагування профілю доступне лише з PRO-підпискою. Активуйте її, щоб зберігати зміни.',
            'pro-billing-provider-unavailable' => 'Оплата monopay тимчасово недоступна: платіжний ключ не налаштований на сервері.',
            'pro-billing-provider-error' => 'monopay не створив платіж. Спробуйте ще раз або зверніться в підтримку.',
            'pro-billing-invalid-amount' => 'Сума PRO-підписки налаштована некоректно. Зверніться в підтримку.',
        ][session('status')] ?? null;

        $payFeatures = [
            'Розширена сторінка профілю',
            'Відповіді на відгуки клієнтів',
            'Детальна аналітика та статистика',
            'Заявки клієнтів напряму в кабінет',
        ];
    @endphp

    <div class="pay-card">
        <div class="pay-hero">
            <span class="pay-hero__badge">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                DOVIRA PRO
            </span>
            <h1 class="pay-hero__title">{{ $product['name'] }}</h1>
            <p class="pay-hero__price">
                <b>{{ $product['price_display'] }}</b>
                <span>за 6 місяців</span>
            </p>
            <div class="pay-hero__meta">
                <span><i class="fa-solid fa-check" aria-hidden="true"></i> Разовий платіж</span>
                <span><i class="fa-solid fa-check" aria-hidden="true"></i> Без автопродовження</span>
                <span><i class="fa-solid fa-check" aria-hidden="true"></i> Ціна закріплюється назавжди</span>
            </div>
        </div>

        @if ($payFlash)
            <div class="pay-flash">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span>{{ $payFlash }}</span>
            </div>
        @endif

        <div class="pay-body">
            <ul class="pay-features">
                @foreach ($payFeatures as $feature)
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ $feature }}</span></li>
                @endforeach
            </ul>

            <div class="pay-summary" aria-label="Деталі підписки">
                <div class="pay-summary__row">
                    <span>Профіль</span>
                    <strong>{{ $profile->name }}</strong>
                </div>
                <div class="pay-summary__row">
                    <span>Тариф</span>
                    <strong>{{ $product['price_display'] }} / 6 місяців</strong>
                </div>
            </div>

            <form method="post" action="{{ route('pro.account.billing.pay.monopay', $profile) }}" data-mono-pay-form>
                @csrf
                <button type="submit" class="pay-submit" data-no-prefetch data-mono-pay-button aria-label="Перейти до оплати {{ $product['price_display'] }}">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span data-mono-pay-label>Перейти до оплати</span>
                </button>
            </form>
            <div class="pay-inline-error" role="alert" data-mono-pay-error></div>

            <div class="pay-accepted">
                <span>Приймаємо</span>
                <span class="pay-accepted__logos" aria-hidden="true">
                    <img src="{{ asset('static/assets/payments/google-pay-light.svg') }}" alt="Google Pay" loading="lazy">
                    <img src="{{ asset('static/assets/payments/apple-pay-light.svg') }}" alt="Apple Pay" loading="lazy">
                </span>
            </div>

            <p class="pay-trust">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                Безпечна оплата через monopay
            </p>

            <p class="pay-note">
                PRO активується автоматично одразу після підтвердження платежу.
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
