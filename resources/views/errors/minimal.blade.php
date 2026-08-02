{{-- Автономний layout сторінок помилок: без header/footer сайту, бо ті
     ходять у БД — 500-ка має рендеритись, навіть коли база лежить. --}}
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — DOVIRA</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background: #f6f7f9;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .error-card {
            background: #ffffff;
            border: 1px solid #e5e8ee;
            border-radius: 24px;
            padding: 48px 40px;
            max-width: 460px;
            width: 100%;
            text-align: center;
        }
        .error-card__brand {
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .12em;
            color: #64748b;
        }
        .error-card__code {
            margin-top: 18px;
            font-size: 4rem;
            font-weight: 800;
            line-height: 1;
            color: #1e293b;
        }
        .error-card__title { margin-top: 12px; font-size: 1.25rem; font-weight: 700; }
        .error-card__text { margin-top: 10px; color: #64748b; font-size: .98rem; line-height: 1.55; }
        .error-card__actions { margin-top: 28px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .error-card__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 22px;
            border-radius: 999px;
            font-size: .95rem;
            font-weight: 600;
            text-decoration: none;
            background: #2563eb;
            color: #ffffff;
        }
        .error-card__btn:hover { background: #1d4ed8; }
        .error-card__btn--ghost {
            background: transparent;
            color: #334155;
            border: 1px solid #d8dde6;
        }
        .error-card__btn--ghost:hover { background: #f6f7f9; }
    </style>
</head>
<body>
    <main class="error-card">
        <div class="error-card__brand">DOVIRA</div>
        <div class="error-card__code">@yield('code')</div>
        <h1 class="error-card__title">@yield('title')</h1>
        <p class="error-card__text">@yield('message')</p>
        <div class="error-card__actions">
            <a class="error-card__btn" href="/">На головну</a>
            <a class="error-card__btn error-card__btn--ghost" href="/catalog">Каталог виконавців</a>
        </div>
    </main>
</body>
</html>
