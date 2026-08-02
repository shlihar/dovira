<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render (like Heroku/Fly.io) always proxies traffic through its own
        // edge layer — the app container is never reached directly from the
        // public internet. Without this, $request->ip() returns the proxy's
        // IP for every visitor instead of the real client IP from
        // X-Forwarded-For, which silently broke per-IP rate limiting and the
        // IP-based city detection on the home page (both saw one shared IP).
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (TokenMismatchException $exception): void {
            /** @var Request $request */
            $request = app('request');

            $sessionToken = $request->hasSession() ? (string) $request->session()->token() : '';
            $formToken = (string) $request->input('_token', '');
            $headerToken = (string) ($request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN') ?: '');

            Log::warning('csrf.token_mismatch', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'host' => $request->getHost(),
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'origin' => (string) $request->headers->get('origin', ''),
                'referer' => (string) $request->headers->get('referer', ''),
                'has_session' => $request->hasSession(),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'session_cookie_name' => config('session.cookie'),
                'has_session_cookie' => $request->cookies->has((string) config('session.cookie')),
                'has_xsrf_cookie' => $request->cookies->has('XSRF-TOKEN'),
                'cookie_names' => array_keys($request->cookies->all()),
                'has_form_token' => $formToken !== '',
                'has_header_token' => $headerToken !== '',
                'form_token_hash' => $formToken !== '' ? hash('sha256', $formToken) : null,
                'header_token_hash' => $headerToken !== '' ? hash('sha256', $headerToken) : null,
                'session_token_hash' => $sessionToken !== '' ? hash('sha256', $sessionToken) : null,
                'form_matches_session' => $formToken !== '' && $sessionToken !== '' ? hash_equals($sessionToken, $formToken) : null,
                'header_matches_session' => $headerToken !== '' && $sessionToken !== '' ? hash_equals($sessionToken, $headerToken) : null,
            ]);
        });
    })->create();
