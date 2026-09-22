@extends('static.layout')

@section('title', 'Підтвердження профілю — DOVIRA')
@section('description', 'Підтвердіть, що це ваш профіль на DOVIRA.')
@section('robots', 'noindex, nofollow')
@section('body_class', 'page-claim-link')

@push('head')
<style>
    .page-claim-link .claim-wrap { max-width: 520px; margin: 0 auto; padding: 48px 16px 64px; }
    .page-claim-link .claim-card { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--radius-xl, 24px); box-shadow: var(--shadow-card); padding: 28px 24px; text-align: center; }
    .page-claim-link .claim-ic { width: 56px; height: 56px; border-radius: 16px; background: #eef3ff; color: var(--c-primary); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 14px; }
    .page-claim-link .claim-card h1 { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: var(--c-heading); letter-spacing: -0.02em; }
    .page-claim-link .claim-name { font-weight: 700; color: var(--c-heading); }
    .page-claim-link .claim-card p { margin: 0 0 8px; color: var(--c-muted); font-size: 15px; line-height: 1.6; }
    .page-claim-link .claim-mail { color: var(--c-text); font-weight: 600; }
    .page-claim-link .claim-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 18px; padding: 14px 22px; border: 0; border-radius: 12px; background: var(--c-primary); color: #fff; font: inherit; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; }
    .page-claim-link .claim-btn:hover { background: var(--c-primary-dark); }
    .page-claim-link .claim-note { margin-top: 14px; font-size: 12.5px; color: var(--c-muted); }
    .page-claim-link .claim-link-more { display: inline-block; margin-top: 16px; color: var(--c-primary); text-decoration: none; font-weight: 600; font-size: 14px; }
</style>
@endpush

@section('content')
<div class="claim-wrap">
    <div class="claim-card">
        @if ($state === 'confirm')
            <span class="claim-ic"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
            <h1>Це ваш профіль?</h1>
            <p>Профіль <span class="claim-name">«{{ $profile->name }}»</span> уже публічний на DOVIRA: його можуть бачити клієнти й знаходити в Google.</p>
            <p>Оскільки цей лист прийшов на <span class="claim-mail">{{ $maskedEmail }}</span>, окремий код не потрібен — підтвердьте права в один клік.</p>
            <form method="POST" action="{{ $confirmUrl }}">
                @csrf
                <button type="submit" class="claim-btn">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    Так, це мій профіль
                </button>
            </form>
            <p class="claim-note">Підтвердження безкоштовно привʼяже сторінку до вашого акаунта. Редагування інформації, контакти, досьє, відповіді на відгуки й просування відкриваються після оформлення підписки. {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }} За бейдж DOVIRA на сайті або в соцмережах діє знижка 20%.</p>
        @elseif ($state === 'yours')
            <span class="claim-ic"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
            <h1>Профіль уже ваш</h1>
            <p>Профіль <span class="claim-name">«{{ $profile->name }}»</span> вже підтверджений на вашому акаунті й залишається публічним для клієнтів та Google. {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }}</p>
            <a class="claim-btn" href="{{ route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]) }}">Оформити підписку</a>
        @elseif ($state === 'taken')
            <span class="claim-ic"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
            <h1>Профіль уже підтверджено</h1>
            <p>Профіль <span class="claim-name">«{{ $profile->name }}»</span> уже має підтвердженого власника. Якщо це помилка — напишіть у підтримку.</p>
            <a class="claim-link-more" href="{{ route('profile.show', ['slug' => $profile->slug]) }}">Переглянути профіль</a>
        @else
            <span class="claim-ic"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
            <h1>Керування профілем</h1>
            <p>Профіль уже публічний на DOVIRA і може знаходитися в Google. Щоб оформити підписку на керування, спочатку треба безкоштовно підтвердити права в кабінеті. {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }} За бейдж DOVIRA на сайті або в соцмережах діє знижка 20%.</p>
            <a class="claim-btn" href="{{ auth()->check() ? route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id]) : route('register', ['next' => route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id])]) }}">Перейти до керування</a>
        @endif
    </div>
</div>
@endsection
