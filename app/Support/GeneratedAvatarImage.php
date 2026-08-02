<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GeneratedAvatarImage
{
    private const DIRECTORY = 'catalog-media/avatar-fallbacks';

    /**
     * @var array<int, array{0:string,1:string}>
     */
    private const PALETTES = [
        ['#2563eb', '#8ec5ff'],
        ['#0ea5e9', '#7dd3fc'],
        ['#14b8a6', '#99f6e4'],
        ['#2f6df4', '#c7d9ff'],
        ['#1f68ff', '#bcdcff'],
        ['#0b63d6', '#b7d4ff'],
        ['#1483ff', '#c9e6ff'],
    ];

    public static function ensure(?string $seed): ?string
    {
        $name = trim((string) $seed);
        if ($name === '') {
            return null;
        }

        $path = self::pathFor($name);

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, self::svgFor($name));
        }

        return $path;
    }

    private static function pathFor(string $name): string
    {
        $hash = substr(sha1($name), 0, 16);
        $slug = Str::slug(Str::ascii($name)) ?: 'avatar';

        return self::DIRECTORY . '/' . substr($hash, 0, 2) . '/' . $slug . '-' . $hash . '.svg';
    }

    private static function svgFor(string $name): string
    {
        $initials = self::initials($name);
        $hash = hexdec(substr(sha1($name), 0, 8));
        $palette = self::PALETTES[$hash % count(self::PALETTES)];

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="%s"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient></defs><rect width="256" height="256" rx="82" fill="url(#g)"/><circle cx="194" cy="62" r="48" fill="#fff" opacity=".16"/><circle cx="58" cy="204" r="68" fill="#fff" opacity=".12"/><text x="128" y="146" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="%d" font-weight="800" fill="#fff">%s</text></svg>',
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            $palette[0],
            $palette[1],
            mb_strlen($initials) > 2 ? 62 : 76,
            htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
        );
    }

    private static function initials(string $name): string
    {
        $parts = collect(preg_split('/\s+/u', $name) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter(fn (string $part): bool => $part !== '' && ! preg_match('/^[\W_]+$/u', $part))
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)));

        $initials = $parts->implode('');

        return $initials !== '' ? $initials : 'D';
    }
}
