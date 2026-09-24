<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;

/**
 * Тематична обкладинка рейтингу: фото під категорію, якщо воно є в проєкті.
 *
 * Шукаємо у такому порядку: явна мапа з config/top_rating_covers.php →
 * файл {covers_path}/{slug}.(jpg|webp|png) → те саме для батьківської
 * категорії. Якщо нічого немає — повертаємо null, і сторінка малює
 * градієнт зі значком категорії.
 */
final class TopRatingCover
{
    /** @var array<string, ?string> */
    private static array $cache = [];

    public static function forCategory(?Category $category): ?string
    {
        if ($category === null) {
            return null;
        }

        $slug = (string) $category->slug;

        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }

        $url = self::resolve($slug);

        if ($url === null && $category->parent_id) {
            $parentSlug = Category::query()->whereKey($category->parent_id)->value('slug');

            if (is_string($parentSlug)) {
                $url = self::resolve($parentSlug);
            }
        }

        return self::$cache[$slug] = $url;
    }

    private static function resolve(string $slug): ?string
    {
        $mapped = (string) (config('top_rating_covers.map')[$slug] ?? '');

        if ($mapped !== '' && is_file(public_path($mapped))) {
            return asset($mapped);
        }

        $base = trim((string) config('top_rating_covers.covers_path', 'static/img/top-covers'), '/');

        foreach (['jpg', 'jpeg', 'webp', 'png'] as $ext) {
            $relative = $base . '/' . $slug . '.' . $ext;

            if (is_file(public_path($relative))) {
                return asset($relative);
            }
        }

        return null;
    }
}
