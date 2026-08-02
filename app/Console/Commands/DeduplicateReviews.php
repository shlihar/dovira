<?php

namespace App\Console\Commands;

use App\Services\ProfileReviewStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Прибирає дублі відгуків: однакові за (profile_id, author_name, body, rating).
 * Такі зʼявляються від повторних прогонів імпортів і повторної публікації того
 * самого вставленого блоку у формі швидкого вводу. Лишає найперший запис
 * (MIN id), решту видаляє з JSONL-бекапом і перераховує статистику профілів.
 */
class DeduplicateReviews extends Command
{
    protected $signature = 'dovira:dedupe-reviews
        {--dry-run : Порахувати без видалення}';

    protected $description = 'Видаляє дублі відгуків (той самий профіль/автор/текст/оцінка), лишаючи найперший.';

    public function handle(ProfileReviewStatsService $stats): int
    {
        // Фаза 1. Точні дублі: ключ — профіль+автор+текст+оцінка. У кожній
        // групі лишаємо MIN(id), решту — під видалення.
        $groups = DB::table('profile_reviews')
            ->select('profile_id', 'author_name', 'body', 'rating', DB::raw('count(*) as c'), DB::raw('MIN(id) as keep'))
            ->groupBy('profile_id', 'author_name', 'body', 'rating')
            ->havingRaw('count(*) > 1')
            ->get();

        // Збираємо id під видалення (усе, окрім MIN(id) кожної групи).
        $idsToDelete = [];
        $affectedProfileIds = [];
        foreach ($groups as $g) {
            $ids = DB::table('profile_reviews')
                ->where('profile_id', $g->profile_id)
                ->where('author_name', $g->author_name)
                ->where('body', $g->body)
                ->where('rating', $g->rating)
                ->where('id', '!=', $g->keep)
                ->pluck('id')
                ->all();

            array_push($idsToDelete, ...$ids);
            $affectedProfileIds[(int) $g->profile_id] = true;
        }

        // Фаза 2. «Перекладні» дублі серед імпортованих: Google авто-перекладає,
        // тож той самий відгук живе двома мовами. Той самий профіль+автор+оцінка
        // з датами в межах 90 днів — лишаємо найперший запис (MIN id).
        $translationGroups = DB::table('profile_reviews')
            ->select('profile_id', DB::raw('LOWER(author_name) as author_key'), 'rating', DB::raw('count(*) as c'))
            ->whereNotNull('external_source_type')
            ->groupBy('profile_id', DB::raw('LOWER(author_name)'), 'rating')
            ->havingRaw('count(*) > 1')
            ->get();

        // Схожість текстів: переклади RU↔UA мають багато спільних коренів, тож
        // посимвольна similar_text дає високий відсоток; різні історії того
        // самого автора — низький. Порівнюємо перші 600 символів.
        $similarity = function (string $a, string $b): float {
            $a = mb_strtolower(mb_substr(trim($a), 0, 600));
            $b = mb_strtolower(mb_substr(trim($b), 0, 600));
            if ($a === '' || $b === '') {
                // Обидва порожні (відгук лише з оцінкою) — вважаємо дублем.
                return ($a === $b) ? 100.0 : 0.0;
            }
            similar_text($a, $b, $percent);

            return (float) $percent;
        };

        $translationDupes = 0;
        foreach ($translationGroups as $g) {
            $rows = DB::table('profile_reviews')
                ->where('profile_id', $g->profile_id)
                ->whereRaw('LOWER(author_name) = ?', [$g->author_key])
                ->where('rating', $g->rating)
                ->whereNotNull('external_source_type')
                ->orderBy('id')
                ->get(['id', 'body', 'external_review_date', 'published_at', 'created_at']);

            // Порівнюємо кожен наступний з усіма збереженими: схожий на будь-який
            // (≥55%) і в межах 90 днів — дубль; інакше лишається як окремий відгук.
            $kept = [$rows->first()];
            foreach ($rows->slice(1) as $row) {
                if (in_array((int) $row->id, $idsToDelete, true)) {
                    continue; // вже під видалення у фазі 1
                }
                $rowDate = strtotime($row->external_review_date ?? $row->published_at ?? $row->created_at);
                $isDupe = false;
                foreach ($kept as $k) {
                    $keptDate = strtotime($k->external_review_date ?? $k->published_at ?? $k->created_at);
                    if (abs($rowDate - $keptDate) <= 90 * 86400 && $similarity((string) $row->body, (string) $k->body) >= 55.0) {
                        $isDupe = true;
                        break;
                    }
                }
                if ($isDupe) {
                    $idsToDelete[] = (int) $row->id;
                    $translationDupes++;
                    $affectedProfileIds[(int) $g->profile_id] = true;
                } else {
                    $kept[] = $row;
                }
            }
        }

        if ($idsToDelete === []) {
            $this->info('Дублів не знайдено.');

            return self::SUCCESS;
        }

        $this->info("З них «перекладних» (той самий автор/оцінка, ±90 днів): {$translationDupes}.");
        $affectedProfileIds = array_keys($affectedProfileIds);

        $this->info('Груп дублів: '.$groups->count().'. Рядків до видалення: '.count($idsToDelete).'. Профілів: '.count($affectedProfileIds).'.');

        if ($this->option('dry-run')) {
            $this->line('(dry-run) нічого не видалено.');

            return self::SUCCESS;
        }

        // Бекап перед видаленням — чанками, щоб не тримати все в памʼяті.
        $backupDir = storage_path('app/review-backups');
        @mkdir($backupDir, 0775, true);
        $backupPath = $backupDir.'/dedupe-'.now()->format('Ymd-His').'.jsonl';
        $fh = fopen($backupPath, 'w');
        foreach (array_chunk($idsToDelete, 500) as $chunk) {
            foreach (DB::table('profile_reviews')->whereIn('id', $chunk)->get() as $row) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
            }
        }
        fclose($fh);
        $this->info("Бекап: {$backupPath}");

        // Bulk-видалення, далі — перерахунок статистики по кожному профілю.
        $deleted = 0;
        foreach (array_chunk($idsToDelete, 1000) as $chunk) {
            $deleted += DB::table('profile_reviews')->whereIn('id', $chunk)->delete();
        }

        foreach ($affectedProfileIds as $pid) {
            $stats->recalculateForProfileId((int) $pid);
        }

        $this->info("Видалено: {$deleted}. Перераховано профілів: ".count($affectedProfileIds).'.');

        return self::SUCCESS;
    }
}
