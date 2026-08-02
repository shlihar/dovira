<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile: антиспам-перевірка гостьових форм.
 *
 * Вмикається наявністю ключів у конфігу — без них перевірка пропускає всіх
 * (локальна розробка і тести працюють без Cloudflare).
 */
class Turnstile
{
    public static function isEnabled(): bool
    {
        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public static function passes(?string $token, ?string $ip = null): bool
    {
        if (! self::isEnabled()) {
            // На проді відсутні ключі — це майже напевно забутий env у Render
            // Dashboard: капча мовчки вимкнена, лишаються honeypot і throttle.
            if (app()->isProduction()) {
                Log::warning('Turnstile keys are not configured in production — captcha check is silently disabled.');
            }

            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            return $response->ok() && (bool) $response->json('success');
        } catch (\Throwable $exception) {
            Log::warning('Turnstile verification failed.', [
                'message' => $exception->getMessage(),
            ]);

            // Збій Cloudflare не має блокувати легітимних користувачів.
            return true;
        }
    }
}
