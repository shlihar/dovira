<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301 з http:// на https://.
 *
 * Без цього сайт віддавав 200 по обох протоколах, і Google індексував
 * http://mydovira.com як окремий сайт-дублікат (canonical на тій версії
 * теж генерувався як http, бо AppServiceProvider форсує https лише за
 * request()->isSecure()). Сигнали ділилися між двома копіями.
 *
 * Циклу за Cloudflare не буде: trustProxies(at: '*') змушує isSecure()
 * читати X-Forwarded-Proto, тому запит, що прийшов до edge по https,
 * вважається захищеним навіть при Flexible SSL до origin.
 */
final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirect($request)) {
            return redirect(
                'https://' . $request->getHttpHost() . $request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        if ($request->isSecure()) {
            return false;
        }

        // Аварійний вимикач: якщо на хостингу щось піде не так зі схемою,
        // редірект гаситься однією змінною без деплою коду.
        if (! (bool) env('FORCE_HTTPS_REDIRECT', true)) {
            return false;
        }

        // Редіректимо лише там, де сайт реально живе на https: локальна
        // розробка (APP_URL=http://127.0.0.1) лишається недоторканою.
        return str_starts_with((string) config('app.url'), 'https://');
    }
}
