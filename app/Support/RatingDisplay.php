<?php

namespace App\Support;

/**
 * Єдине правило показу оцінки профілю.
 *
 * Середнє з кількох відгуків — статистичний шум: один негатив тягне профіль
 * на «1.0», а два позитиви малюють «5.0». Тому цифру й зірки показуємо лише
 * коли відгуків достатньо, інакше пишемо, що оцінка ще формується.
 */
class RatingDisplay
{
    /** Мінімум відгуків, з якого показуємо числову оцінку та зірки. */
    public const MIN_REVIEWS = 20;

    public static function visible(int $reviewsCount): bool
    {
        return $reviewsCount >= self::MIN_REVIEWS;
    }
}
