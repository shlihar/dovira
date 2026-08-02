<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiEnrichment\ProfileContentAiService;
use Illuminate\Console\Command;
use Throwable;

class RewriteProfileDescriptions extends Command
{
    protected $signature = 'dovira:rewrite-descriptions
        {--limit=50 : Скільки профілів обробити за запуск}
        {--id=* : Обробити лише конкретні ID профілів}
        {--force : Переписати навіть уже переписані (description_rewritten_at)}
        {--dry-run : Показати результат для першого профілю без збереження}';

    protected $description = 'Переписує описи профілів своїми словами через AI (та сама суть, оригінальний текст). Оригінал зберігається в description_original.';

    public function handle(ProfileContentAiService $ai): int
    {
        $query = Profile::query()
            ->whereNotNull('description')
            ->whereRaw("TRIM(description) != ''")
            ->orderBy('id');

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        }

        if (! $this->option('force')) {
            $query->whereNull('description_rewritten_at');
        }

        $limit = max(1, (int) $this->option('limit'));
        $profiles = $query->limit($limit)->get();

        if ($profiles->isEmpty()) {
            $this->info('Немає профілів для обробки (усі вже переписані або описи порожні).');

            return self::SUCCESS;
        }

        $this->info("Профілів в обробці: {$profiles->count()}");
        $done = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            try {
                $result = $ai->rewriteDescription($profile);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  #{$profile->id} {$profile->name}: {$exception->getMessage()}");

                continue;
            }

            if ($result === null) {
                $this->warn("  #{$profile->id} {$profile->name}: пропущено (закороткий/порожній результат)");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("--- #{$profile->id} {$profile->name} ---");
                $this->line('БУЛО: '.$profile->description);
                $this->newLine();
                $this->line('СТАЛО: '.$result['description']);
                $this->line('SEO: '.$result['seo_description']);

                return self::SUCCESS;
            }

            $profile->forceFill([
                // Бекап робиться лише один раз — при повторних запусках
                // з --force оригінал не затирається переписаною версією.
                'description_original' => $profile->description_original ?? $profile->description,
                'description' => $result['description'],
                'seo_description' => $result['seo_description'],
                'description_rewritten_at' => now(),
            ])->save();

            $done++;
            $this->line("  #{$profile->id} {$profile->name}: готово");
        }

        $this->info("Переписано: {$done}, помилок: {$failed}.");
        $remaining = Profile::query()
            ->whereNotNull('description')
            ->whereRaw("TRIM(description) != ''")
            ->whereNull('description_rewritten_at')
            ->count();
        $this->info("Залишилось непереписаних: {$remaining}.");

        return self::SUCCESS;
    }
}
