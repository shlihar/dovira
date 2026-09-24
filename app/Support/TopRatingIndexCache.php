<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class TopRatingIndexCache
{
    // v5: хаб рейтингів перебудовано на стрічку статей із місячних зрізів —
    // форма payload змінилась, тож ключ бампимо, інакше віддасть старий кеш.
    public const KEY = 'public:top-rating:index:v5';

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
