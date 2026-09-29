<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Захист від clickjacking тримає CSP frame-ancestors: X-Frame-Options не
        // вміє списків доменів, а панель Plerdy відкриває сторінки сайту в
        // рамці, щоб показати теплову карту (без цього вона пише «Підключення
        // заблоковано»). Заголовок лишаємо там, де CSP не віддаємо, — в адмінці.
        // Виняток: /widget/* створений саме для вбудовування на сторонні сайти,
        // тому frame-ancestors для нього виставляє WidgetController.
        if ($request->is('admin', 'admin/*')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Вимикаємо потенційно небезпечні браузерні API, які платформа не використовує.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=()'
        );

        // HSTS — лише на HTTPS у production, щоб не заблокувати локальну розробку.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // CSP — defense-in-depth проти XSS. Дозволяємо власні скрипти + inline
        // (сайт має чимало inline-скриптів/стилів), Google Fonts, Turnstile,
        // Microsoft Clarity та data:-картинки. frame-ancestors керує
        // вбудовуванням (widget — окремо).
        //
        // Адмінку (Filament) НЕ чіпаємо: вона на Alpine/Livewire, яким потрібен
        // 'unsafe-eval', а bunny.net-шрифти й свої asset-домени вимагали б окремих
        // директив. Це довірена авторизована зона без анонімного user-контенту,
        // тож сувора CSP там не потрібна — інакше логін зависає на Alpine-помилках.
        if (! $request->routeIs('widget.*')
            && ! $request->is('admin', 'admin/*')
            && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // Аналітика: Clarity (теплові карти) і Plerdy (кліки, записи сесій).
                // 'unsafe-eval' потрібен саме Plerdy: його модулі виконують код
                // із рядків, і без цього падає частина трекінгу (GA-події,
                // SEO-перевірки). Захисна цінність втрачається небагато —
                // 'unsafe-inline' тут і так дозволений через inline-скрипти сайту.
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://*.clarity.ms https://a.plerdy.com https://*.plerdy.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https:",
                // Відео в кейсах PRO лежать на сайтах самих компаній, тому
                // media-src мусить дозволяти будь-який https-хост: без цього
                // браузер навіть не робить запит (NETWORK_NO_SOURCE) і плеєр
                // мовчки лишається порожнім.
                "media-src 'self' https: blob:",
                // Plerdy малює свою панель і плеєр записів у власному iframe
                // просто на сторінці сайту — без цього розширення пише
                // «Підключення заблоковано».
                "frame-src 'self' https://challenges.cloudflare.com https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com https://plerdy.com https://*.plerdy.com",
                "connect-src 'self' https://*.clarity.ms https://a.plerdy.com https://*.plerdy.com",
                // Plerdy показує теплові карти, вбудовуючи сторінку в рамку.
                "frame-ancestors 'self' https://plerdy.com https://*.plerdy.com",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
