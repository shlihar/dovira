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

        // Захист від clickjacking, MIME-sniffing і витоку реферера на сторонні сайти.
        // Виняток: /widget/* створений саме для вбудовування на сторонні сайти,
        // тому frame-ancestors для нього виставляє WidgetController.
        if (! $request->routeIs('widget.*')) {
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
        // (сайт має чимало inline-скриптів/стилів), Google Fonts, Turnstile та
        // data:-картинки. frame-ancestors керує вбудовуванням (widget — окремо).
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
                "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https:",
                "frame-src 'self' https://challenges.cloudflare.com",
                "connect-src 'self'",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
