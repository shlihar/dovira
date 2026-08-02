@extends('static.layout')

@section('title', $order->isPaid() ? 'PRO активовано' : 'Очікуємо підтвердження оплати')
@section('robots', 'noindex, nofollow')

@push('head')
@unless ($order->isPaid())
    <meta http-equiv="refresh" content="5">
@endunless
<style>
    .pay-page { max-width: 560px; margin: 48px auto 96px; padding: 0 16px; }
    .pay-card { background: #fff; border: 1px solid rgba(15, 23, 42, .08); border-radius: 18px; padding: 36px 28px; box-shadow: 0 12px 40px rgba(15, 23, 42, .06); text-align: center; }
    .pay-status__icon { width: 64px; height: 64px; margin: 0 auto 18px; border-radius: 50%; display: grid; place-items: center; font-size: 28px; color: #fff; }
    .pay-status__icon--paid { background: #22c55e; }
    .pay-status__icon--pending { background: #f59e0b; }
    .pay-card h1 { font-size: 1.35rem; margin: 0 0 10px; }
    .pay-card p { color: #64748b; line-height: 1.55; margin: 0 0 20px; }
    .pay-action { display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: #fff; border-radius: 12px; padding: 13px 22px; font-weight: 600; text-decoration: none; }
    .pay-action:hover { background: #1d4ed8; }
</style>
@endpush

@section('content')
<div class="pay-page">
    <div class="pay-card">
        @if ($order->isPaid())
            <div class="pay-status__icon pay-status__icon--paid"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <h1>PRO активовано</h1>
            <p>Оплату отримано. Підписка для профілю «{{ $profile->name }}» активна.</p>
            <a class="pay-action" href="{{ $product['success_url'] }}" data-no-prefetch>
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                Перейти до управління PRO
            </a>
        @else
            <div class="pay-status__icon pay-status__icon--pending"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></div>
            <h1>Очікуємо підтвердження оплати</h1>
            <p>
                monopay ще не підтвердив платіж. Сторінка оновлюється автоматично,
                а PRO активується одразу після підтвердження.
            </p>
        @endif
    </div>
</div>
@endsection
