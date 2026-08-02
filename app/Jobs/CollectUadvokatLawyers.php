<?php

namespace App\Jobs;

use App\Models\AiEnrichmentBatch;
use App\Services\UadvokatImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Фонове збирання адвокатів з uadvokat.com.ua: наповнює AI-batch CSV-даними
 * і передає його в існуючий пайплайн AI-збагачення.
 */
class CollectUadvokatLawyers implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    /**
     * @param  string  $aiMode  none — профілі без AI; valid — AI лише для адвокатів
     *                          з реальним цифровим слідом (top20/Google Maps); all — AI для всіх.
     */
    public function __construct(
        public int $batchId,
        public string $regionSlug,
        public string $citySlug,
        public int $startPage = 1,
        public ?int $endPage = null,
        public ?int $maxLawyers = null,
        public bool $soloOnly = false,
        public string $aiMode = 'valid',
    ) {
        $this->onConnection((string) config('ai_enrichment.connection', config('queue.default', 'database')));
        $this->onQueue((string) config('ai_enrichment.priority_queue', config('ai_enrichment.queue', 'default')));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('collect-uadvokat:' . $this->batchId))
                ->expireAfter($this->timeout + 120)
                ->dontRelease(),
        ];
    }

    public function handle(UadvokatImportService $service): void
    {
        $batch = AiEnrichmentBatch::query()->find($this->batchId);
        if (! $batch || in_array($batch->status, ['archived', 'cancelled'], true)) {
            return;
        }

        try {
            $collected = $service->collectCityLawyerUrls(
                $this->regionSlug,
                $this->citySlug,
                $this->startPage,
                $this->endPage,
                $this->maxLawyers
            );

            $lawyers = [];
            foreach ($collected['urls'] as $url) {
                $details = $service->fetchLawyerDetails($url);
                usleep($service->requestDelayMs() * 1000);

                if ($details === null) {
                    continue;
                }

                if ($this->soloOnly && ! str_contains(mb_strtolower((string) ($details['activity'] ?? '')), 'індивідуальна')) {
                    continue;
                }

                $lawyers[] = $details;
            }

            if ($lawyers === []) {
                $batch->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'options' => array_merge($batch->options ?? [], [
                        'error' => sprintf(
                            'На uadvokat.com.ua не знайдено адвокатів (сторінки %d-%s). Перевір посилання на місто і діапазон сторінок.',
                            $this->startPage,
                            $this->endPage !== null ? (string) $this->endPage : 'кінець'
                        ),
                    ]),
                ]);

                return;
            }

            $cityName = (string) ($lawyers[0]['city'] ?? $this->citySlug);
            $pageRangeLabel = $this->endPage !== null
                ? sprintf('стор. %d-%d', $this->startPage, $this->endPage)
                : ($this->startPage > 1 ? sprintf('стор. %d+', $this->startPage) : 'усі сторінки');

            if ($this->aiMode !== 'all') {
                $this->createProfilesAndMaybeEnrichValid($batch, $service, $lawyers, $cityName, $pageRangeLabel, $collected['pages_visited']);

                return;
            }

            $batch->update([
                'name' => sprintf('Адвокати uadvokat · %s · %s · %d осіб · %s', $cityName, $pageRangeLabel, count($lawyers), now()->format('d.m.Y H:i')),
                'status' => 'queued',
                'default_city' => $cityName,
                'input_text' => $service->buildEnrichmentCsv($lawyers),
                'total_items' => count($lawyers),
                'options' => array_merge($batch->options ?? [], [
                    'pages_visited' => $collected['pages_visited'],
                ]),
            ]);

            Log::info('uadvokat_collect.completed', [
                'batch_id' => $batch->id,
                'region' => $this->regionSlug,
                'city' => $this->citySlug,
                'pages_visited' => $collected['pages_visited'],
                'lawyers' => count($lawyers),
            ]);

            ProcessAiEnrichmentBatch::dispatchAndEnsureWorker((int) $batch->id);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
                'options' => array_merge($batch->options ?? [], [
                    'error' => 'Збір даних з uadvokat не вдався: ' . $e->getMessage(),
                ]),
            ]);

            throw $e;
        }
    }

    /**
     * Режими none/valid: усі профілі створюються напряму з реєстрових даних,
     * а в AI (режим valid) ідуть лише адвокати з реальним цифровим слідом —
     * присутні на top20 (там точно є відгуки) або на Google Maps з відгуками.
     *
     * @param  array<int, array<string, mixed>>  $lawyers
     */
    private function createProfilesAndMaybeEnrichValid(
        AiEnrichmentBatch $batch,
        UadvokatImportService $service,
        array $lawyers,
        string $cityName,
        string $pageRangeLabel,
        int $pagesVisited,
    ): void {
        $defaultCategory = $batch->default_category_id
            ? \App\Models\Category::query()->find($batch->default_category_id)
            : null;
        $publish = (bool) data_get($batch->options, 'publish_profiles', false);
        $result = $service->createProfilesDirectly($lawyers, $defaultCategory, $publish);

        $validProfileIds = [];
        $validNames = [];
        $placesApiUsable = true;
        $reviewsCreated = 0;
        $reviewImportedProfiles = 0;

        if ($this->aiMode === 'valid') {
            $top20Index = $service->buildTop20LawyerNameIndex($cityName);
            $createdByName = [];
            $createdIds = array_values(array_unique(array_merge(
                $result['profile_ids'],
                $result['existing_profile_ids'] ?? []
            )));
            $createdProfiles = \App\Models\Profile::query()->whereKey($createdIds)->get(['id', 'name']);
            foreach ($createdProfiles as $profile) {
                $createdByName[(string) $profile->name] = (int) $profile->id;
            }

            $top20Service = app(\App\Services\Top20BulkImportService::class);

            foreach ($lawyers as $lawyer) {
                $name = trim((string) ($lawyer['name'] ?? ''));
                $profileId = $createdByName[$name] ?? null;
                if ($name === '' || $profileId === null) {
                    continue;
                }

                $top20Match = $service->matchTop20Entry($name, $top20Index);
                $isValid = $top20Match !== null;

                // Email на власному домені з живим сайтом = сайт адвоката/фірми.
                if (! $isValid) {
                    $website = $service->detectLawyerWebsiteFromEmail((string) ($lawyer['email'] ?? ''));
                    if ($website !== null) {
                        $isValid = true;
                        \App\Models\Profile::query()
                            ->whereKey($profileId)
                            ->whereNull('website')
                            ->update(['website' => $website]);
                    }
                }

                if (! $isValid && $placesApiUsable) {
                    $placesVerdict = $service->lawyerFindableViaGooglePlaces($name, $cityName);
                    if ($placesVerdict === null) {
                        $placesApiUsable = false;
                    } else {
                        $isValid = $placesVerdict;
                    }
                }

                if (! $isValid) {
                    continue;
                }

                $validProfileIds[] = $profileId;
                $validNames[] = $name;

                // Персональний збіг (прізвище + ім'я) — одразу тягнемо відгуки з його top20-картки.
                if ($top20Match !== null && $top20Match['personal']) {
                    try {
                        $profile = \App\Models\Profile::query()->find($profileId);
                        if ($profile) {
                            $stats = $top20Service->importReviewsFromTop20Url($profile, (string) $top20Match['url']);
                            $reviewsCreated += (int) ($stats['created'] ?? 0);
                            $reviewImportedProfiles++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('uadvokat_collect.review_import_failed', [
                            'profile_id' => $profileId,
                            'top20_url' => $top20Match['url'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        $hasAiWork = $validProfileIds !== [];

        $batch->update([
            'name' => sprintf(
                'Адвокати uadvokat · %s · %s · %d осіб%s · %s',
                $cityName,
                $pageRangeLabel,
                count($lawyers),
                $this->aiMode === 'valid' ? (' · AI для ' . count($validProfileIds)) : ' · без AI',
                now()->format('d.m.Y H:i')
            ),
            'status' => $hasAiWork ? 'queued' : 'completed',
            'default_city' => $cityName,
            'total_items' => $hasAiWork ? count($validProfileIds) : count($lawyers),
            'drafts_created' => $result['created'],
            'duplicates_found' => $result['skipped'],
            'finished_at' => $hasAiWork ? null : now(),
            'options' => array_merge($batch->options ?? [], [
                'pages_visited' => $pagesVisited,
                'ai_mode' => $this->aiMode,
                'profiles_created_directly' => $result['created'],
                'profiles_skipped_existing' => $result['skipped'],
                'profile_ids' => $validProfileIds,
                'ai_valid_names' => array_slice($validNames, 0, 100),
                'places_api_usable' => $placesApiUsable,
                'top20_reviews_created' => $reviewsCreated,
                'top20_review_profiles' => $reviewImportedProfiles,
            ]),
        ]);

        Log::info('uadvokat_collect.direct_completed', [
            'batch_id' => $batch->id,
            'ai_mode' => $this->aiMode,
            'created' => $result['created'],
            'skipped_existing' => $result['skipped'],
            'ai_valid' => count($validProfileIds),
            'top20_reviews_created' => $reviewsCreated,
        ]);

        if ($hasAiWork) {
            ProcessAiEnrichmentBatch::dispatchAndEnsureWorker((int) $batch->id);
        }
    }
}
