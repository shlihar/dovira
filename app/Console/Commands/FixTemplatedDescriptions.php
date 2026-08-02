<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiEnrichment\ProfileContentAiService;
use Illuminate\Console\Command;
use Throwable;

class FixTemplatedDescriptions extends Command
{
    // Маркери шаблонного опису локального евристичного провайдера.
    private const TEMPLATE_MARKER = 'надає юридичну допомогу за напрямами';

    private const META_MARKER = 'потребує ручної перевірки джерел перед публікацією';

    protected $signature = 'dovira:fix-templated-descriptions
        {--limit=50 : Скільки профілів обробити за запуск}
        {--id=* : Обробити лише конкретні ID}
        {--dry-run : Показати результат для першого профілю без збереження}';

    protected $description = 'Замінює однаковий шаблонний опис (і службове речення) на унікальні AI-описи.';

    public function handle(ProfileContentAiService $ai): int
    {
        $query = Profile::query()
            ->where(function ($q): void {
                $q->where('description', 'like', '%'.self::TEMPLATE_MARKER.'%')
                    ->orWhere('description', 'like', '%'.self::META_MARKER.'%');
            })
            ->orderBy('id');

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        }

        $profiles = $query->limit(max(1, (int) $this->option('limit')))->get();

        if ($profiles->isEmpty()) {
            $this->info('Немає шаблонних описів для обробки.');

            return self::SUCCESS;
        }

        $this->info("Профілів в обробці: {$profiles->count()}");
        $done = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            try {
                $result = $ai->composeProfileDescription($profile);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  #{$profile->id} {$profile->name}: {$exception->getMessage()}");

                continue;
            }

            if ($result === null) {
                $this->warn("  #{$profile->id} {$profile->name}: пропущено (порожній результат)");

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
                'description_original' => $profile->description_original ?? $profile->description,
                'description' => $result['description'],
                'seo_description' => $result['seo_description'],
                'description_rewritten_at' => now(),
            ])->save();

            $done++;
            $this->line("  #{$profile->id} {$profile->name}: готово");
        }

        $remaining = Profile::query()
            ->where('description', 'like', '%'.self::TEMPLATE_MARKER.'%')
            ->count();

        $this->info("Перероблено: {$done}, помилок: {$failed}. Залишилось шаблонних: {$remaining}.");

        return self::SUCCESS;
    }
}
