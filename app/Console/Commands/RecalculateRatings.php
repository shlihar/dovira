<?php

namespace App\Console\Commands;

use App\Services\Rating\RatingRecalculator;
use Illuminate\Console\Command;

/**
 * Перерахунок рейтингів і популярності. З --dry-run нічого не пише і показує
 * діф (як зміняться числа) — точка перевірки перед бойовим запуском.
 */
class RecalculateRatings extends Command
{
    protected $signature = 'rating:recalculate
        {--dry-run : Порахувати й показати діф, нічого не записуючи}
        {--profile= : Тільки один профіль (id)}
        {--category= : Тільки профілі однієї категорії (id)}
        {--top=30 : Скільки найбільших змін показати у звіті}';

    protected $description = 'Перераховує рейтинг (якість) і популярність (позицію) профілів у profile_ratings + дзеркалить у profiles';

    public function handle(RatingRecalculator $recalculator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $profileId = $this->option('profile') !== null ? (int) $this->option('profile') : null;
        $categoryId = $this->option('category') !== null ? (int) $this->option('category') : null;

        $this->info($dryRun ? '🔍 DRY-RUN: нічого не записуємо, лише рахуємо діф.' : '⚙️  Перерахунок рейтингів…');

        $bar = null;
        $result = $recalculator->run($dryRun, $profileId, $categoryId, function (int $done, int $total) use (&$bar): void {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
                $bar->start();
            }
            $bar->setProgress($done);
        });
        if ($bar) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->info("Оброблено профілів: {$result['processed']}");
        if (! empty($result['failed'])) {
            $this->warn('Не вдалося (пропущено після ретраїв): ' . count($result['failed']) . ' — можна перезапустити команду, розрахунок ідемпотентний.');
        }

        // Звіт: найбільші зміни рейтингу (для точки перевірки).
        $changes = collect($result['changes'])
            ->sortByDesc(fn ($c) => abs($c['new_rating'] - $c['old_rating']))
            ->take((int) $this->option('top'));

        if ($changes->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Найбільші зміни рейтингу:</comment>');
            $this->table(
                ['id', 'Профіль', 'Було', 'Стало', 'Надійно', 'Бейдж', 'Популярність'],
                $changes->map(fn ($c) => [
                    $c['id'],
                    mb_strimwidth((string) $c['name'], 0, 32, '…'),
                    number_format($c['old_rating'], 2),
                    number_format($c['new_rating'], 2) . ($c['reliable'] ? '' : ' (н/д)'),
                    $c['reliable'] ? 'так' : 'ні',
                    $c['recommended'] ? '★' : '',
                    number_format($c['old_pop'], 2) . ' → ' . number_format($c['new_pop'], 2),
                ])->all()
            );
        }

        $recommended = collect($result['changes'])->where('recommended', true)->count();
        $unreliable = collect($result['changes'])->where('reliable', false)->count();
        $this->newLine();
        $this->line("З бейджем «Dovira рекомендує»: <info>{$recommended}</info>");
        $this->line("Ненадійних (мало відгуків, число ховаємо): <info>{$unreliable}</info>");

        if ($dryRun) {
            $this->newLine();
            $this->warn('Це був DRY-RUN. Щоб застосувати: php artisan rating:recalculate');
        }

        return self::SUCCESS;
    }
}
