<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Розбір посилання на відео в кейсі профілю.
 *
 * Три різні речі за одним полем вводу, бо власник просто вставляє посилання
 * і не має думати про формат:
 *  - file    — прямий файл (.mp4/.mov/.webm), як на сайтах із власним хостингом
 *              відео; програється вбудованим плеєром браузера;
 *  - youtube — вантажимо не одразу: спершу превʼю-картинка, iframe підʼїжджає
 *              за кліком (інакше кожен кейс тягнув би важкий плеєр і вбивав
 *              швидкість сторінки профілю);
 *  - link    — TikTok, Instagram, Facebook тощо: їхні вбудовування ненадійні
 *              й важкі, тому показуємо акуратне посилання «Дивитись відео».
 */
final class CaseVideo
{
    /**
     * @return array{kind: string, href: string, embed: ?string, thumb: ?string}|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        if (preg_match('#\.(mp4|mov|webm|m4v)(\?|$)#i', $url) === 1) {
            return ['kind' => 'file', 'href' => $url, 'embed' => null, 'thumb' => null];
        }

        $youtubeId = self::youtubeId($url);
        if ($youtubeId !== null) {
            return [
                'kind' => 'youtube',
                'href' => 'https://www.youtube.com/watch?v=' . $youtubeId,
                // nocookie: менше трекінгу до того, як людина натиснула play.
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $youtubeId . '?autoplay=1&rel=0',
                'thumb' => 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg',
            ];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m) === 1) {
            return [
                'kind' => 'youtube',
                'href' => $url,
                'embed' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1',
                'thumb' => null,
            ];
        }

        return ['kind' => 'link', 'href' => $url, 'embed' => null, 'thumb' => null];
    }

    private static function youtubeId(string $url): ?string
    {
        $patterns = [
            '#youtu\.be/([\w-]{6,20})#i',
            '#youtube\.com/watch\?(?:.*&)?v=([\w-]{6,20})#i',
            '#youtube\.com/shorts/([\w-]{6,20})#i',
            '#youtube\.com/embed/([\w-]{6,20})#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
