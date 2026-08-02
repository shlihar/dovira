<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Top20BulkImportService;
use Illuminate\Console\Command;
use Throwable;

class RefetchProfileReviews extends Command
{
    protected $signature = 'dovira:refetch-reviews
        {--category= : Slug категорії (напр. advokaty)}
        {--min-current=95 : Обробляти лише профілі з поточним reviews_count >= цього (обрізані стелею)}
        {--max-per-profile=1000 : Стеля відгуків на профіль (хардкеп 1000)}
        {--no-avatars : Не завантажувати аватарки відгуків (значно швидше й легше на памʼять)}
        {--id=* : Обробити лише конкретні ID профілів}
        {--limit=0 : Обмежити кількість профілів за запуск (0 = усі)}
        {--dry-run : Показати, які профілі будуть оброблені, без запитів}';

    protected $description = 'Перезабирає всі відгуки з Top20 для профілів (знімає стелю у 100) і публікує їх.';

    public function handle(Top20BulkImportService $service): int
    {
        // Піднімаємо стелю на час запуску: і фетч, і обрізання зважають на цей ліміт.
        $maxPerProfile = max(1, min(1000, (int) $this->option('max-per-profile')));
        config(['top20_bulk_import.max_reviews_per_profile' => $maxPerProfile]);

        // Завантаження аватарок — найважча за памʼяттю й часом частина. Вимкнення
        // дає ×3–5 швидкості; аватарки потім доганяються dovira:repair-review-avatars.
        if ($this->option('no-avatars')) {
            config(['top20_bulk_import.download_review_avatars' => false]);
        }

        $minCurrent = max(0, (int) $this->option('min-current'));

        $query = Profile::query()->orderByDesc('reviews_count');

        if ($category = trim((string) $this->option('category'))) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $category));
        }

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('reviews_count', '>=', $minCurrent);
        }

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $profiles = $query->get(['id', 'name', 'reviews_count']);

        if ($profiles->isEmpty()) {
            $this->info('Немає профілів для обробки.');

            return self::SUCCESS;
        }

        $this->info("Профілів в обробці: {$profiles->count()} (стеля {$maxPerProfile} відгуків/профіль)");

        $totalCreated = 0;
        $totalDeleted = 0;
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($profiles as $profileRow) {
            $profile = Profile::find($profileRow->id);
            if (! $profile) {
                continue;
            }

            $top20Url = $service->resolveProfileTop20Url($profile);
            if (blank($top20Url)) {
                $skipped++;
                $this->line("  #{$profile->id} {$profile->name}: пропущено (нема Top20-джерела)");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  #{$profile->id} {$profile->name} ({$profile->reviews_count} відг.) → {$top20Url}");

                continue;
            }

            try {
                $before = (int) $profile->reviews_count;
                $stats = $service->importReviewsFromTop20Url($profile, $top20Url);
                $after = (int) $profile->fresh()->reviews_count;

                $created = (int) ($stats['created'] ?? 0);
                $deleted = (int) ($stats['deleted'] ?? 0);
                $totalCreated += $created;
                $totalDeleted += $deleted;
                $processed++;

                $this->line("  #{$profile->id} {$profile->name}: {$before} → {$after} (нових {$created}, прибрано {$deleted})");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  #{$profile->id} {$profile->name}: {$exception->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->info("Оброблено: {$processed}, пропущено: {$skipped}, помилок: {$failed}. Нових відгуків: {$totalCreated}, прибрано: {$totalDeleted}.");

        return self::SUCCESS;
    }
}
