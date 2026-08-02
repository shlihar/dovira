<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiEnrichment\ProfileContentAiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SummarizeProfileReviews extends Command
{
    protected $signature = 'dovira:summarize-reviews
        {--limit=50 : Скільки профілів обробити за запуск}
        {--id=* : Обробити лише конкретні ID профілів}
        {--category= : Slug категорії (напр. advokaty) — обробити лише її профілі}
        {--top-per-city= : Топ-N найпопулярніших профілів у кожній парі категорія×місто}
        {--min-reviews=10 : Мінімум опублікованих відгуків для підсумку}
        {--stale : Оновити підсумки, де кількість відгуків змінилась}
        {--dry-run : Показати результат для першого профілю без збереження}';

    protected $description = 'Генерує AI-підсумки відгуків для сторінок профілів (блок "Що кажуть клієнти").';

    public function handle(ProfileContentAiService $ai): int
    {
        $minReviews = max(1, (int) $this->option('min-reviews'));

        $query = Profile::query()
            ->where('reviews_count', '>=', $minReviews)
            ->orderByDesc('reviews_count');

        if ($category = trim((string) $this->option('category'))) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $category));
        }

        // Режим «топ-N на категорію×місто»: обмежуємо набір найпопулярнішими
        // профілями кожної пари (категорія, місто) — це підсилює SEO-лендінги.
        if ($topPerCity = (int) $this->option('top-per-city')) {
            $rankedIds = $this->topPerCategoryCityIds($minReviews, max(1, $topPerCity));

            if ($rankedIds === []) {
                $this->info('Немає профілів для відбору топ-N на категорію×місто.');

                return self::SUCCESS;
            }

            $query->whereIn('id', $rankedIds);
        }

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        } elseif ($this->option('stale')) {
            $query->whereNotNull('ai_review_summary_generated_at')
                ->whereColumn('ai_review_summary_source_count', '!=', 'reviews_count');
        } else {
            $query->whereNull('ai_review_summary_generated_at');
        }

        $limit = max(1, (int) $this->option('limit'));
        $profiles = $query->limit($limit)->get();

        if ($profiles->isEmpty()) {
            $this->info('Немає профілів для обробки.');

            return self::SUCCESS;
        }

        $this->info("Профілів в обробці: {$profiles->count()}");
        $done = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            try {
                $summary = $ai->summarizeReviews($profile);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  #{$profile->id} {$profile->name}: {$exception->getMessage()}");

                continue;
            }

            if ($summary === null) {
                $this->warn("  #{$profile->id} {$profile->name}: пропущено (замало змістовних відгуків)");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("--- #{$profile->id} {$profile->name} ---");
                $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $profile->forceFill([
                'ai_review_summary' => $summary,
                'ai_review_summary_generated_at' => now(),
                'ai_review_summary_source_count' => (int) $profile->reviews_count,
            ])->save();

            $done++;
            $this->line("  #{$profile->id} {$profile->name}: готово");
        }

        $this->info("Згенеровано: {$done}, помилок: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * ID топ-N найпопулярніших опублікованих профілів у кожній парі
     * (категорія, місто). Популярність: popularity_score → reviews_count →
     * rating_avg. Профіль може належати кільком категоріям — ID дедуплікуються.
     *
     * @return array<int, int>
     */
    private function topPerCategoryCityIds(int $minReviews, int $topN): array
    {
        $rows = DB::select(
            'SELECT DISTINCT id FROM (
                SELECT p.id,
                    ROW_NUMBER() OVER (
                        PARTITION BY pc.category_id, p.city
                        ORDER BY p.popularity_score DESC, p.reviews_count DESC, p.rating_avg DESC
                    ) AS rn
                FROM profiles p
                JOIN profile_category pc ON pc.profile_id = p.id
                WHERE p.status = ?
                    AND p.is_published = 1
                    AND p.reviews_count >= ?
                    AND p.city IS NOT NULL
                    AND p.city <> ?
            ) ranked
            WHERE ranked.rn <= ?',
            ['active', $minReviews, '', $topN]
        );

        return array_map(static fn ($row) => (int) $row->id, $rows);
    }
}
