<?php

namespace App\Http\Controllers;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * On-the-fly image thumbnails.
 *
 * Serves a resized WebP version of a stored media image (profile logos/photos
 * are imported at full resolution but shown as small cards). Resized files are
 * cached to storage/app/public/thumbs so each size is generated only once.
 */
class MediaThumbController extends Controller
{
    /** Whitelisted widths — prevents attackers from filling disk with arbitrary sizes. */
    private const ALLOWED_WIDTHS = [64, 96, 128, 160, 200, 240, 320, 400, 480, 640, 800];

    /** Only these storage folders may be resized. */
    private const ALLOWED_PREFIXES = ['catalog-media/', 'reviews-media/', 'profiles/', 'reviews/'];

    private const RASTER = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __invoke(Request $request, int $width, string $path): BinaryFileResponse
    {
        if (! in_array($width, self::ALLOWED_WIDTHS, true)) {
            abort(404);
        }

        $path = ltrim(urldecode($path), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }
        if (! Str::startsWith($path, self::ALLOWED_PREFIXES)) {
            abort(404);
        }

        // Нові URL мають статичний суфікс .webp (кеш = реальний файл у
        // public/media/thumb, який веб-сервер віддає повз PHP). Для пошуку
        // джерела суфікс знімаємо, якщо під ним власне raster-розширення.
        if (str_ends_with($path, '.webp')) {
            $inner = substr($path, 0, -5);
            $innerExt = strtolower(pathinfo($inner, PATHINFO_EXTENSION));
            if (in_array($innerExt, self::RASTER, true)) {
                $path = $inner;
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, self::RASTER, true)) {
            abort(404);
        }

        $source = MediaUrl::physicalPathForLocalMedia($path);
        if ($source === null || ! is_file($source)) {
            abort(404);
        }

        // Кеш у public — під тим самим URL: наступні запити не доходять до PHP.
        $cache = public_path('media/thumb/' . $width . '/' . $path . '.webp');

        if (! is_file($cache) || filemtime($cache) < filemtime($source)) {
            MediaUrl::generateThumbFile($source, $cache, $width);
        }

        // Generation may fail (corrupt image / animated gif) — serve the original.
        $file = is_file($cache) ? $cache : $source;

        return response()->file($file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
