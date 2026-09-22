<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Синхронізує profiles.popularity_score з profile_ratings.popularity_score.
 *
 * Колонка в profiles — дзеркало для швидкого сортування списків (вона
 * проіндексована, тож каталог не робить join). Джерело правди — рейтингова
 * система. Команда потрібна разово після видалення старої адитивної формули
 * (вона затирала дзеркало значеннями в сотні разів більшого масштабу) і як
 * ремонтний інструмент, якщо дзеркало колись розійдеться.
 */
class SyncProfilePopularity extends Command
{
    protected $signature = 'dovira:rating:sync-popularity {--dry-run : Лише показати, скільки профілів розійшлося}';

    protected $description = 'Привести profiles.popularity_score у відповідність до profile_ratings';

    public function handle(): int
    {
        $drifted = DB::table('profiles as p')
            ->join('profile_ratings as r', 'r.profile_id', '=', 'p.id')
            ->whereRaw('ABS(p.popularity_score - r.popularity_score) > 0.01')
            ->count();

        $this->line("Профілів із розбіжністю дзеркала: {$drifted}");

        if ($drifted === 0) {
            $this->info('Дзеркало вже актуальне.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $sample = DB::table('profiles as p')
                ->join('profile_ratings as r', 'r.profile_id', '=', 'p.id')
                ->whereRaw('ABS(p.popularity_score - r.popularity_score) > 0.01')
                ->orderByDesc('p.popularity_score')
                ->limit(5)
                ->get(['p.id', 'p.name', 'p.popularity_score as mirror', 'r.popularity_score as source']);

            foreach ($sample as $row) {
                $this->line(sprintf('#%d %s: %s → %s', $row->id, mb_substr((string) $row->name, 0, 34), $row->mirror, round((float) $row->source, 2)));
            }

            $this->warn('Dry-run: змін не внесено.');

            return self::SUCCESS;
        }

        // Одним UPDATE ... JOIN, без вивантаження 34k моделей у память.
        $updated = DB::table('profiles as p')
            ->join('profile_ratings as r', 'r.profile_id', '=', 'p.id')
            ->whereRaw('ABS(p.popularity_score - r.popularity_score) > 0.01')
            ->update(['p.popularity_score' => DB::raw('ROUND(r.popularity_score, 2)')]);

        $this->info("Оновлено профілів: {$updated}.");

        return self::SUCCESS;
    }
}
