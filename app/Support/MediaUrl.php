<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MediaUrl
{
    public static function publicUrl(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $localPublicPath = self::localPublicPathFromAbsoluteUrl($raw);
        if ($localPublicPath !== null) {
            return self::publicUrl($localPublicPath);
        }

        if (Str::startsWith($raw, ['http://', 'https://', '//'])) {
            return $raw;
        }

        if (Str::startsWith($raw, ['/media/', 'media/'])) {
            return '/' . ltrim((string) preg_replace('#^/?media/#', 'media/', $raw), '/');
        }

        if (Str::startsWith($raw, ['/storage/', 'storage/'])) {
            return self::publicMediaPath(
                ltrim((string) preg_replace('#^/?storage/#', '', $raw), '/')
            );
        }

        if (Str::startsWith($raw, '/')) {
            return $raw;
        }

        return self::publicMediaPath($raw);
    }

    /**
     * Resolve image URLs while suppressing known placeholders and missing local files.
     *
     * @param  array<int, string>  $placeholderPaths
     */
    public static function publicImageUrl(?string $value, array $placeholderPaths = []): ?string
    {
        $value = self::unproxyImportResizeUrl($value);

        if (self::isKnownPlaceholderPath($value)) {
            return null;
        }

        $url = self::publicUrl($value);
        if ($url === null) {
            return null;
        }

        if (self::isKnownPlaceholderPath($url)) {
            return null;
        }

        $path = strtolower(trim((string) parse_url($url, PHP_URL_PATH), '/'));
        if ($path === '') {
            return null;
        }

        $normalizedPlaceholders = array_map(
            static fn (string $item): string => strtolower(trim((string) parse_url($item, PHP_URL_PATH), '/')),
            $placeholderPaths
        );

        if (in_array($path, $normalizedPlaceholders, true)) {
            return null;
        }

        $localStoragePath = self::existingLocalStoragePath($value);
        if ($localStoragePath !== null) {
            return self::publicUrl($localStoragePath);
        }

        if (self::localStoragePath($value) !== null) {
            return null;
        }

        return $url;
    }

    /**
     * URL of a resized WebP thumbnail for a stored raster image.
     *
     * Local managed JPEG/PNG/WebP images are routed through the thumbnail
     * endpoint; external URLs, placeholders and non-raster (e.g. SVG) files
     * fall back to their normal public URL untouched.
     */
    /**
     * Підганяє розмір зовнішнього зображення під ширину показу.
     *
     * Наразі лише Google user-content (lh*.googleusercontent.com / ggpht.com),
     * який підтримує директиву `=s{px}`. Ставимо ширину×2 (retina), обмежену
     * [48, 1024]. Наявну розмірну директиву (=s800, =w800-h600, =s0-c-rp…)
     * замінюємо; якщо її нема — додаємо. Для інших хостів URL не чіпаємо.
     */
    public static function sizedRemoteImageUrl(string $url, int $width): string
    {
        if (! str_contains($url, 'googleusercontent.com') && ! str_contains($url, 'ggpht.com')) {
            return $url;
        }

        $target = max(48, min(1024, $width * 2));
        $base = preg_replace('#=[sw]\d[\w-]*$#', '', $url) ?? $url;

        return $base . '=s' . $target;
    }

    public static function thumbUrl(?string $value, int $width): ?string
    {
        $value = self::sourcePathFromThumbUrl($value) ?? $value;
        $relative = self::localStoragePath($value);
        if ($relative === null) {
            // Зовнішній/некерований URL. Для Google user-content підганяємо
            // розмірну директиву під ширину показу (=s{width*2} для retina), щоб
            // не тягнути повнорозмірне фото на дрібну аватарку/лого.
            $url = self::publicImageUrl($value);

            return $url === null ? null : self::sizedRemoteImageUrl($url, $width);
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return self::publicImageUrl($value);
        }

        $source = self::resolvePhysicalLocalPath($relative);
        if ($source === null) {
            return self::publicImageUrl($value);
        }

        // Генеруємо файл-кеш ОДРАЗУ при формуванні URL: тоді адреса завжди
        // вказує на реальний файл, і роздача працює навіть на хостингах,
        // які 404-лять відсутні .webp статикою, не передаючи запит у PHP.
        // Разово на файл; далі — лише is_file(). PHP-роут лишається страховкою.
        $cache = public_path('media/thumb/' . $width . '/' . $relative . '.webp');
        if (! is_file($cache) || filemtime($cache) < filemtime($source)) {
            self::generateThumbFile($source, $cache, $width);
        }

        // Файл не створився (нема прав на public/, нема WebP у GD тощо) —
        // віддаємо старий надійний URL оригіналу через /media/{path}.
        if (! is_file($cache)) {
            return self::publicImageUrl($value);
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));

        // Суфікс .webp робить URL адресою РЕАЛЬНОГО файлу-кеша в public/:
        // мініатюру віддає веб-сервер статично, без бутстрапа Laravel.
        return '/media/thumb/' . $width . '/' . $encoded . '.webp';
    }

    public static function profileLogoUrl(Profile $profile, int $width): ?string
    {
        $url = self::thumbUrl($profile->logo_url, $width);
        if ($url !== null) {
            return $url;
        }

        try {
            $fallbackPath = GeneratedProfileLogo::ensure($profile);
        } catch (Throwable) {
            return null;
        }

        return self::publicImageUrl($fallbackPath);
    }

    public static function realProfileLogoUrl(Profile $profile, int $width): ?string
    {
        $source = trim((string) $profile->logo_url);
        if ($source === '' || GeneratedProfileLogo::isGenerated($source)) {
            return null;
        }

        $url = self::thumbUrl($source, $width);
        if ($url === null || GeneratedProfileLogo::isGenerated($url)) {
            return null;
        }

        return $url;
    }

    public static function avatarUrl(?string $value, ?string $seed, int $width): ?string
    {
        $url = self::thumbUrl($value, $width);
        if ($url !== null) {
            return $url;
        }

        try {
            $fallbackPath = GeneratedAvatarImage::ensure($seed);
        } catch (Throwable) {
            return null;
        }

        return self::publicImageUrl($fallbackPath);
    }

    /**
     * Ресайз у WebP через GD. Тихий: невдача (битий файл, нема прав) просто
     * не створює кеш — тоді запит піде через PHP-роут, який віддасть оригінал.
     */
    public static function generateThumbFile(string $source, string $cache, int $width): void
    {
        try {
            if (! function_exists('imagewebp') || ! function_exists('imagecreatetruecolor')) {
                return;
            }

            $info = @getimagesize($source);
            if ($info === false) {
                return;
            }

            [$srcW, $srcH] = $info;
            if ($srcW < 1 || $srcH < 1) {
                return;
            }

            $img = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                IMAGETYPE_PNG => @imagecreatefrompng($source),
                IMAGETYPE_WEBP => @imagecreatefromwebp($source),
                IMAGETYPE_GIF => @imagecreatefromgif($source),
                default => null,
            };
            if (! $img) {
                return;
            }

            $dstW = min($width, $srcW); // never upscale
            $dstH = max(1, (int) round($srcH * $dstW / $srcW));

            $dst = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

            $dir = dirname($cache);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @imagewebp($dst, $cache, 80);

            imagedestroy($img);
            imagedestroy($dst);
        } catch (\Throwable $e) {
            // Тихо: фолбек — PHP-роут з оригіналом.
        }
    }

    /**
     * Ресайз-проксі джерела імпорту (media-resize-url/{w,h}/{base64}) ховає
     * справжню адресу картинки. Розгортаємо до оригінального URL (зазвичай
     * googleusercontent): не світимо стороннє джерело в HTML і не залежимо
     * від його аптайму.
     */
    private static function unproxyImportResizeUrl(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || ! str_contains($raw, 'media-resize-url/')) {
            return $value;
        }

        if (preg_match('#https?://[^/]+/media-resize-url/[^/]+/([A-Za-z0-9+/=_-]+)#', $raw, $matches)) {
            $decoded = base64_decode(strtr($matches[1], '-_', '+/'), true);
            if (is_string($decoded) && str_starts_with($decoded, 'http')) {
                return $decoded;
            }
        }

        return $value;
    }

    public static function physicalPathForLocalMedia(string $path): ?string
    {
        $normalizedPath = self::localStoragePath($path);
        if ($normalizedPath === null) {
            return null;
        }

        return self::resolvePhysicalLocalPath($normalizedPath);
    }

    public static function isKnownPlaceholderPath(?string $value): bool
    {
        $path = self::normalizeComparablePath($value);
        if ($path === '') {
            return false;
        }

        return $path === 'img/empty.png'
            || str_ends_with($path, '/img/empty.png')
            || str_ends_with($path, '/empty.png')
            || preg_match('#^img/avatars/icons_uzer_v\d+\.(png|jpe?g|webp|gif)$#i', $path) === 1;
    }

    private static function existingLocalStoragePath(?string $value): ?string
    {
        $path = self::localStoragePath($value);
        if ($path === null) {
            return null;
        }

        return self::resolvePhysicalLocalPath($path) !== null ? $path : null;
    }

    private static function localStoragePath(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $thumbSourcePath = self::sourcePathFromThumbUrl($raw);
        if ($thumbSourcePath !== null) {
            $raw = $thumbSourcePath;
        }

        $localPublicPath = self::localPublicPathFromAbsoluteUrl($raw);
        if ($localPublicPath !== null) {
            $raw = $localPublicPath;
        }

        $generatedPath = GeneratedProfileLogo::normalizePath($raw);
        if ($generatedPath !== null) {
            return $generatedPath;
        }

        if (Str::startsWith($raw, ['http://', 'https://', '//'])) {
            return null;
        }

        $path = trim((string) (parse_url($raw, PHP_URL_PATH) ?: $raw));
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['/storage/', 'storage/'])) {
            return ltrim((string) preg_replace('#^/?storage/#', '', $path), '/');
        }

        if (Str::startsWith($path, ['/media/', 'media/'])) {
            return ltrim((string) preg_replace('#^/?media/#', '', $path), '/');
        }

        if (Str::startsWith($path, '/')) {
            return null;
        }

        $normalizedPath = ltrim($path, '/');

        if (self::isManagedLocalPath($normalizedPath) || Storage::disk('public')->exists($normalizedPath)) {
            return $normalizedPath;
        }

        return null;
    }

    private static function publicMediaPath(string $path): string
    {
        return '/media/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private static function resolvePhysicalLocalPath(string $path): ?string
    {
        $normalizedPath = ltrim($path, '/');
        if ($normalizedPath === '') {
            return null;
        }

        if (Storage::disk('public')->exists($normalizedPath)) {
            return Storage::disk('public')->path($normalizedPath);
        }

        $candidates = [
            public_path('storage/' . $normalizedPath),
            public_path($normalizedPath),
            storage_path('app/public/' . $normalizedPath),
            storage_path('app/' . $normalizedPath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isManagedLocalPath(string $path): bool
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return false;
        }

        $managedPrefixes = array_filter([
            trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/') . '/',
            'profiles/',
            'profile-logos/',
            'profiles-gallery/',
            'reviews-media/',
            'categories/',
        ]);

        return Str::startsWith($path, $managedPrefixes);
    }

    private static function sourcePathFromThumbUrl(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $path = (string) (parse_url($raw, PHP_URL_PATH) ?: $raw);
        $path = rawurldecode($path);
        $path = ltrim($path, '/');

        if (! preg_match('#^media/thumb/\d+/(.+)\.webp$#', $path, $matches)) {
            return null;
        }

        $source = $matches[1];
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? $source
            : null;
    }

    private static function localPublicPathFromAbsoluteUrl(string $value): ?string
    {
        if (! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);
        if ($path === '' || ! Str::startsWith($path, ['/media/', '/storage/'])) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $knownHosts = array_filter(array_map(
            static fn (?string $item): string => strtolower(trim((string) $item)),
            [
                'localhost',
                '127.0.0.1',
                '::1',
                parse_url((string) config('app.url'), PHP_URL_HOST),
                request()?->getHost(),
            ]
        ));

        if (! in_array($host, $knownHosts, true)) {
            return null;
        }

        return $path;
    }

    private static function normalizeComparablePath(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $path = strtolower(trim((string) (parse_url($raw, PHP_URL_PATH) ?: $raw), '/'));
        if ($path === '') {
            return '';
        }

        $path = preg_replace('#^storage/#', '', $path) ?: $path;
        $path = preg_replace('#^media/#', '', $path) ?: $path;

        return trim($path, '/');
    }
}
