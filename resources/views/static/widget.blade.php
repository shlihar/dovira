<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $profile->name }} — рейтинг на DOVIRA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            background: transparent;
        }
        .dw {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
            padding: 14px 16px;
            border: 1px solid #e4e9f2;
            border-radius: 14px;
            background: #ffffff;
            text-decoration: none;
            overflow: hidden;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .dw:hover {
            border-color: #2563eb;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.12);
        }
        .dw__brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        /* Бренд-пілюля — точна копія лого платформи: білий щит + «dovira»
           на синьому тлі. */
        .dw__brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px 4px 8px;
            border-radius: 8px;
            background: linear-gradient(135deg, #175cf0 0%, #2d76ff 100%);
            flex: none;
        }
        .dw__brand-pill svg { width: 13px; height: 13px; }
        .dw__logo {
            font-size: 13px;
            font-weight: 650;
            letter-spacing: 0.01em;
            color: #ffffff;
        }
        .dw__tagline { font-size: 11px; color: #64748b; }
        .dw__name {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dw__row { display: flex; align-items: center; gap: 8px; }
        .dw__stars { position: relative; width: 88px; height: 16px; flex: none; }
        .dw__stars svg { position: absolute; inset: 0; }
        .dw__stars-gold { clip-path: inset(0 {{ 100 - (int) round($rating / 5 * 100) }}% 0 0); }
        .dw__rating { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; color: #1e293b; }
        .dw__count { font-size: 12px; color: #64748b; }
        .dw__cta {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            align-self: flex-start;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            transition: background 0.15s ease;
        }
        .dw:hover .dw__cta { background: rgba(37, 99, 235, 0.14); }
    </style>
</head>
<body>
    @php
        $widgetStarPath = 'M8 0l2.2 4.9 5.2.5-4 3.6 1.2 5.2L8 11.5 3.4 14.2 4.6 9 0.6 5.4l5.2-.5z';
        // Font Awesome 6 Free shield-halved — той самий знак, що в шапці сайту.
        $widgetShieldPath = 'M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0zm0 66.8l0 378.1C394 378 431.1 230.1 432 141.4L256 66.8s0 0 0 0z';
    @endphp
    <a class="dw" href="{{ $profileUrl }}" target="_blank" rel="noopener">
        <span class="dw__brand">
            <span class="dw__brand-pill">
                <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" fill="#ffffff" aria-hidden="true">
                    <path d="{{ $widgetShieldPath }}"/>
                </svg>
                <span class="dw__logo">dovira</span>
            </span>
            <span class="dw__tagline">рейтинг довіри</span>
        </span>
        <span class="dw__name">{{ $profile->name }}</span>
        <span class="dw__row">
            <span class="dw__stars" aria-hidden="true">
                <svg viewBox="0 0 88 16" fill="#dbe3ee" xmlns="http://www.w3.org/2000/svg">
                    @for ($i = 0; $i < 5; $i++)
                        <path d="{{ $widgetStarPath }}" transform="translate({{ $i * 18 }},0)"/>
                    @endfor
                </svg>
                <svg class="dw__stars-gold" viewBox="0 0 88 16" fill="#f5a623" xmlns="http://www.w3.org/2000/svg">
                    @for ($i = 0; $i < 5; $i++)
                        <path d="{{ $widgetStarPath }}" transform="translate({{ $i * 18 }},0)"/>
                    @endfor
                </svg>
            </span>
            <span class="dw__rating">{{ $ratingLabel }}</span>
            <span class="dw__count">{{ $reviewsLabel }}</span>
        </span>
        <span class="dw__cta">Читати відгуки на DOVIRA →</span>
    </a>
</body>
</html>
