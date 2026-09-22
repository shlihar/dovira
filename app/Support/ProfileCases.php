<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Кейси PRO-профілю: «з чим прийшов клієнт → що зробили → чим закінчилось».
 *
 * Зберігаються в `profiles.ai_suggested_data.owner_profile.cases` (там само,
 * де FAQ і досвід), тож окрема таблиця не потрібна: це контент сторінки, який
 * редагує власник, а не сутність із власним життєвим циклом.
 *
 * Єдиний нормалізатор для обох боків — кабінету (запис) і сторінки профілю
 * (читання). Інакше поля розʼїжджаються: так уже сталося з FAQ, де q/a і
 * question/answer довелося підтримувати паралельно.
 */
final class ProfileCases
{
    /** Скільки кейсів показуємо максимум: більше перетворює сторінку на стрічку. */
    public const MAX_ITEMS = 10;

    private const LIMITS = [
        'title' => 120,
        'task' => 400,
        'result' => 400,
        'period' => 40,
        'tag' => 60,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $cases = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $case = [];
            foreach (self::LIMITS as $key => $limit) {
                $value = self::text($item[$key] ?? null, $limit);
                if ($value !== null) {
                    $case[$key] = $value;
                }
            }

            // Кейс без назви й без результату нічого не каже — відкидаємо.
            if (! isset($case['title']) && ! isset($case['result'])) {
                continue;
            }

            $url = self::url($item['url'] ?? null);
            if ($url !== null) {
                $case['url'] = $url;
            }

            // Підтвердження: id реального опублікованого відгуку, з якого
            // складено кейс. Саме воно робить блок перевірюваним, а не
            // черговою саморекламою.
            $reviewId = (int) ($item['review_id'] ?? 0);
            if ($reviewId > 0) {
                $case['review_id'] = $reviewId;
            }

            $reviewDate = self::text($item['review_date'] ?? null, 20);
            if ($reviewDate !== null) {
                $case['review_date'] = $reviewDate;
            }

            $cases[] = $case;

            if (count($cases) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $cases;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        // Теги й переноси рядків зрізаємо: це контент, який рендериться як
        // звичайний текст, а не як розмітка.
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private static function url(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || mb_strlen($url) > 300) {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
