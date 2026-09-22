<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\BadgeAuditLog;
use App\Models\Profile;
use App\Models\ProfileLead;
use App\Models\ProfileRating;
use App\Support\CategoryHierarchy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Рахує для ОДНОГО профілю: рейтинг (якість), популярність (позиція) і стан
 * бейджа. Пише в profile_ratings (джерело правди + розкладка) і дзеркалить
 * фінальні числа в наявні колонки profiles, щоб решта сайту працювала без змін.
 *
 * Рейтинг НЕ залежить від PRO. PRO впливає лише на позицію у видачі (окремий
 * промаркований блок) — і робиться це в каталозі, не тут.
 */
class ProfileRatingCalculator
{
    public function __construct(private readonly ReviewTrustScorer $trustScorer)
    {
    }

    /**
     * @param float $p НЕ ВИКОРИСТОВУЄТЬСЯ з версії 2.0 (лишився в сигнатурі,
     *                 щоб не ламати наявні виклики): згладжування прибрано,
     *                 рейтинг більше не залежить від середньої по категорії.
     * @return array повна розкладка (calculation_breakdown)
     */
    public function calculate(Profile $profile, float $p = 0.0, bool $persist = true): array
    {
        $cfg = config('rating');
        $now = now();

        $reviews = $profile->reviews()
            ->where('status', 'published')
            ->get([
                'id', 'rating', 'source', 'body',
                'external_review_date', 'published_at', 'created_at',
                'author_phone_verified', 'author_email_verified',
                'trust_score', 'exclusion_reason', 'manually_reviewed',
            ]);

        $pairs = [];
        $dist = [0, 0, 0, 0, 0];
        $counted = 0;
        $own = 0;
        $confirmed = 0;
        $imported = 0;
        $last90 = 0;
        $rawSum = 0.0;
        $countedSum = 0.0;
        $reviewUpdates = [];
        $ninetyAgo = $now->copy()->subDays(90);

        foreach ($reviews as $r) {
            $rawSum += (float) $r->rating;
            $trust = $this->trustScorer->score($r, $cfg['antifraud']);
            $ageDays = $this->ageDays($r, $now);

            $weight = $trust['excluded'] ? 0.0 : RatingMath::reviewWeight(
                (string) $r->source,
                $ageDays,
                (bool) $r->author_phone_verified,
                (bool) $r->author_email_verified,
                $trust['trust'],
                $cfg['weight'],
            );

            $reviewUpdates[] = [
                'id' => $r->id,
                'trust_score' => $trust['trust'],
                'is_excluded_from_rating' => $trust['excluded'],
                'exclusion_reason' => $trust['reason'],
                'computed_weight' => $weight,
            ];

            if ($trust['excluded']) {
                continue;
            }

            $counted++;
            $countedSum += (float) $r->rating;
            $stars = (int) round((float) $r->rating);
            if ($stars >= 1 && $stars <= 5) {
                $dist[$stars - 1]++;
            }
            $pairs[] = [(float) $r->rating, $weight];

            if ($r->source === 'platform' || $r->source === 'confirmed_request') {
                $own++;
            }
            if ($r->source === 'confirmed_request') {
                $confirmed++;
            }
            if ($r->source === 'google_import') {
                $imported++;
            }
            $rDate = $this->reviewDate($r);
            if ($rDate && $rDate->greaterThanOrEqualTo($ninetyAgo)) {
                $last90++;
            }
        }

        // --- Рейтинг: СЕРЕДНЄ АРИФМЕТИЧНЕ ---
        // З версії 2.0 (21.09.2026) жодного згладжування, ваг чи штрафів у
        // підсумковому числі немає: rating = сума оцінок / кількість оцінок.
        // Так число збігається з тим, що відвідувач порахує сам по видимих
        // відгуках, і «всі 5 → 5.0» працює без окремих правил-винятків.
        $agg = RatingMath::weightedAverage($pairs); // лише аналітика (не впливає)
        $entropy = RatingMath::normalizedEntropy($dist);
        $rawAverage = $reviews->count() > 0 ? round($rawSum / $reviews->count(), 2) : 0.0;
        $importedShare = $counted > 0 ? $imported / $counted : 0.0;
        $average = $counted > 0 ? $countedSum / $counted : 0.0;

        $flags = [];
        // Аномально однорідний розподіл — СИГНАЛ, а не штраф: оцінку не чіпаємо,
        // лише ставимо прапорець (він блокує бейдж «Dovira рекомендує»).
        // Профілі, де відгуки переважно з Google, не позначаємо: там 5★ — норма.
        if ($counted >= (int) $cfg['entropy']['min_reviews']
            && $entropy < (float) $cfg['entropy']['threshold']
            && $importedShare < (float) $cfg['entropy']['skip_when_imported_share']) {
            $flags[] = 'low_entropy';
        }

        $rating = RatingMath::finalizeRating($average, (float) $cfg['final']['min'], (float) $cfg['final']['max'], (int) $cfg['final']['round']);
        // Без жодного відгуку рейтингу немає: раніше сюди записувалось апріорне
        // середнє (4.71 / 4.41), і 16 тис. профілів без відгуків носили
        // «оцінку», яка живила популярність і витікала в експорти.
        if ($counted === 0) {
            $rating = 0.0;
        }
        $reliable = $counted >= (int) $cfg['final']['min_reviews_for_number'];

        // --- Популярність (позиція) ---
        $completeness = $this->completenessFactor($profile, $cfg['popularity']['completeness']);
        $engagement = $this->engagementFactor($profile, $cfg['popularity']['engagement']);
        $responsiveness = $this->responsivenessFactor($profile, $cfg['popularity']['responsiveness']);
        $volume = 1.0 + (float) $cfg['popularity']['volume']['coef'] * log10(1 + $own);
        // Перегляди профілю — головний сигнал попиту; домінують над рештою факторів.
        $views = $this->viewsFactor($profile, $cfg['popularity']['views']);
        $popularity = $views * ($rating / 5.0) * $completeness * $engagement * $responsiveness * $volume;

        // --- Бейдж (з відстрочкою втрати) ---
        $badge = $this->evaluateBadge($profile, $rating, $counted, $own, $last90, $flags, $reliable, $cfg['badge']);
        $badgeState = $this->resolveBadgeState($profile, $badge, $now, (int) $cfg['badge']['grace_days']);

        $breakdown = [
            'version' => (string) $cfg['version'],
            'formula' => 'plain_average', // rating = Σоцінок / кількість
            'average' => round($average, 4),
            'weighted_average' => round($agg['weighted_average'], 4), // довідково
            'effective_weight_sum' => round($agg['effective_weight_sum'], 3),
            'raw_average' => $rawAverage,
            'entropy' => round($entropy, 3),
            'flags' => $flags,
            'reviews' => compact('counted', 'own', 'confirmed', 'imported', 'last90'),
            'distribution' => $dist,
            'popularity' => [
                'views_factor' => round($views, 4),
                'rating_normalized' => round($rating / 5.0, 4),
                'completeness_factor' => round($completeness, 4),
                'engagement_factor' => round($engagement, 4),
                'responsiveness_factor' => round($responsiveness, 4),
                'volume_factor' => round($volume, 4),
                'score' => round($popularity, 5),
            ],
            'badge' => ['qualifies' => $badge['qualifies'], 'failed' => $badge['failed'], 'state' => $badgeState['action']],
        ];

        $payload = [
            'rating' => $rating,
            'raw_average' => $rawAverage,
            'weighted_average' => round($agg['weighted_average'], 4),
            // Колонка з часів згладжування: тримаємо рівною рейтингу, щоб
            // старі звіти й експорти не показували два різні числа.
            'bayesian_rating' => $rating,
            'reviews_total' => $reviews->count(),
            'reviews_counted' => $counted,
            'reviews_own' => $own,
            'reviews_confirmed' => $confirmed,
            'reviews_imported' => $imported,
            'reviews_last_90d' => $last90,
            'effective_weight_sum' => round($agg['effective_weight_sum'], 3),
            'dist_1' => $dist[0], 'dist_2' => $dist[1], 'dist_3' => $dist[2], 'dist_4' => $dist[3], 'dist_5' => $dist[4],
            'distribution_entropy' => round($entropy, 4),
            'popularity_score' => round($popularity, 5),
            'completeness_factor' => round($completeness, 4),
            'engagement_factor' => round($engagement, 4),
            'responsiveness_factor' => round($responsiveness, 4),
            'is_recommended' => $badgeState['is_recommended'],
            'recommended_since' => $badgeState['recommended_since'],
            'recommended_lost_at' => $badgeState['recommended_lost_at'],
            'badge_at_risk' => $badgeState['badge_at_risk'],
            'badge_at_risk_since' => $badgeState['badge_at_risk_since'],
            'badge_failed_criteria' => $badge['failed'],
            'is_rating_reliable' => $reliable,
            'calculation_version' => (string) $cfg['version'],
            'calculation_breakdown' => $breakdown,
            'calculated_at' => $now,
        ];

        if ($persist) {
            $this->persist($profile, $payload, $reviewUpdates, $badgeState, $reliable, $rating, $popularity);
        }

        return $payload;
    }

    private function persist(Profile $profile, array $payload, array $reviewUpdates, array $badgeState, bool $reliable, float $rating, float $popularity): void
    {
        DB::transaction(function () use ($profile, $payload, $reviewUpdates, $badgeState, $reliable, $rating, $popularity): void {
            ProfileRating::query()->updateOrCreate(['profile_id' => $profile->id], $payload);

            // Ваги/виключення назад у відгуки — ОДИН CASE-запит на чанк
            // (а не запит на кожен відгук), щоб не бити БД тисячами round-trip.
            $this->bulkWriteReviewWeights($reviewUpdates);

            // Дзеркало у наявні колонки profiles — щоб решта сайту працювала.
            $status = $badgeState['is_recommended']
                ? 'recommend'
                : ((string) ($profile->dovira_recommendation_status ?? '') === 'not_recommend' ? 'not_recommend' : null);

            DB::table('profiles')->where('id', $profile->id)->update([
                'rating_avg' => $rating,
                'popularity_score' => round($popularity, 2),
                'dovira_recommends' => $badgeState['is_recommended'],
                'dovira_recommendation_status' => $status,
            ]);

            if ($badgeState['action'] !== null) {
                BadgeAuditLog::query()->create([
                    'profile_id' => $profile->id,
                    'action' => $badgeState['action'],
                    'reason' => ['failed' => $payload['badge_failed_criteria']],
                    'snapshot' => [
                        'rating' => $payload['rating'],
                        'reviews_counted' => $payload['reviews_counted'],
                        'reviews_own' => $payload['reviews_own'],
                        'reviews_last_90d' => $payload['reviews_last_90d'],
                    ],
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Пакетний запис ваг/виключень у відгуки одним UPDATE ... CASE на чанк.
     *
     * @param array<int,array{id:int,trust_score:float,is_excluded_from_rating:bool,exclusion_reason:string,computed_weight:float}> $rows
     */
    private function bulkWriteReviewWeights(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        foreach (array_chunk($rows, 300) as $chunk) {
            $ids = [];
            $cw = $ts = $ex = $er = [];
            $bind = [];

            foreach ($chunk as $r) {
                $id = (int) $r['id'];
                $ids[] = $id;
                $cw[] = "WHEN {$id} THEN ?";
                $bind[] = $r['computed_weight'];
            }
            foreach ($chunk as $r) {
                $id = (int) $r['id'];
                $ts[] = "WHEN {$id} THEN ?";
                $bind[] = $r['trust_score'];
            }
            foreach ($chunk as $r) {
                $id = (int) $r['id'];
                $ex[] = "WHEN {$id} THEN ?";
                $bind[] = $r['is_excluded_from_rating'] ? 1 : 0;
            }
            foreach ($chunk as $r) {
                $id = (int) $r['id'];
                $er[] = "WHEN {$id} THEN ?";
                $bind[] = (string) $r['exclusion_reason'];
            }
            $bind[] = $now; // weight_computed_at

            $inList = implode(',', $ids);
            $sql = 'UPDATE profile_reviews SET '
                . 'computed_weight = CASE id ' . implode(' ', $cw) . ' END, '
                . 'trust_score = CASE id ' . implode(' ', $ts) . ' END, '
                . 'is_excluded_from_rating = CASE id ' . implode(' ', $ex) . ' END, '
                . 'exclusion_reason = CASE id ' . implode(' ', $er) . ' END, '
                . 'weight_computed_at = ? '
                . "WHERE id IN ({$inList})";

            DB::update($sql, $bind);
        }
    }

    // ── Популярність: фактори ──────────────────────────────────────────────

    /**
     * Головний фактор популярності — перегляди профілю (сигнал реального попиту).
     * factor = 1 + coef × log10(1 + views). log10 демпфує масштаб; 0 переглядів
     * дає 1.0 (без cold-start пастки). За замовчуванням беремо УНІКАЛЬНІ перегляди
     * — їх важче накрутити, ніж загальні (див. приклад: 121 переглядів / 10 унік.).
     *
     * @param  array{coef:float,source:string}  $cfg
     */
    private function viewsFactor(Profile $profile, array $cfg): float
    {
        $views = ($cfg['source'] ?? 'unique') === 'total'
            ? (int) $profile->views_count
            : (int) $profile->unique_views_count;

        return 1.0 + (float) $cfg['coef'] * log10(1 + max(0, $views));
    }

    private function completenessFactor(Profile $profile, array $cfg): float
    {
        $p = $cfg['points'];
        $score = 0;
        if (filled($profile->logo_url)) {
            $score += $p['has_logo'];
        }
        if (count((array) ($profile->gallery ?? [])) >= 3) {
            $score += $p['gallery_3plus'];
        }
        if ($profile->services()->exists()) {
            $score += $p['has_services'];
        }
        if (mb_strlen(trim((string) $profile->description)) >= 200) {
            $score += $p['description_200plus'];
        }
        if (filled($profile->phone) || filled($profile->website)) {
            $score += $p['has_contacts'];
        }
        if (filled($profile->city)) {
            $score += $p['has_location'];
        }
        if (! empty(($profile->ai_suggested_data['owner_profile']['faq'] ?? $profile->ai_suggested_data['owner_profile_faq'] ?? null))) {
            $score += $p['has_faq'];
        }
        if (filled($profile->dossier)) {
            $score += $p['has_dossier'];
        }

        $ratio = min(1.0, $score / max(1, (int) $cfg['max_points']));

        return (float) $cfg['min'] + ((float) $cfg['max'] - (float) $cfg['min']) * $ratio;
    }

    private function engagementFactor(Profile $profile, array $cfg): float
    {
        $window = now()->subDays((int) $cfg['window_days']);
        $recent = $profile->reviews()
            ->where('status', 'published')
            ->where(function ($q) use ($window): void {
                $q->where('published_at', '>=', $window)
                    ->orWhere(function ($q2) use ($window): void {
                        $q2->whereNull('published_at')->where('created_at', '>=', $window);
                    });
            });
        $total = (clone $recent)->count();
        if ($total === 0) {
            return 1.0; // нема свіжих відгуків — не караємо
        }
        $answered = (clone $recent)->whereHas('officialReply')->count();
        $ratio = $answered / $total;

        return (float) $cfg['min'] + ((float) $cfg['max'] - (float) $cfg['min']) * $ratio;
    }

    private function responsivenessFactor(Profile $profile, array $cfg): float
    {
        $window = now()->subDays((int) $cfg['window_days']);
        $leads = ProfileLead::query()
            ->where('profile_id', $profile->id)
            ->where('created_at', '>=', $window)
            ->get(['created_at', 'responded_at']);

        // Даних про час відповіді ще нема → нейтрально (не караємо за брак фічі).
        if ($leads->isEmpty() || ! $leads->contains(fn ($l) => $l->responded_at !== null)) {
            return (float) $cfg['neutral'];
        }
        if ($leads->count() < (int) $cfg['min_leads']) {
            return (float) $cfg['neutral'];
        }

        $answered = $leads->filter(fn ($l) => $l->responded_at !== null);
        $rate = $answered->count() / $leads->count();

        if ($answered->isEmpty() && $leads->count() >= (int) $cfg['no_answer_min_leads']) {
            return (float) $cfg['no_answer'];
        }

        $hours = $answered
            ->map(fn ($l) => $l->created_at->diffInMinutes($l->responded_at) / 60)
            ->sort()->values()->all();
        $median = $this->median($hours);

        foreach ($cfg['tiers'] as $tier) {
            $okRate = $rate >= (float) $tier['rate'];
            $okTime = $tier['median_hours'] === null || $median < (float) $tier['median_hours'];
            if ($okRate && $okTime) {
                return (float) $tier['factor'];
            }
        }

        return (float) $cfg['below_50'];
    }

    // ── Бейдж ──────────────────────────────────────────────────────────────

    private function evaluateBadge(Profile $profile, float $rating, int $counted, int $own, int $last90, array $flags, bool $reliable, array $cfg): array
    {
        $c = $cfg['criteria'];
        $failed = [];

        if ($rating < (float) $c['min_rating']) {
            $failed[] = 'rating';
        }
        if ($counted < (int) $c['min_reviews_counted']) {
            $failed[] = 'reviews_counted';
        }
        if ($own < (int) $c['min_reviews_own']) {
            $failed[] = 'reviews_own';
        }
        if ($last90 < (int) $c['min_reviews_last_90d']) {
            $failed[] = 'reviews_last_90d';
        }
        if ($c['require_claimed'] && ! $profile->is_owner_verified) {
            $failed[] = 'not_claimed';
        }
        if ($c['forbid_low_entropy'] && in_array('low_entropy', $flags, true)) {
            $failed[] = 'low_entropy';
        }
        // #6 реєстр, #7/#8 швидкість відповіді, #10 скарги на фейки — поки нема
        // структурованих даних, пропускаємо (пас).

        return ['qualifies' => $failed === [] && $reliable, 'failed' => $failed];
    }

    /**
     * Стан бейджа з відстрочкою втрати (14 днів): не мигаємо.
     */
    private function resolveBadgeState(Profile $profile, array $badge, CarbonInterface $now, int $graceDays): array
    {
        $existing = $profile->rating; // ProfileRating|null
        $wasRecommended = (bool) ($existing->is_recommended ?? false);
        $wasAtRisk = (bool) ($existing->badge_at_risk ?? false);
        $riskSince = $existing->badge_at_risk_since ?? null;
        $since = $existing->recommended_since ?? null;

        $state = [
            'is_recommended' => false,
            'recommended_since' => $since,
            'recommended_lost_at' => $existing->recommended_lost_at ?? null,
            'badge_at_risk' => false,
            'badge_at_risk_since' => null,
            'action' => null,
        ];

        if ($badge['qualifies']) {
            $state['is_recommended'] = true;
            $state['recommended_since'] = $since ?? $now;
            if (! $wasRecommended) {
                $state['action'] = 'granted';
            } elseif ($wasAtRisk) {
                $state['action'] = 'recovered';
            }

            return $state;
        }

        // Не кваліфікується:
        if (! $wasRecommended) {
            return $state; // бейджа не було — лишається без
        }

        // Був бейдж → відстрочка.
        if (! $wasAtRisk) {
            $state['is_recommended'] = true;
            $state['badge_at_risk'] = true;
            $state['badge_at_risk_since'] = $now;
            $state['action'] = 'at_risk';

            return $state;
        }

        $graceElapsed = $riskSince && Carbon::parse($riskSince)->lessThanOrEqualTo($now->copy()->subDays($graceDays));
        if ($graceElapsed) {
            $state['is_recommended'] = false;
            $state['recommended_lost_at'] = $now;
            $state['action'] = 'revoked';

            return $state;
        }

        // Ще в межах відстрочки — тримаємо бейдж.
        $state['is_recommended'] = true;
        $state['badge_at_risk'] = true;
        $state['badge_at_risk_since'] = $riskSince;

        return $state;
    }

    // ── helpers ──

    private function ageDays(object $review, CarbonInterface $now): int
    {
        $date = $this->reviewDate($review);

        return $date ? max(0, (int) $date->diffInDays($now)) : 0;
    }

    private function reviewDate(object $review): ?CarbonInterface
    {
        foreach (['external_review_date', 'published_at', 'created_at'] as $field) {
            if (! empty($review->{$field})) {
                return Carbon::parse($review->{$field});
            }
        }

        return null;
    }

    /**
     * @param array<int,float> $values відсортований масив
     */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return INF;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
