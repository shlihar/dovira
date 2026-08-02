<?php

namespace App\Support;

/**
 * Українська плюралізація лічильників. Одне джерело правди для всіх місць,
 * де виводяться числа з іменником (компанії, відгуки, категорії тощо).
 */
final class Plural
{
    /**
     * @param  string  $one  форма для 1 (компанія, відгук)
     * @param  string  $few  форма для 2-4 (компанії, відгуки)
     * @param  string  $many  форма для 0, 5-20, 11-14 (компаній, відгуків)
     */
    public static function uk(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }
}
