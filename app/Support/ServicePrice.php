<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Формат цін прайсу послуг — одна точка правди для кабінету, публічної
 * сторінки, карток каталогу і schema.org.
 */
final class ServicePrice
{
    public const TYPES = ['fixed', 'from', 'range', 'negotiable'];

    /** «1 500 грн» / «від 800 грн» / «800–2 000 грн» / «договірна» / null. */
    public static function label(?string $type, ?int $price, ?int $priceMax): ?string
    {
        return match ($type) {
            'fixed' => $price ? self::uah($price) : null,
            'from' => $price ? 'від ' . self::uah($price) : null,
            'range' => $price && $priceMax
                ? self::num($price) . '–' . self::uah($priceMax)
                : ($price ? 'від ' . self::uah($price) : null),
            'negotiable' => 'договірна',
            default => null,
        };
    }

    /** Мінімальна ціна для картки каталогу: «від 800 грн» або null. */
    public static function fromLabel(?int $minPrice): ?string
    {
        return $minPrice ? 'від ' . self::uah($minPrice) : null;
    }

    private static function uah(int $amount): string
    {
        return self::num($amount) . ' грн';
    }

    private static function num(int $amount): string
    {
        return number_format($amount, 0, '', ' ');
    }
}
