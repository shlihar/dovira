<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class SitemapCache
{
    private const KEY_PREFIX = 'public:sitemap:v4:';

    private const TTL_SECONDS = 21600;

    /**
     * Стеля файлів профілів у sitemap-індексі. forget() чистить фіксований
     * набір ключів (cache-драйвер database не має тегів), тож межа має
     * покривати найбільший можливий каталог: 50 × 45 000 = 2.25 млн профілів.
     */
    public const PROFILE_PAGES_MAX = 50;

    /**
     * @param  callable():string  $resolver
     */
    public static function remember(string $variant, callable $resolver): string
    {
        return self::rememberPayload($variant, $resolver);
    }

    /**
     * @param  callable():array<int, mixed>  $resolver
     * @return array<int, mixed>
     */
    public static function rememberEntries(string $variant, callable $resolver): array
    {
        return self::rememberPayload($variant, $resolver);
    }

    /**
     * Перевіряємо політику і для готового XML, і для списку профілів.
     * Інакше зміна strict/min-description залишає старий sitemap на 6 годин.
     * Стабільні ключі дозволяють forget() скидати всі варіанти як раніше.
     *
     * @param  callable(): (string|array)  $resolver
     */
    private static function rememberPayload(string $variant, callable $resolver): string|array
    {
        $key = self::key($variant);
        $policy = [config('seo.profile_indexing_mode'), ProfileSeoIndexPolicy::strictEnabled(), ProfileSeoIndexPolicy::minDescriptionLength(), ProfileSeoIndexPolicy::reviewedProfileIds()];
        $cached = Cache::get($key);

        if (! is_array($cached) || ($cached['policy'] ?? null) !== $policy || ! array_key_exists('value', $cached)) {
            $cached = ['policy' => $policy, 'value' => $resolver()];
            Cache::put($key, $cached, now()->addSeconds(self::TTL_SECONDS));
        }

        return $cached['value'];
    }

    /**
     * Скидає індекс і всі дочірні sitemap-и разом із кешем списку профілів.
     * Драйвер database не підтримує теги, тому перебираємо відомі варіанти.
     */
    public static function forget(): void
    {
        foreach (self::variants() as $variant) {
            Cache::forget(self::key($variant));
        }
    }

    /**
     * @return array<int, string>
     */
    private static function variants(): array
    {
        $variants = ['index', 'static', 'landing', 'ratings', 'profile-entries', 'profile-entries-strict'];

        for ($page = 1; $page <= self::PROFILE_PAGES_MAX; $page++) {
            $variants[] = 'profiles-'.$page;
        }

        return $variants;
    }

    private static function key(string $variant): string
    {
        $root = rtrim((string) (config('app.url') ?: url('/')), '/');

        return self::KEY_PREFIX.md5($root.'|'.$variant);
    }
}
