<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DOVIRA')</title>
    <meta name="description" content="@yield('description', 'Платформа чесних відгуків про адвокатів та компанії.')">

    <link rel="stylesheet" href="{{ asset('static/styles.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @stack('head')
</head>
<body>
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
