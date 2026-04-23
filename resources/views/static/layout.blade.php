<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DOVIRA')</title>
    <meta name="description" content="@yield('description', 'Платформа чесних відгуків про компанії, магазини та спеціалістів.')">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('static/styles.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @stack('head')
</head>
<body class="@yield('body_class')">
    @include('static.partials.header')
    <div class="menu-overlay" data-overlay hidden></div>

    <main>
        @yield('content')
    </main>

    @include('static.partials.footer')

    <script src="{{ asset('static/app.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
