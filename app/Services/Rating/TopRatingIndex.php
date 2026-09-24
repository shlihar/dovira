<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * Індекс DOVIRA для сторінок «ТОП-10 {категорія} {місто}».
 *
 * Рахується ВСЕРЕДИНІ пари «категорія × місто»: адвокат Одеси конкурує з
 * адвокатами Одеси, а не з Києвом. Кожен вимір нормалізується 0..1 у межах
 * пулу, множиться на вагу, сума × 100 = індекс.
 *
 * PRO-статус у формулу не входить — обіцянка «місце в рейтингу не купується»
 * має лишатися правдою. Профілі з червоним вердиктом досьє не допускаються.
 */
final class TopRatingIndex
{
    /** Ваги вимірів. Сума = 1.0. */
    public const WEIGHTS = [
        'quality' => 0.35,
        'volume' => 0.20,
        'freshness' => 0.20,
        'demand' => 0.10,
        'trust' => 0.10,
        'responsiveness' => 0.05,
    ];

    /** Мінімум відгуків для допуску в рейтинг. */
    public const MIN_REVIEWS = 20;

    /** Мінімальна оцінка для допуску. */
    public const MIN_RATING = 4.0;

    /** Вікно свіжості, днів. */
    public const FRESH_WINDOW_DAYS = 90;

    /**
     * @param  Collection<int, Profile>  $pool  профілі однієї пари «категорія × місто»
     * @return array<int, array{profile:Profile, index:float, components:array<string, float>}>
     *                                          відсортовано за спаданням індексу
     */
    public function rank(Collection $pool): array
    {
        // Ручне закріплення з адмінки (rating_placement = force) обходить пороги:
        // це свідоме рішення редакції, і воно має право бути сильнішим за формулу.
        $forced = $pool->filter(fn (Profile $p): bool => $p->rating_placement === 'force')->values();
        $eligible = $pool
            ->filter(fn (Profile $p): bool => $p->rating_placement !== 'force' && $this->isEligible($p))
            ->values();

        if ($eligible->isEmpty() && $forced->isEmpty()) {
            return [];
        }

        $raw = $eligible->concat($forced)->map(fn (Profile $p): array => [
            'profile' => $p,
            'quality' => $this->quality($p),
            // Логарифм навмисно: 246 відгуків важать більше за 20, але не в 12 разів.
            'volume' => log10(1 + (float) $p->reviews_count),
            'freshness' => (float) $this->freshCount($p),
            'demand' => $this->demand($p),
            'trust' => $this->trust($p),
            'responsiveness' => $this->responsiveness($p),
        ]);

        // Нормалізація в межах пулу — окремо для вимірів, що мають сирий масштаб.
        foreach (['volume', 'freshness', 'demand'] as $key) {
            $values = $raw->pluck($key)->all();
            $min = min($values);
            $max = max($values);
            $raw = $raw->map(function (array $row) use ($key, $min, $max): array {
                $row[$key] = $max === $min ? 0.0 : ($row[$key] - $min) / ($max - $min);

                return $row;
            });
        }

        $ranked = $raw->map(function (array $row): array {
            $components = [];
            $index = 0.0;

            foreach (self::WEIGHTS as $key => $weight) {
                $value = round((float) $row[$key], 4);
                $components[$key] = $value;
                $index += $weight * $value;
            }

            return [
                'profile' => $row['profile'],
                'index' => round($index * 100, 1),
                'components' => $components,
            ];
        })->all();

        usort($ranked, function (array $a, array $b): int {
            $aForced = $a['profile']->rating_placement === 'force' ? 1 : 0;
            $bForced = $b['profile']->rating_placement === 'force' ? 1 : 0;

            return [$bForced, (int) $b['profile']->rating_priority, $b['index'], $b['profile']->reviews_count, $a['profile']->id]
                <=> [$aForced, (int) $a['profile']->rating_priority, $a['index'], $a['profile']->reviews_count, $b['profile']->id];
        });

        return $ranked;
    }

    public function isEligible(Profile $profile): bool
    {
        if ((int) $profile->reviews_count < self::MIN_REVIEWS) {
            return false;
        }

        if ((float) $profile->rating_avg < self::MIN_RATING) {
            return false;
        }

        // Червоний вердикт досьє — наша власна перевірка знайшла тривожні
        // сигнали. Такий профіль лишається в каталозі, але не в топі.
        if ($profile->dossier_verdict === 'red') {
            return false;
        }

        return $profile->status === 'active' && $profile->is_published && $profile->show_in_catalog;
    }

    /**
     * Якість: нормалізована середня (60%) + «чистий сентимент» (40%).
     * Другий доданок карає поляризовані профілі «або 5, або 1» — типову
     * картину накручених відгуків, де середня виглядає пристойно.
     */
    private function quality(Profile $profile): float
    {
        $mean = max(0.0, min(1.0, ((float) $profile->rating_avg - 3.0) / 2.0));
        $rating = $profile->rating;
        $total = max(1, (int) ($rating->reviews_total ?? $profile->reviews_count));
        $positive = (int) ($rating->dist_5 ?? 0);
        $negative = (int) ($rating->dist_1 ?? 0) + (int) ($rating->dist_2 ?? 0);
        $net = max(0.0, min(1.0, ($positive - $negative) / $total));

        return round(0.6 * $mean + 0.4 * $net, 4);
    }

    private function freshCount(Profile $profile): int
    {
        return (int) ($profile->rating->reviews_last_90d ?? 0);
    }

    /**
     * Попит: перегляди профілю і кліки по контактах за вікно свіжості.
     * ВАЖЛИВО: власний аутріч і внутрішні переходи виключені — інакше наші ж
     * розсилки формували б рейтинг. З тієї ж причини не беремо готовий
     * popularity_score: він усередині вже містить оцінку й повноту профілю,
     * тож якість зараховувалась би двічі.
     */
    private function demand(Profile $profile): float
    {
        return (float) ($profile->top_rating_demand ?? 0);
    }

    private function trust(Profile $profile): float
    {
        $score = 0.0;

        if ((bool) $profile->is_owner_verified) {
            $score += 0.4;
        }

        if (trim((string) $profile->dossier) !== '') {
            $score += 0.4;
        }

        if (is_array($profile->ai_review_summary) && $profile->ai_review_summary !== []) {
            $score += 0.2;
        }

        return round(min(1.0, $score), 4);
    }

    /**
     * Відповіді власника на відгуки, з акцентом на негативні. Поки що нуль у
     * всіх профілів — але вимір лишається, бо це важіль: він показує власникам,
     * що робота з відгуками впливає на позицію.
     */
    private function responsiveness(Profile $profile): float
    {
        $replies = (int) ($profile->top_rating_replies ?? 0);

        if ($replies <= 0) {
            return 0.0;
        }

        $reviews = max(1, (int) $profile->reviews_count);

        return round(min(1.0, $replies / min($reviews, 20)), 4);
    }
}
