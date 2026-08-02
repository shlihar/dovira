<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshAuthPageState
{
    private const AUTH_PAGE_ROUTE_NAMES = [
        'login',
        'register',
        'password.request',
        'password.reset',
        'filament.admin.auth.login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $shouldRefresh = $request->isMethod('GET') && in_array($routeName, self::AUTH_PAGE_ROUTE_NAMES, true);

        if ($shouldRefresh && $request->hasSession()) {
            $request->session()->regenerateToken();
        }

        /** @var Response $response */
        $response = $next($request);

        if ($shouldRefresh) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');
        }

        return $response;
    }
}
