<?php

namespace App\Console\Commands;

use App\Models\ProfileReview;
use App\Services\ProfileReviewStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Видаляє N% найдавніших опублікованих 5-зіркових відгуків у кожному профілі.
 * Перед видаленням робить бекап (JSON) для можливого відкату.
 */
class PruneOldFiveStarReviews extends Command
{
    protected $signature = 'dovira:prune-old-5star
        {--percent=10 : Відсоток найдавніших 5★ відгуків на профіль}
        {--dry-run : Порахувати без видалення}';

    protected $description = 'Видаляє N% найдавніших 5★ відгуків у кожному профілі (з бекапом).';

    public function handle(ProfileReviewStatsService $stats): int
    {
        $percent = max(1, min(90, (int) $this->option('percent')));

        // Профілі, що мають опубліковані 5★ відгуки, з їх кількістю.
        $profileCounts = ProfileReview::query()
            ->where('status', 'published')
            ->where('rating', 5)
            ->select('profile_id', DB::raw('count(*) as c'))
            ->groupBy('profile_id')
            ->having('c', '>=', 1)
            ->pluck('c', 'profile_id');

        $idsToDelete = [];

        foreach ($profileCounts as $profileId => $count) {
            $take = (int) floor($count * $percent / 100);
            if ($take < 1) {
                continue;
            }

            $ids = ProfileReview::query()
                ->where('profile_id', $profileId)
                ->where('status', 'published')
                ->where('rating', 5)
                ->orderByRaw('COALESCE(external_review_date, published_at, created_at) asc')
                ->limit($take)
                ->pluck('id')
                ->all();

            array_push($idsToDelete, ...$ids);
        }

        if ($idsToDelete === []) {
            $this->info('Немає відгуків під видалення (замало 5★ на профіль).');

            return self::SUCCESS;
        }

        $this->info('Профілів зачеплено: '.$profileCounts->count().'. Відгуків до видалення: '.count($idsToDelete).'.');

        if ($this->option('dry-run')) {
            $this->line('(dry-run) нічого не видалено.');

            return self::SUCCESS;
        }

        // Бекап перед видаленням — чанками, щоб не тримати всі моделі в памʼяті.
        $backupDir = storage_path('app/review-backups');
        @mkdir($backupDir, 0775, true);
        $backupPath = $backupDir.'/prune-5star-'.now()->format('Ymd-His').'.jsonl';
        $fh = fopen($backupPath, 'w');
        $affectedProfileIds = [];
        foreach (array_chunk($idsToDelete, 500) as $chunk) {
            foreach (DB::table('profile_reviews')->whereIn('id', $chunk)->get() as $row) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
                $affectedProfileIds[(int) $row->profile_id] = true;
            }
        }
        fclose($fh);
        $affectedProfileIds = array_keys($affectedProfileIds);
        $this->info("Бекап: {$backupPath}");

        // Bulk-видалення (швидко), далі — перерахунок статистики по кожному профілю.
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
