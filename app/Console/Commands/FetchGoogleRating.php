<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Тягне реальний Google-рейтинг (панель знань) для профілів через справжній
 * Chrome (scripts/google_ratings.js). Працює лише локально з дисплеєм.
 */
class FetchGoogleRating extends Command
{
    protected $signature = 'dovira:fetch-google-rating
        {--id=* : ID профілів}
        {--category= : Slug категорії}
        {--limit=20 : Скільки профілів за запуск}
        {--refresh : Оновити навіть уже отримані}';

    protected $description = 'Отримує Google-рейтинг профілів через браузер і зберігає google_rating.';

    public function handle(): int
    {
        $query = Profile::query()->whereNotNull('name');

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('reviews_count', '>', 0);
            if (! $this->option('refresh')) {
                $query->whereNull('google_rating_fetched_at');
            }
            if ($category = trim((string) $this->option('category'))) {
                $query->whereHas('categories', fn ($q) => $q->where('slug', $category));
            }
            $query->orderByDesc('reviews_count')->limit(max(1, (int) $this->option('limit')));
        }

        $profiles = $query->get(['id', 'name', 'city']);
        if ($profiles->isEmpty()) {
            $this->info('Немає профілів для обробки.');

            return self::SUCCESS;
        }

        $input = $profiles->map(fn (Profile $p) => [
            'id' => $p->id,
            'query' => trim($p->name.' '.($p->city ?? '')),
        ])->values()->all();

        $dir = storage_path('app/google-ratings');
        @mkdir($dir, 0775, true);
        $inPath = $dir.'/in-'.Str::random(6).'.json';
        $outPath = $dir.'/out-'.Str::random(6).'.json';
        file_put_contents($inPath, json_encode($input, JSON_UNESCAPED_UNICODE));

        $this->info("Профілів: {$profiles->count()}. Запускаю браузер (відкриється вікно Chrome)…");

        $result = Process::timeout(60 * max(2, $profiles->count()))
            ->path(base_path())
            ->run("node scripts/google_ratings.cjs {$inPath} {$outPath}");

        $this->line($result->errorOutput());

        if (! is_file($outPath)) {
            $this->error('Скрипт не повернув результат.');

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($outPath), true) ?: [];
        $saved = 0;
        $blocked = 0;

        foreach ($rows as $row) {
            if (! empty($row['blocked'])) {
                $blocked++;
            }
            if (empty($row['rating'])) {
                continue;
            }
            Profile::where('id', (int) $row['id'])->update([
                'google_rating' => (float) $row['rating'],
                'google_reviews_count' => isset($row['count']) ? (int) $row['count'] : null,
                'google_rating_fetched_at' => now(),
            ]);
            $saved++;
        }

        @unlink($inPath);
        @unlink($outPath);

        $this->info("Збережено рейтингів: {$saved}/{$profiles->count()}".($blocked ? " (заблоковано Google: {$blocked} — зробіть паузу)" : ''));

        return self::SUCCESS;
    }
}
