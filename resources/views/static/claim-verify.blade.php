@extends('static.layout')

@section('title', 'Підтвердження профілю — DOVIRA')
@section('description', 'Підтвердіть, що це ваш профіль на DOVIRA.')
@section('robots', 'noindex, nofollow')
@section('body_class', 'page-claim-verify')

@php
    $phoneChannels = collect($channels)->where('channel', 'phone')->values();
    $emailChannel = collect($channels)->firstWhere('channel', 'email');
    $primaryPhone = $phoneChannels->first();
    $extraPhones = $phoneChannels->slice(1)->values();
@endphp

@push('head')
<style>
    /* Фокус-сторінка на весь екран: хедер сайту fixed, тому контент сам
       відступає на --header-h і центрується у вільній висоті вʼюпорта. */
    .cv-wrap { display: flex; flex-direction: column; align-items: center; justify-content: center; box-sizing: border-box; min-height: 100dvh; padding: calc(var(--header-h, 76px) + 28px) 16px 56px; background: linear-gradient(180deg, #f4f7fc 0%, #fbfcfe 55%, #f6f8fc 100%); }
    .cv-card { width: 100%; max-width: 560px; overflow: hidden; background: var(--c-surface, #fff); border: 1px solid var(--c-border, rgba(15, 23, 42, .08)); border-radius: 26px; box-shadow: 0 24px 64px rgba(23, 42, 90, .10); }
    .cv-card__inner { padding: 30px 32px 30px; }

    /* Профіль, який підтверджуємо, — щоб людина була певна, що це та сторінка. */
    .cv-profile { display: flex; align-items: center; gap: 14px; padding: 15px 18px; margin: 0 0 26px; background: #f6f8fc; border: 1px solid var(--c-border, rgba(15, 23, 42, .07)); border-radius: 16px; }
    .cv-profile__logo { flex: 0 0 auto; width: 52px; height: 52px; border-radius: 13px; object-fit: cover; background: #fff; border: 1px solid rgba(15, 23, 42, .08); }
    .cv-profile__logo--initial { display: inline-flex; align-items: center; justify-content: center; background: #e8f0ff; color: var(--c-primary, #2563eb); font-size: 21px; font-weight: 800; }
    .cv-profile__name { margin: 0; color: var(--c-heading, #1e293b); font-size: 16px; font-weight: 750; line-height: 1.3; }
    .cv-profile__meta { margin: 3px 0 0; color: var(--c-muted, #64748b); font-size: 13.5px; }

    .cv-head { text-align: center; margin: 0 0 24px; }
    .cv-head h1 { margin: 0 0 9px; color: var(--c-heading, #1e293b); font-size: 24px; font-weight: 800; letter-spacing: -.02em; }
    .cv-head p { margin: 0 auto; max-width: 420px; color: var(--c-muted, #64748b); font-size: 15px; line-height: 1.55; }

    .cv-step[hidden] { display: none; }

    /* Вибір способу: телефон, email і підтримка — рівноправні великі картки. */
    .cv-channels { display: grid; gap: 11px; }
    .cv-channel { display: flex; align-items: center; gap: 14px; width: 100%; padding: 15px 16px; border: 1px solid var(--c-border, rgba(15, 23, 42, .12)); border-radius: 16px; background: #fff; font: inherit; text-align: left; text-decoration: none; cursor: pointer; transition: border-color .12s ease, box-shadow .12s ease, transform .1s ease; }
    .cv-channel:hover { border-color: rgba(37, 99, 235, .5); box-shadow: 0 8px 22px rgba(37, 99, 235, .10); }
    .cv-channel:active { transform: translateY(1px); }
    .cv-channel:focus-visible { outline: 3px solid rgba(37, 99, 235, .35); outline-offset: 2px; }
    .cv-channel[disabled] { opacity: .6; cursor: wait; }
    .cv-channel__icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 13px; background: #e8f0ff; color: var(--c-primary, #2563eb); }
    .cv-channel__icon svg { width: 22px; height: 22px; }
    .cv-channel__label { display: block; color: var(--c-muted, #64748b); font-size: 13px; font-weight: 650; }
    .cv-channel__value { display: block; margin-top: 2px; color: var(--c-heading, #1e293b); font-size: 16.5px; font-weight: 780; letter-spacing: .01em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .cv-channel__go { flex: 0 0 auto; margin-left: auto; color: #b6c2d6; }
    .cv-channel__go svg { width: 16px; height: 16px; }
    /* Telegram-підтримка — акцентна кнопка в фірмовому синьому сайту:
       рівноправний (і завжди доступний) шлях підтвердження, не «сірий» фолбек. */
    .cv-channel--tg { border-color: transparent; background: linear-gradient(135deg, #3a67f2 0%, var(--c-primary, #2563eb) 100%); box-shadow: 0 10px 24px rgba(37, 99, 235, .26); }
    .cv-channel--tg:hover { border-color: transparent; background: linear-gradient(135deg, #2f5ae0 0%, var(--c-primary-dark, #1d4ed8) 100%); box-shadow: 0 12px 28px rgba(37, 99, 235, .34); }
    .cv-channel--tg .cv-channel__icon { background: rgba(255, 255, 255, .18); color: #fff; }
    .cv-channel--tg .cv-channel__label { color: rgba(232, 240, 255, .88); }
    .cv-channel--tg .cv-channel__value { color: #fff; }
    .cv-channel--tg .cv-channel__go { color: rgba(255, 255, 255, .8); }

    /* Додаткові номери — сховані за розкривним рядком, щоб не було стіни цифр. */
    .cv-more-toggle { display: inline-flex; align-items: center; gap: 7px; margin: 2px auto 0; padding: 6px 10px; border: 0; border-radius: 9px; background: transparent; color: var(--c-muted, #64748b); font: inherit; font-size: 13.5px; font-weight: 650; cursor: pointer; }
    .cv-more-toggle:hover { color: var(--c-heading, #1e293b); background: #f1f4f9; }
    .cv-more-toggle svg { width: 13px; height: 13px; transition: transform .15s ease; }
    .cv-more-toggle[aria-expanded="true"] svg { transform: rotate(180deg); }
    .cv-more { display: grid; gap: 11px; }
    .cv-more[hidden] { display: none; }
    .cv-divider { display: flex; align-items: center; gap: 12px; margin: 7px 0; color: #a6b3c6; font-size: 12.5px; font-weight: 650; text-transform: uppercase; letter-spacing: .07em; }
    .cv-divider::before, .cv-divider::after { content: ""; flex: 1; height: 1px; background: var(--c-border, rgba(15, 23, 42, .09)); }

    /* Крок 2: введення коду. */
    .cv-back { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 16px; padding: 0; border: 0; background: transparent; color: var(--c-muted, #64748b); font: inherit; font-size: 14px; font-weight: 650; cursor: pointer; }
    .cv-back:hover { color: var(--c-heading, #1e293b); }
    .cv-back svg { width: 15px; height: 15px; }
    .cv-sent { margin: 0 0 16px; padding: 12px 14px; border-radius: 13px; background: #f0fdf4; border: 1px solid rgba(22, 163, 74, .2); color: #166534; font-size: 14px; line-height: 1.5; }
    .cv-sent b { font-variant-numeric: tabular-nums; }
    .cv-code { width: 100%; box-sizing: border-box; height: 66px; padding: 0 14px; border: 1.5px solid var(--c-border, rgba(15, 23, 42, .14)); border-radius: 15px; background: #fff; color: var(--c-heading, #1e293b); font: inherit; font-size: 28px; font-weight: 800; letter-spacing: .42em; text-align: center; text-indent: .42em; font-variant-numeric: tabular-nums; }
    .cv-code:focus { outline: none; border-color: var(--c-primary, #2563eb); box-shadow: 0 0 0 4px rgba(37, 99, 235, .14); }
    .cv-code::placeholder { color: #c3cddc; letter-spacing: .2em; text-indent: .2em; font-size: 15px; font-weight: 600; transform: translateY(-4px); }

    .cv-submit { display: inline-flex; align-items: center; justify-content: center; gap: 9px; width: 100%; box-sizing: border-box; min-height: 54px; margin-top: 13px; padding: 0 18px; border: 0; border-radius: 15px; background: var(--c-primary, #2563eb); color: #fff; font: inherit; font-size: 16px; font-weight: 750; cursor: pointer; text-decoration: none; box-shadow: 0 10px 24px rgba(37, 99, 235, .28); transition: background .13s ease, transform .1s ease; }
    .cv-submit:hover { background: var(--c-primary-dark, #1d4ed8); color: #fff; }
    .cv-submit:active { transform: translateY(1px); }
    .cv-submit:focus-visible { outline: 3px solid rgba(37, 99, 235, .35); outline-offset: 3px; }
    .cv-submit[disabled] { cursor: wait; opacity: .7; box-shadow: none; }
    .cv-submit svg { width: 18px; height: 18px; }
    /* Кнопка підтримки в manual/verified-станах — той самий фірмовий синій,
       що й основний .cv-submit (без телеграм-бірюзи, стиль сайту). */
    .cv-submit--tg { background: linear-gradient(135deg, #3a67f2 0%, var(--c-primary, #2563eb) 100%); }
    .cv-submit--tg:hover { background: linear-gradient(135deg, #2f5ae0 0%, var(--c-primary-dark, #1d4ed8) 100%); }

    .cv-resend { display: block; margin: 14px auto 0; padding: 0; border: 0; background: transparent; color: var(--c-primary, #2563eb); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
    .cv-resend[disabled] { color: var(--c-muted, #94a3b8); cursor: default; font-weight: 600; }

    .cv-error { margin: 14px 0 0; padding: 12px 14px; border: 1px solid rgba(225, 29, 72, .22); border-radius: 13px; background: #fff1f2; color: #be123c; font-size: 14px; line-height: 1.45; }
    .cv-error[hidden] { display: none; }

    /* Успіх. */
    .cv-success { text-align: center; padding: 10px 0 4px; }
    .cv-success__icon { display: inline-flex; align-items: center; justify-content: center; width: 62px; height: 62px; margin-bottom: 15px; border-radius: 50%; background: #dcfce7; color: #16a34a; }
    .cv-success__icon svg { width: 28px; height: 28px; }
    .cv-success h2 { margin: 0 0 7px; color: var(--c-heading, #1e293b); font-size: 20px; font-weight: 800; }
    .cv-success p { margin: 0; color: var(--c-muted, #64748b); font-size: 14.5px; }

    .cv-note { margin: 16px auto 0; max-width: 460px; color: var(--c-muted, #94a3b8); font-size: 13px; line-height: 1.55; text-align: center; }

    /* Шлях назад на профіль з самої картки (не лише через хедер). */
    .cv-profile-back { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 20px; color: var(--c-muted, #64748b); font-size: 14px; font-weight: 650; text-decoration: none; }
    .cv-profile-back:hover { color: var(--c-heading, #1e293b); }
    .cv-profile-back svg { width: 15px; height: 15px; }

    @media (max-width: 480px) {
        .cv-wrap { justify-content: flex-start; padding-top: calc(var(--header-h, 76px) + 16px); }
        .cv-card { border-radius: 20px; }
        .cv-card__inner { padding: 22px 18px 22px; }
        .cv-head h1 { font-size: 21px; }
    }
</style>
@endpush

@section('content')
<div class="cv-wrap">
    <div class="cv-card">
        <div class="cv-card__inner">
            <div class="cv-profile">
                @if ($logoUrl)
                    <img class="cv-profile__logo" src="{{ $logoUrl }}" alt="" loading="lazy">
                @else
                    <span class="cv-profile__logo cv-profile__logo--initial" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) $profile->name), 0, 1)) ?: 'D' }}</span>
                @endif
                <div>
                    <p class="cv-profile__name">{{ $profile->name }}</p>
                    @if (filled($profile->city))
                        <p class="cv-profile__meta">{{ $profile->city }}</p>
                    @endif
                </div>
            </div>

            @if ($state === 'confirm')
                <div
                    data-cv
                    data-profile-id="{{ $profile->id }}"
                    data-request-url="{{ route('pro.account.claims.request-code') }}"
                    data-verify-url="{{ route('pro.account.claims.verify-code') }}"
                    @if ($next === 'pay') data-intent="pay" @endif
                >
                    <div class="cv-step" data-cv-step="channel">
                        <div class="cv-head">
                            <h1>Підтвердіть, що це ваш профіль</h1>
                            <p>Це безкоштовно і займає близько хвилини. Оберіть зручний спосіб.</p>
                        </div>

                        <div class="cv-channels">
                            @if ($primaryPhone)
                                <button type="button" class="cv-channel" data-cv-channel="phone" data-cv-destination="{{ $primaryPhone['destination_key'] }}" data-cv-value="{{ $primaryPhone['masked'] }}">
                                    <span class="cv-channel__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.6"/><path d="M10.5 5.4h3"/><path d="M12 18.2h.01"/></svg>
                                    </span>
                                    <span>
                                        <span class="cv-channel__label">Отримати SMS-код на номер</span>
                                        <span class="cv-channel__value">{{ $primaryPhone['masked'] }}</span>
                                    </span>
                                    <span class="cv-channel__go" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>
                                    </span>
                                </button>
                            @endif

                            @if ($emailChannel)
                                <button type="button" class="cv-channel" data-cv-channel="email" data-cv-destination="{{ $emailChannel['destination_key'] }}" data-cv-value="{{ $emailChannel['masked'] }}">
                                    <span class="cv-channel__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7.5 8 6 8-6"/></svg>
                                    </span>
                                    <span>
                                        <span class="cv-channel__label">Отримати код на email</span>
                                        <span class="cv-channel__value">{{ $emailChannel['masked'] }}</span>
                                    </span>
                                    <span class="cv-channel__go" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>
                                    </span>
                                </button>
                            @endif

                            @if ($extraPhones->isNotEmpty())
                                <button type="button" class="cv-more-toggle" data-cv-more-toggle aria-expanded="false">
                                    <span>Надіслати SMS на інший номер ({{ $extraPhones->count() }})</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="cv-more" data-cv-more hidden>
                                    @foreach ($extraPhones as $channel)
                                        <button type="button" class="cv-channel" data-cv-channel="phone" data-cv-destination="{{ $channel['destination_key'] }}" data-cv-value="{{ $channel['masked'] }}">
                                            <span class="cv-channel__icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.6"/><path d="M10.5 5.4h3"/><path d="M12 18.2h.01"/></svg>
                                            </span>
                                            <span>
                                                <span class="cv-channel__label">SMS на номер</span>
                                                <span class="cv-channel__value">{{ $channel['masked'] }}</span>
                                            </span>
                                            <span class="cv-channel__go" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="cv-divider" aria-hidden="true">або</div>

                            <a class="cv-channel cv-channel--tg" href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer">
                                <span class="cv-channel__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.9 4.6c.3-1.2-.9-2.2-2-1.7L2.7 9.9c-1.2.5-1.1 2.2.1 2.6l4.4 1.4 1.7 5.3c.3 1 1.6 1.3 2.3.5l2.4-2.5 4.5 3.3c.9.7 2.2.2 2.4-.9l3.4-14.9v-.1ZM8.2 12.7l9.6-6.1c.2-.2.5.1.3.3l-7.9 7.4c-.3.3-.5.6-.5 1l-.3 2.2c0 .3-.4.3-.5 0l-1.1-3.6c-.2-.5 0-.9.4-1.2Z"/></svg>
                                </span>
                                <span>
                                    <span class="cv-channel__label">Немає доступу до цих контактів?</span>
                                    <span class="cv-channel__value">Підтвердити через підтримку</span>
                                </span>
                                <span class="cv-channel__go" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>
                                </span>
                            </a>
                        </div>
                    </div>

                    <div class="cv-step" data-cv-step="code" hidden>
                        <button type="button" class="cv-back" data-cv-back>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
                            Інший спосіб
                        </button>
                        <p class="cv-sent" data-cv-sent aria-live="polite"></p>
                        <input
                            type="text"
                            class="cv-code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="Код із 6 цифр"
                            aria-label="Код підтвердження"
                            data-cv-code
                        >
                        <button type="button" class="cv-submit" data-cv-verify>
                            <span data-cv-verify-label>Підтвердити профіль</span>
                        </button>
                        <button type="button" class="cv-resend" data-cv-resend>Надіслати код ще раз</button>
                    </div>

                    <div class="cv-step cv-success" data-cv-step="success" hidden>
                        <span class="cv-success__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 5 5 10-11"/></svg>
                        </span>
                        <h2>Профіль підтверджено</h2>
                        <p data-cv-success-note>Відкриваємо ваш кабінет…</p>
                    </div>

                    <p class="cv-error" role="alert" data-cv-error hidden></p>
                </div>
            @elseif ($state === 'manual')
                <div class="cv-head">
                    <h1>Підтвердимо вручну</h1>
                    <p>У профілі не вказано контактів для автоматичного підтвердження. Напишіть у підтримку — перевіримо права й привʼяжемо профіль до вашого акаунта.</p>
                </div>
                <a class="cv-submit cv-submit--tg" href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.9 4.6c.3-1.2-.9-2.2-2-1.7L2.7 9.9c-1.2.5-1.1 2.2.1 2.6l4.4 1.4 1.7 5.3c.3 1 1.6 1.3 2.3.5l2.4-2.5 4.5 3.3c.9.7 2.2.2 2.4-.9l3.4-14.9v-.1ZM8.2 12.7l9.6-6.1c.2-.2.5.1.3.3l-7.9 7.4c-.3.3-.5.6-.5 1l-.3 2.2c0 .3-.4.3-.5 0l-1.1-3.6c-.2-.5 0-.9.4-1.2Z"/></svg>
                    <span>Написати в підтримку</span>
                </a>
            @else
                <div class="cv-head">
                    <h1>Профіль уже підтверджено</h1>
                    <p>Ця сторінка вже привʼязана до іншого акаунта. Якщо це помилка — напишіть у підтримку, і ми розберемося.</p>
                </div>
                <a class="cv-submit cv-submit--tg" href="{{ $supportHref }}" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.9 4.6c.3-1.2-.9-2.2-2-1.7L2.7 9.9c-1.2.5-1.1 2.2.1 2.6l4.4 1.4 1.7 5.3c.3 1 1.6 1.3 2.3.5l2.4-2.5 4.5 3.3c.9.7 2.2.2 2.4-.9l3.4-14.9v-.1ZM8.2 12.7l9.6-6.1c.2-.2.5.1.3.3l-7.9 7.4c-.3.3-.5.6-.5 1l-.3 2.2c0 .3-.4.3-.5 0l-1.1-3.6c-.2-.5 0-.9.4-1.2Z"/></svg>
                    <span>Написати в підтримку</span>
                </a>
            @endif

            <a class="cv-profile-back" href="{{ route('profile.show', ['slug' => $profile->slug]) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
                Повернутися до профілю
            </a>
        </div>
    </div>

    @if ($state === 'confirm')
        <p class="cv-note">
            Підтвердження безкоштовне і привʼязує профіль до акаунта {{ auth()->user()?->email }}.
        </p>
    @endif
</div>
@endsection

@if ($state === 'confirm')
@push('scripts')
<script>
    (() => {
        const root = document.querySelector('[data-cv]');
        if (!root || !window.fetch) return;

        const profileId = Number(root.dataset.profileId || 0);
        const requestUrl = root.dataset.requestUrl || '';
        const verifyUrl = root.dataset.verifyUrl || '';
        const intent = root.dataset.intent || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const steps = {
            channel: root.querySelector('[data-cv-step="channel"]'),
            code: root.querySelector('[data-cv-step="code"]'),
            success: root.querySelector('[data-cv-step="success"]'),
        };
        const channelButtons = Array.from(root.querySelectorAll('[data-cv-channel]'));
        const moreToggle = root.querySelector('[data-cv-more-toggle]');
        const moreBox = root.querySelector('[data-cv-more]');
        const sentNote = root.querySelector('[data-cv-sent]');
        const codeInput = root.querySelector('[data-cv-code]');
        const verifyBtn = root.querySelector('[data-cv-verify]');
        const verifyLabel = root.querySelector('[data-cv-verify-label]');
        const resendBtn = root.querySelector('[data-cv-resend]');
        const backBtn = root.querySelector('[data-cv-back]');
        const errorBox = root.querySelector('[data-cv-error]');
        const successNote = root.querySelector('[data-cv-success-note]');

        let selected = null;
        let busy = false;
        let cooldownTimer = null;

        const showStep = (name) => {
            Object.entries(steps).forEach(([key, el]) => { if (el) el.hidden = key !== name; });
        };

        const setError = (message = '') => {
            if (!errorBox) return;
            errorBox.textContent = message;
            errorBox.hidden = message === '';
        };

        moreToggle?.addEventListener('click', () => {
            const expanded = moreToggle.getAttribute('aria-expanded') === 'true';
            moreToggle.setAttribute('aria-expanded', String(!expanded));
            if (moreBox) moreBox.hidden = expanded;
        });

        // Кулдаун повторного надсилання синхронізований із серверним (60 с).
        const startCooldown = (seconds = 60) => {
            if (!resendBtn) return;
            clearInterval(cooldownTimer);
            let left = seconds;
            resendBtn.disabled = true;
            const tick = () => {
                if (left <= 0) {
                    clearInterval(cooldownTimer);
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Надіслати код ще раз';
                    return;
                }
                resendBtn.textContent = `Надіслати ще раз можна через ${left} с`;
                left -= 1;
            };
            tick();
            cooldownTimer = setInterval(tick, 1000);
        };

        const postJson = async (url, payload) => {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                throw new Error(data.error || data.message || 'Щось пішло не так. Спробуйте ще раз.');
            }
            return data;
        };

        const sendCode = async (channel, destination, value) => {
            if (busy || !profileId || !requestUrl) return;
            busy = true;
            setError('');
            channelButtons.forEach((item) => { item.disabled = true; });
            try {
                const data = await postJson(requestUrl, {
                    profile_id: profileId,
                    channel,
                    destination_key: destination,
                });
                selected = { channel, destination, value };
                if (sentNote) {
                    sentNote.innerHTML = `Код надіслано ${channel === 'phone' ? 'в SMS на' : 'на'} <b>${value}</b>. Дійсний ${data.ttl || 10} хв.`;
                }
                showStep('code');
                if (codeInput) { codeInput.value = ''; codeInput.focus(); }
                startCooldown();
            } catch (error) {
                setError(error.message);
            } finally {
                busy = false;
                channelButtons.forEach((item) => { item.disabled = false; });
            }
        };

        const verify = async () => {
            const code = String(codeInput?.value || '').replace(/\D+/g, '');
            if (code.length < 4) {
                setError('Введіть код із повідомлення.');
                return;
            }
            if (busy) return;
            busy = true;
            setError('');
            if (verifyBtn) verifyBtn.disabled = true;
            if (verifyLabel) verifyLabel.textContent = 'Перевіряємо…';
            try {
                const payload = { profile_id: profileId, code };
                if (intent) payload.intent = intent;
                const data = await postJson(verifyUrl, payload);
                showStep('success');
                if (successNote && intent === 'pay') successNote.textContent = 'Повертаємо до оформлення підписки…';
                setTimeout(() => window.location.assign(data.redirect || '{{ route('pro.account') }}'), 900);
            } catch (error) {
                setError(error.message);
                if (verifyBtn) verifyBtn.disabled = false;
                if (verifyLabel) verifyLabel.textContent = 'Підтвердити профіль';
                busy = false;
                codeInput?.focus();
            }
        };

        channelButtons.forEach((button) => {
            button.addEventListener('click', () => sendCode(
                button.dataset.cvChannel || '',
                button.dataset.cvDestination || '',
                button.dataset.cvValue || '',
            ));
        });

        backBtn?.addEventListener('click', () => {
            setError('');
            showStep('channel');
        });

        resendBtn?.addEventListener('click', () => {
            if (selected) sendCode(selected.channel, selected.destination, selected.value);
        });

        verifyBtn?.addEventListener('click', verify);

        codeInput?.addEventListener('input', () => {
            const digits = codeInput.value.replace(/\D+/g, '').slice(0, 6);
            if (codeInput.value !== digits) codeInput.value = digits;
            // Всі 6 цифр введено (чи вставлено з SMS) — перевіряємо одразу.
            if (digits.length === 6) verify();
        });
        codeInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); verify(); }
        });
    })();
</script>
@endpush
@endif
