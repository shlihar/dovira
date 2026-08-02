<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GeneratedProfileLogo
{
    private const DIRECTORY = 'catalog-media/profile-fallbacks';

    /**
     * @var array<int, array{0:string,1:string}>
     */
    // Blue-family only, on brand with the primary accent (#2f6df4) used across
    // badges/buttons — kept varied by shade/saturation rather than by hue, so
    // fallback avatars never clash with the rest of the palette (was: a wide
    // rainbow including violet/amber/red/green).
    private const PALETTES = [
        ['#2563eb', '#8ec5ff'],
        ['#0ea5e9', '#7dd3fc'],
        ['#14b8a6', '#99f6e4'],
        ['#2f6df4', '#c7d9ff'],
        ['#1f68ff', '#bcdcff'],
        ['#0b63d6', '#b7d4ff'],
        ['#1483ff', '#c9e6ff'],
    ];

    public static function ensure(Profile $profile): string
    {
        $path = self::pathFor($profile);

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, self::svgFor($profile));
        }

        return $path;
    }

    public static function isGenerated(?string $path): bool
    {
        return self::normalizePath($path) !== null;
    }

    public static function normalizePath(?string $path): ?string
    {
        $raw = trim((string) $path);
        if ($raw === '') {
            return null;
        }

        $normalized = (string) (parse_url($raw, PHP_URL_PATH) ?: $raw);
        $normalized = ltrim($normalized, '/');
        $normalized = preg_replace('#^storage/#', '', $normalized) ?: $normalized;
        $normalized = preg_replace('#^media/#', '', $normalized) ?: $normalized;

        return Str::startsWith($normalized, self::DIRECTORY . '/') ? $normalized : null;
    }

    private static function pathFor(Profile $profile): string
    {
        $seed = (string) ($profile->slug ?: $profile->name ?: ('profile-' . $profile->id));
        $hash = substr(sha1($seed), 0, 16);
        $slug = Str::slug(Str::ascii((string) ($profile->slug ?: $profile->name))) ?: 'profile';

        return self::DIRECTORY . '/' . substr($hash, 0, 2) . '/' . $slug . '-' . $hash . '.svg';
    }

    private static function svgFor(Profile $profile): string
    {
        $name = trim((string) ($profile->name ?: 'DOVIRA'));
        $initials = self::initials($name);
        $hash = hexdec(substr(sha1($name), 0, 8));
        $palette = self::PALETTES[$hash % count(self::PALETTES)];

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512" role="img" aria-label="%s"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient><filter id="s" x="-20%%" y="-20%%" width="140%%" height="140%%"><feDropShadow dx="0" dy="22" stdDeviation="24" flood-color="#1d4ed8" flood-opacity=".18"/></filter></defs><rect width="512" height="512" rx="160" fill="url(#g)" filter="url(#s)"/><circle cx="392" cy="122" r="94" fill="#fff" opacity=".16"/><circle cx="116" cy="398" r="130" fill="#fff" opacity=".12"/><text x="256" y="286" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="%d" font-weight="800" fill="#fff" letter-spacing="-4">%s</text></svg>',
            e($name),
            $palette[0],
            $palette[1],
            mb_strlen($initials) > 2 ? 132 : 156,
            e($initials)
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
