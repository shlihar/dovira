<?php

namespace App\Console\Commands;

use App\Jobs\CollectUadvokatLawyers;
use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Services\UadvokatImportService;
use Illuminate\Console\Command;

class ImportUadvokatCity extends Command
{
    protected $signature = 'dovira:import-uadvokat
        {region : Слаг області на uadvokat.com.ua, наприклад cherkaska}
        {city : Слаг міста, наприклад cherkasi}
        {--start-page=1 : З якої сторінки реєстру починати (~10 адвокатів на сторінці)}
        {--end-page= : По яку сторінку включно; порожньо = до кінця міста}
        {--max-lawyers= : Обмежити кількість адвокатів (для тестового прогону)}
        {--category-slug=catalog-yurydychni-poslugy : Категорія DOVIRA}
        {--subcategory-slug=advokaty : Підкатегорія DOVIRA}
        {--solo-only : Лише індивідуальна адвокатська діяльність}
        {--publish : Одразу публікувати створені профілі}
        {--ai-mode=valid : none|valid|all — кому запускати AI-збагачення}
        {--dry-run : Показати зібрані дані без створення профілів}';

    protected $description = 'Імпорт адвокатів з uadvokat.com.ua: всі профілі + вибіркове AI-збагачення.';

    public function handle(UadvokatImportService $service): int
    {
        $regionSlug = (string) $this->argument('region');
        $citySlug = (string) $this->argument('city');
        $startPage = max(1, (int) $this->option('start-page'));
        $endPage = filled($this->option('end-page')) ? max($startPage, (int) $this->option('end-page')) : null;
        $maxLawyers = filled($this->option('max-lawyers')) ? max(1, (int) $this->option('max-lawyers')) : null;
        $soloOnly = (bool) $this->option('solo-only');
        $aiMode = in_array($this->option('ai-mode'), ['none', 'valid', 'all'], true)
            ? (string) $this->option('ai-mode')
            : 'valid';

        $categorySlug = (string) $this->option('category-slug');
        $subcategorySlug = (string) $this->option('subcategory-slug');
        $category = Category::query()->where('slug', $categorySlug)->first();
        if (! $category) {
            $this->error("Категорію DOVIRA не знайдено: {$categorySlug}");

            return self::FAILURE;
        }

        $subcategory = $subcategorySlug !== ''
            ? Category::query()->where('parent_id', $category->id)->where('slug', $subcategorySlug)->first()
            : null;

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun($service, $regionSlug, $citySlug, $startPage, $endPage, $maxLawyers, $soloOnly);
        }

        $pageRangeLabel = $endPage !== null
            ? sprintf('стор. %d-%d', $startPage, $endPage)
            : ($startPage > 1 ? sprintf('стор. %d+', $startPage) : 'усі сторінки');

        $batch = AiEnrichmentBatch::create([
            'default_category_id' => $subcategory?->id ?? $category->id,
            'name' => sprintf('Адвокати uadvokat · %s/%s · %s · збір даних… · %s', $regionSlug, $citySlug, $pageRangeLabel, now()->format('d.m.Y H:i')),
            'source_type' => 'uadvokat',
            'status' => 'collecting',
            'default_country' => 'Україна',
            'language' => 'uk',
            'options' => [
                'find_reviews' => true,
                'uadvokat_region' => $regionSlug,
                'uadvokat_city' => $citySlug,
                'start_page' => $startPage,
                'end_page' => $endPage,
                'solo_only' => $soloOnly,
                'publish_profiles' => (bool) $this->option('publish'),
                'ai_mode' => $aiMode,
                'max_lawyers' => $maxLawyers,
            ],
            'total_items' => 0,
        ]);

        $this->line("Batch #{$batch->id}: збираю адвокатів (сторінки {$startPage}-" . ($endPage ?? 'кінець') . ", AI-режим: {$aiMode})...");

        $job = new CollectUadvokatLawyers(
            (int) $batch->id,
            $regionSlug,
            $citySlug,
            $startPage,
            $endPage,
            $maxLawyers,
            $soloOnly,
            $aiMode
        );
        $job->handle($service);

        $batch->refresh();
        $this->table(['Метрика', 'Значення'], [
            ['Статус batch', (string) $batch->status],
            ['Профілів створено', (string) data_get($batch->options, 'profiles_created_directly', $batch->drafts_created)],
            ['Пропущено (вже існують)', (string) data_get($batch->options, 'profiles_skipped_existing', $batch->duplicates_found)],
            ['Відібрано для AI', (string) count((array) data_get($batch->options, 'profile_ids', []))],
            ['Google Places доступний', data_get($batch->options, 'places_api_usable', true) ? 'так' : 'ні (перевірка лише по top20)'],
        ]);

        if ($batch->status === 'queued') {
            $this->info('AI-збагачення відібраних поставлено в чергу. Прогрес: адмінка → AI збагачення профілів.');
        }

        return self::SUCCESS;
    }

    private function dryRun(UadvokatImportService $service, string $regionSlug, string $citySlug, int $startPage, ?int $endPage, ?int $maxLawyers, bool $soloOnly): int
    {
        $collected = $service->collectCityLawyerUrls($regionSlug, $citySlug, $startPage, $endPage, $maxLawyers);
        if ($collected['urls'] === []) {
            $this->error('Не знайдено жодного адвоката. Перевір слаги області/міста і діапазон сторінок.');

            return self::FAILURE;
        }
        $this->line(sprintf('Пройдено сторінок: %d, знайдено посилань: %d', $collected['pages_visited'], count($collected['urls'])));

        $lawyers = [];
        $skippedNonSolo = 0;
        $bar = $this->output->createProgressBar(count($collected['urls']));

        foreach ($collected['urls'] as $url) {
            $details = $service->fetchLawyerDetails($url);
            $bar->advance();
            usleep($service->requestDelayMs() * 1000);

            if ($details === null) {
                continue;
            }

            if ($soloOnly && ! str_contains(mb_strtolower((string) ($details['activity'] ?? '')), 'індивідуальна')) {
                $skippedNonSolo++;
                continue;
            }

            $lawyers[] = $details;
        }
        $bar->finish();
        $this->newLine(2);

        $cityName = (string) ($lawyers[0]['city'] ?? $citySlug);
        $top20Index = $service->buildTop20LawyerNameIndex($cityName);
        $validCount = count(array_filter(
            $lawyers,
            fn (array $lawyer): bool => $service->lawyerMatchesTop20Index((string) $lawyer['name'], $top20Index)
        ));

        $this->table(['Метрика', 'Значення'], [
            ['Спарсено адвокатів', (string) count($lawyers)],
            ['З телефоном', (string) count(array_filter($lawyers, fn (array $l): bool => filled($l['phone'])))],
            ['З email', (string) count(array_filter($lawyers, fn (array $l): bool => filled($l['email'])))],
            ['Пропущено (не одноосібники)', (string) $skippedNonSolo],
            ['Валідні для AI (знайдені на top20)', (string) $validCount],
        ]);

        $this->newLine();
        $this->table(
            ['ПІБ', 'Місто', 'Телефон', 'top20?'],
            array_map(fn (array $l): array => [
                mb_substr((string) $l['name'], 0, 40),
                (string) ($l['city'] ?? ''),
                mb_substr((string) ($l['phone'] ?? ''), 0, 25),
                $service->lawyerMatchesTop20Index((string) $l['name'], $top20Index) ? 'ТАК' : '-',
            ], array_slice($lawyers, 0, 15))
        );

        return self::SUCCESS;
    }
}
