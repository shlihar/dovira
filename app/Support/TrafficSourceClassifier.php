<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Єдина класифікація джерела перегляду сайту для аналітичних віджетів.
 *
 * Раніше логіка дублювалася в двох віджетах і НЕ виключала власний домен:
 * внутрішня навігація (перехід між сторінками Dovira) має referrer нашого ж
 * хоста, тож потрапляла у «Referral» і роздувала його власним трафіком.
 * Тут same-origin referrer → «Внутрішній перехід».
 */
class TrafficSourceClassifier
{
    public const INTERNAL = 'Внутрішній перехід';

    public const UNKNOWN = 'Невідомо';

    public static function label(?string $source, ?string $internalSource, ?string $referrer): string
    {
        $sourceValue = Str::lower(trim((string) $source));
        $internalValue = Str::lower(trim((string) $internalSource));
        $referrerValue = Str::lower(trim((string) $referrer));

        // Перехід із нашої ж сторінки (referrer того самого хоста) або явний
        // internal-маркер — це внутрішня навігація, а не зовнішнє джерело.
        // Значення source 'referral'/'internal' на таких переглядах ставить сам
        // фронтенд, коли UTM немає, тож не даємо їм осісти в «Referral».
        if (self::isSameOriginReferrer($referrerValue)
            || $sourceValue === 'internal'
            || $internalValue !== '') {
            return self::INTERNAL;
        }

        if ($sourceValue !== '') {
            return match (true) {
                Str::contains($sourceValue, 'google') => 'Google',
                Str::contains($sourceValue, ['facebook', 'fb']) => 'Facebook',
                Str::contains($sourceValue, ['instagram', 'ig']) => 'Instagram',
                Str::contains($sourceValue, ['telegram', 'tg']) => 'Telegram',
                Str::contains($sourceValue, 'direct') => 'Direct',
                default => ucfirst($sourceValue),
            };
        }

        if ($referrerValue !== '') {
            return match (true) {
                Str::contains($referrerValue, 'google.') => 'Google',
                Str::contains($referrerValue, 'facebook.') => 'Facebook',
                Str::contains($referrerValue, 'instagram.') => 'Instagram',
                Str::contains($referrerValue, 't.me') => 'Telegram',
                default => 'Referral',
            };
        }

        return self::UNKNOWN;
    }

    private static function isSameOriginReferrer(string $referrerValue): bool
    {
        if ($referrerValue === '') {
            return false;
        }

        $ownHost = Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $ownHost = (string) preg_replace('/^www\./', '', $ownHost);

        return $ownHost !== '' && Str::contains($referrerValue, $ownHost);
    }
}
