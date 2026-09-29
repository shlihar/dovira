@extends('static.layout')

@section('title', 'Контакти DOVIRA — як звʼязатися з командою')
@section('description', 'Як звʼязатися з командою DOVIRA: пошта, Telegram-підтримка, звернення щодо профілю компанії, скарги на відгуки та питання про PRO-підписку.')
@section('canonical', route('contacts'))
@section('body_class', 'page-legal')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/legal.css') }}?v={{ @filemtime(public_path('static/css/pages/legal.css')) }}">
    @php
        $contactEmail = (string) config('site_contacts.email', 'info@mydovira.com');
        $contactTelegram = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $contactPhone = trim((string) config('site_contacts.phone', ''));

        $contactPoint = [
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'email' => $contactEmail,
            'url' => route('contacts'),
            'availableLanguage' => ['uk', 'ru'],
        ];
        if ($contactPhone !== '') {
            $contactPoint['telephone'] = $contactPhone;
        }
    @endphp
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'ContactPage',
                'name' => 'Контакти DOVIRA',
                'url' => route('contacts'),
                'inLanguage' => 'uk',
                'isPartOf' => ['@id' => url('/') . '#website'],
                'about' => ['@id' => url('/') . '#organization'],
            ],
            [
                '@type' => 'Organization',
                '@id' => url('/') . '#organization',
                'name' => 'DOVIRA',
                'url' => url('/'),
                'contactPoint' => [$contactPoint],
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Контакти', 'item' => route('contacts')],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section class="section legal-page">
    <div class="container">
        <nav class="legal-page__breadcrumbs" aria-label="Хлібні крихти">
            <a href="{{ route('home') }}">Головна</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Контакти</span>
        </nav>

        <article class="legal-page__card">
            <h1 class="legal-page__title">Контакти</h1>
            <p class="legal-page__updated">Пишіть у зручний спосіб — відповідаємо в робочі дні.</p>

            <h2>Написати нам</h2>
            <ul>
                <li><strong>Пошта:</strong> <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> — основний канал для звернень і документів.</li>
                <li><strong>Telegram:</strong> <a href="https://t.me/{{ $contactTelegram }}" target="_blank" rel="noopener">@{{ $contactTelegram }}</a> — найшвидший спосіб поставити коротке питання.</li>
                @if ($contactPhone !== '')
                    <li><strong>Телефон:</strong> <a href="tel:{{ preg_replace('/\D+/', '', $contactPhone) }}">{{ $contactPhone }}</a></li>
                @endif
            </ul>

            <h2>З чим до нас звертаються найчастіше</h2>
            <ul>
                <li><strong>Це профіль моєї компанії.</strong> Знайдіть сторінку в <a href="{{ route('catalog') }}">каталозі</a> й підтвердіть права — це безкоштовно. Якщо не виходить, напишіть нам назву компанії та місто.</li>
                <li><strong>Відгук порушує правила.</strong> Опишіть, яке саме правило порушено, і додайте матеріали. Як це працює — у розділі <a href="{{ route('trust') }}#review-appeals">про оскарження відгуків</a>.</li>
                <li><strong>У досьє неточність.</strong> Напишіть, що саме не відповідає дійсності, і дайте джерело. Ми перевіряємо факти й оновлюємо текст.</li>
                <li><strong>Питання про PRO-підписку.</strong> Тарифи й можливості описані на сторінці <a href="{{ route('pro') }}">для бізнесу</a>; з оплатою та документами допоможемо поштою.</li>
                <li><strong>Співпраця і медіа.</strong> Пишіть на пошту з темою листа — так звернення не загубиться.</li>
            </ul>

            <h2>Скільки чекати відповідь</h2>
            <p>На звичайні звернення відповідаємо в робочі дні. Скарги на відгуки розглядає модерація: первинна відповідь — до 3 робочих днів, складні випадки — до 10 робочих днів. Про рішення повідомляємо тому, хто звертався.</p>

            <h2>Що ми не робимо</h2>
            <p>Ми не видаляємо відгуки за оплату й не змінюємо рейтинг на замовлення. Зняти публікацію можна лише за порушення правил, і це рішення модерації. Докладніше — у <a href="{{ route('editorial') }}">редакційній політиці</a>.</p>
        </article>
    </div>
</section>
@endsection
