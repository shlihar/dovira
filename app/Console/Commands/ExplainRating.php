<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Rating\CategoryStatsService;
use App\Services\Rating\ProfileRatingCalculator;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;

/**
 * Друкує повну розкладку рейтингу одного профілю: «чому в нього саме 4.62».
 */
class ExplainRating extends Command
{
    protected $signature = 'rating:explain {profile : id профілю}';

    protected $description = 'Показує повну розкладку рейтингу та популярності профілю (без запису)';

    public function handle(CategoryStatsService $stats, ProfileRatingCalculator $calculator): int
    {
        $profile = Profile::query()->with('rating')->find((int) $this->argument('profile'));
        if (! $profile) {
            $this->error('Профіль не знайдено.');

            return self::FAILURE;
        }

        $primary = CategoryHierarchy::primaryCategory($profile);
        $means = $stats->computeMeans();
        $p = $stats->resolveP(
            $primary?->id,
            $means,
            $stats->globalMean(),
            (int) config('rating.category_stats.category_min_profiles', 30)
        );

        $r = $calculator->calculate($profile, $p, false);
        $b = $r['calculation_breakdown'];

        $this->info("Профіль #{$profile->id}: {$profile->name}");
        $this->line("Категорія (p): {$b['p']}   C: {$b['c']}");
        $this->newLine();
        $this->line('<comment>РЕЙТИНГ (якість)</comment>');
        $this->line("  сира середня:        {$b['raw_average']}");
        $this->line("  зважена середня:     {$b['weighted_average']}");
        $this->line("  сума ваг (Σw):       {$b['effective_weight_sum']}");
        $this->line("  байєс:               {$b['bayesian']}");
        $this->line("  ентропія розподілу:  {$b['entropy']}" . ($b['flags'] ? '  ⚠ ' . implode(',', $b['flags']) : ''));
        $this->line("  → РЕЙТИНГ:           <info>" . ($r['is_rating_reliable'] ? number_format($r['rating'], 2) : 'н/д (мало відгуків)') . '</info>');
        $this->newLine();
        $this->line('<comment>ВІДГУКИ</comment>');
        $rv = $b['reviews'];
        $this->line("  зараховано: {$rv['counted']} (власних: {$rv['own']}, підтв.: {$rv['confirmed']}, імпорт: {$rv['imported']}), за 90 днів: {$rv['last90']}");
        $this->line('  розподіл 1→5: ' . implode(' / ', $b['distribution']));
        $this->newLine();
        $this->line('<comment>ПОПУЛЯРНІСТЬ (позиція)</comment>');
        foreach ($b['popularity'] as $k => $v) {
            $this->line("  {$k}: {$v}");
        }
        $this->newLine();
        $this->line('<comment>БЕЙДЖ «Dovira рекомендує»</comment>');
        $this->line('  кваліфікується: ' . ($b['badge']['qualifies'] ? 'так' : 'ні'));
        if ($b['badge']['failed']) {
            $this->line('  не вистачає: ' . implode(', ', $b['badge']['failed']));
        }

        return self::SUCCESS;
    }
}
