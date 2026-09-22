<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class TopRatingIndexCache
{
    // v4: іконки категорій змінені на FA-гліфи з сабсету (порожні квадрати
    // зникли) — бампимо ключ, щоб після деплою хаб не віддав старий кеш.
    public const KEY = 'public:top-rating:index:v4';

    private const TTL_SECONDS = 21600;

    /**
     * @template T of array
     *
     * @param  callable():T  $resolver
     * @return T
     */
    public static function remember(callable $resolver): array
    {
        return Cache::remember(
            self::KEY,
            now()->addSeconds(self::TTL_SECONDS),
            $resolver
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
