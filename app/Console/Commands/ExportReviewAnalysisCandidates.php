<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Вивантажує профілі, яким бракує справжнього AI-аналізу відгуків, разом із
 * вибіркою текстів відгуків. Файл читає модель (Claude), пише JSONL із
 * висновками, після чого їх заливає dovira:import-review-analysis.
 */
class ExportReviewAnalysisCandidates extends Command
{
    protected $signature = 'dovira:export-review-analysis
        {--min-reviews=100 : Мінімум опублікованих відгуків у профілі}
        {--limit=100 : Скільки профілів вивантажити}
        {--offset=0 : Пропустити N профілів у рейтингу (для наступних батчів)}
        {--max-reviews=22 : Скільки відгуків на профіль класти у файл}
        {--chars=420 : Обрізати текст відгуку до N символів}
        {--id=* : Вивантажити лише конкретні ID}
        {--include-ai : Включати профілі, де AI-аналіз уже є}
        {--exclude= : ID через кому або шлях до файлу зі списком ID/JSONL, які пропустити}
        {--out= : Куди писати JSON (за замовчуванням storage/app/review-analysis/)}';

    protected $description = 'Експортує кандидатів на AI-аналіз відгуків у JSON для ручної генерації.';

    public function handle(): int
    {
        $minReviews = max(1, (int) $this->option('min-reviews'));
        $limit = max(1, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $maxReviews = max(3, (int) $this->option('max-reviews'));
        $chars = max(120, (int) $this->option('chars'));

        $query = Profile::query()
            ->with('categories:id,name')
            ->where('status', 'active')
            ->where('is_published', 1)
            ->where('reviews_count', '>=', $minReviews);

        if ($ids = array_filter(array_map('intval', (array) $this->option('id')))) {
            $query->whereIn('id', $ids);
        } elseif (! $this->option('include-ai')) {
            // Пропускаємо лише профілі зі справжнім AI-висновком; heuristic —
            // це патерн-матчинг із шаблонними фразами, його перезаписуємо.
            $query->whereRaw(
                "(JSON_EXTRACT(ai_suggested_data, '$.review_analysis') IS NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(ai_suggested_data, '$.review_analysis.provider')) NOT IN ('openai', 'claude'))"
            );
        }

        if ($excluded = $this->excludedIds()) {
            $query->whereNotIn('id', $excluded);
        }

        $profiles = $query
            ->orderByDesc('popularity_score')
            ->orderByDesc('reviews_count')
            ->orderBy('id')
            ->skip($offset)
            ->take($limit)
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('Немає профілів для експорту.');

            return self::SUCCESS;
        }

        $payload = [];
        $skipped = 0;

        foreach ($profiles as $profile) {
            $reviews = $this->sampleReviews($profile, $maxReviews, $chars);

            if (count($reviews) < 3) {
                $skipped++;

                continue;
            }

            $payload[] = [
                'profile_id' => (int) $profile->id,
                'name' => (string) $profile->name,
                'category' => (string) ($profile->categories->first()?->name ?? ''),
                'city' => (string) ($profile->city ?? ''),
                'reviews_count' => (int) $profile->reviews_count,
                'rating_avg' => (float) $profile->rating_avg,
                // Вибірка навмисно перекошена в бік критики, тому поруч кладемо
                // реальний розподіл — інакше висновок перебільшить негатив.
                'rating_breakdown' => $this->ratingBreakdown($profile),
                'reviews' => $reviews,
            ];
        }

        if ($payload === []) {
            $this->warn('Усі відібрані профілі не мають достатньо змістовних відгуків.');

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('out'))
            ?: storage_path('app/review-analysis/candidates-'.now()->format('Ymd-His').'.json');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Профілів у файлі: '.count($payload).($skipped > 0 ? ", пропущено (мало текстів): {$skipped}" : ''));
        $this->line($path);

        return self::SUCCESS;
    }

    /**
     * ID, які треба пропустити: або перелік через кому, або файл, з якого
     * витягуємо всі profile_id (працює і зі списком чисел, і з JSONL-звітом).
     *
     * @return array<int, int>
     */
    private function excludedIds(): array
    {
        $value = trim((string) $this->option('exclude'));
        if ($value === '') {
            return [];
        }

        $source = is_file($value) ? (string) file_get_contents($value) : $value;

        preg_match_all('/"(?:profile_id|id)"\s*:\s*(\d+)|(?:^|[,\s])(\d+)(?=[,\s]|$)/m', $source, $matches);

        $ids = array_filter(array_map('intval', array_merge($matches[1], $matches[2])));

        return array_values(array_unique($ids));
    }

    /**
     * Скільки опублікованих відгуків на кожну зірку.
     *
     * @return array<int, int>
     */
    private function ratingBreakdown(Profile $profile): array
    {
        $counts = $profile->reviews()
            ->where('status', 'published')
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $breakdown = [];
        foreach ([5, 4, 3, 2, 1] as $star) {
            $breakdown[$star] = (int) ($counts[$star] ?? 0);
        }

        return $breakdown;
    }

    /**
     * Вибірка відгуків для аналізу: спершу всі критичні (≤3), далі найсвіжіші
     * позитивні. Дублікати за нормалізованим текстом відкидаємо — у імпортах
     * з агрегаторів однакові короткі похвали трапляються десятками.
     *
     * @return array<int, array{r: int, d: string, t: string}>
     */
    private function sampleReviews(Profile $profile, int $maxReviews, int $chars): array
    {
        $rows = $profile->reviews()
            ->where('status', 'published')
            ->whereNotNull('body')
            ->whereRaw('CHAR_LENGTH(TRIM(body)) >= 25')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(160)
            ->get(['rating', 'body', 'published_at', 'created_at']);

        $critical = [];
        $positive = [];
        $seen = [];

        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row->body) ?? '');
            if ($text === '') {
                continue;
            }

            $key = Str::lower(Str::limit($text, 120, ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entry = [
                'r' => (int) $row->rating,
                'd' => (string) (optional($row->published_at ?? $row->created_at)?->format('Y-m') ?? ''),
                't' => Str::limit($text, $chars),
            ];

            if ($entry['r'] > 0 && $entry['r'] <= 3) {
                $critical[] = $entry;
            } else {
                $positive[] = $entry;
            }
        }

        $criticalQuota = min(count($critical), (int) ceil($maxReviews / 2));

        return array_slice(
            array_merge(
                array_slice($critical, 0, $criticalQuota),
                array_slice($positive, 0, $maxReviews - $criticalQuota)
            ),
            0,
            $maxReviews
        );
    }
}
