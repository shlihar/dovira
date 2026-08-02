@extends('static.layout')

@section('title', 'Як формується рейтинг на Dovira')
@section('description', 'Методологія рейтингу Dovira: середня оцінка з поправкою на кількість відгуків, мінімальний поріг участі, що не впливає на позицію та як часто оновлюються дані.')
@section('canonical', route('rating.method'))
@section('body_class', 'page-legal')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/legal.css') }}?v={{ @filemtime(public_path('static/css/pages/legal.css')) }}">
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Як формується рейтинг', 'item' => route('rating.method')],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section class="section legal-page">
    <div class="container">
        <nav class="legal-page__breadcrumbs" aria-label="Хлібні крихти">
            <a href="{{ route('home') }}">Головна</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Як формується рейтинг</span>
        </nav>

        <article class="legal-page__card">
            <h1 class="legal-page__title">Як формується рейтинг на Dovira</h1>
            <p class="legal-page__updated">Рейтинг — це математика поверх реальних відгуків. Ось вся формула, без секретів.</p>

            <h2>Що впливає на позицію</h2>
            <p>Дві речі: <strong>середня оцінка</strong> профілю і <strong>кількість відгуків</strong>, на яких вона тримається. Ми не показуємо просто середнє — воно легко обманює: три п'ятірки від трьох людей дають «ідеальні 5.0», але кажуть про компанію менше, ніж 4.8 на трьохстах відгуках.</p>
            <p>Тому позиція рахується за зваженою формулою (байєсівська оцінка):</p>
            <p><code>Рейтинг = (v / (v + 10)) × R + (10 / (v + 10)) × C</code></p>
            <ul>
                <li><strong>R</strong> — середня оцінка профілю;</li>
                <li><strong>v</strong> — кількість опублікованих відгуків профілю;</li>
                <li><strong>C</strong> — середня оцінка по всій платформі (зараз ≈ 4.3).</li>
            </ul>
            <p>Простими словами: поки відгуків мало, оцінка профілю «підтягується» до середньої по платформі, і лише зі зростанням кількості відгуків його власна оцінка набирає повну вагу. Що більше людей підтвердили якість — то міцніша позиція.</p>
            <p>Числову оцінку і зірки показуємо, лише коли у профіля щонайменше <strong>{{ \App\Support\RatingDisplay::MIN_REVIEWS }} відгуків</strong> — до цього порогу пишемо «оцінка формується», щоб кілька випадкових оцінок не малювали хибну картину.</p>

            <h2>Хто потрапляє в рейтинг</h2>
            <ul>
                <li>активні опубліковані профілі, видимі в каталозі;</li>
                <li>з щонайменше <strong>3 опублікованими відгуками</strong>;</li>
                <li>враховуються лише відгуки, що пройшли <a href="{{ route('trust') }}">модерацію</a>.</li>
            </ul>

            <h2>Що не впливає на позицію</h2>
            <ul>
                <li><strong>PRO-підписка</strong> — не додає жодного бала до рейтингу і не змінює оцінку профілю. Чесно кажемо: PRO-профілі показуються вище у списку каталогу (це платний пріоритет показу), але сам рейтинг, оцінки та відгуки від підписки не залежать.</li>
                <li><strong>Позначка «Dovira рекомендує»</strong> — це окрема відзнака платформи, вона не множить оцінку і не піднімає рядок у рейтингу.</li>
                <li><strong>Видалення негативу</strong> — купити прибирання відгуку неможливо, тож рейтинг не можна «почистити». Докладніше — у розділі <a href="{{ route('trust') }}">«Чому нам довіряти»</a>.</li>
            </ul>

            <h2>Як часто оновлюється</h2>
            <p>Рейтинг перераховується автоматично: нові відгуки враховуються одразу після проходження модерації, агрегати на головній оновлюються протягом години.</p>
            @php
                $ratingReviews = (int) (($stats ?? [])['reviews'] ?? 0);
                $ratingProfiles = (int) (($stats ?? [])['profiles'] ?? 0);
            @endphp
            @if ($ratingReviews > 0 && $ratingProfiles > 0)
                <p>Формула працює на реальних даних платформи: <strong>{{ number_format($ratingReviews, 0, '', ' ') }}</strong> опублікованих відгуків про <strong>{{ number_format($ratingProfiles, 0, '', ' ') }}</strong> профілів.</p>
            @endif

            <h2>Помітили дивне?</h2>
            <p>Якщо позиція профілю виглядає підозріло — напишіть нам через форму скарги на сторінці профілю. Спірні випадки перевіряємо вручну.</p>

            <p style="margin-top:28px;">
                <a class="btn btn--primary" href="{{ route('home') }}#leaderboard-title">До рейтингу на головній</a>
            </p>
        </article>
    </div>
</section>
@endsection
