@extends('static.layout')

@section('title', 'Чому відгукам на Dovira можна довіряти')
@section('description', 'Як Dovira забезпечує чесність відгуків: модерація кожного відгуку, заборона купувати видалення, позначки довіри, чесне маркування Google-відгуків та антиспам.')
@section('canonical', route('trust'))
@section('body_class', 'page-legal')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/legal.css') }}?v={{ @filemtime(public_path('static/css/pages/legal.css')) }}">
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Чому нам довіряти', 'item' => route('trust')],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section class="section legal-page">
    <div class="container">
        <nav class="legal-page__breadcrumbs" aria-label="Хлібні крихти">
            <a href="{{ route('home') }}">Головна</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Чому нам довіряти</span>
        </nav>

        <article class="legal-page__card">
            <h1 class="legal-page__title">Чому відгукам на Dovira можна довіряти</h1>
            <p class="legal-page__updated">Довіра — основа платформи. Ось як ми її забезпечуємо.</p>
            @php
                $trustReviews = (int) (($stats ?? [])['reviews'] ?? 0);
                $trustProfiles = (int) (($stats ?? [])['profiles'] ?? 0);
            @endphp
            @if ($trustReviews > 0 && $trustProfiles > 0)
                <p>На Dovira вже <strong>{{ number_format($trustReviews, 0, '', ' ') }}</strong> опублікованих відгуків про <strong>{{ number_format($trustProfiles, 0, '', ' ') }}</strong> компаній і спеціалістів — і кожен пройшов модерацію перед публікацією.</p>
            @endif

            <h2>Модерація кожного відгуку</h2>
            <p>Кожен відгук проходить перевірку перед публікацією. Ми відсіюємо спам, образи та очевидно фейкові дописи. Автоматична модерація доповнюється ручною там, де потрібно.</p>

            <h2>Видалення відгуку не можна купити</h2>
            <p>Бізнес не може оплатити приховування чи видалення негативного відгуку. PRO-підписка дає інструменти <em>відповіді</em> та керування репутацією — але не право стирати чесну критику. Так рейтинг лишається правдивим.</p>

            <h2>Прозорі позначки довіри</h2>
            <p>Біля профілю ви бачите рівень підтвердження:</p>
            <ul>
                <li><strong>Керує власник</strong> — профіль веде підтверджений представник компанії.</li>
                <li><strong>Бізнес підтверджено</strong> — платформа перевірила, що компанія реальна.</li>
            </ul>

            <h2>Google-відгуки позначені чесно</h2>
            <p>Частину відгуків імпортовано з відкритих джерел — вони мають позначку «Відгук з Google». Так ви завжди знаєте, що залишено безпосередньо на Dovira, а що зібрано з інших платформ.</p>

            <h2>Захист від накруток</h2>
            <p>Гостьові відгуки захищені перевіркою Cloudflare Turnstile та прихованими пастками для ботів, а підозріла активність фільтрується автоматично. Це ускладнює масову накрутку оцінок — як позитивних, так і замовних негативних.</p>

            <h2>Право бізнесу на відповідь</h2>
            <p>Компанії можуть офіційно відповідати на відгуки. Публічний діалог — найчесніший спосіб показати, як бізнес вирішує проблеми, і допомагає майбутнім клієнтам скласти повну картину.</p>

            <h2>Відкрита формула рейтингу</h2>
            <p>Позиції профілів у рейтингах рахуються за відкритою формулою — середня оцінка з поправкою на кількість відгуків, без платних множників. <a href="{{ route('rating.method') }}">Повна методологія рейтингу →</a></p>

            <p style="margin-top:28px;">
                <a class="btn btn--primary" href="{{ route('catalog') }}">Перейти до каталогу</a>
            </p>
        </article>
    </div>
</section>
@endsection
