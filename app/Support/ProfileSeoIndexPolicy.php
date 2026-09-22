<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Shared profile index eligibility. This is a site policy, not evidence of a
 * Google penalty. A length threshold or AI content alone does not prove quality.
 * Explicitly reviewed profiles may be admitted without generating filler text.
 */
final class ProfileSeoIndexPolicy
{
    /** @var array<int, true>|null */
    private static ?array $uniqueSignalIds = null;

    private static ?array $reviewedConfig = null;

    private static array $reviewedIds = [];

    private static array $reviewedSet = [];

    public static function indexesAllPublicProfiles(): bool
    {
        return config('seo.profile_indexing_mode') === 'all_public';
    }

    public static function isPublic(Profile $profile): bool
    {
        return $profile->status === 'active' && $profile->is_published && $profile->show_in_catalog
            && ! str_contains((string) $profile->internal_note, '[IDENTITY_REPAIR_REVIEW]');
    }

    public static function usesContentSignals(): bool
    {
        return config('seo.profile_indexing_mode') === 'reviews_contacts_dossier';
    }

    /** Режим «відгук / досьє / AI-аналіз» — рішення власника 22.09.2026. */
    public static function usesReviewsDossierAi(): bool
    {
        return config('seo.profile_indexing_mode') === 'reviews_dossier_ai';
    }

    /**
     * Індексуємо профіль, якщо на сторінці є хоч щось своє: опублікований
     * відгук, досьє або AI-розбір відгуків. Решта лишається публічною для
     * людей, але не проситься в індекс — саме передрук чужих відгуків без
     * доданої цінності привів до демоції в серпні 2026.
     *
     * Працює і на частково завантаженій моделі (sitemap вантажить лише
     * потрібні колонки + withExists('has_published_reviews')).
     */
    public static function passesReviewsDossierAi(Profile $profile): bool
    {
        if (! self::isPublic($profile)) {
            return false;
        }

        if (filled($profile->dossier_verdict) || trim((string) ($profile->dossier ?? '')) !== '') {
            return true;
        }

        if ($profile->ai_review_summary_generated_at !== null || filled($profile->ai_review_summary)) {
            return true;
        }

        $attributes = $profile->getAttributes();
        if (array_key_exists('has_published_reviews', $attributes)) {
            return (bool) $attributes['has_published_reviews'];
        }
        // reviews_count — дзеркало опублікованих відгуків (розходиться на 60
        // записів із 34 тис., тож для рішення «є/немає» його достатньо).
        if (array_key_exists('reviews_count', $attributes)) {
            return (int) $attributes['reviews_count'] > 0;
        }

        return $profile->publishedReviews()->exists();
    }

    /** Shared by page robots and sitemap; never publish or revive quarantined records. */
    public static function passesContentSignals(Profile $profile): bool
    {
        if (! self::isPublic($profile)) {
            return false;
        }

        foreach (['phone', 'email', 'website', 'address', 'dossier'] as $field) {
            $text = html_entity_decode(strip_tags((string) $profile->{$field}), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) !== '') {
                return true;
            }
        }

        if (array_key_exists('has_published_reviews', $profile->getAttributes())) {
            return (bool) $profile->has_published_reviews;
        }

        return $profile->publishedReviews()->exists();
    }

    public static function strictEnabled(): bool
    {
        return (bool) config('seo.strict_profile_indexing', false);
    }

    public static function minDescriptionLength(): int
    {
        return max(0, (int) config('seo.strict_min_description', 500));
    }

    /**
     * Чи має профіль унікальний контент, вартий індексації в суворому режимі.
     * Працює і на частково завантаженій моделі (sitemap): досьє визначається
     * за dossier_verdict, AI-аналіз — за ai_review_summary_generated_at.
     */
    public static function passes(Profile $profile): bool
    {
        self::reviewedProfileIds();
        if (isset(self::$reviewedSet[(int) $profile->id])) {
            return true;
        }

        if (filled($profile->dossier_verdict) || trim((string) ($profile->dossier ?? '')) !== '') {
            return true;
        }

        if ($profile->ai_review_summary_generated_at !== null || filled($profile->ai_review_summary)) {
            return true;
        }

        $description = trim(strip_tags((string) ($profile->description ?? '')));
        if (mb_strlen($description) >= self::minDescriptionLength()) {
            return true;
        }

        return isset(self::uniqueSignalIds()[(int) $profile->id]);
    }

    /**
     * Профілі з нативним відгуком або підтвердженим власником — маленькі
     * множини (десятки id), тому вантажимо один раз на запит і тримаємо
     * як set для O(1)-перевірки при обході десятків тисяч профілів у sitemap.
     *
     * @return array<int, true>
     */
    private static function uniqueSignalIds(): array
    {
        if (self::$uniqueSignalIds !== null) {
            return self::$uniqueSignalIds;
        }

        $ids = rescue(function (): array {
            $native = DB::table('profile_reviews')
                ->whereNull('external_source_type')
                ->where('status', 'published')
                ->distinct()
                ->pluck('profile_id');

            $claimed = DB::table('profile_claims')
                ->where('status', 'approved')
                ->distinct()
                ->pluck('profile_id');

            return $native->merge($claimed)->map(fn ($id): int => (int) $id)->unique()->all();
        }, [], false);

        return self::$uniqueSignalIds = array_fill_keys($ids, true);
    }

    /** Explicitly approved indexing cohort; not a verification badge for business data. */
    public static function reviewedProfileIds(): array
    {
        $configured = (array) config('seo.reviewed_profile_ids', []);
        if (self::$reviewedConfig !== $configured) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $configured), fn (int $id): bool => $id > 0)));
            sort($ids);
            self::$reviewedConfig = $configured;
            self::$reviewedIds = $ids;
            self::$reviewedSet = array_fill_keys($ids, true);
        }

        return self::$reviewedIds;
    }

    public static function flush(): void
    {
        self::$uniqueSignalIds = null;
        self::$reviewedConfig = null;
        self::$reviewedIds = [];
        self::$reviewedSet = [];
    }
}
