<?php

declare(strict_types=1);

namespace App\Services\Rating;

/**
 * Чиста математика рейтингу — без БД, без стану, без побічних ефектів.
 * Кожна функція детермінована (ідемпотентність) і пояснювана одним реченням.
 * Константи передаються ЗЗОВНІ (з config/rating.php), щоб RatingMath можна
 * було тестувати ізольовано і не міняти при зміні порогів.
 */
final class RatingMath
{
    /**
     * w_source — довіра до джерела відгуку.
     *
     * @param array<string,float> $sourceCfg
     */
    public static function sourceWeight(string $source, array $sourceCfg): float
    {
        return (float) ($sourceCfg[$source] ?? $sourceCfg['google_import'] ?? 0.3);
    }

    /**
     * w_freshness — плавний експоненційний спад за віком (не сходинки).
     * w = max(floor, exp(-age_days / tau)).
     */
    public static function freshnessWeight(int $ageDays, float $floor, float $tauDays): float
    {
        $ageDays = max(0, $ageDays);

        return max($floor, exp(-$ageDays / $tauDays));
    }

    /**
     * w_verification. Для google_import завжди 1.0 — вони вже понижені через
     * w_source, подвійний штраф не потрібен.
     *
     * @param array{phone:float,email_only:float,none:float} $cfg
     */
    public static function verificationWeight(
        string $source,
        bool $phoneVerified,
        bool $emailVerified,
        array $cfg
    ): float {
        if ($source === 'google_import') {
            return 1.0;
        }
        if ($phoneVerified) {
            return (float) $cfg['phone'];
        }
        if ($emailVerified) {
            return (float) $cfg['email_only'];
        }

        return (float) $cfg['none'];
    }

    /**
     * Підсумкова вага одного відгуку:
     * weight = w_source × w_freshness × w_verification × trust_score.
     * Виключені відгуки (trust нижче порога) сюди не потрапляють — їхня вага 0
     * рахується на рівні агрегатора.
     *
     * @param array<string,mixed> $weightCfg секція config('rating.weight')
     */
    public static function reviewWeight(
        string $source,
        int $ageDays,
        bool $phoneVerified,
        bool $emailVerified,
        float $trustScore,
        array $weightCfg
    ): float {
        $wSource = self::sourceWeight($source, $weightCfg['source']);
        $wFresh = self::freshnessWeight(
            $ageDays,
            (float) $weightCfg['freshness']['floor'],
            (float) $weightCfg['freshness']['tau_days'],
        );
        $wVerif = self::verificationWeight($source, $phoneVerified, $emailVerified, $weightCfg['verification']);

        return $wSource * $wFresh * $wVerif * max(0.0, min(1.0, $trustScore));
    }

    /**
     * Зважена середня + сума ваг.
     *
     * @param array<int,array{0:float,1:float}> $ratingWeightPairs [rating, weight]
     * @return array{weighted_average:float,effective_weight_sum:float}
     */
    public static function weightedAverage(array $ratingWeightPairs): array
    {
        $weightSum = 0.0;
        $acc = 0.0;
        foreach ($ratingWeightPairs as [$rating, $weight]) {
            $weightSum += $weight;
            $acc += $rating * $weight;
        }

        return [
            'weighted_average' => $weightSum > 0 ? $acc / $weightSum : 0.0,
            'effective_weight_sum' => $weightSum,
        ];
    }

    /**
     * Середнє арифметичне оцінок — підсумкова формула рейтингу з 21.09.2026.
     * Без ваг, без апріорного середнього: число дорівнює тому, що відвідувач
     * порахує сам по видимих відгуках.
     *
     * @param array<int,float> $ratings
     */
    public static function average(array $ratings): float
    {
        $n = count($ratings);

        return $n > 0 ? array_sum($ratings) / $n : 0.0;
    }

    /**
     * Нормалізована ентропія розподілу оцінок: H = -Σ(p_i·ln p_i)/ln5.
     * 1.0 = рівномірно, 0.0 = всі оцінки однакові.
     *
     * @param array<int,int> $distribution [n1, n2, n3, n4, n5]
     */
    public static function normalizedEntropy(array $distribution): float
    {
        $total = array_sum($distribution);
        if ($total <= 0) {
            return 0.0;
        }

        $h = 0.0;
        foreach ($distribution as $count) {
            if ($count <= 0) {
                continue;
            }
            $p = $count / $total;
            $h -= $p * log($p);
        }

        return $h / log(5);
    }

    /**
     * Фінальне число: clamp + round.
     */
    public static function finalizeRating(float $value, float $min, float $max, int $round): float
    {
        return round(max($min, min($max, $value)), $round);
    }
}
