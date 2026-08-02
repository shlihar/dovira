<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiProfileEnrichmentService;
use Illuminate\Console\Command;
use Throwable;

class EnrichLawyers extends Command
{
    protected $signature = 'dovira:enrich-lawyers
        {--id=* : ID профілів для збагачення}
        {--name=* : Пошук профілів за назвою (LIKE, можна кілька)}
        {--city= : Обмежити пошук за назвою цим містом}
        {--no-web-search : Вимкнути веб-пошук (за замовчуванням увімкнено)}
        {--dry-run : Показати, які профілі будуть збагачені, без запитів}';

    protected $description = 'Справжнє AI-збагачення обраних профілів: веб-пошук сайту/соцмереж/відгуків (не локальний шаблон).';

    public function handle(AiProfileEnrichmentService $service): int
    {
        // Ключове: вимикаємо локальний шаблон для структурованих рядків і
        // вмикаємо веб-пошук — інакше профіль з категорією+містом іде в шаблон.
        config(['ai_enrichment.openai.prefer_local_for_structured_rows' => false]);
        if (! $this->option('no-web-search')) {
            config(['ai_enrichment.openai.web_search' => true]);
        }

        $profiles = $this->resolveProfiles();

        if ($profiles->isEmpty()) {
            $this->error('Не знайдено профілів. Задайте --id або --name.');

            return self::FAILURE;
        }

        $this->info("Профілів до збагачення: {$profiles->count()} (веб-пошук: "
            .($this->option('no-web-search') ? 'вимк' : 'увімк').')');

        if ($this->option('dry-run')) {
            foreach ($profiles as $p) {
                $this->line("  #{$p->id} {$p->name} — {$p->city}, відг: {$p->reviews_count}");
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            $beforeDesc = mb_strlen((string) $profile->description);
            $beforeReviews = (int) $profile->reviews_count;

            try {
                $task = $service->refreshSingleProfile($profile);
                $fresh = $profile->fresh();

                $this->line(sprintf(
                    '  #%d %s: статус=%s | опис %d→%d симв | сайт: %s | відг %d→%d',
                    $profile->id,
                    $profile->name,
                    $task->status,
                    $beforeDesc,
                    mb_strlen((string) $fresh->description),
                    filled($fresh->website) ? $fresh->website : 'нема',
                    $beforeReviews,
                    (int) $fresh->reviews_count,
                ));
                $ok++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  #{$profile->id} {$profile->name}: {$exception->getMessage()}");
            }
        }

        $this->info("Збагачено: {$ok}, помилок: {$failed}.");

        return self::SUCCESS;
    }

    private function resolveProfiles()
    {
        $ids = array_filter((array) $this->option('id'));
        $names = array_filter((array) $this->option('name'));
        $city = trim((string) $this->option('city'));

        return Profile::query()
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when($names, fn ($q) => $q->where(function ($sub) use ($names) {
                foreach ($names as $name) {
                    $sub->orWhere('name', 'like', '%'.trim((string) $name).'%');
                }
            }))
            ->when($city !== '', fn ($q) => $q->where('city', 'like', '%'.$city.'%'))
            ->when(! $ids && ! $names, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get();
    }
}
