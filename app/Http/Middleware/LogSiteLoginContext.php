<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSiteLoginContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || $request->route()?->getName() !== 'login') {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $sessionToken = $request->hasSession() ? (string) $request->session()->token() : '';
        $formToken = (string) $request->input('_token', '');
        $headerToken = (string) ($request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN') ?: '');

        Log::info('site_login.request', [
            'email' => (string) $request->input('email', ''),
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

        /** @var Response $response */
        $response = $next($request);

        Log::info('site_login.response', [
            'email' => (string) $request->input('email', ''),
            'host' => $request->getHost(),
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'set_cookie_headers' => $response->headers->getCookies()
                ? array_map(static fn ($cookie) => $cookie->getName(), $response->headers->getCookies())
                : [],
        ]);

        return $response;
    }
}
