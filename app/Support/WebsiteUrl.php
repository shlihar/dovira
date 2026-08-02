<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class WebsiteUrl
{
    private const PLACEHOLDER_HOSTS = [
        'example.com',
        'example.org',
        'example.net',
        'dovira.org',
        'localhost',
        '127.0.0.1',
    ];

    public static function href(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = Str::startsWith($raw, ['http://', 'https://']) ? $raw : ('https://' . ltrim($raw, '/'));
        $host = self::normalizedHost($normalized);

        if (! filter_var($normalized, FILTER_VALIDATE_URL) || $host === '' || in_array($host, self::PLACEHOLDER_HOSTS, true)) {
            return null;
        }

        return $normalized;
    }

    public static function display(?string $value): ?string
    {
        $href = self::href($value);
        if ($href === null) {
            return null;
        }

        $host = self::normalizedHost($href);
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? '');

        $path = rtrim($path, '/');

        return $host . $path;
    }

    private static function normalizedHost(string $href): string
    {
        $host = (string) (parse_url($href, PHP_URL_HOST) ?? '');
        $host = Str::lower($host);

        return Str::startsWith($host, 'www.') ? (string) Str::after($host, 'www.') : $host;
    }
}
