<?php

namespace App\Support;

/**
 * Єдине джерело правди для візуалу категорій: колір іконки (фон + штрих) і
 * сам значок. Ці ж кольори мають використовуватись на сторінці категорії,
 * у профілі та в хлібних крихтах — тому вони закріплені тут, а не в CSS.
 *
 * Іконки — один набір inline-SVG однакової товщини штриха (раніше категорії
 * тягли значки з різних наборів FontAwesome, і ваги ліній гуляли).
 */
final class CategoryVisuals
{
    /**
     * Колір по slug категорії: [фон квадрата, колір штриха].
     *
     * @var array<string, array{bg:string, ink:string}>
     */
    private const COLORS = [
        'medicina-ta-zdorovia' => ['bg' => '#E1F5EE', 'ink' => '#0F6E56'],
        'catalog-yurydychni-poslugy' => ['bg' => '#E6F1FB', 'ink' => '#185FA5'],
        'budivnictvo-ta-remont' => ['bg' => '#FAECE7', 'ink' => '#993C1D'],
        'neruxomist' => ['bg' => '#FAEEDA', 'ink' => '#854F0B'],
        'tvarini' => ['bg' => '#FBEAF0', 'ink' => '#993556'],
        'osvitni-poslugi' => ['bg' => '#EEEDFE', 'ink' => '#534AB7'],
        'finansovi-poslugi' => ['bg' => '#F1EFE8', 'ink' => '#5F5E5A'],
        'it-ta-biznes-poslugi' => ['bg' => '#EEEDFE', 'ink' => '#534AB7'],
        'blogeri' => ['bg' => '#FBEAF0', 'ink' => '#993556'],
    ];

    /** Нейтральний дефолт для категорій поза таблицею (напр. «Весілля та події»). */
    private const DEFAULT_COLOR = ['bg' => '#EEF1F6', 'ink' => '#516079'];

    /**
     * Slug → ключ значка з набору нижче.
     *
     * @var array<string, string>
     */
    private const ICON_KEYS = [
        'medicina-ta-zdorovia' => 'med',
        'catalog-yurydychni-poslugy' => 'legal',
        'budivnictvo-ta-remont' => 'build',
        'neruxomist' => 'home',
        'tvarini' => 'paw',
        'osvitni-poslugi' => 'edu',
        'finansovi-poslugi' => 'finance',
        'it-ta-biznes-poslugi' => 'it',
        'blogeri' => 'blog',
        'vesillia-ta-podiyi' => 'events',
    ];

    /**
     * Значки — один набір Lucide (viewBox 0 0 24 24, stroke=currentColor,
     * товщина 2). Один візуальний стиль, однакова вага ліній.
     *
     * @var array<string, string>
     */
    private const ICON_PATHS = [
        // cross — медичний хрест (не плутати з пульсом/графіком)
        'med' => '<path d="M11 2a2 2 0 0 0-2 2v5H4a2 2 0 0 0-2 2v2c0 1.1.9 2 2 2h5v5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-5h5a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2h-5V4a2 2 0 0 0-2-2z"/>',
        // scale — терези правосуддя
        'legal' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        // hard-hat — будівельна каска
        'build' => '<path d="M2 18a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1z"/><path d="M10 10V5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5"/><path d="M4 15a8 8 0 0 1 8-8 8 8 0 0 1 8 8"/>',
        // house — нерухомість
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        // paw-print — тварини
        'paw' => '<circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/>',
        // graduation-cap — освіта
        'edu' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        // banknote — фінанси (чітко «гроші», не графік)
        'finance' => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        // monitor — IT / бізнес
        'it' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        // camera — блогери
        'blog' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        // gift — весілля та події
        'events' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8"/><path d="M16.5 8a2.5 2.5 0 0 0 0-5C13 3 12 8 12 8"/>',
        // shapes — дефолт
        'shapes' => '<path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.198-.043L16.3 8.9a.7.7 0 0 1-.572 1.1Z"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>',
    ];

    /**
     * @return array{bg:string, ink:string}
     */
    public static function color(string $slug): array
    {
        return self::COLORS[$slug] ?? self::DEFAULT_COLOR;
    }

    public static function iconKey(string $slug): string
    {
        return self::ICON_KEYS[$slug] ?? 'shapes';
    }

    /**
     * Готовий inline-SVG значка категорії.
     */
    public static function iconSvg(string $slug, string $class = 'cat-card__icon-svg'): string
    {
        $inner = self::ICON_PATHS[self::iconKey($slug)] ?? self::ICON_PATHS['shapes'];

        return sprintf(
            '<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            e($class),
            $inner
        );
    }
}
