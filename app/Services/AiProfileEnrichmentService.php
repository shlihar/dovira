<?php

namespace App\Services;

use App\Models\AiEnrichmentBatch;
use App\Models\AiEnrichmentTask;
use App\Models\Category;
use App\Models\CategoryService;
use App\Models\ExternalProfileMention;
use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Models\ProfileReview;
use App\Services\AiEnrichment\Contracts\ProfileEnrichmentProvider;
use App\Support\CategoryHierarchy;
use App\Support\MediaUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiProfileEnrichmentService
{
    private ProfileEnrichmentProvider $provider;
    /**
     * @var array<string, array{row_number:int,name:string,city:?string}>
     */
    private array $seenBatchIdentities = [];

    public function __construct()
    {
        $provider = (string) config('ai_enrichment.provider', 'local');
        $providerClass = (string) (config("ai_enrichment.providers.{$provider}") ?: $provider);

        $this->provider = app($providerClass);
    }

    public function refreshSingleProfile(Profile $profile, ?int $requestedByUserId = null): AiEnrichmentTask
    {
        $profile->loadMissing(['categories', 'dataSources']);

        $selection = CategoryHierarchy::selectionFromProfile($profile);
        $defaultCategoryId = $selection['subcategory_id'] ?: $selection['category_id'];
        $sourceUrl = ProfileDataSource::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('url')
            ->orderByRaw("CASE WHEN source_type IN ('top20_import', 'top20') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->value('url');

        $batch = AiEnrichmentBatch::create([
            'user_id' => $requestedByUserId,
            'default_category_id' => $defaultCategoryId ? (int) $defaultCategoryId : null,
            'name' => 'Оновлення AI профілю · ' . $profile->name . ' · ' . now()->format('d.m.Y H:i'),
            'source_type' => 'manual',
            'status' => 'queued',
            'default_city' => $profile->city,
            'default_country' => 'Україна',
            'language' => 'uk',
            'options' => [
                'find_reviews' => true,
                'single_profile_refresh' => true,
                'requested_by_user_id' => $requestedByUserId,
            ],
            'total_items' => 1,
        ]);

        $task = AiEnrichmentTask::create([
            'batch_id' => $batch->id,
            'profile_id' => $profile->id,
            'category_id' => $defaultCategoryId ? (int) $defaultCategoryId : null,
            'row_number' => 1,
            'raw_name' => $profile->name ?: 'Невідомий профіль',
            'raw_city' => $profile->city,
            'raw_phone' => $profile->phone,
            'raw_website' => $profile->website,
            'raw_source_url' => $sourceUrl,
            'raw_payload' => [
                'profile_id' => $profile->id,
                'name' => $profile->name,
                'city' => $profile->city,
                'phone' => $profile->phone,
                'website' => $profile->website,
                'email' => $profile->email,
                'address' => $profile->address,
                'source_url' => $sourceUrl,
            ],
            'status' => 'pending',
        ]);

        $batch->update([
            'status' => 'processing',
            'started_at' => now(),
            'finished_at' => null,
            'drafts_created' => 0,
            'profiles_updated' => 0,
            'duplicates_found' => 0,
            'errors_count' => 0,
            'needs_review_count' => 0,
            'sources_found' => 0,
            'options' => array_merge($batch->options ?? [], [
                'progress' => [
                    'processed_items' => 0,
                    'total_items' => 1,
                    'percent' => 0,
                    'pending_items' => 1,
                    'current' => null,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $this->processPreparedTask($task);
        $task->refresh();

        return $task;
    }

    public function processBatch(AiEnrichmentBatch $batch): void
    {
        $this->seenBatchIdentities = [];
        $this->clearPreviousRun($batch);

        $batch->update([
            'status' => 'processing',
            'started_at' => now(),
            'finished_at' => null,
            'total_items' => 0,
            'drafts_created' => 0,
            'profiles_updated' => 0,
            'duplicates_found' => 0,
            'errors_count' => 0,
            'needs_review_count' => 0,
            'sources_found' => 0,
        ]);

        $items = $this->parseBatchItems($batch);

        $counters = [
            'total_items' => count($items),
            'drafts_created' => 0,
            'profiles_updated' => 0,
            'duplicates_found' => 0,
            'errors_count' => 0,
            'needs_review_count' => 0,
            'sources_found' => 0,
        ];
        $processedItems = 0;

        $batch->update([
            'total_items' => $counters['total_items'],
        ]);

        foreach ($items as $index => $item) {
            try {
                $result = $this->processItem($batch, $item, $index + 1);

                foreach ($result as $key => $value) {
                    $counters[$key] = ($counters[$key] ?? 0) + $value;
                }
            } catch (\Throwable $exception) {
                $counters['errors_count']++;

                AiEnrichmentTask::create([
                    'batch_id' => $batch->id,
                    'row_number' => $index + 1,
                    'raw_name' => $item['name'] ?: 'Невідомий профіль',
                    'raw_city' => $item['city'] ?? null,
                    'raw_phone' => $item['phone'] ?? null,
                    'raw_website' => $item['website'] ?? null,
                    'raw_source_url' => $item['source_url'] ?? null,
                    'raw_payload' => $item,
                    'status' => 'error',
                    'error_message' => $exception->getMessage(),
                    'processed_at' => now(),
                ]);
            }

            $processedItems++;
            $batch->update([
                'drafts_created' => $counters['drafts_created'],
                'profiles_updated' => $counters['profiles_updated'],
                'duplicates_found' => $counters['duplicates_found'],
                'errors_count' => $counters['errors_count'],
                'needs_review_count' => $counters['needs_review_count'],
                'sources_found' => $counters['sources_found'],
                'options' => array_merge($batch->options ?? [], [
                    'progress' => [
                        'processed_items' => $processedItems,
                        'total_items' => $counters['total_items'],
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
        }

        $batch->update([
            ...$counters,
            'status' => $counters['errors_count'] > 0 ? 'completed_with_errors' : 'completed',
            'finished_at' => now(),
        ]);
    }

    /**
     * Prepare a batch for queue processing without doing slow network work in the coordinator job.
     *
     * @return \Illuminate\Support\Collection<int, AiEnrichmentTask>
     */
    public function prepareBatchForQueue(AiEnrichmentBatch $batch)
    {
        $this->clearPreviousRun($batch);

        $items = $this->parseBatchItems($batch);

        $batch->update([
            'status' => 'processing',
            'started_at' => now(),
            'finished_at' => null,
            'total_items' => count($items),
            'drafts_created' => 0,
            'profiles_updated' => 0,
            'duplicates_found' => 0,
            'errors_count' => 0,
            'needs_review_count' => 0,
            'sources_found' => 0,
            'options' => array_merge($batch->options ?? [], [
                'progress' => [
                    'processed_items' => 0,
                    'total_items' => count($items),
                    'percent' => count($items) > 0 ? 0 : 100,
                    'current' => null,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $tasks = collect();

        foreach ($items as $index => $item) {
            $category = $this->resolveCategory($batch, $item);
            $city = filled($item['city'] ?? null) ? (string) $item['city'] : $batch->default_city;
            $name = trim((string) ($item['name'] ?? ''));

            $tasks->push(AiEnrichmentTask::create([
                'batch_id' => $batch->id,
                'profile_id' => filled($item['profile_id'] ?? null) ? (int) $item['profile_id'] : null,
                'category_id' => $category?->id,
                'row_number' => $index + 1,
                'raw_name' => $name ?: 'Невідомий профіль',
                'raw_city' => $city,
                'raw_phone' => $item['phone'] ?? null,
                'raw_website' => $item['website'] ?? null,
                'raw_source_url' => $item['source_url'] ?? null,
                'raw_payload' => $item,
                'status' => filled($item['error'] ?? null) ? 'error' : 'pending',
                'error_message' => $item['error'] ?? null,
                'processed_at' => filled($item['error'] ?? null) ? now() : null,
            ]));
        }

        $this->refreshBatchProgress($batch);

        return $tasks->filter(fn (AiEnrichmentTask $task) => $task->status === 'pending')->values();
    }

    public function processPreparedTask(AiEnrichmentTask $task): void
    {
        $task->loadMissing('batch');
        $batch = $task->batch;

        if (! $batch || in_array($batch->status, ['archived', 'cancelled', 'completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        $task->update(['status' => 'processing']);
        $this->updateBatchCurrentTask($batch, $task);

        try {
            $counters = $this->processPreparedTaskItem($batch, $task);
            $this->applyBatchCounterDelta($batch, $counters);
        } catch (\Throwable $exception) {
            $task->update([
                'status' => 'error',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ]);
            $this->applyBatchCounterDelta($batch, ['errors_count' => 1]);
        }

        $this->refreshBatchProgress($batch);
    }

    public function refreshBatchProgress(AiEnrichmentBatch $batch): void
    {
        $batch->refresh();

        if (in_array($batch->status, ['archived', 'cancelled'], true)) {
            return;
        }

        $total = max(0, (int) $batch->total_items);
        $processed = $batch->tasks()
            ->whereIn('status', ['data_found', 'needs_review', 'possible_duplicate', 'completed', 'error', 'rejected'])
            ->count();
        $processing = $batch->tasks()->where('status', 'processing')->orderBy('row_number')->first();
        $pending = $batch->tasks()->where('status', 'pending')->count();
        $errors = $batch->tasks()->where('status', 'error')->count();

        $progress = [
            'processed_items' => $processed,
            'total_items' => $total,
            'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 100,
            'pending_items' => $pending,
            'current' => $processing ? [
                'row_number' => (int) $processing->row_number,
                'name' => (string) $processing->raw_name,
            ] : null,
            'updated_at' => now()->toIso8601String(),
        ];

        $updates = [
            'errors_count' => $errors,
            'options' => array_merge($batch->options ?? [], ['progress' => $progress]),
        ];

        if ($total === 0) {
            $updates['status'] = 'completed';
            $updates['finished_at'] = now();
        } elseif ($processed >= $total) {
            $updates['status'] = $errors > 0 ? 'completed_with_errors' : 'completed';
            $updates['finished_at'] = now();
        } elseif ($batch->status === 'queued') {
            $updates['status'] = 'processing';
        }

        $batch->update($updates);
    }

    private function updateBatchCurrentTask(AiEnrichmentBatch $batch, AiEnrichmentTask $task): void
    {
        $options = $batch->options ?? [];
        $progress = (array) ($options['progress'] ?? []);
        $progress['current'] = [
            'row_number' => (int) $task->row_number,
            'name' => (string) $task->raw_name,
        ];
        $progress['pending_items'] = max(0, (int) ($progress['pending_items'] ?? 0) - 1);
        $progress['updated_at'] = now()->toIso8601String();
        $options['progress'] = $progress;

        $batch->update(['status' => 'processing', 'options' => $options]);
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function applyBatchCounterDelta(AiEnrichmentBatch $batch, array $counters): void
    {
        $allowed = [
            'drafts_created',
            'profiles_updated',
            'duplicates_found',
            'errors_count',
            'needs_review_count',
            'sources_found',
        ];

        foreach ($allowed as $key) {
            $value = (int) ($counters[$key] ?? 0);
            if ($value !== 0) {
                $batch->increment($key, $value);
            }
        }
    }

    private function clearPreviousRun(AiEnrichmentBatch $batch): void
    {
        $taskIds = $batch->tasks()->pluck('id');

        if ($taskIds->isEmpty()) {
            return;
        }

        ProfileDataSource::query()
            ->whereIn('ai_enrichment_task_id', $taskIds)
            ->delete();

        ExternalProfileMention::query()
            ->whereIn('ai_enrichment_task_id', $taskIds)
            ->delete();

        AiEnrichmentTask::query()
            ->whereKey($taskIds)
            ->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseBatchItems(AiEnrichmentBatch $batch): array
    {
        $items = [];

        if (filled(Arr::get($batch->options ?? [], 'profile_ids'))) {
            $items = array_merge($items, $this->parseSelectedProfiles($batch));
        }

        if ($batch->source_type === 'existing_profiles') {
            $items = array_merge($items, $this->parseExistingProfiles($batch));
        }

        if (filled($batch->input_text)) {
            $items = array_merge($items, $this->parseTextInput((string) $batch->input_text));
        }

        if (filled($batch->file_path) && Storage::disk('local')->exists((string) $batch->file_path)) {
            $items = array_merge($items, $this->parseFile((string) $batch->file_path));
        }

        return array_values(array_filter($items, fn (array $item) => filled($item['name'] ?? null)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * Явно вибрані профілі (bulk-дія у списку профілів): без фільтра "неповноти".
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseSelectedProfiles(AiEnrichmentBatch $batch): array
    {
        $profileIds = array_values(array_filter(array_map(
            'intval',
            (array) Arr::get($batch->options ?? [], 'profile_ids', [])
        )));

        if ($profileIds === []) {
            return [];
        }

        return Profile::query()
            ->whereKey($profileIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Profile $profile) => [
                'profile_id' => $profile->id,
                'name' => $profile->name,
                'city' => $profile->city,
                'phone' => $profile->phone,
                'website' => $profile->website,
                'email' => $profile->email,
                'address' => $profile->address,
                'source_url' => ProfileDataSource::query()
                    ->where('profile_id', $profile->id)
                    ->whereNotNull('url')
                    ->orderByDesc('id')
                    ->value('url'),
            ])
            ->all();
    }

    private function parseExistingProfiles(AiEnrichmentBatch $batch): array
    {
        return Profile::query()
            ->when($batch->default_category_id, fn ($query) => $query->whereHas(
                'categories',
                fn ($categoryQuery) => $categoryQuery->whereKey($batch->default_category_id)
            ))
            ->where(function ($query): void {
                $query
                    ->whereNull('website')
                    ->orWhereNull('phone')
                    ->orWhereNull('address')
                    ->orWhereNull('city')
                    ->orWhereNull('short_description')
                    ->orWhereNull('seo_title')
                    ->orWhereNull('ai_enrichment_status');
            })
            ->orderBy('id')
            ->limit(250)
            ->get()
            ->map(fn (Profile $profile) => [
                'profile_id' => $profile->id,
                'name' => $profile->name,
                'city' => $profile->city,
                'phone' => $profile->phone,
                'website' => $profile->website,
                'email' => $profile->email,
                'address' => $profile->address,
                'source_url' => null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTextInput(string $input): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($input)) ?: [];

        if ($lines === []) {
            return [];
        }

        $firstLine = trim((string) $lines[0]);
        $looksLikeCsv = str_contains($firstLine, ',') || str_contains($firstLine, ';') || str_contains($firstLine, "\t");

        if ($looksLikeCsv) {
            return $this->parseCsvString(implode("\n", $lines));
        }

        return collect($lines)
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(fn (string $line) => $this->parsePlainLine($line))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseFile(string $path): array
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['csv', 'txt'], true)) {
            return [[
                'name' => 'Файл не оброблено',
                'error' => 'Поки що стабільно підтримується CSV/TXT. Excel-парсер підключимо окремим пакетом.',
                'source_url' => null,
            ]];
        }

        return $this->parseCsvString(Storage::disk('local')->get($path));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvString(string $contents): array
    {
        $rows = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($contents)) ?: []));

        if ($rows === []) {
            return [];
        }

        $delimiter = str_contains($rows[0], ';') ? ';' : (str_contains($rows[0], "\t") ? "\t" : ',');
        $firstRow = str_getcsv((string) $rows[0], $delimiter);
        $headerLooksValid = $this->looksLikeStructuredHeader($firstRow);

        if (! $headerLooksValid) {
            return collect($rows)
                ->map(fn (string $row): array => $this->parseUnstructuredDelimitedLine($row, $delimiter))
                ->filter(fn (array $payload) => filled($payload['name'] ?? null))
                ->values()
                ->all();
        }

        $header = str_getcsv((string) array_shift($rows), $delimiter);
        $normalizedHeader = array_map(fn ($value) => $this->normalizeHeader((string) $value), $header);

        return collect($rows)
            ->map(function (string $row) use ($delimiter, $normalizedHeader): array {
                $values = str_getcsv($row, $delimiter);
                $payload = [];

                foreach ($normalizedHeader as $index => $key) {
                    if ($key === '') {
                        continue;
                    }

                    $payload[$key] = trim((string) ($values[$index] ?? ''));
                }

                return $payload;
            })
            ->filter(fn (array $payload) => filled($payload['name'] ?? null))
            ->values()
            ->all();
    }

    private function parseUnstructuredDelimitedLine(string $line, string $delimiter): array
    {
        $parts = array_map(
            fn (string $value): string => trim($value),
            str_getcsv($line, $delimiter)
        );
        $parts = array_values(array_filter($parts, fn (string $value): bool => $value !== ''));

        $name = trim((string) ($parts[0] ?? ''));

        return [
            'name' => $name,
            'city' => '',
            'category' => '',
            'phone' => '',
            'website' => '',
            'email' => '',
            'address' => '',
            'source_url' => '',
            'raw_text' => trim($line),
        ];
    }

    /**
     * @param  array<int, string>  $row
     */
    private function looksLikeStructuredHeader(array $row): bool
    {
        if ($row === []) {
            return false;
        }

        $normalized = array_map(fn (string $value) => $this->normalizeHeader($value), $row);
        $known = ['name', 'city', 'category', 'phone', 'website', 'email', 'address', 'source_url'];

        foreach ($normalized as $field) {
            if (in_array($field, $known, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHeader(string $header): string
    {
        $header = Str::lower(trim($header));

        return match ($header) {
            'name', 'title', 'назва', 'профіль', 'назва профілю', 'компанія' => 'name',
            'city', 'місто', 'город' => 'city',
            'category', 'категорія' => 'category',
            'phone', 'телефон' => 'phone',
            'website', 'site', 'сайт', 'url' => 'website',
            'email', 'пошта' => 'email',
            'address', 'адреса' => 'address',
            'source', 'source_url', 'джерело', 'посилання на джерело' => 'source_url',
            default => Str::snake($header),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePlainLine(string $line): array
    {
        $city = null;
        $cityCandidates = ['Київ', 'Львів', 'Одеса', 'Харків', 'Дніпро', 'Вінниця', 'Тернопіль', 'Полтава'];

        foreach ($cityCandidates as $candidate) {
            if (Str::contains(Str::lower($line), Str::lower($candidate))) {
                $city = $candidate;
                break;
            }
        }

        $name = $city ? trim(preg_replace('/\b' . preg_quote($city, '/') . '\b/iu', '', $line) ?: $line) : $line;

        return [
            'name' => $name,
            'city' => $city,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processItem(AiEnrichmentBatch $batch, array $item, int $rowNumber): array
    {
        $itemError = $item['error'] ?? null;
        $existingProfile = filled($item['profile_id'] ?? null) ? Profile::find($item['profile_id']) : null;
        $category = $this->resolveCategory($batch, $item);
        $city = filled($item['city'] ?? null) ? (string) $item['city'] : $batch->default_city;
        $name = trim((string) ($item['name'] ?? ''));

        $task = AiEnrichmentTask::create([
            'batch_id' => $batch->id,
            'profile_id' => $existingProfile?->id,
            'category_id' => $category?->id,
            'row_number' => $rowNumber,
            'raw_name' => $name ?: 'Невідомий профіль',
            'raw_city' => $city,
            'raw_phone' => $item['phone'] ?? null,
            'raw_website' => $item['website'] ?? null,
            'raw_source_url' => $item['source_url'] ?? null,
            'raw_payload' => $item,
            'status' => $itemError ? 'error' : 'processing',
            'error_message' => $itemError,
        ]);

        if ($itemError) {
            $task->update(['processed_at' => now()]);

            return ['errors_count' => 1];
        }

        $batchDuplicates = $this->findInBatchDuplicateCandidates($name, $city, $item, $rowNumber);
        if ($batchDuplicates !== []) {
            $task->update([
                'status' => 'possible_duplicate',
                'duplicate_candidates' => $batchDuplicates,
                'confidence_score' => 30,
                'processed_at' => now(),
            ]);

            return [
                'duplicates_found' => 1,
                'needs_review_count' => 1,
            ];
        }

        $duplicates = $existingProfile
            ? []
            : $this->findDuplicateCandidates($name, $city, $category, $item);

        if ($duplicates !== []) {
            $task->update([
                'status' => 'possible_duplicate',
                'duplicate_candidates' => $duplicates,
                'confidence_score' => 35,
                'processed_at' => now(),
            ]);

            return [
                'duplicates_found' => 1,
                'needs_review_count' => 1,
            ];
        }

        $suggested = $this->provider->enrich($batch, $item, $category, $city);
        if ($this->shouldSkipSuggestedProfile($suggested)) {
            $this->markTaskSkipped($task, $suggested);

            return [
                'needs_review_count' => 1,
            ];
        }

        $suggested = $this->sanitizeSuggestedWebsite($suggested, $item);
        $suggested = $this->enrichSuggestedContactsFromOfficialWebsite($suggested, $item);
        $suggested = $this->sanitizeGoogleMapsMentions($suggested);
        if ($this->hasInsufficientEnrichmentData($suggested)) {
            $this->markTaskNoData($task, $suggested);

            return [
                'needs_review_count' => 1,
            ];
        }
        $suggested = $this->enrichSuggestedLogo($suggested);
        $resolvedCategory = $this->resolveCategoryFromSuggested($batch, $item, $suggested, $category);
        $suggested['services'] = $this->expandServiceCandidates($suggested, $resolvedCategory);
        $confidence = $this->calculateConfidence($suggested);

        if (! $existingProfile) {
            $existingProfile = $this->resolveExistingProfileBySuggested($suggested, $city, $resolvedCategory, $name);
        }

        $profile = $existingProfile;
        $profileWasCreated = false;
        $publishProfiles = (bool) Arr::get($batch->options ?? [], 'publish_profiles', false);
        if (! $profile) {
            $profile = Profile::create([
                'name' => $suggested['name'],
                'slug' => $this->uniqueProfileSlug($suggested['name']),
                'type' => 'company',
                'short_description' => $suggested['short_description'] ?? null,
                'description' => $suggested['description'] ?? null,
                'website' => $suggested['website'] ?? null,
                'email' => $suggested['email'] ?? null,
                'phone' => $suggested['phone'] ?? null,
                'address' => $suggested['address'] ?? null,
                'city' => $suggested['city'] ?? null,
                'logo_url' => $suggested['logo_url'] ?? null,
                'status' => $publishProfiles ? 'active' : 'draft',
                'is_published' => $publishProfiles,
                'show_in_catalog' => $publishProfiles,
                'seo_title' => $suggested['seo_title'] ?? null,
                'seo_description' => $suggested['seo_description'] ?? null,
                'ai_confidence_score' => $confidence,
                'ai_enrichment_status' => $confidence >= 70 ? 'ready_for_review' : 'needs_review',
                'ai_enriched_at' => now(),
                'ai_suggested_data' => $suggested,
            ]);
            $profileWasCreated = true;
        }

        if (! $profileWasCreated) {
            // Контакти, підтверджені офіційним сайтом, актуальніші за реєстрові — перекривають існуючі.
            $officialContactFields = collect((array) ($suggested['sources'] ?? []))
                ->filter(fn ($source) => is_array($source) && (string) ($source['source_type'] ?? '') === 'official_website_contacts')
                ->flatMap(fn (array $source) => (array) ($source['found_fields'] ?? []))
                ->all();

            $profile->fill([
                'short_description' => $profile->short_description ?: ($suggested['short_description'] ?? null),
                'description' => $profile->description ?: ($suggested['description'] ?? null),
                'website' => $profile->website ?: ($suggested['website'] ?? null),
                'email' => in_array('email', $officialContactFields, true) && filled($suggested['email'] ?? null)
                    ? $suggested['email']
                    : ($profile->email ?: ($suggested['email'] ?? null)),
                'phone' => in_array('phone', $officialContactFields, true) && filled($suggested['phone'] ?? null)
                    ? $suggested['phone']
                    : ($profile->phone ?: ($suggested['phone'] ?? null)),
                'address' => $profile->address ?: ($suggested['address'] ?? null),
                'city' => $profile->city ?: ($suggested['city'] ?? null),
                'logo_url' => $profile->logo_url ?: ($suggested['logo_url'] ?? null),
                'seo_title' => $profile->seo_title ?: ($suggested['seo_title'] ?? null),
                'seo_description' => $profile->seo_description ?: ($suggested['seo_description'] ?? null),
                'ai_confidence_score' => $confidence,
                'ai_enrichment_status' => $confidence >= 70 ? 'ready_for_review' : 'needs_review',
                'ai_enriched_at' => now(),
                'ai_suggested_data' => $suggested,
            ])->save();
        }

        if (! $profileWasCreated) {
            $suggested = $this->reconcileSuggestedWithExistingProfile($suggested, $profile);
        }

        if ($resolvedCategory) {
            $rootCategoryId = CategoryHierarchy::resolveRootCategoryId((int) $resolvedCategory->id);
            $subcategoryId = $resolvedCategory->parent_id ? (int) $resolvedCategory->id : null;

            CategoryHierarchy::syncProfileCategories($profile, $rootCategoryId, $subcategoryId);

            $serviceCategory = ($rootCategoryId ? Category::find($rootCategoryId) : null) ?: $resolvedCategory;
            $serviceIds = $this->resolveServiceIds($serviceCategory, $suggested['services'] ?? []);
            $profile->services()->sync($serviceIds);
        }

        if ((bool) Arr::get($batch->options ?? [], 'find_reviews', true)
            && $this->isGoogleMapsApiEnabled()
            && $this->shouldUseGoogleReviewsForResolvedProfile($profile)
            && $this->shouldCollectResolvedGoogleMapsMentions($suggested, $profile)) {
            $suggested = $this->mergeAdditionalMentions(
                $suggested,
                $this->collectGoogleMapsMentionsForResolvedProfile($profile),
            );
        }

        $suggested = $this->sanitizeGoogleMapsMentions($suggested, $profile);
        $profile->forceFill(['ai_suggested_data' => $suggested])->save();

        $task->update([
            'category_id' => $resolvedCategory?->id,
            'profile_id' => $profile->id,
            'status' => $confidence >= 70 ? 'data_found' : 'needs_review',
            'confidence_score' => $confidence,
            'suggested_data' => $suggested,
            'processed_at' => now(),
        ]);

        $sourcesCount = $this->createSources($profile, $task, $item, $suggested);

        $mentionsCreated = 0;
        if ((bool) Arr::get($batch->options ?? [], 'find_reviews', true)) {
            $this->purgeProfileAiDraftArtifacts($profile);
            $mentionsCreated = $this->createExternalMentions($profile, $task, $suggested);
        }

        if (! $this->hasRequiredEvidenceForTask($profile, $item, $suggested, $mentionsCreated)) {
            $this->markTaskNoData($task, $suggested, $profile);

            return [
                'drafts_created' => $profileWasCreated ? 1 : 0,
                'profiles_updated' => $profileWasCreated ? 0 : 1,
                'needs_review_count' => 1,
                'sources_found' => $sourcesCount,
            ];
        }

        return [
            'drafts_created' => $profileWasCreated ? 1 : 0,
            'profiles_updated' => $profileWasCreated ? 0 : 1,
            'needs_review_count' => 1,
            'sources_found' => $sourcesCount,
        ];
    }

    /**
     * Existing DOVIRA profile identity is stronger than a fresh AI guess.
     *
     * @param  array<string, mixed>  $suggested
     * @return array<string, mixed>
     */
    private function reconcileSuggestedWithExistingProfile(array $suggested, Profile $profile): array
    {
        $warnings = array_values((array) ($suggested['warnings'] ?? []));

        $trustedFields = [
            'name' => $profile->name,
            'phone' => $profile->phone,
            'website' => $profile->website,
            'city' => $profile->city,
            'address' => $profile->address,
            'email' => $profile->email,
        ];

        foreach ($trustedFields as $field => $trustedValue) {
            $trustedValue = trim((string) $trustedValue);
            if ($trustedValue === '') {
                continue;
            }

            $currentValue = trim((string) ($suggested[$field] ?? ''));
            if ($currentValue !== '' && $currentValue !== $trustedValue) {
                $warnings[] = "AI повернув {$field}='{$currentValue}', але існуючий профіль має '{$trustedValue}'. Для пошуку відгуків використано дані профілю.";
            }

            $suggested[$field] = $trustedValue;
        }

        $suggested['warnings'] = array_values(array_unique(array_filter($warnings)));

        return $suggested;
    }

    private function shouldSkipSuggestedProfile(array $suggested): bool
    {
        if ((bool) ($suggested['skip_profile'] ?? false)) {
            return true;
        }

        $reason = Str::lower(trim((string) ($suggested['inactive_reason'] ?? '')));
        if ($reason === '') {
            return false;
        }

        return Str::contains($reason, [
            'припинив',
            'припинено',
            'зупинено',
            'suspended',
            'terminated',
            'inactive',
        ]);
    }

    /**
     * @param  array<string, mixed>  $suggested
     */
    private function markTaskSkipped(AiEnrichmentTask $task, array $suggested): void
    {
        $reason = trim((string) ($suggested['inactive_reason'] ?? 'Профіль не підходить для імпорту за даними джерел.'));

        $task->update([
            'status' => 'rejected',
            'confidence_score' => 0,
            'suggested_data' => $suggested,
            'error_message' => $reason,
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $suggested
     */
    private function markTaskNoData(AiEnrichmentTask $task, array $suggested, ?Profile $profile = null): void
    {
        if ($profile) {
            $profile->forceFill([
                'ai_enrichment_status' => 'rejected',
                'ai_confidence_score' => 0,
                'ai_enriched_at' => now(),
                'ai_suggested_data' => $suggested,
                'status' => 'draft',
                'is_published' => false,
                'show_in_catalog' => false,
            ])->save();
        }

        $task->update([
            'status' => 'rejected',
            'confidence_score' => 0,
            'suggested_data' => $suggested,
            'error_message' => 'Недостатньо публічних даних: не знайдено контактів, джерел або релевантних відгуків.',
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $suggested
     */
    private function hasInsufficientEnrichmentData(array $suggested): bool
    {
        $hasContacts = filled($suggested['phone'] ?? null)
            || filled($suggested['website'] ?? null)
            || filled($suggested['email'] ?? null)
            || filled($suggested['address'] ?? null);

        $hasSources = collect((array) ($suggested['sources'] ?? []))
            ->contains(function ($source): bool {
                if (! is_array($source)) {
                    return false;
                }

                return filled($source['url'] ?? null)
                    || filled($source['title'] ?? null)
                    || collect((array) ($source['found_fields'] ?? []))->filter()->isNotEmpty();
            });

        $hasReviewSignals = collect((array) ($suggested['external_mentions'] ?? []))
            ->contains(function ($mention): bool {
                if (! is_array($mention)) {
                    return false;
                }

                return filled($mention['review_text'] ?? null)
                    || is_numeric($mention['review_rating'] ?? null)
                    || (
                        is_numeric($mention['external_reviews_count'] ?? null)
                        && (int) $mention['external_reviews_count'] > 0
                    );
            });

        return ! $hasContacts && ! $hasSources && ! $hasReviewSignals;
    }

    private function hasRequiredEvidenceForTask(Profile $profile, array $item, array $suggested, int $mentionsCreated): bool
    {
        $hasContacts = filled($profile->phone)
            || filled($profile->website)
            || filled($profile->email)
            || filled($profile->address);

        $hasRealSources = filled($item['source_url'] ?? null)
            || collect((array) ($suggested['sources'] ?? []))
                ->contains(fn ($source): bool => is_array($source) && filled($source['url'] ?? null));

        $hasReviews = $mentionsCreated > 0;

        return $hasContacts || $hasRealSources || $hasReviews;
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @param  array<int, array<string, mixed>>  $mentions
     * @return array<string, mixed>
     */
    private function mergeAdditionalMentions(array $suggested, array $mentions): array
    {
        if ($mentions === []) {
            return $suggested;
        }

        $merged = [];
        foreach ([...((array) ($suggested['external_mentions'] ?? [])), ...$mentions] as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $identity = sha1(implode('|', [
                Str::lower(trim((string) ($mention['url'] ?? ''))),
                Str::lower(trim((string) ($mention['source_type'] ?? ''))),
                Str::lower(trim((string) ($mention['review_author'] ?? ''))),
                (string) ($mention['review_rating'] ?? ''),
                (string) ($mention['review_date'] ?? ''),
                Str::lower(trim((string) ($mention['review_text'] ?? ''))),
            ]));

            $merged[$identity] = $mention;
        }

        $suggested['external_mentions'] = array_values($merged);

        return $suggested;
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<string, mixed>
     */
    private function sanitizeGoogleMapsMentions(array $suggested, ?Profile $profile = null): array
    {
        $mentions = [];

        foreach ((array) ($suggested['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            if ($this->shouldRejectGoogleMapsMention($mention, $profile)) {
                continue;
            }

            $mentions[] = $mention;
        }

        $suggested['external_mentions'] = $mentions;

        return $suggested;
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function shouldRejectGoogleMapsMention(array $mention, ?Profile $profile = null): bool
    {
        if (! $this->isGoogleMapsMention($mention)) {
            return false;
        }

        $url = Str::lower(trim((string) ($mention['url'] ?? '')));
        if ($url !== '' && Str::contains($url, ['abc123', 'example', 'placeholder', 'goo.gl/maps/abc'])) {
            return true;
        }

        $mentionPhone = $this->normalizePhoneDigits((string) ($mention['source_phone'] ?? data_get($mention, 'raw_payload.source_phone', '')));
        $mentionDomain = $this->extractDomain((string) ($mention['source_website'] ?? data_get($mention, 'raw_payload.source_website', '')));

        if ($profile instanceof Profile) {
            $profilePhone = $this->normalizePhoneDigits((string) ($profile->phone ?? ''));
            $profileDomain = $this->extractDomain((string) ($profile->website ?? ''));

            $samePhone = $profilePhone !== null
                && $mentionPhone !== null
                && (str_contains($profilePhone, $mentionPhone) || str_contains($mentionPhone, $profilePhone));
            $sameDomain = $profileDomain !== ''
                && $mentionDomain !== ''
                && ($profileDomain === $mentionDomain
                    || str_ends_with($profileDomain, '.' . $mentionDomain)
                    || str_ends_with($mentionDomain, '.' . $profileDomain));

            // Once the profile has an official phone or website, Google Maps is trusted only by that identity signal.
            if (($profilePhone !== null || $profileDomain !== '') && ! $samePhone && ! $sameDomain) {
                return true;
            }
        }

        if ($mentionPhone !== null || $mentionDomain !== '') {
            return false;
        }

        $hasReview = filled($mention['review_text'] ?? null) || is_numeric($mention['review_rating'] ?? null);
        $hasAggregate = is_numeric($mention['external_rating'] ?? null) || is_numeric($mention['external_reviews_count'] ?? null);

        return ! $hasReview && ! $hasAggregate;
    }

    /**
     * @return array<string, int>
     */
    private function processPreparedTaskItem(AiEnrichmentBatch $batch, AiEnrichmentTask $task): array
    {
        $item = is_array($task->raw_payload) ? $task->raw_payload : [];
        $itemError = $item['error'] ?? $task->error_message;
        $existingProfile = filled($task->profile_id) ? Profile::find($task->profile_id) : null;
        $category = $task->category_id ? Category::find($task->category_id) : $this->resolveCategory($batch, $item);
        $city = filled($task->raw_city) ? (string) $task->raw_city : $batch->default_city;
        $name = trim((string) ($task->raw_name ?: ($item['name'] ?? '')));

        if ($itemError) {
            $task->update([
                'status' => 'error',
                'error_message' => (string) $itemError,
                'processed_at' => now(),
            ]);

            return ['errors_count' => 1];
        }

        $batchDuplicates = $this->findPreparedBatchDuplicateCandidates($batch, $name, $city, $item, (int) $task->row_number, (int) $task->id);
        if ($batchDuplicates !== []) {
            $task->update([
                'status' => 'possible_duplicate',
                'duplicate_candidates' => $batchDuplicates,
                'confidence_score' => 30,
                'processed_at' => now(),
            ]);

            return [
                'duplicates_found' => 1,
                'needs_review_count' => 1,
            ];
        }

        $duplicates = $existingProfile
            ? []
            : $this->findDuplicateCandidates($name, $city, $category, $item);

        if ($duplicates !== []) {
            $task->update([
                'status' => 'possible_duplicate',
                'duplicate_candidates' => $duplicates,
                'confidence_score' => 35,
                'processed_at' => now(),
            ]);

            return [
                'duplicates_found' => 1,
                'needs_review_count' => 1,
            ];
        }

        $suggested = $this->provider->enrich($batch, $item, $category, $city);
        if ($this->shouldSkipSuggestedProfile($suggested)) {
            $this->markTaskSkipped($task, $suggested);

            return [
                'needs_review_count' => 1,
            ];
        }

        $suggested = $this->sanitizeSuggestedWebsite($suggested, $item);
        $suggested = $this->enrichSuggestedContactsFromOfficialWebsite($suggested, $item);
        $suggested = $this->sanitizeGoogleMapsMentions($suggested);
        if ($this->hasInsufficientEnrichmentData($suggested)) {
            $this->markTaskNoData($task, $suggested);

            return [
                'needs_review_count' => 1,
            ];
        }
        $suggested = $this->enrichSuggestedLogo($suggested);
        $resolvedCategory = $this->resolveCategoryFromSuggested($batch, $item, $suggested, $category);
        $suggested['services'] = $this->expandServiceCandidates($suggested, $resolvedCategory);
        $confidence = $this->calculateConfidence($suggested);

        if (! $existingProfile) {
            $existingProfile = $this->resolveExistingProfileBySuggested($suggested, $city, $resolvedCategory, $name);
        }

        $profile = $existingProfile;
        $profileWasCreated = false;
        $publishProfiles = (bool) Arr::get($batch->options ?? [], 'publish_profiles', false);
        if (! $profile) {
            $profile = Profile::create([
                'name' => $suggested['name'],
                'slug' => $this->uniqueProfileSlug($suggested['name']),
                'type' => 'company',
                'short_description' => $suggested['short_description'] ?? null,
                'description' => $suggested['description'] ?? null,
                'website' => $suggested['website'] ?? null,
                'email' => $suggested['email'] ?? null,
                'phone' => $suggested['phone'] ?? null,
                'address' => $suggested['address'] ?? null,
                'city' => $suggested['city'] ?? null,
                'logo_url' => $suggested['logo_url'] ?? null,
                'status' => $publishProfiles ? 'active' : 'draft',
                'is_published' => $publishProfiles,
                'show_in_catalog' => $publishProfiles,
                'seo_title' => $suggested['seo_title'] ?? null,
                'seo_description' => $suggested['seo_description'] ?? null,
                'ai_confidence_score' => $confidence,
                'ai_enrichment_status' => $confidence >= 70 ? 'ready_for_review' : 'needs_review',
                'ai_enriched_at' => now(),
                'ai_suggested_data' => $suggested,
            ]);
            $profileWasCreated = true;
        }

        if (! $profileWasCreated) {
            // Контакти, підтверджені офіційним сайтом, актуальніші за реєстрові — перекривають існуючі.
            $officialContactFields = collect((array) ($suggested['sources'] ?? []))
                ->filter(fn ($source) => is_array($source) && (string) ($source['source_type'] ?? '') === 'official_website_contacts')
                ->flatMap(fn (array $source) => (array) ($source['found_fields'] ?? []))
                ->all();

            $profile->fill([
                'short_description' => $profile->short_description ?: ($suggested['short_description'] ?? null),
                'description' => $profile->description ?: ($suggested['description'] ?? null),
                'website' => $profile->website ?: ($suggested['website'] ?? null),
                'email' => in_array('email', $officialContactFields, true) && filled($suggested['email'] ?? null)
                    ? $suggested['email']
                    : ($profile->email ?: ($suggested['email'] ?? null)),
                'phone' => in_array('phone', $officialContactFields, true) && filled($suggested['phone'] ?? null)
                    ? $suggested['phone']
                    : ($profile->phone ?: ($suggested['phone'] ?? null)),
                'address' => $profile->address ?: ($suggested['address'] ?? null),
                'city' => $profile->city ?: ($suggested['city'] ?? null),
                'logo_url' => $profile->logo_url ?: ($suggested['logo_url'] ?? null),
                'seo_title' => $profile->seo_title ?: ($suggested['seo_title'] ?? null),
                'seo_description' => $profile->seo_description ?: ($suggested['seo_description'] ?? null),
                'ai_confidence_score' => $confidence,
                'ai_enrichment_status' => $confidence >= 70 ? 'ready_for_review' : 'needs_review',
                'ai_enriched_at' => now(),
                'ai_suggested_data' => $suggested,
            ])->save();
        }

        if (! $profileWasCreated) {
            $suggested = $this->reconcileSuggestedWithExistingProfile($suggested, $profile);
        }

        if ($resolvedCategory) {
            $rootCategoryId = CategoryHierarchy::resolveRootCategoryId((int) $resolvedCategory->id);
            $subcategoryId = $resolvedCategory->parent_id ? (int) $resolvedCategory->id : null;

            CategoryHierarchy::syncProfileCategories($profile, $rootCategoryId, $subcategoryId);

            $serviceCategory = ($rootCategoryId ? Category::find($rootCategoryId) : null) ?: $resolvedCategory;
            $serviceIds = $this->resolveServiceIds($serviceCategory, $suggested['services'] ?? []);
            $profile->services()->sync($serviceIds);
        }

        if ((bool) Arr::get($batch->options ?? [], 'find_reviews', true)
            && $this->isGoogleMapsApiEnabled()
            && $this->shouldUseGoogleReviewsForResolvedProfile($profile)
            && $this->shouldCollectResolvedGoogleMapsMentions($suggested, $profile)) {
            $suggested = $this->mergeAdditionalMentions(
                $suggested,
                $this->collectGoogleMapsMentionsForResolvedProfile($profile),
            );
        }

        $suggested = $this->sanitizeGoogleMapsMentions($suggested, $profile);
        $profile->forceFill(['ai_suggested_data' => $suggested])->save();

        $task->update([
            'category_id' => $resolvedCategory?->id,
            'profile_id' => $profile->id,
            'status' => $confidence >= 70 ? 'data_found' : 'needs_review',
            'confidence_score' => $confidence,
            'suggested_data' => $suggested,
            'processed_at' => now(),
        ]);

        $sourcesCount = $this->createSources($profile, $task, $item, $suggested);

        $mentionsCreated = 0;
        if ((bool) Arr::get($batch->options ?? [], 'find_reviews', true)) {
            $this->purgeProfileAiDraftArtifacts($profile);
            $mentionsCreated = $this->createExternalMentions($profile, $task, $suggested);
        }

        if (! $this->hasRequiredEvidenceForTask($profile, $item, $suggested, $mentionsCreated)) {
            $this->markTaskNoData($task, $suggested, $profile);

            return [
                'drafts_created' => $profileWasCreated ? 1 : 0,
                'profiles_updated' => $profileWasCreated ? 0 : 1,
                'needs_review_count' => 1,
                'sources_found' => $sourcesCount,
            ];
        }

        return [
            'drafts_created' => $profileWasCreated ? 1 : 0,
            'profiles_updated' => $profileWasCreated ? 0 : 1,
            'needs_review_count' => 1,
            'sources_found' => $sourcesCount,
        ];
    }

    private function shouldCollectResolvedGoogleMapsMentions(array $suggested, Profile $profile): bool
    {
        $googleMentions = [];

        foreach ((array) ($suggested['external_mentions'] ?? []) as $mention) {
            if (is_array($mention) && $this->isGoogleMapsMention($mention)) {
                $googleMentions[] = $mention;
            }
        }

        if ($googleMentions === []) {
            return true;
        }

        $profilePhone = $this->normalizePhoneDigits((string) ($profile->phone ?? ''));
        if ($profilePhone === null) {
            return false;
        }

        foreach ($googleMentions as $mention) {
            $mentionPhone = $this->normalizePhoneDigits((string) (
                $mention['source_phone']
                ?? data_get($mention, 'raw_payload.source_phone')
                ?? ''
            ));

            if ($mentionPhone !== null && $mentionPhone === $profilePhone) {
                return false;
            }
        }

        return true;
    }

    private function shouldUseGoogleReviewsForResolvedProfile(Profile $profile): bool
    {
        // Google should only enrich review mentions after profile identity is established
        // by non-Google signals (website/phone/email from import or web sources).
        return filled($profile->website)
            || filled($profile->phone)
            || filled($profile->email);
    }

    private function isGoogleMapsApiEnabled(): bool
    {
        return (bool) config('ai_enrichment.google_maps.enabled', false)
            && filled(config('ai_enrichment.google_maps.api_key'));
    }

    private function resolveExistingProfileBySuggested(array $suggested, ?string $city, ?Category $category, ?string $rawName = null): ?Profile
    {
        $website = $this->normalizeWebsite((string) ($suggested['website'] ?? ''));
        $phoneDigits = $this->normalizePhoneDigits((string) ($suggested['phone'] ?? ''));
        $name = trim((string) ($suggested['name'] ?? ''));
        $matchName = trim((string) ($rawName ?: $name));
        $nameNormalized = $this->normalizeNameForDuplicate($name);
        $cityNormalized = Str::lower(trim((string) ($suggested['city'] ?? $city ?? '')));
        $priorityTokens = $this->extractPriorityTokens($name);

        // Hard match first: exact identity by website/phone must ignore category,
        // because the same profile can be classified differently across imports.
        if ($website !== null || $phoneDigits !== null) {
            $hard = Profile::query()
                ->when(
                    $website !== null || $phoneDigits !== null,
                    function ($q) use ($website, $phoneDigits): void {
                        $q->where(function ($inner) use ($website, $phoneDigits): void {
                            if ($website !== null) {
                                $inner->orWhere('website', 'like', '%' . $website . '%');
                            }
                            if ($phoneDigits !== null) {
                                $inner->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),' ',''),'-',''),'(',''),')','') LIKE ?", ['%' . $phoneDigits . '%']);
                            }
                        });
                    }
                )
                ->orderBy('id')
                ->get();

            foreach ($hard as $hardCandidate) {
                if ($this->areEntityKindsCompatible($matchName, (string) ($hardCandidate->name ?? ''))) {
                    return $hardCandidate;
                }
            }
        }

        $query = Profile::query()
            ->when($category, fn ($q) => $q->whereHas('categories', fn ($cq) => $cq->whereKey($category->id)))
            ->when(
                $website !== null || $phoneDigits !== null || $name !== '',
                function ($q) use ($website, $phoneDigits, $name): void {
                    $q->where(function ($inner) use ($website, $phoneDigits, $name): void {
                        if ($website !== null) {
                            $inner->orWhere('website', 'like', '%' . $website . '%');
                        }
                        if ($phoneDigits !== null) {
                            $inner->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),' ',''),'-',''),'(',''),')','') LIKE ?", ['%' . $phoneDigits . '%']);
                        }
                        if ($name !== '') {
                            $inner->orWhereRaw('lower(name) = ?', [Str::lower($name)]);
                        }
                    });
                }
            )
            ->limit(60)
            ->get();

        $best = null;
        $bestScore = 0;

        foreach ($query as $candidate) {
            if (! $this->areEntityKindsCompatible($matchName, (string) ($candidate->name ?? ''))) {
                continue;
            }

            $score = 0;
            $candidateWebsite = $this->normalizeWebsite((string) ($candidate->website ?? ''));
            $candidatePhoneDigits = $this->normalizePhoneDigits((string) ($candidate->phone ?? ''));
            $candidateNameNormalized = $this->normalizeNameForDuplicate((string) ($candidate->name ?? ''));
            $candidateCity = Str::lower(trim((string) ($candidate->city ?? '')));

            if ($website !== null && $candidateWebsite !== null) {
                if ($website === $candidateWebsite || str_contains($website, $candidateWebsite) || str_contains($candidateWebsite, $website)) {
                    $score += 70;
                }
            }

            if ($phoneDigits !== null && $candidatePhoneDigits !== null) {
                if (str_contains($phoneDigits, $candidatePhoneDigits) || str_contains($candidatePhoneDigits, $phoneDigits)) {
                    $score += 55;
                }
            }

            if ($nameNormalized !== '' && $candidateNameNormalized !== '') {
                similar_text($nameNormalized, $candidateNameNormalized, $percent);
                if ($percent >= 93.0) {
                    $score += 40;
                } elseif ($percent >= 88.0) {
                    $score += 25;
                }
            }

            if ($priorityTokens !== []) {
                $haystack = Str::lower((string) ($candidate->name ?? ''));
                $allMatched = true;
                foreach ($priorityTokens as $token) {
                    if (! $this->containsTokenFlexible($haystack, $token)) {
                        $allMatched = false;
                        break;
                    }
                }
                if ($allMatched) {
                    $score += 35;
                }
            }

            if ($cityNormalized !== '' && $candidateCity !== '' && $cityNormalized === $candidateCity) {
                $score += 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    private function resolveCategory(AiEnrichmentBatch $batch, array $item): ?Category
    {
        if (filled($item['category'] ?? null)) {
            $category = $this->findActiveCategoryByName((string) $item['category']);

            if ($category) {
                return $category;
            }
        }

        if (filled($batch->default_category_id)) {
            return Category::find($batch->default_category_id);
        }

        $textParts = [];
        foreach ($item as $value) {
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $textParts[] = $text;
                }
            }
        }

        $haystack = Str::lower(implode(' ', $textParts));

        $name = match (true) {
            Str::contains($haystack, [
                'адвокат', 'lawyer', 'юрист', 'юрид',
                'рада адвокатів', 'кдка', 'свідоцтво адвоката',
                'правнича допомога', 'правова допомога',
            ]) => 'Адвокати',
            Str::contains($haystack, ['нотаріус', 'notary']) => 'Нотаріуси',
            Str::contains($haystack, ['стомат', 'dental', 'dent', 'smile']) => 'Стоматологи',
            Str::contains($haystack, ['crypto', 'крипто', 'usdt', 'btc', 'обмін']) => 'Криптообмінники',
            Str::contains($haystack, ['blog', 'блог', 'інфлюенсер']) => 'Блогери',
            default => 'Компанії',
        };

        return $this->findActiveCategoryByName($name)
            ?? $this->findActiveCategoryByName('Юридичні послуги')
            ?? Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->first()
            ?? Category::query()->where('is_active', true)->orderBy('sort_order')->first();
    }

    private function resolveCategoryFromSuggested(
        AiEnrichmentBatch $batch,
        array $item,
        array $suggested,
        ?Category $fallback
    ): ?Category {
        $suggestedCategory = trim((string) ($suggested['category'] ?? ''));
        if ($suggestedCategory !== '') {
            $exact = $this->findActiveCategoryByName($suggestedCategory);
            if ($exact) {
                return $exact;
            }

            $bySlug = Category::query()
                ->where('is_active', true)
                ->where('slug', Str::slug($suggestedCategory))
                ->first();
            if ($bySlug) {
                return $bySlug;
            }
        }

        $derived = $this->resolveCategory($batch, array_merge($item, [
            'category' => $suggestedCategory !== '' ? $suggestedCategory : ($item['category'] ?? ''),
            'raw_text' => trim(implode(' ', array_filter([
                $item['raw_text'] ?? null,
                $suggested['short_description'] ?? null,
                $suggested['description'] ?? null,
                is_array($suggested['services'] ?? null) ? implode(' ', $suggested['services']) : null,
            ]))),
        ]));

        return $derived ?: $fallback;
    }

    private function findActiveCategoryByName(string $name): ?Category
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return Category::query()
            ->where('is_active', true)
            ->where(function ($query) use ($name): void {
                $query->whereRaw('lower(name) = ?', [Str::lower($name)])
                    ->orWhere('slug', Str::slug($name));
            })
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicateCandidates(string $name, ?string $city, ?Category $category, array $item): array
    {
        $phoneRaw = (string) ($item['phone'] ?? '');
        $phoneDigits = $this->normalizePhoneDigits($phoneRaw);
        $website = $this->normalizeWebsite((string) ($item['website'] ?? ''));
        $nameNormalized = $this->normalizeNameForDuplicate($name);
        $cityNormalized = Str::lower(trim((string) ($city ?? '')));

        $candidates = Profile::query()
            ->with('categories:id,name')
            ->where(function ($query) use ($name, $city, $phoneDigits, $phoneRaw, $website): void {
                $query->whereRaw('lower(name) = ?', [Str::lower($name)]);

                if (filled($city)) {
                    $query->where('city', $city);
                }

                if (filled($phoneDigits)) {
                    $query->orWhere('phone', 'like', '%' . ($phoneRaw ?: $phoneDigits) . '%');
                }

                if (filled($website)) {
                    $query->orWhere('website', 'like', '%' . $website . '%');
                }
            })
            ->when($category, fn ($query) => $query->whereHas('categories', fn ($q) => $q->whereKey($category->id)))
            ->limit(50)
            ->get();

        return $candidates
            ->filter(function (Profile $profile) use ($nameNormalized, $cityNormalized, $phoneDigits, $website, $name): bool {
                if (! $this->areEntityKindsCompatible($name, (string) $profile->name)) {
                    return false;
                }

                $profileNameNormalized = $this->normalizeNameForDuplicate((string) $profile->name);
                $profileCityNormalized = Str::lower(trim((string) ($profile->city ?? '')));
                $profilePhoneDigits = $this->normalizePhoneDigits((string) ($profile->phone ?? ''));
                $profileWebsite = $this->normalizeWebsite((string) ($profile->website ?? ''));

                $isSameWebsite = $website !== null && $profileWebsite !== null
                    && ($website === $profileWebsite || str_contains($website, $profileWebsite) || str_contains($profileWebsite, $website));

                $isSamePhone = $phoneDigits !== null && $profilePhoneDigits !== null
                    && (str_contains($phoneDigits, $profilePhoneDigits) || str_contains($profilePhoneDigits, $phoneDigits));

                if ($isSameWebsite || $isSamePhone) {
                    return true;
                }

                if ($nameNormalized === '' || $profileNameNormalized === '') {
                    return false;
                }

                similar_text($nameNormalized, $profileNameNormalized, $percent);
                $isVerySimilarName = $percent >= 90.0;

                if (! $isVerySimilarName) {
                    return false;
                }

                if ($cityNormalized === '' || $profileCityNormalized === '') {
                    return true;
                }

                return $cityNormalized === $profileCityNormalized;
            })
            ->take(5)
            ->map(fn (Profile $profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'city' => $profile->city,
                'website' => $profile->website,
            ])
            ->values()
            ->all();
    }

    private function areEntityKindsCompatible(string $leftName, string $rightName): bool
    {
        $leftKind = $this->detectEntityKind($leftName);
        $rightKind = $this->detectEntityKind($rightName);

        if ($leftKind === 'unknown' || $rightKind === 'unknown') {
            return true;
        }

        return $leftKind === $rightKind;
    }

    private function detectEntityKind(string $name): string
    {
        $name = trim(Str::lower($name));
        if ($name === '') {
            return 'unknown';
        }

        $companyNeedles = [
            'центр', 'компан', 'фірм', 'бюро', 'агентств', 'груп', 'тов', 'пп', 'llc', 'ltd',
            'legal', 'law firm', 'office', 'element', 'елемент', 'правов', 'help', 'допомог',
        ];

        foreach ($companyNeedles as $needle) {
            if (str_contains($name, $needle)) {
                return 'company';
            }
        }

        if (preg_match('/["«»]/u', $name)) {
            return 'company';
        }

        $parts = array_values(array_filter(preg_split('/\s+/u', $name) ?: []));
        if (count($parts) >= 2 && count($parts) <= 4) {
            $allWords = true;
            foreach ($parts as $part) {
                if (! preg_match('/^\p{L}+$/u', $part)) {
                    $allWords = false;
                    break;
                }
            }

            if ($allWords) {
                return 'person';
            }
        }

        return 'unknown';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findInBatchDuplicateCandidates(string $name, ?string $city, array $item, int $rowNumber): array
    {
        $signatures = $this->buildDuplicateSignatures($name, $city, $item);
        $matches = [];

        foreach ($signatures as $signature) {
            $existing = $this->seenBatchIdentities[$signature] ?? null;
            if (is_array($existing)) {
                $matches[] = [
                    'id' => null,
                    'name' => $existing['name'],
                    'city' => $existing['city'],
                    'website' => null,
                    'source' => 'batch_row_' . $existing['row_number'],
                ];
            }
        }

        if ($matches !== []) {
            return $matches;
        }

        foreach ($signatures as $signature) {
            $this->seenBatchIdentities[$signature] = [
                'row_number' => $rowNumber,
                'name' => $name,
                'city' => $city,
            ];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findPreparedBatchDuplicateCandidates(AiEnrichmentBatch $batch, string $name, ?string $city, array $item, int $rowNumber, int $taskId): array
    {
        $signatures = $this->buildDuplicateSignatures($name, $city, $item);
        if ($signatures === []) {
            return [];
        }

        $previousTasks = AiEnrichmentTask::query()
            ->where('batch_id', $batch->id)
            ->where('id', '!=', $taskId)
            ->where('row_number', '<', $rowNumber)
            ->orderBy('row_number')
            ->get(['id', 'row_number', 'raw_name', 'raw_city', 'raw_payload']);

        foreach ($previousTasks as $previousTask) {
            $previousItem = is_array($previousTask->raw_payload) ? $previousTask->raw_payload : [];
            $previousSignatures = $this->buildDuplicateSignatures(
                (string) ($previousTask->raw_name ?? ''),
                $previousTask->raw_city,
                $previousItem,
            );

            if (array_intersect($signatures, $previousSignatures) === []) {
                continue;
            }

            return [[
                'id' => null,
                'name' => $previousTask->raw_name,
                'city' => $previousTask->raw_city,
                'website' => $previousItem['website'] ?? null,
                'source' => 'batch_row_' . $previousTask->row_number,
            ]];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $serviceNames
     * @return array<int, int>
     */
    private function resolveServiceIds(Category $category, array $serviceNames): array
    {
        return collect($serviceNames)
            ->filter()
            ->map(function (string $name) use ($category): int {
                $slug = Str::slug($name);

                return CategoryService::firstOrCreate(
                    ['category_id' => $category->id, 'slug' => $slug],
                    [
                        'name' => $name,
                        'is_active' => true,
                        'show_in_catalog' => true,
                        'sort_order' => 0,
                    ],
                )->id;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, string>
     */
    private function expandServiceCandidates(array $suggested, ?Category $category): array
    {
        $seed = collect((array) ($suggested['services'] ?? []))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values()
            ->all();

        $haystack = Str::lower(trim(implode(' ', array_filter([
            (string) ($suggested['name'] ?? ''),
            (string) ($suggested['short_description'] ?? ''),
            (string) ($suggested['description'] ?? ''),
            implode(' ', array_map(fn ($s) => (string) data_get($s, 'title'), (array) ($suggested['sources'] ?? []))),
            implode(' ', array_map(fn ($m) => (string) data_get($m, 'summary'), (array) ($suggested['external_mentions'] ?? []))),
            implode(' ', array_map(fn ($m) => (string) data_get($m, 'review_text'), (array) ($suggested['external_mentions'] ?? []))),
        ]))));

        if ($haystack === '') {
            return $seed;
        }

        $categoryName = Str::lower((string) ($category?->name ?? ''));
        $isLegal = Str::contains($categoryName, ['адвокат', 'юрид', 'нотар']) || Str::contains($haystack, ['адвокат', 'юрист', 'юридич']);

        $detected = [];

        if ($isLegal) {
            $map = [
                'Військове право' => ['військов', 'влк', 'влк/мсек', 'мобілізац', 'повістк', 'тцк', 'сзч'],
                'Виплати сім’ям загиблих' => ['виплат', 'загибл', 'сім’ям загиблих', 'одноразов', 'компенсац'],
                'Оскарження рішень ТЦК та ВЛК' => ['оскаржен', 'тцк', 'влк', 'мсек'],
                'Кримінальне право' => ['криміналь', 'підозр', 'обвинувачен', 'захист у суді'],
                'Сімейне право' => ['розлучен', 'алімент', 'опік', 'поділ майна'],
                'Цивільні спори' => ['цивільн', 'договір', 'борг', 'відшкодуван'],
                'Адміністративні спори' => ['адміністратив', 'штраф', 'держорган', 'постанова'],
                'Трудові спори' => ['трудов', 'звільнен', 'зарплат', 'роботодав'],
                'Спадкові справи' => ['спадщин', 'спадков', 'заповіт'],
            ];

            foreach ($map as $service => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($haystack, $needle)) {
                        $detected[] = $service;
                        break;
                    }
                }
            }
        }

        return collect([...$seed, ...$detected])
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => Str::lower($v))
            ->values()
            ->all();
    }

    private function calculateConfidence(array $suggested): int
    {
        if (isset($suggested['confidence_score']) && is_numeric($suggested['confidence_score'])) {
            return max(0, min(100, (int) $suggested['confidence_score']));
        }

        $score = 20;

        foreach (['name', 'category', 'city', 'phone', 'website', 'email', 'address', 'source_url'] as $field) {
            if (filled($suggested[$field] ?? null)) {
                $score += match ($field) {
                    'name' => 15,
                    'category', 'city' => 10,
                    'phone', 'website', 'address' => 12,
                    'email', 'source_url' => 8,
                    default => 5,
                };
            }
        }

        if (! empty($suggested['services'])) {
            $score += 8;
        }

        return min(100, $score);
    }

    private function createSources(Profile $profile, AiEnrichmentTask $task, array $item, array $suggested): int
    {
        $count = 0;

        ProfileDataSource::create([
            'profile_id' => $profile->id,
            'ai_enrichment_task_id' => $task->id,
            'source_type' => 'import',
            'title' => 'Імпортований рядок',
            'url' => $item['source_url'] ?? null,
            'found_fields' => Arr::only($suggested, ['name', 'category', 'city', 'phone', 'website', 'email', 'address']),
            'confidence_score' => $task->confidence_score,
            'fetched_at' => now(),
            'status' => 'pending',
        ]);

        $count++;

        if (filled($item['source_url'] ?? null)) {
            ProfileDataSource::create([
                'profile_id' => $profile->id,
                'ai_enrichment_task_id' => $task->id,
                'source_type' => 'external_source',
                'title' => 'Джерело з імпорту',
                'url' => $item['source_url'],
                'found_fields' => Arr::only($suggested, ['name', 'city', 'website', 'phone']),
                'confidence_score' => 60,
                'fetched_at' => now(),
                'status' => 'pending',
            ]);

            $count++;
        }

        foreach ((array) ($suggested['sources'] ?? []) as $source) {
            $url = $source['url'] ?? null;

            if (blank($url)) {
                continue;
            }

            ProfileDataSource::create([
                'profile_id' => $profile->id,
                'ai_enrichment_task_id' => $task->id,
                'source_type' => $source['source_type'] ?? 'ai_found_source',
                'title' => $source['title'] ?? 'AI-знайдене джерело',
                'url' => $url,
                'found_fields' => $source['found_fields'] ?? [],
                'confidence_score' => (int) ($source['confidence_score'] ?? $task->confidence_score),
                'fetched_at' => now(),
                'status' => 'pending',
            ]);

            $count++;
        }

        return $count;
    }

    private function createExternalMentions(Profile $profile, AiEnrichmentTask $task, array $suggested): int
    {
        $baseMentions = (array) ($suggested['external_mentions'] ?? []);
        $directoryMentions = $this->collectReviewDirectoryMentions($suggested, $profile);
        $mentions = $this->preferHighPriorityReviewPlatformMentions(array_values(array_filter(
            [...$directoryMentions, ...$baseMentions],
            fn ($mention) => is_array($mention)
                && $this->isMentionUrlAcceptable($mention)
                && $this->isMentionRelevantToProfile($mention, $profile)
        )));

        if ($mentions === []) {
            $this->createExternalMentionPlaceholders($profile, $task);

            return 0;
        }

        $createdMentions = 0;

        foreach ($mentions as $mention) {
            ExternalProfileMention::create([
                'profile_id' => $profile->id,
                'ai_enrichment_task_id' => $task->id,
                'source_type' => $mention['source_type'] ?? 'external_review',
                'title' => $mention['title'] ?? 'Зовнішня згадка',
                'url' => $mention['url'] ?? null,
                'external_rating' => $mention['external_rating'] ?? null,
                'external_reviews_count' => $mention['external_reviews_count'] ?? null,
                'sentiment' => $mention['sentiment'] ?? 'unknown',
                'topic' => $mention['topic'] ?? 'external_mentions',
                'summary' => $mention['summary'] ?? null,
                'confidence_score' => (int) ($mention['confidence_score'] ?? 30),
                'status' => 'pending',
                'raw_payload' => $mention,
            ]);

            $createdMentions++;
            $this->createExternalReviewDraft($profile, $task, $mention);
        }

        $this->pruneDuplicateExternalDraftReviews($profile);

        return $createdMentions;
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, array<string, mixed>>
     */
    private function collectReviewDirectoryMentions(array $suggested, Profile $profile): array
    {
        $mentions = $this->collectTop20ReviewMentions($suggested, $profile);

        if (! (bool) config('ai_enrichment.review_directories.enabled', true)) {
            return $mentions;
        }

        $urls = [
            ...$this->collectSuggestedReviewDirectoryUrls($suggested),
            ...$this->discoverReviewDirectoryCandidateUrls($profile, $suggested),
        ];
        $urls = array_values(array_unique(array_filter($urls)));

        foreach ($urls as $url) {
            if (str_contains(Str::lower($url), 'top20.ua/')) {
                continue;
            }

            $mentions = [...$mentions, ...$this->parseReviewDirectoryMentionsFromUrl($url, $profile)];
        }

        $deduped = [];
        foreach ($mentions as $mention) {
            $identity = $this->buildExternalReviewHash($mention);
            $deduped[$identity] = $mention;
        }

        return array_values($deduped);
    }

    /**
     * Prefer review-platform mentions over weak website testimonials or generic pages.
     *
     * @param  array<int, array<string, mixed>>  $mentions
     * @return array<int, array<string, mixed>>
     */
    private function preferHighPriorityReviewPlatformMentions(array $mentions): array
    {
        $platformMentions = array_values(array_filter(
            $mentions,
            fn (array $mention): bool => $this->isHighPriorityReviewPlatformMention($mention)
        ));

        if ($platformMentions === []) {
            return $mentions;
        }

        $platformHasIndividualReviews = collect($platformMentions)
            ->contains(fn (array $mention): bool => $this->hasIndividualReviewContent($mention));

        if ($platformHasIndividualReviews) {
            return $platformMentions;
        }

        return $mentions;
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function hasIndividualReviewContent(array $mention): bool
    {
        return filled($mention['review_text'] ?? null) || is_numeric($mention['review_rating'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function isHighPriorityReviewPlatformMention(array $mention): bool
    {
        $sourceType = Str::lower(trim((string) ($mention['source_type'] ?? '')));
        if (in_array($sourceType, ['top20', 'google_maps', 'vidhuk', 'realreviews', 'list_in_ua', 'trustpilot', 'twogis', 'external_review_directory'], true)) {
            return true;
        }

        $url = $this->normalizeAbsoluteUrl((string) ($mention['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        if (str_contains($host, 'top20.ua')
            || str_contains($host, 'vidhuk.ua')
            || str_contains($host, 'realreviews.io')
            || str_contains($host, 'list.in.ua')
            || str_contains($host, 'trustpilot.')
            || str_contains($host, '2gis.')
            || str_contains($host, 'google.')
            || str_contains($host, 'share.google')
            || str_contains($host, 'maps.app.goo.gl')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, string>
     */
    private function collectSuggestedReviewDirectoryUrls(array $suggested): array
    {
        $urls = [];

        foreach (['external_mentions', 'sources'] as $key) {
            foreach ((array) ($suggested[$key] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $url = trim((string) data_get($entry, 'url', ''));
                if ($url !== '' && $this->isReviewDirectoryUrl($url)) {
                    $urls[] = $this->normalizeAbsoluteUrl($url);
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, string>
     */
    private function discoverReviewDirectoryCandidateUrls(Profile $profile, array $suggested): array
    {
        if (! (bool) config('ai_enrichment.review_directories.discovery_enabled', true)) {
            return [];
        }

        $hosts = array_values(array_filter(array_map(
            fn ($host) => Str::lower(trim((string) $host)),
            (array) config('ai_enrichment.review_directories.hosts', [])
        )));
        if ($hosts === []) {
            return [];
        }

        $timeout = max(3, min(12, (int) config('ai_enrichment.review_directories.discovery_timeout', 6)));
        $maxUrls = max(1, min(30, (int) config('ai_enrichment.review_directories.discovery_max_urls', 12)));
        $queriesPerHost = max(1, min(6, (int) config('ai_enrichment.review_directories.discovery_queries_per_host', 3)));
        $queries = $this->buildReviewDirectorySearchQueries($profile, $suggested);

        if ($queries === []) {
            return [];
        }

        $urls = [];

        foreach ($hosts as $host) {
            foreach (array_slice($queries, 0, $queriesPerHost) as $query) {
                foreach ($this->searchReviewDirectoryUrlsViaBingRss($host, $query, $profile, $timeout) as $url) {
                    $urls[] = $url;

                    if (count(array_unique($urls)) >= $maxUrls) {
                        break 3;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, string>
     */
    private function buildReviewDirectorySearchQueries(Profile $profile, array $suggested): array
    {
        $queries = [];
        $name = trim((string) $profile->name);
        $city = trim((string) ($profile->city ?? ''));
        $priorityTokens = $this->extractPriorityTokens($name);
        $domainTokens = $this->extractWebsiteDomainQueries($profile, $suggested);
        $phone = trim((string) ($profile->phone ?? ''));
        $phoneDigits = $this->normalizePhoneDigits($phone);

        if ($priorityTokens !== []) {
            $queries[] = '"' . implode(' ', $priorityTokens) . '"' . ($city !== '' ? ' ' . $city : '');
        }

        if ($name !== '') {
            $queries[] = $name . ($city !== '' ? ' ' . $city : '');
            $queries[] = $name . ' відгуки';
        }

        foreach ($domainTokens as $domainToken) {
            $queries[] = $domainToken;
        }

        if ($phone !== '') {
            $queries[] = $phone;
        }

        if ($phoneDigits !== null) {
            $queries[] = $phoneDigits;
            if (Str::startsWith($phoneDigits, '380')) {
                $queries[] = '0' . substr($phoneDigits, 3);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $queries))));
    }

    /**
     * @return array<int, string>
     */
    private function searchReviewDirectoryUrlsViaBingRss(string $host, string $query, Profile $profile, int $timeout): array
    {
        if ($host === '' || $query === '') {
            return [];
        }

        try {
            $rss = (string) Http::timeout($timeout)
                ->accept('application/rss+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.7')
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 DOVIRA deterministic review discovery',
                    'Accept-Language' => 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->get('https://www.bing.com/search', [
                    'format' => 'rss',
                    'q' => $query . ' site:' . $host,
                ])
                ->throw()
                ->body();
        } catch (\Throwable) {
            return [];
        }

        return $this->parseBingReviewDirectoryRssLinks($rss, $host, $profile);
    }

    /**
     * @return array<int, string>
     */
    private function parseBingReviewDirectoryRssLinks(string $rss, string $host, Profile $profile): array
    {
        $rss = trim($rss);
        if ($rss === '' || ! function_exists('simplexml_load_string')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($rss);
        } catch (\Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml || ! isset($xml->channel->item)) {
            return [];
        }

        $urls = [];

        foreach ($xml->channel->item as $item) {
            $url = $this->normalizeAbsoluteUrl((string) ($item->link ?? ''));
            $title = trim((string) ($item->title ?? ''));

            if ($url === '') {
                continue;
            }

            $resultHost = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            if (! $this->isReviewDirectoryHost($resultHost) || ! $this->matchesReviewDirectoryHost($resultHost, $host)) {
                continue;
            }

            $candidateMention = [
                'source_type' => $this->sourceTypeFromReviewDirectoryUrl($url),
                'title' => $title,
                'url' => $url,
                'summary' => $title,
            ];

            if (! $this->isMentionRelevantToProfile($candidateMention, $profile)) {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    private function isReviewDirectoryUrl(string $url): bool
    {
        $normalized = $this->normalizeAbsoluteUrl($url);
        $host = Str::lower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));

        return $this->isReviewDirectoryHost($host);
    }

    private function isReviewDirectoryHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $hosts = [
            'top20.ua',
            ...((array) config('ai_enrichment.review_directories.hosts', [])),
        ];

        foreach ($hosts as $allowedHost) {
            if ($this->matchesReviewDirectoryHost($host, Str::lower(trim((string) $allowedHost)))) {
                return true;
            }
        }

        return false;
    }

    private function matchesReviewDirectoryHost(string $host, string $allowedHost): bool
    {
        if ($host === '' || $allowedHost === '') {
            return false;
        }

        return $host === $allowedHost || str_ends_with($host, '.' . $allowedHost);
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<int, array<string, mixed>>
     */
    private function collectTop20ReviewMentions(array $suggested, Profile $profile): array
    {
        if (! (bool) config('ai_enrichment.top20.enabled', true)) {
            return [];
        }

        $urls = [];

        foreach ((array) ($suggested['external_mentions'] ?? []) as $mention) {
            $url = trim((string) data_get($mention, 'url', ''));
            if ($url !== '' && str_contains(Str::lower($url), 'top20.ua/')) {
                $urls[] = $url;
            }
        }

        foreach ((array) ($suggested['sources'] ?? []) as $source) {
            $url = trim((string) data_get($source, 'url', ''));
            if ($url !== '' && str_contains(Str::lower($url), 'top20.ua/')) {
                $urls[] = $url;
            }
        }

        // Deterministic fallback: discover top20 company page via top20 suggest API,
        // independent from AI response quality.
        foreach ($this->discoverTop20CandidateUrls($profile, $suggested) as $url) {
            $urls[] = $url;
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return [];
        }

        $allMentions = [];
        foreach ($urls as $url) {
            $allMentions = [...$allMentions, ...$this->parseTop20MentionsFromUrl($url, $profile)];
        }

        $deduped = [];
        foreach ($allMentions as $mention) {
            $identity = $this->buildExternalReviewHash($mention);
            $deduped[$identity] = $mention;
        }

        return array_values($deduped);
    }

    /**
     * @return array<int, string>
     */
    private function discoverTop20CandidateUrls(Profile $profile, array $suggested = []): array
    {
        $timeout = max(3, min(20, (int) config('ai_enrichment.top20.timeout', 12)));
        $queries = [];

        $priorityTokens = $this->extractPriorityTokens((string) $profile->name);
        if ($priorityTokens !== []) {
            $queries[] = implode(' ', $priorityTokens);
        }
        $queries[] = (string) $profile->name;
        $domainTokens = $this->extractWebsiteDomainQueries($profile, $suggested);
        if ($domainTokens !== []) {
            $queries[] = implode(' ', $domainTokens);
            foreach ($domainTokens as $domainToken) {
                $queries[] = $domainToken;
            }
        }

        $profilePhone = trim((string) ($profile->phone ?? ''));
        $profilePhoneDigits = $this->normalizePhoneDigits($profilePhone);
        if ($profilePhone !== '') {
            $queries[] = $profilePhone;
        }
        if ($profilePhoneDigits !== null) {
            $queries[] = $profilePhoneDigits;
            if (Str::startsWith($profilePhoneDigits, '380')) {
                $queries[] = '0' . substr($profilePhoneDigits, 3);
            }
        }

        $asciiName = trim(Str::lower(Str::ascii((string) $profile->name)));
        if ($asciiName !== '') {
            $queries[] = $asciiName;
        }

        $queries = array_values(array_unique(array_filter(array_map(
            fn (string $q) => trim($q),
            $queries
        ))));

        if ($queries === []) {
            return [];
        }

        $cityCodes = $this->resolveTop20CityCodes((string) ($profile->city ?? ''));
        $discovered = [];

        foreach ($cityCodes as $cityCode) {
            foreach ($queries as $query) {
                try {
                    $response = Http::timeout($timeout)
                        ->acceptJson()
                        ->get("https://top20.ua/{$cityCode}/search/suggest", ['query' => $query])
                        ->throw()
                        ->json();
                } catch (\Throwable) {
                    continue;
                }

                foreach ((array) data_get($response, 'options', []) as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $url = $this->normalizeAbsoluteUrl((string) data_get($option, 'url', ''));
                    $name = trim((string) data_get($option, 'name', ''));

                    if (! str_contains(Str::lower($url), 'top20.ua/')) {
                        continue;
                    }

                    if (! $this->isTop20CandidateRelevantToProfile($name, $url, $profile, $suggested)) {
                        continue;
                    }

                    $discovered[] = $url;
                }
            }
        }

        return array_values(array_unique($discovered));
    }

    /**
     * @return array<int, string>
     */
    private function resolveTop20CityCodes(string $city): array
    {
        $city = Str::lower(trim($city));

        $map = [
            'одеса' => 'od',
            'odesa' => 'od',
            'одесса' => 'od',
            'київ' => 'kyiv',
            'киев' => 'kyiv',
            'kyiv' => 'kyiv',
            'kiev' => 'kyiv',
            'львів' => 'lv',
            'львов' => 'lv',
            'lviv' => 'lv',
            'харків' => 'kh',
            'харьков' => 'kh',
            'kharkiv' => 'kh',
            'дніпро' => 'dp',
            'днепр' => 'dp',
            'dnipro' => 'dp',
            'вінниця' => 'vn',
            'винница' => 'vn',
            'vinnytsia' => 'vn',
        ];

        $resolved = $map[$city] ?? null;
        if ($resolved !== null) {
            return [$resolved];
        }

        return ['od', 'kyiv', 'vn', 'lv'];
    }

    private function isTop20CandidateRelevantToProfile(string $candidateName, string $candidateUrl, Profile $profile, array $suggested = []): bool
    {
        $haystack = Str::lower(trim($candidateName . ' ' . $candidateUrl));
        if ($haystack === '') {
            return false;
        }

        $domainTokens = $this->extractWebsiteDomainQueries($profile, $suggested);
        if ($domainTokens !== []) {
            $matched = 0;
            foreach ($domainTokens as $domainToken) {
                if ($domainToken !== '' && $this->containsTokenFlexible($haystack, $domainToken)) {
                    $matched++;
                }
            }
            if ($matched >= min(2, count($domainTokens))) {
                return true;
            }
        }

        $priorityTokens = $this->extractPriorityTokens((string) $profile->name);
        if ($priorityTokens !== []) {
            foreach ($priorityTokens as $token) {
                if (! $this->containsTokenFlexible($haystack, $token)) {
                    return false;
                }
            }

            return true;
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/u', Str::lower((string) $profile->name)) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
        ));

        $matched = 0;
        foreach ($tokens as $token) {
            if ($this->containsTokenFlexible($haystack, $token)) {
                $matched++;
            }
        }

        return $matched >= 2;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTop20MentionsFromUrl(string $url, Profile $profile): array
    {
        $normalizedUrl = $this->normalizeAbsoluteUrl($url);
        $timeout = max(3, min(20, (int) config('ai_enrichment.top20.timeout', 12)));

        try {
            $html = (string) $this->reviewSourceHttp($timeout)->get($normalizedUrl)->throw()->body();
        } catch (\Throwable) {
            return [];
        }

        if ($html === '') {
            return [];
        }

        $meta = $this->parseTop20Microdata($html);
        $maxReviews = $this->resolveReviewFetchLimit($meta['aggregate_count'] ?? null, (int) config('ai_enrichment.top20.max_reviews', 1000));
        $companyId = $this->extractTop20CompanyId($html);

        $mentions = [];
        if ($companyId !== null) {
            $mentions = $this->parseTop20WidgetReviews($companyId, $normalizedUrl, $timeout, $maxReviews, $meta);
        }

        // Fallback to microdata reviews when widget parsing fails.
        if ($mentions === [] && isset($meta['reviews']) && is_array($meta['reviews'])) {
            $mentions = $this->mapTop20MicrodataReviews($meta, $normalizedUrl, $maxReviews);
        }

        return $mentions;
    }

    /**
     * @return array{name:string,website:string,aggregate_rating:float|null,aggregate_count:int|null,reviews:array<int,array<string,mixed>>}|array{}
     */
    private function parseTop20Microdata(string $html): array
    {
        if (! preg_match('/<script[^>]+id=["\']company-microdata["\'][^>]*>(.*?)<\/script>/isu', $html, $m)) {
            return [];
        }

        $json = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return [];
        }

        return [
            'name' => trim((string) data_get($payload, 'name', '')),
            'website' => trim((string) data_get($payload, 'url', '')),
            'phone' => trim((string) data_get($payload, 'telephone', '')),
            'aggregate_rating' => is_numeric(data_get($payload, 'aggregateRating.ratingValue'))
                ? (float) data_get($payload, 'aggregateRating.ratingValue')
                : null,
            'aggregate_count' => is_numeric(data_get($payload, 'aggregateRating.ratingCount'))
                ? (int) data_get($payload, 'aggregateRating.ratingCount')
                : null,
            'reviews' => array_values(array_filter((array) data_get($payload, 'review', []), fn ($r) => is_array($r))),
        ];
    }

    private function extractTop20CompanyId(string $html): ?int
    {
        if (! preg_match('/id=["\']company-show["\'][^>]*data-id=["\'](\d+)["\']/isu', $html, $m)) {
            return null;
        }

        $id = (int) ($m[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array{name?:string,website?:string,aggregate_rating?:float|null,aggregate_count?:int|null}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function parseTop20WidgetReviews(int $companyId, string $top20Url, int $timeout, int $maxReviews, array $meta = []): array
    {
        $baseUrl = 'https://top20.ua/company-widget/' . $companyId . '/reviews';
        $nextUrl = $baseUrl;
        $mentions = [];
        $seenReviewIds = [];
        $maxPages = max(1, min(120, (int) ceil($maxReviews / 10) + 2));

        for ($page = 1; $page <= $maxPages && count($mentions) < $maxReviews; $page++) {
            try {
                $html = (string) $this->reviewSourceHttp($timeout)->get($nextUrl)->throw()->body();
            } catch (\Throwable) {
                break;
            }

            if ($html === '') {
                break;
            }

            $parsed = $this->extractTop20WidgetReviewItems($html, $top20Url, $meta);
            foreach ($parsed as $item) {
                $rid = (string) ($item['_rid'] ?? '');
                if ($rid !== '' && isset($seenReviewIds[$rid])) {
                    continue;
                }
                if ($rid !== '') {
                    $seenReviewIds[$rid] = true;
                }
                unset($item['_rid']);
                $mentions[] = $item;
                if (count($mentions) >= $maxReviews) {
                    break 2;
                }
            }

            $nextUrl = $this->extractTop20NextPageUrl($html);
            if ($nextUrl === null) {
                break;
            }
        }

        return $mentions;
    }

    /**
     * @param  array{name?:string,website?:string,aggregate_rating?:float|null,aggregate_count?:int|null}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function extractTop20WidgetReviewItems(string $html, string $top20Url, array $meta = []): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//div[@id="company-reviews"]//div[starts-with(@id, "review-")]');
        if (! $nodes) {
            return [];
        }

        $aggregateRating = $meta['aggregate_rating'] ?? null;
        $aggregateCount = $meta['aggregate_count'] ?? null;
        $title = trim((string) ($meta['name'] ?? '')) ?: 'Компанія';
        $sourceWebsite = trim((string) ($meta['website'] ?? ''));
        $mentions = [];

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $rid = trim((string) $node->getAttribute('id'));
            $author = trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//span[1])', $node));
            $text = trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-text")])', $node));
            $ratingRaw = trim((string) $xpath->evaluate('string((.//div[contains(@class,"company_page--top")]//div[contains(@class,"label")]//span)[1])', $node));
            $ratingValue = is_numeric(str_replace(',', '.', $ratingRaw)) ? (float) str_replace(',', '.', $ratingRaw) : null;
            if (! is_numeric($ratingValue)) {
                continue;
            }

            $mentions[] = [
                '_rid' => $rid,
                'source_type' => 'top20',
                'title' => 'Top20 · ' . $title,
                'url' => $top20Url,
                'source_website' => $sourceWebsite !== '' ? $sourceWebsite : null,
                'source_phone' => trim((string) ($meta['phone'] ?? '')) ?: null,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => $author !== '' ? $author : 'Користувач',
                'review_author_avatar_url' => $this->normalizeExternalAvatarUrl((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//img/@src)', $node))
                    ?? $this->normalizeExternalAvatarUrl((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//img/@data-src)', $node)),
                'review_text' => $text !== '' ? Str::limit($text, 1200, '') : null,
                'review_rating' => (float) $ratingValue,
                'review_date' => null,
                'sentiment' => $this->sentimentFromRating((float) $ratingValue),
                'topic' => 'top20_google_review',
                'summary' => 'Відгук імпортовано з Top20 (дзеркало Google). Потребує ручної модерації.',
                'confidence_score' => 90,
            ];
        }

        return $mentions;
    }

    private function extractTop20NextPageUrl(string $html): ?string
    {
        if (! preg_match('/class=["\'][^"\']*js-more[^"\']*["\'][^>]*data-href=["\']([^"\']+)["\']/isu', $html, $m)) {
            return null;
        }

        $encoded = html_entity_decode(trim((string) ($m[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        return $this->normalizeAbsoluteUrl($decoded);
    }

    /**
     * @param  array{name?:string,website?:string,aggregate_rating?:float|null,aggregate_count?:int|null,reviews?:array<int,array<string,mixed>>}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function mapTop20MicrodataReviews(array $meta, string $top20Url, int $maxReviews): array
    {
        $reviews = array_values(array_filter((array) ($meta['reviews'] ?? []), fn ($r) => is_array($r)));
        if ($reviews === []) {
            return [];
        }

        $mentions = [];
        foreach (array_slice($reviews, 0, $maxReviews) as $review) {
            $rating = data_get($review, 'reviewRating.ratingValue');
            if (! is_numeric($rating)) {
                continue;
            }

            $author = trim((string) data_get($review, 'author.name', ''));
            $body = trim((string) data_get($review, 'reviewBody', ''));
            $date = trim((string) data_get($review, 'datePublished', ''));
            $mentions[] = [
                'source_type' => 'top20',
                'title' => 'Top20 · ' . (trim((string) ($meta['name'] ?? '')) ?: 'Компанія'),
                'url' => $top20Url,
                'source_website' => trim((string) ($meta['website'] ?? '')) ?: null,
                'source_phone' => trim((string) ($meta['phone'] ?? '')) ?: null,
                'external_rating' => is_numeric($meta['aggregate_rating'] ?? null) ? (float) $meta['aggregate_rating'] : null,
                'external_reviews_count' => is_numeric($meta['aggregate_count'] ?? null) ? (int) $meta['aggregate_count'] : count($reviews),
                'review_author' => $author !== '' ? $author : 'Користувач',
                'review_author_avatar_url' => null,
                'review_text' => $body !== '' ? Str::limit($body, 1200, '') : null,
                'review_rating' => (float) $rating,
                'review_date' => $date !== '' ? $date : null,
                'sentiment' => $this->sentimentFromRating((float) $rating),
                'topic' => 'top20_google_review',
                'summary' => 'Відгук імпортовано з Top20 (дзеркало Google). Потребує ручної модерації.',
                'confidence_score' => 88,
            ];
        }

        return $mentions;
    }

    /**
     * Google Maps must be searched again after the profile is resolved, because
     * AI can initially choose the wrong registry phone/city for people with common names.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectGoogleMapsMentionsForResolvedProfile(Profile $profile): array
    {
        if (! (bool) config('ai_enrichment.google_maps.enabled', false) || blank(config('ai_enrichment.google_maps.api_key'))) {
            return [];
        }

        $name = trim((string) $profile->name);
        if ($name === '') {
            return [];
        }

        $apiKey = (string) config('ai_enrichment.google_maps.api_key');
        $googleMode = (string) config('ai_enrichment.google_maps.mode', 'api_budget');
        $language = (string) config('ai_enrichment.google_maps.language_code', 'uk');
        $timeout = max(2, min(10, (int) config('ai_enrichment.google_maps.timeout', 4)));
        $maxResults = max(1, min(20, (int) config('ai_enrichment.google_maps.max_result_count', 8)));
        $phone = trim((string) ($profile->phone ?? ''));
        $phoneDigits = $this->normalizePhoneDigits($phone);
        $city = trim((string) ($profile->city ?? ''));
        $websiteDomain = $this->extractDomain((string) ($profile->website ?? ''));

        $queries = array_values(array_unique(array_filter([
            $phone,
            $phoneDigits,
            $phoneDigits ? '+' . ltrim($phoneDigits, '+') : null,
            trim($name . ' ' . $phone),
            trim($name . ' ' . $city),
            trim($name . ' Google Maps'),
            $websiteDomain !== '' ? $websiteDomain : null,
        ])));
        if ($googleMode === 'api_budget' || $googleMode === 'links_only') {
            $queries = array_slice($queries, 0, 2);
            $maxResults = min($maxResults, 3);
        }

        $placesByResourceName = [];
        foreach ($queries as $query) {
            try {
                $response = Http::withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'places.id,places.name,places.displayName,places.googleMapsUri,places.internationalPhoneNumber,places.nationalPhoneNumber',
                ])
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->post((string) config('ai_enrichment.google_maps.search_endpoint'), [
                        'textQuery' => $query,
                        'languageCode' => $language,
                        'maxResultCount' => $maxResults,
                        'regionCode' => 'UA',
                    ])
                    ->throw();
            } catch (\Throwable) {
                continue;
            }

            foreach ((array) data_get($response->json(), 'places', []) as $place) {
                if (! is_array($place)) {
                    continue;
                }

                $resourceName = (string) (data_get($place, 'name') ?: (data_get($place, 'id') ? 'places/' . data_get($place, 'id') : ''));
                if ($resourceName !== '') {
                    $placesByResourceName[$resourceName] = $place;
                }
            }
        }

        $mentions = [];
        $detailsFetched = 0;
        $maxDetailsFetch = $googleMode === 'api_budget' ? 1 : 2;
        foreach ($placesByResourceName as $resourceName => $place) {
            if ($googleMode === 'links_only') {
                if (! $this->isGoogleMapsPlaceRelevantToResolvedProfile($place, $profile)) {
                    continue;
                }

                $mentions[] = $this->mapResolvedGoogleMapsPlaceToLinkMention($place, $profile);
                continue;
            }

            if (! $this->isGoogleMapsPlaceRelevantToResolvedProfile($place, $profile)) {
                continue;
            }

            if ($detailsFetched >= $maxDetailsFetch) {
                break;
            }

            try {
                $detailsResponse = Http::withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'id,name,displayName,googleMapsUri,internationalPhoneNumber,nationalPhoneNumber,websiteUri,rating,userRatingCount,reviews',
                ])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->get(rtrim((string) config('ai_enrichment.google_maps.details_endpoint'), '/') . '/' . ltrim((string) $resourceName, '/'), [
                        'languageCode' => $language,
                    ])
                    ->throw();
            } catch (\Throwable) {
                continue;
            }

            $details = $detailsResponse->json();
            $resolvedPlace = is_array($details) ? array_replace_recursive($place, $details) : $place;
            $detailsFetched++;

            if (! $this->isGoogleMapsPlaceRelevantToResolvedProfile($resolvedPlace, $profile)) {
                continue;
            }

            $mentions = [...$mentions, ...$this->mapResolvedGoogleMapsReviewsToMentions($resolvedPlace, $profile)];
        }

        return array_values(array_unique($mentions, SORT_REGULAR));
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function mapResolvedGoogleMapsPlaceToLinkMention(array $place, Profile $profile): array
    {
        $placeTitle = trim((string) data_get($place, 'displayName.text', '')) ?: (string) $profile->name;
        $placeUrl = (string) (data_get($place, 'googleMapsUri') ?: '');
        $placePhone = trim((string) (data_get($place, 'internationalPhoneNumber') ?: data_get($place, 'nationalPhoneNumber') ?: '')) ?: null;
        $aggregateRating = data_get($place, 'rating');
        $aggregateCount = data_get($place, 'userRatingCount');

        return [
            'source_type' => 'google_maps',
            'title' => 'Google Maps · ' . $placeTitle,
            'url' => $placeUrl,
            'source_phone' => $placePhone,
            'source_website' => null,
            'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
            'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
            'review_author' => null,
            'review_text' => null,
            'review_rating' => null,
            'review_date' => null,
            'sentiment' => 'unknown',
            'topic' => 'google_maps_link',
            'summary' => 'Зовнішнє посилання на профіль Google Maps. Текст відгуків не імпортовано.',
            'confidence_score' => $placePhone !== null ? 84 : 74,
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function isGoogleMapsPlaceRelevantToResolvedProfile(array $place, Profile $profile): bool
    {
        $profilePhone = $this->normalizePhoneDigits((string) ($profile->phone ?? ''));
        $placePhone = $this->normalizePhoneDigits((string) (data_get($place, 'internationalPhoneNumber') ?: data_get($place, 'nationalPhoneNumber') ?: ''));
        if ($profilePhone !== null && $placePhone !== null && (str_contains($profilePhone, $placePhone) || str_contains($placePhone, $profilePhone))) {
            return true;
        }

        $profileDomain = $this->extractDomain((string) ($profile->website ?? ''));
        $placeDomain = $this->extractDomain((string) (data_get($place, 'websiteUri') ?: ''));
        if ($profileDomain !== '' && $placeDomain !== ''
            && ($profileDomain === $placeDomain || str_ends_with($profileDomain, '.' . $placeDomain) || str_ends_with($placeDomain, '.' . $profileDomain))) {
            return true;
        }

        $title = Str::lower((string) data_get($place, 'displayName.text', ''));
        $tokens = $this->extractPriorityTokens((string) $profile->name);
        if ($tokens === []) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/u', Str::lower((string) $profile->name)) ?: [],
                fn (string $token) => mb_strlen($token) >= 4 && ! in_array($token, ['адвокат', 'юрист', 'юридична', 'центр', 'допомога'], true)
            ));
        }

        if ($tokens === []) {
            return false;
        }

        if ($this->looksLikePersonProfileName((string) $profile->name)) {
            $surnameToken = $this->extractLikelySurnameToken((string) $profile->name);
            if ($surnameToken !== null && ! $this->containsTokenFlexible($title, $surnameToken)) {
                return false;
            }
        }

        $matches = 0;
        foreach ($tokens as $token) {
            if ($this->containsTokenFlexible($title, $token)) {
                $matches++;
            }
        }

        return count($tokens) >= 2 ? $matches >= 2 : $matches >= 1;
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<int, array<string, mixed>>
     */
    private function mapResolvedGoogleMapsReviewsToMentions(array $place, Profile $profile): array
    {
        $placeTitle = trim((string) data_get($place, 'displayName.text', '')) ?: (string) $profile->name;
        $placeUrl = (string) (data_get($place, 'googleMapsUri') ?: '');
        $placePhone = trim((string) (data_get($place, 'internationalPhoneNumber') ?: data_get($place, 'nationalPhoneNumber') ?: '')) ?: null;
        $placeWebsite = trim((string) (data_get($place, 'websiteUri') ?: '')) ?: null;
        $aggregateRating = data_get($place, 'rating');
        $aggregateCount = data_get($place, 'userRatingCount');
        $mentions = [];

        foreach ((array) data_get($place, 'reviews', []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $rating = data_get($review, 'rating');
            if (! is_numeric($rating)) {
                continue;
            }

            $text = trim((string) (data_get($review, 'text.text') ?: data_get($review, 'originalText.text') ?: ''));

            $mentions[] = [
                'source_type' => 'google_maps',
                'title' => 'Google Maps · ' . $placeTitle,
                'url' => $placeUrl,
                'source_phone' => $placePhone,
                'source_website' => $placeWebsite,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => trim((string) (data_get($review, 'authorAttribution.displayName') ?: 'Користувач Google')),
                'review_author_avatar_url' => trim((string) (data_get($review, 'authorAttribution.photoUri') ?: '')) ?: null,
                'review_text' => $text !== '' ? Str::limit($text, 1200, '') : null,
                'review_rating' => (float) $rating,
                'review_date' => filled(data_get($review, 'publishTime')) ? substr((string) data_get($review, 'publishTime'), 0, 10) : null,
                'sentiment' => $this->sentimentFromRating((float) $rating),
                'topic' => 'google_maps_review',
                'summary' => 'Індивідуальний відгук з Google Maps, знайдений за даними існуючого профілю.',
                'confidence_score' => $placePhone !== null ? 95 : 88,
            ];
        }

        if ($mentions === [] && ($placeUrl !== '' || is_numeric($aggregateRating))) {
            $mentions[] = [
                'source_type' => 'google_maps',
                'title' => 'Google Maps · ' . $placeTitle,
                'url' => $placeUrl,
                'source_phone' => $placePhone,
                'source_website' => $placeWebsite,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => null,
                'review_text' => null,
                'review_rating' => null,
                'review_date' => null,
                'sentiment' => 'unknown',
                'topic' => 'google_maps_aggregate',
                'summary' => 'Знайдено Google Maps профіль за даними існуючого профілю.',
                'confidence_score' => $placePhone !== null ? 85 : 70,
            ];
        }

        return $mentions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseReviewDirectoryMentionsFromUrl(string $url, Profile $profile): array
    {
        $normalizedUrl = $this->normalizeAbsoluteUrl($url);
        $timeout = max(3, min(20, (int) config('ai_enrichment.review_directories.timeout', 12)));

        try {
            $html = (string) $this->reviewSourceHttp($timeout)->get($normalizedUrl)->throw()->body();
        } catch (\Throwable) {
            return [];
        }

        if ($html === '') {
            return [];
        }

        $sourceType = $this->sourceTypeFromReviewDirectoryUrl($normalizedUrl);
        $pageTitle = $this->extractHtmlTitle($html) ?: $this->directoryLabelFromSourceType($sourceType);
        $sourcePhone = $this->extractFirstPhoneFromText($html);
        $aggregate = $this->extractAggregateRatingFromHtml($html);
        $maxReviews = $this->resolveReviewFetchLimit($aggregate['count'] ?? null, (int) config('ai_enrichment.review_directories.max_reviews', 1000));

        $mentions = $this->parseJsonLdReviewMentions($html, $normalizedUrl, $sourceType, $pageTitle, $sourcePhone, $aggregate, $maxReviews);

        if ($mentions === []) {
            $mentions = $this->parseDomReviewMentions($html, $normalizedUrl, $sourceType, $pageTitle, $sourcePhone, $aggregate, $maxReviews);
        }

        return $mentions;
    }

    private function resolveReviewFetchLimit(?int $aggregateCount, int $configuredLimit): int
    {
        $configuredLimit = max(1, $configuredLimit);
        $aggregateCount = max(0, (int) ($aggregateCount ?? 0));

        return max(1, min(1000, max($configuredLimit, $aggregateCount)));
    }

    private function reviewSourceHttp(int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language' => 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ])
            ->withOptions([
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                ],
            ])
            ->timeout($timeout);
    }

    /**
     * @param  array{rating:?float,count:?int}  $aggregate
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonLdReviewMentions(string $html, string $url, string $sourceType, string $pageTitle, ?string $sourcePhone, array $aggregate, int $maxReviews): array
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu', $html, $matches)) {
            return [];
        }

        $reviews = [];
        foreach ($matches[1] as $rawJson) {
            $json = trim(html_entity_decode((string) $rawJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $payload = json_decode($json, true);
            if (! is_array($payload)) {
                continue;
            }

            foreach ($this->extractReviewNodesFromJsonLd($payload) as $review) {
                $rating = data_get($review, 'reviewRating.ratingValue');
                $body = trim((string) (data_get($review, 'reviewBody') ?: data_get($review, 'description') ?: data_get($review, 'text') ?: ''));

                if (! is_numeric($rating) || $body === '') {
                    continue;
                }

                $author = trim((string) (data_get($review, 'author.name') ?: data_get($review, 'author') ?: 'Користувач'));

                $reviews[] = [
                    'source_type' => $sourceType,
                    'title' => $this->directoryLabelFromSourceType($sourceType) . ' · ' . $pageTitle,
                    'url' => $url,
                    'source_phone' => $sourcePhone,
                    'source_website' => null,
                    'external_rating' => $aggregate['rating'],
                    'external_reviews_count' => $aggregate['count'],
                    'review_author' => $author !== '' ? $author : 'Користувач',
                    'review_author_avatar_url' => null,
                    'review_text' => Str::limit($body, 1200, ''),
                    'review_rating' => (float) $rating,
                    'review_date' => trim((string) data_get($review, 'datePublished', '')) ?: null,
                    'sentiment' => $this->sentimentFromRating((float) $rating),
                    'topic' => $sourceType . '_review',
                    'summary' => 'Відгук імпортовано з публічної директорії. Потребує ручної модерації.',
                    'confidence_score' => 84,
                ];

                if (count($reviews) >= $maxReviews) {
                    break 2;
                }
            }
        }

        return $reviews;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractReviewNodesFromJsonLd(array $payload): array
    {
        $nodes = [];
        $stack = [$payload];

        while ($stack !== []) {
            $node = array_pop($stack);
            if (! is_array($node)) {
                continue;
            }

            $type = data_get($node, '@type');
            $types = is_array($type) ? $type : [$type];
            $hasReviewType = collect($types)
                ->map(fn ($value) => Str::lower((string) $value))
                ->contains('review');

            if ($hasReviewType) {
                $nodes[] = $node;
            }

            foreach (['@graph', 'review', 'reviews', 'itemReviewed'] as $key) {
                $child = $node[$key] ?? null;
                if (is_array($child)) {
                    if (array_is_list($child)) {
                        foreach ($child as $entry) {
                            $stack[] = $entry;
                        }
                    } else {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $nodes;
    }

    /**
     * @param  array{rating:?float,count:?int}  $aggregate
     * @return array<int, array<string, mixed>>
     */
    private function parseDomReviewMentions(string $html, string $url, string $sourceType, string $pageTitle, ?string $sourcePhone, array $aggregate, int $maxReviews): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[contains(translate(concat(" ", @class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ", "abcdefghijklmnopqrstuvwxyzабвгґдеєжзиіїйклмнопрстуфхцчшщьюя"), " review") or contains(translate(concat(" ", @class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ", "abcdefghijklmnopqrstuvwxyzабвгґдеєжзиіїйклмнопрстуфхцчшщьюя"), "відгу") or contains(translate(concat(" ", @class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ", "abcdefghijklmnopqrstuvwxyzабвгґдеєжзиіїйклмнопрстуфхцчшщьюя"), "otzyv")]');

        if (! $nodes) {
            return [];
        }

        $mentions = [];
        $seenTexts = [];

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $text = $this->firstXPathString($xpath, $node, [
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "text")]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "body")]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "content")]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comment")]',
                './/*[@itemprop="reviewBody"]',
            ]);

            $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
            if ($text === '') {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?: '');
            }

            $text = $this->cleanDirectoryReviewText($text);
            if (mb_strlen($text) < 8 || mb_strlen($text) > 2000) {
                continue;
            }

            $rating = $this->extractRatingFromReviewNode($xpath, $node);
            if (! is_numeric($rating)) {
                continue;
            }

            $identity = sha1(Str::lower($text));
            if (isset($seenTexts[$identity])) {
                continue;
            }
            $seenTexts[$identity] = true;

            $author = $this->firstXPathString($xpath, $node, [
                './/*[@itemprop="author"]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "author")]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "user")]',
                './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "name")]',
                './/strong',
            ]);
            $date = $this->firstXPathString($xpath, $node, ['.//time/@datetime', './/*[@itemprop="datePublished"]/@content', './/time']);
            $avatar = $this->normalizeExternalAvatarUrl($this->firstXPathString($xpath, $node, ['.//img/@src', './/img/@data-src']));

            $mentions[] = [
                'source_type' => $sourceType,
                'title' => $this->directoryLabelFromSourceType($sourceType) . ' · ' . $pageTitle,
                'url' => $url,
                'source_phone' => $sourcePhone,
                'source_website' => null,
                'external_rating' => $aggregate['rating'],
                'external_reviews_count' => $aggregate['count'],
                'review_author' => trim($author) !== '' ? Str::limit(trim($author), 120, '') : 'Користувач',
                'review_author_avatar_url' => $avatar,
                'review_text' => Str::limit($text, 1200, ''),
                'review_rating' => (float) $rating,
                'review_date' => trim($date) !== '' ? trim($date) : null,
                'sentiment' => $this->sentimentFromRating((float) $rating),
                'topic' => $sourceType . '_review',
                'summary' => 'Відгук імпортовано з публічної директорії. Потребує ручної модерації.',
                'confidence_score' => 72,
            ];

            if (count($mentions) >= $maxReviews) {
                break;
            }
        }

        return $mentions;
    }

    /**
     * @param  array<int, string>  $queries
     */
    private function firstXPathString(\DOMXPath $xpath, \DOMElement $context, array $queries): string
    {
        foreach ($queries as $query) {
            $value = trim((string) $xpath->evaluate('string(' . $query . ')', $context));
            if ($value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return '';
    }

    private function cleanDirectoryReviewText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        $text = preg_replace('/^(відгук|review|отзыв)\s*/iu', '', $text) ?: $text;

        return trim($text);
    }

    private function extractRatingFromReviewNode(\DOMXPath $xpath, \DOMElement $node): ?float
    {
        $raw = $this->firstXPathString($xpath, $node, [
            './/*[@itemprop="ratingValue"]/@content',
            './/*[@itemprop="ratingValue"]',
            './/*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "rating")]',
            './/*[@aria-label]',
        ]);

        if (preg_match('/([1-5](?:[.,]\d)?)(?:\s*(?:\/|з|из)\s*5)?/u', $raw, $m)) {
            return (float) str_replace(',', '.', (string) $m[1]);
        }

        $filledStars = (int) $xpath->evaluate('count(.//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "star") and (contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "active") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "filled"))])', $node);

        return $filledStars > 0 && $filledStars <= 5 ? (float) $filledStars : null;
    }

    /**
     * @return array{rating:?float,count:?int}
     */
    private function extractAggregateRatingFromHtml(string $html): array
    {
        $rating = null;
        $count = null;

        if (preg_match('/"aggregateRating"\s*:\s*\{.*?"ratingValue"\s*:\s*"?([0-9]+(?:[.,][0-9]+)?)"?/isu', $html, $m)) {
            $rating = (float) str_replace(',', '.', (string) $m[1]);
        }

        if (preg_match('/"aggregateRating"\s*:\s*\{.*?"(?:reviewCount|ratingCount)"\s*:\s*"?(\d+)"?/isu', $html, $m)) {
            $count = (int) $m[1];
        }

        return ['rating' => $rating, 'count' => $count];
    }

    private function extractHtmlTitle(string $html): ?string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags((string) $m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title !== '' ? Str::limit($title, 160, '') : null;
    }

    private function extractFirstPhoneFromText(string $text): ?string
    {
        if (! preg_match('/(?:\+?38)?\s*\(?0\d{2}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}/u', strip_tags($text), $m)) {
            return null;
        }

        return trim((string) $m[0]);
    }

    private function sourceTypeFromReviewDirectoryUrl(string $url): string
    {
        $host = Str::lower((string) (parse_url($this->normalizeAbsoluteUrl($url), PHP_URL_HOST) ?? ''));

        return match (true) {
            str_contains($host, 'vidhuk.ua') => 'vidhuk',
            str_contains($host, 'realreviews.io') => 'realreviews',
            str_contains($host, 'list.in.ua') => 'list_in_ua',
            str_contains($host, 'trustpilot.') => 'trustpilot',
            str_contains($host, '2gis.') => 'twogis',
            default => 'external_review_directory',
        };
    }

    private function directoryLabelFromSourceType(string $sourceType): string
    {
        return match ($sourceType) {
            'vidhuk' => 'Vidhuk.ua',
            'realreviews' => 'RealReviews',
            'list_in_ua' => 'List.in.ua',
            'trustpilot' => 'Trustpilot',
            'twogis' => '2GIS',
            default => 'Review directory',
        };
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function isMentionUrlAcceptable(array $mention): bool
    {
        $url = trim((string) ($mention['url'] ?? ''));
        if ($url === '') {
            return true;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = Str::lower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        if ($host === '') {
            return false;
        }

        $isGoogleMaps = $this->isGoogleMapsMention($mention);
        if (! $isGoogleMaps) {
            return true;
        }

        if (str_contains($host, 'maps.app.goo.gl') || str_contains($host, 'share.google') || str_contains($host, 'maps.google.com')) {
            return true;
        }

        if (str_contains($host, 'google.') && str_contains($path, '/maps')) {
            return true;
        }

        return false;
    }

    private function purgeProfileAiDraftArtifacts(Profile $profile): void
    {
        ExternalProfileMention::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'pending')
            ->delete();

        ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('verification_type', 'external_ai_draft')
            ->where('status', 'pending')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function createExternalReviewDraft(Profile $profile, AiEnrichmentTask $task, array $mention): void
    {
        $url = trim((string) ($mention['url'] ?? ''));
        $sourceType = (string) ($mention['source_type'] ?? 'external_review');
        $body = trim((string) ($mention['review_text'] ?? ''));

        if ($url === '') {
            return;
        }

        $allowEmptyBody = Str::lower(trim($sourceType)) === 'google_maps';
        if ($body === '' && ! $allowEmptyBody) {
            return;
        }

        if (Str::contains(Str::lower($body), [
            'заготовка для майбутнього пошуку',
            'публікація можлива тільки після ручної модерації',
        ])) {
            return;
        }

        $rating = $mention['review_rating'] ?? null;

        if (! is_numeric($rating)) {
            $rating = $this->inferRatingFromMention($mention);
        }

        if (! is_numeric($rating)) {
            return;
        }

        $rating = max(1, min(5, (int) round((float) $rating)));
        $author = trim((string) ($mention['review_author'] ?? ''));
        $authorAvatarUrl = $this->normalizeExternalAvatarUrl((string) ($mention['review_author_avatar_url'] ?? ''));
        $author = $author !== '' ? $author : ($sourceType === 'google_maps' ? 'Користувач Google' : 'Зовнішній автор');
        $normalizedUrl = $this->normalizeExternalMentionUrl($url);
        $normalizedDate = (string) ($this->normalizeExternalReviewDate($mention['review_date'] ?? null) ?? '');
        $normalizedSourceType = Str::lower(trim($sourceType));
        $hash = $this->buildExternalReviewHash([
            'url' => $normalizedUrl,
            'source_type' => $normalizedSourceType,
            'review_author' => $author,
            'review_rating' => $rating,
            'review_date' => $normalizedDate,
            'review_text' => $body,
        ]);

        $existingByIdentity = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('verification_type', 'external_ai_draft')
            ->where('external_source_url', $normalizedUrl)
            ->where('rating', $rating)
            ->get()
            ->first(function (ProfileReview $review) use ($author): bool {
                $candidateAuthor = (string) ($review->external_review_author ?: $review->author_name ?: '');

                return Str::lower(trim($candidateAuthor)) === Str::lower(trim($author));
            });

        if ($existingByIdentity && $existingByIdentity->external_review_hash !== $hash) {
            $existingByIdentity->external_review_hash = $hash;
            $existingByIdentity->save();
        }

        $existingByHash = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('external_review_hash', $hash)
            ->first();

        $existing = $existingByHash ?: $existingByIdentity;
        if ($existing && $existing->status !== 'pending') {
            return;
        }

        $review = $existing ?: new ProfileReview([
            'profile_id' => $profile->id,
            'external_review_hash' => $hash,
        ]);

        $review->fill([
            'user_id' => null,
            'author_name' => $author,
            'author_email' => null,
            'rating' => $rating,
            'title' => null,
            'body' => Str::limit($body, 1200, ''),
            'status' => 'pending',
            'is_anonymous' => false,
            'is_suspicious' => false,
            'is_featured' => false,
            'is_verified_purchase' => false,
            'verification_type' => 'external_ai_draft',
            'moderation_reason' => 'external_review_candidate',
            'moderation_note' => 'Кандидат у відгук знайдено AI-збагаченням. Потрібна ручна перевірка джерела перед публікацією.',
            'admin_note' => implode("\n", array_filter([
                'AI-збагачення #' . $task->id,
                'Джерело: ' . $normalizedUrl,
                filled($mention['source_type'] ?? null) ? 'Тип джерела: ' . $mention['source_type'] : null,
                filled($mention['sentiment'] ?? null) ? 'Тональність: ' . $mention['sentiment'] : null,
                filled($mention['confidence_score'] ?? null) ? 'Confidence: ' . $mention['confidence_score'] . '%' : null,
            ])),
            'risk_score' => 0,
            'media' => [],
            'published_at' => null,
            'external_source_url' => $normalizedUrl,
            'external_source_type' => $sourceType,
            'external_review_author' => $author,
            'external_review_author_avatar_url' => $authorAvatarUrl,
            'external_review_date' => $this->normalizeExternalReviewDate($mention['review_date'] ?? null),
            'external_review_hash' => $hash,
        ]);
        $review->save();
    }

    private function normalizeExternalReviewDate(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse((string) $date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeExternalMentionUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $resolved = $this->resolveKnownShortUrl($url);
        if (! preg_match('#^https?://#i', $resolved)) {
            $resolved = 'https://' . ltrim($resolved, '/');
        }
        $parts = parse_url($resolved);
        if ($parts === false) {
            return Str::lower($resolved);
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($host === '') {
            return Str::lower($resolved);
        }

        if (str_contains($host, 'google.') || str_contains($host, 'share.google')) {
            parse_str($query, $queryParams);
            $stable = [];

            foreach (['cid', 'q', 'query', 'ftid'] as $key) {
                if (isset($queryParams[$key])) {
                    $stable[$key] = (string) $queryParams[$key];
                }
            }

            $query = $stable !== [] ? http_build_query($stable) : '';
        }

        $normalized = 'https://' . $host . rtrim($path, '/');
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return Str::lower($normalized);
    }

    private function normalizeExternalAvatarUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (MediaUrl::isKnownPlaceholderPath($url)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $url) && ! str_starts_with($url, '//')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function pruneDuplicateExternalDraftReviews(Profile $profile): void
    {
        $reviews = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('verification_type', 'external_ai_draft')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        $seen = [];
        $toDelete = [];

        foreach ($reviews as $review) {
            $identity = $this->buildExternalReviewHash([
                'url' => (string) ($review->external_source_url ?? ''),
                'source_type' => (string) ($review->external_source_type ?? 'external_review'),
                'review_author' => (string) ($review->external_review_author ?: $review->author_name ?: ''),
                'review_rating' => (int) ($review->rating ?? 0),
                'review_date' => $review->external_review_date?->toDateString() ?? '',
                'review_text' => (string) ($review->body ?? ''),
            ]);

            if (! isset($seen[$identity])) {
                $seen[$identity] = (int) $review->id;
                continue;
            }

            $toDelete[] = (int) $review->id;
        }

        if ($toDelete !== []) {
            ProfileReview::query()->whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $reviewLike
     */
    private function buildExternalReviewHash(array $reviewLike): string
    {
        $normalizedUrl = $this->normalizeExternalMentionUrl((string) ($reviewLike['url'] ?? ''));
        $normalizedSourceType = Str::lower(trim((string) ($reviewLike['source_type'] ?? 'external_review')));
        $normalizedAuthor = Str::lower(trim((string) ($reviewLike['review_author'] ?? '')));
        $normalizedRating = is_numeric($reviewLike['review_rating'] ?? null)
            ? (string) max(1, min(5, (int) round((float) $reviewLike['review_rating'])))
            : '';
        $normalizedDate = (string) ($this->normalizeExternalReviewDate($reviewLike['review_date'] ?? null) ?? '');
        $normalizedBody = Str::lower(trim((string) ($reviewLike['review_text'] ?? '')));
        $normalizedBody = preg_replace('/\s+/u', ' ', $normalizedBody) ?: $normalizedBody;
        $bodyOrDate = $normalizedBody !== '' ? $normalizedBody : $normalizedDate;

        return sha1(implode('|', [
            $normalizedUrl,
            $normalizedSourceType,
            $normalizedAuthor,
            $normalizedRating,
            $bodyOrDate,
        ]));
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function isMentionRelevantToProfile(array $mention, Profile $profile): bool
    {
        $name = Str::lower(trim((string) $profile->name));
        if ($name === '') {
            return false;
        }
        $profilePhoneDigits = $this->normalizePhoneDigits((string) ($profile->phone ?? ''));
        $profileWebsiteDomain = $this->extractDomain((string) ($profile->website ?? ''));

        $genericTokens = [
            'адвокат', 'юрист', 'юридичний', 'юридична', 'допомога', 'центр',
            'компанія', 'фірма', 'office', 'law', 'legal', 'group', 'service',
            'services', 'help', 'helping', 'консультація', 'консультації',
        ];

        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $name) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
        ));
        $priorityTokens = $this->extractPriorityTokens((string) $profile->name);

        $strongTokens = array_values(array_filter(
            $tokens,
            fn (string $token) => ! in_array($token, $genericTokens, true)
        ));
        if ($priorityTokens !== []) {
            // For brand-like names use the extracted specific phrase tokens first.
            $strongTokens = $priorityTokens;
        }

        $sourceType = Str::lower(trim((string) ($mention['source_type'] ?? 'external_review')));
        $isGoogleMaps = $this->isGoogleMapsMention($mention);
        $haystackParts = [
            (string) ($mention['title'] ?? ''),
            (string) ($mention['summary'] ?? ''),
            (string) ($mention['url'] ?? ''),
        ];

        // For Google Maps, match profile against place-level identity only.
        // Review text may mention other people and creates false positives.
        if (! $isGoogleMaps) {
            $haystackParts[] = (string) ($mention['review_text'] ?? '');
        }

        $haystack = Str::lower(trim(implode(' ', array_filter($haystackParts))));

        if ($haystack === '') {
            return false;
        }

        $mentionPhoneRaw = trim((string) ($mention['source_phone'] ?? data_get($mention, 'raw_payload.source_phone', '')));
        $mentionPhoneDigits = $this->normalizePhoneDigits($mentionPhoneRaw);
        $mentionWebsiteRaw = trim((string) ($mention['source_website'] ?? data_get($mention, 'raw_payload.source_website', '')));
        $mentionWebsiteDomain = $this->extractDomain($mentionWebsiteRaw);
        $isPersonProfile = $this->looksLikePersonProfileName((string) $profile->name);
        $isStrictReviewPlatform = $this->isHighPriorityReviewPlatformMention($mention);

        if ($isStrictReviewPlatform && ! $isGoogleMaps && ($profilePhoneDigits !== null || $profileWebsiteDomain !== '')) {
            $samePhone = $profilePhoneDigits !== null
                && $mentionPhoneDigits !== null
                && (str_contains($profilePhoneDigits, $mentionPhoneDigits) || str_contains($mentionPhoneDigits, $profilePhoneDigits));
            $sameWebsite = $profileWebsiteDomain !== ''
                && $mentionWebsiteDomain !== ''
                && ($profileWebsiteDomain === $mentionWebsiteDomain
                    || str_ends_with($profileWebsiteDomain, '.' . $mentionWebsiteDomain)
                    || str_ends_with($mentionWebsiteDomain, '.' . $profileWebsiteDomain));
            $mentionHasStrongIdentity = $mentionPhoneDigits !== null || $mentionWebsiteDomain !== '';

            if ($mentionHasStrongIdentity && ! $samePhone && ! $sameWebsite) {
                return false;
            }
        }

        if ($isGoogleMaps && $profilePhoneDigits !== null && $mentionPhoneDigits === null && $mentionWebsiteDomain === '') {
            return false;
        }

        if ($isGoogleMaps && $profilePhoneDigits !== null && $mentionPhoneDigits !== null) {
            $samePhone = str_contains($profilePhoneDigits, $mentionPhoneDigits) || str_contains($mentionPhoneDigits, $profilePhoneDigits);
            $sameWebsite = $profileWebsiteDomain !== '' && $mentionWebsiteDomain !== ''
                && ($profileWebsiteDomain === $mentionWebsiteDomain
                    || str_ends_with($profileWebsiteDomain, '.' . $mentionWebsiteDomain)
                    || str_ends_with($mentionWebsiteDomain, '.' . $profileWebsiteDomain));

            if (! $samePhone && ! $sameWebsite) {
                return false;
            }
        }

        if ($profilePhoneDigits !== null && $mentionPhoneDigits !== null) {
            if (str_contains($profilePhoneDigits, $mentionPhoneDigits) || str_contains($mentionPhoneDigits, $profilePhoneDigits)) {
                return true;
            }
        }
        if ($profileWebsiteDomain !== '' && $mentionWebsiteDomain !== '') {
            if ($profileWebsiteDomain === $mentionWebsiteDomain
                || str_ends_with($profileWebsiteDomain, '.' . $mentionWebsiteDomain)
                || str_ends_with($mentionWebsiteDomain, '.' . $profileWebsiteDomain)) {
                return true;
            }
        }
        if ($isGoogleMaps && $profilePhoneDigits !== null && $mentionPhoneDigits !== null) {
            if (str_contains($profilePhoneDigits, $mentionPhoneDigits) || str_contains($mentionPhoneDigits, $profilePhoneDigits)) {
                return true;
            }
        }
        if ($isGoogleMaps && $profileWebsiteDomain !== '' && $mentionWebsiteDomain !== '') {
            if ($profileWebsiteDomain === $mentionWebsiteDomain
                || str_ends_with($profileWebsiteDomain, '.' . $mentionWebsiteDomain)
                || str_ends_with($mentionWebsiteDomain, '.' . $profileWebsiteDomain)) {
                return true;
            }
        }

        if ($isGoogleMaps && $isPersonProfile) {
            $surnameToken = $this->extractLikelySurnameToken((string) $profile->name);
            if ($surnameToken !== null && ! $this->containsTokenFlexible($haystack, $surnameToken)) {
                return false;
            }
        }

        $matchCount = function (array $pool) use ($haystack): int {
            $count = 0;
            foreach ($pool as $token) {
                if ($token !== '' && $this->containsTokenFlexible($haystack, $token)) {
                    $count++;
                }
            }

            return $count;
        };

        if ($isGoogleMaps && $strongTokens !== []) {
            if ($priorityTokens !== []) {
                $priorityMatches = $matchCount($priorityTokens);
                if ($priorityMatches < count($priorityTokens)) {
                    return false;
                }
            }

            $strongMatches = $matchCount($strongTokens);

            // For Google Maps use stricter matching for person names,
            // but avoid over-filtering brand-like entities with generic place titles.
            if (count($strongTokens) >= 2) {
                return $strongMatches >= 2;
            }

            return $strongMatches >= 1;
        }

        if ($strongTokens !== []) {
            if ($priorityTokens !== []) {
                $priorityMatches = $matchCount($priorityTokens);
                if ($priorityMatches < count($priorityTokens)) {
                    return false;
                }
            }

            $strongMatches = $matchCount($strongTokens);

            if (count($strongTokens) >= 2) {
                return $strongMatches >= 2;
            }

            return $strongMatches >= 1;
        }

        return $matchCount($tokens) >= 2;
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function isGoogleMapsMention(array $mention): bool
    {
        $sourceType = Str::lower(trim((string) ($mention['source_type'] ?? '')));
        if ($sourceType === 'google_maps') {
            return true;
        }

        $url = trim((string) ($mention['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = Str::lower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        if ($host === '') {
            return false;
        }

        return str_contains($host, 'share.google')
            || str_contains($host, 'maps.app.goo.gl')
            || (str_contains($host, 'google.') && str_contains($path, '/maps'));
    }

    private function containsTokenFlexible(string $haystack, string $token): bool
    {
        $haystack = Str::lower($haystack);
        $token = Str::lower($token);

        if ($haystack === '' || $token === '') {
            return false;
        }

        if (str_contains($haystack, $token)) {
            return true;
        }

        $asciiHaystack = Str::lower(Str::ascii($haystack));
        $asciiToken = Str::lower(Str::ascii($token));

        if ($asciiHaystack === '' || $asciiToken === '') {
            return false;
        }

        return str_contains($asciiHaystack, $asciiToken);
    }

    private function looksLikePersonProfileName(string $name): bool
    {
        $normalized = Str::lower(trim($name));
        if ($normalized === '') {
            return false;
        }

        if (Str::contains($normalized, ['центр', 'компан', 'фірм', 'бюро', 'group', 'law firm', 'legal', 'тов', 'пп', '«', '"'])) {
            return false;
        }

        $normalized = trim((string) preg_replace('/\b(адвокат|юрист|нотаріус|лікар|стоматолог)\b/iu', ' ', $normalized));
        $parts = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

        if (count($parts) < 2 || count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if (! preg_match('/^\p{L}+$/u', $part)) {
                return false;
            }
        }

        return true;
    }

    private function extractLikelySurnameToken(string $name): ?string
    {
        $normalized = Str::lower(trim((string) preg_replace('/\b(адвокат|юрист|нотаріус|лікар|стоматолог)\b/iu', ' ', $name)));
        $parts = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

        if (count($parts) < 2) {
            return null;
        }

        $first = (string) ($parts[0] ?? '');
        $last = (string) ($parts[count($parts) - 1] ?? '');

        if (count($parts) >= 3 && preg_match('/(ович|евич|йович|вич|івна|ївна|евна)$/u', $last) === 1) {
            return mb_strlen($first) >= 3 ? $first : null;
        }

        if (count($parts) === 2) {
            return mb_strlen($last) >= 3 ? $last : null;
        }

        return mb_strlen($first) >= 3 ? $first : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractPriorityTokens(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $priorityPhrase = '';
        if (preg_match('/["«](.+?)["»]/u', $name, $m)) {
            $priorityPhrase = trim((string) $m[1]);
        }

        if ($priorityPhrase === '') {
            $genericPattern = '/\b(центр|юридичн(ої|ий|а)|допомог(и|а)|адвокат|юрист|компанія|фірма|office|law|legal|group)\b/iu';
            $priorityPhrase = trim((string) preg_replace($genericPattern, ' ', $name));
            $priorityPhrase = trim((string) preg_replace('/\s+/u', ' ', $priorityPhrase));
        }

        $priorityPhrase = Str::lower($priorityPhrase);

        return array_values(array_filter(
            preg_split('/\s+/u', $priorityPhrase) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
        ));
    }

    private function resolveKnownShortUrl(string $url): string
    {
        static $cache = [];

        if (isset($cache[$url])) {
            return $cache[$url];
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if (! str_contains($host, 'share.google') && ! str_contains($host, 'maps.app.goo.gl')) {
            $cache[$url] = $url;

            return $url;
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(4)
                ->head($url);

            $location = trim((string) $response->header('Location', ''));
            $resolved = $location !== '' ? $location : $url;
            $cache[$url] = $resolved;

            return $resolved;
        } catch (\Throwable) {
            $cache[$url] = $url;

            return $url;
        }
    }

    /**
     * @param  array<string, mixed>  $mention
     */
    private function inferRatingFromMention(array $mention): ?int
    {
        $sentiment = (string) ($mention['sentiment'] ?? 'unknown');

        return match ($sentiment) {
            'positive' => 5,
            'mixed' => 3,
            'neutral' => 3,
            'negative' => 2,
            default => null,
        };
    }

    private function sentimentFromRating(float $rating): string
    {
        if ($rating >= 4.0) {
            return 'positive';
        }

        if ($rating <= 2.0) {
            return 'negative';
        }

        return 'neutral';
    }

    private function createExternalMentionPlaceholders(Profile $profile, AiEnrichmentTask $task): void
    {
        $queries = array_filter([
            $profile->name . ' ' . $profile->city . ' відгуки',
            $profile->name . ' reviews',
            $profile->name . ' контакти',
        ]);

        foreach (array_unique($queries) as $query) {
            ExternalProfileMention::create([
                'profile_id' => $profile->id,
                'ai_enrichment_task_id' => $task->id,
                'source_type' => 'search_query',
                'title' => 'Пошуковий запит: ' . $query,
                'summary' => 'Заготовка для майбутнього пошуку зовнішніх згадок. Публікація можлива тільки після ручної модерації.',
                'sentiment' => 'unknown',
                'topic' => 'external_mentions',
                'confidence_score' => 20,
                'status' => 'pending',
                'raw_payload' => ['query' => $query],
            ]);
        }
    }

    private function uniqueProfileSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'profile-' . Str::random(8);
        $slug = $base;
        $counter = 2;

        while (Profile::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function normalizeWebsite(string $website): ?string
    {
        if (blank($website)) {
            return null;
        }

        return Str::of($website)
            ->replace(['https://', 'http://', 'www.'], '')
            ->trim('/')
            ->lower()
            ->toString();
    }

    private function extractDomain(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return '';
        }

        return (string) preg_replace('/^www\./', '', $host);
    }

    /**
     * @param  array<string,mixed>  $suggested
     * @return array<int,string>
     */
    private function extractWebsiteDomainQueries(Profile $profile, array $suggested = []): array
    {
        $domains = [];
        $profileDomain = $this->extractDomain((string) ($profile->website ?? ''));
        if ($profileDomain !== '') {
            $domains[] = $profileDomain;
        }

        $suggestedDomain = $this->extractDomain((string) ($suggested['website'] ?? ''));
        if ($suggestedDomain !== '') {
            $domains[] = $suggestedDomain;
        }

        foreach ((array) ($suggested['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $domain = $this->extractDomain((string) ($source['url'] ?? ''));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        $tokens = [];
        foreach (array_unique($domains) as $domain) {
            $base = (string) preg_replace('/\.(com|ua|org|net|biz|info|co|io)$/i', '', $domain);
            foreach (preg_split('/[\.\-_]+/', $base) ?: [] as $part) {
                $part = Str::lower(trim((string) $part));
                if (mb_strlen($part) >= 4) {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function normalizePhoneDigits(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return $digits !== '' ? $digits : null;
    }

    private function normalizeNameForDuplicate(string $name): string
    {
        $name = Str::lower(trim($name));
        $name = (string) preg_replace('/["«»\'`]/u', ' ', $name);
        $name = (string) preg_replace('/[^[:alnum:]\s]+/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        $genericPattern = '/\b(центр|юридичн(ої|ий|а)|допомог(и|а)|адвокат|юрист|компанія|фірма|office|law|legal|group|services?|help)\b/iu';
        $name = trim((string) preg_replace($genericPattern, ' ', $name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::ascii($name);
    }

    /**
     * @return array<int, string>
     */
    private function buildDuplicateSignatures(string $name, ?string $city, array $item): array
    {
        $signatures = [];
        $cityPart = Str::lower(trim((string) ($city ?? '')));
        $namePart = $this->normalizeNameForDuplicate($name);
        $phonePart = $this->normalizePhoneDigits((string) ($item['phone'] ?? ''));
        $websitePart = $this->normalizeWebsite((string) ($item['website'] ?? ''));

        if ($namePart !== '') {
            $signatures[] = 'name_city:' . sha1($namePart . '|' . $cityPart);
        }

        if ($phonePart !== null) {
            $signatures[] = 'phone:' . $phonePart;
        }

        if ($websitePart !== null) {
            $signatures[] = 'website:' . $websitePart;
        }

        return array_values(array_unique($signatures));
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function sanitizeSuggestedWebsite(array $suggested, array $item): array
    {
        $current = trim((string) ($suggested['website'] ?? ''));
        $profileName = (string) ($suggested['name'] ?? ($item['name'] ?? ''));

        if ($current !== '' && ! $this->isDirectoryWebsite($current) && $this->isWebsiteLikelyRelevant($current, $profileName)) {
            return $suggested;
        }

        // Strong structured signals from mentions/maps/top20 should win over weak directory links.
        $mentionedWebsite = $this->extractWebsiteFromMentionSignals($suggested, $profileName);
        if ($mentionedWebsite !== null) {
            $suggested['website'] = $mentionedWebsite;

            return $suggested;
        }

        $candidates = [];

        foreach ((array) ($suggested['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $foundFields = array_map('strval', (array) ($source['found_fields'] ?? []));
            $sourceType = Str::lower(trim((string) ($source['source_type'] ?? '')));

            $score = 0;
            if (in_array('website', $foundFields, true)) {
                $score += 5;
            }
            if (str_contains($sourceType, 'official') || str_contains($sourceType, 'website')) {
                $score += 4;
            }
            if (! $this->isDirectoryWebsite($url)) {
                $score += 3;
            }

            $candidates[] = ['url' => $url, 'score' => $score];
        }

        $itemWebsite = trim((string) ($item['website'] ?? ''));
        if ($itemWebsite !== '') {
            $candidates[] = ['url' => $itemWebsite, 'score' => 10];
        }

        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']));

        foreach ($candidates as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '' || $this->isDirectoryWebsite($url)) {
                continue;
            }
            if (! $this->isWebsiteLikelyRelevant($url, $profileName)) {
                continue;
            }

            $suggested['website'] = $this->normalizeAbsoluteUrl($url);

            return $suggested;
        }

        // Deterministic fallback: try Top20 company page microdata website.
        $top20Website = $this->discoverWebsiteFromTop20((string) ($suggested['name'] ?? ''), (string) ($suggested['city'] ?? ($item['city'] ?? '')), $suggested);
        if ($top20Website !== null && ! $this->isDirectoryWebsite($top20Website)) {
            $suggested['website'] = $top20Website;

            return $suggested;
        }

        // If only directory links were found, don't set website at all.
        $suggested['website'] = null;

        return $suggested;
    }

    /**
     * Official website contacts are stronger than AI-selected registry/directory contacts.
     *
     * @param  array<string, mixed>  $suggested
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichSuggestedContactsFromOfficialWebsite(array $suggested, array $item): array
    {
        $website = trim((string) ($suggested['website'] ?? ''));
        $profileName = (string) ($suggested['name'] ?? ($item['name'] ?? ''));

        if ($website === '' || $this->isDirectoryWebsite($website) || ! $this->isWebsiteLikelyRelevant($website, $profileName)) {
            return $suggested;
        }

        $normalizedWebsite = $this->normalizeAbsoluteUrl($website);

        try {
            $html = (string) Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 DOVIRA enrichment contact verifier'])
                ->get($normalizedWebsite)
                ->throw()
                ->body();
        } catch (\Throwable) {
            return $suggested;
        }

        if ($html === '') {
            return $suggested;
        }

        $sitePhone = $this->extractBestPhoneFromOfficialWebsiteHtml($html);
        $siteEmail = $this->extractBestEmailFromOfficialWebsiteHtml($html);
        $warnings = array_values((array) ($suggested['warnings'] ?? []));

        if ($sitePhone !== null) {
            $currentPhone = trim((string) ($suggested['phone'] ?? ''));
            $currentDigits = $this->normalizePhoneDigits($currentPhone);
            $siteDigits = $this->normalizePhoneDigits($sitePhone);

            if ($currentPhone !== '' && $currentDigits !== null && $siteDigits !== null && $currentDigits !== $siteDigits) {
                $warnings[] = "Телефон з AI/реєстру '{$currentPhone}' замінено на телефон з офіційного сайту '{$sitePhone}'.";
            }

            $suggested['phone'] = $sitePhone;
        }

        if ($siteEmail !== null && blank($suggested['email'] ?? null)) {
            $suggested['email'] = $siteEmail;
        }

        if ($sitePhone !== null || $siteEmail !== null) {
            $suggested['sources'][] = [
                'source_type' => 'official_website_contacts',
                'title' => 'Контакти з офіційного сайту',
                'url' => $normalizedWebsite,
                'found_fields' => array_values(array_filter([
                    $sitePhone !== null ? 'phone' : null,
                    $siteEmail !== null ? 'email' : null,
                ])),
                'confidence_score' => 96,
            ];
        }

        $suggested['warnings'] = array_values(array_unique(array_filter($warnings)));

        return $suggested;
    }

    private function extractBestPhoneFromOfficialWebsiteHtml(string $html): ?string
    {
        $candidates = [];

        if (preg_match_all('/href=["\']tel:([^"\']+)["\']/iu', $html, $matches)) {
            foreach ($matches[1] as $phone) {
                $candidates[] = html_entity_decode((string) $phone, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/(?:\+?38)?\s*\(?0\d{2}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}/u', $plain, $matches)) {
            foreach ($matches[0] as $phone) {
                $candidates[] = (string) $phone;
            }
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $digits = $this->normalizePhoneDigits((string) $candidate);
            if ($digits === null || strlen($digits) < 10) {
                continue;
            }

            if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                $digits = '38' . $digits;
            }

            if (! str_starts_with($digits, '380') || strlen($digits) !== 12) {
                continue;
            }

            $normalized[$digits] = '+'.$digits;
        }

        return array_values($normalized)[0] ?? null;
    }

    private function extractBestEmailFromOfficialWebsiteHtml(string $html): ?string
    {
        if (preg_match('/href=["\']mailto:([^"\']+)["\']/iu', $html, $m)) {
            $email = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $email = preg_replace('/\?.*$/', '', $email) ?: $email;

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $plain, $m)) {
            $email = trim((string) $m[0]);

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        return null;
    }

    private function isWebsiteLikelyRelevant(string $website, string $profileName): bool
    {
        $domain = $this->extractDomain($website);
        if ($domain === '') {
            return false;
        }

        $priorityTokens = $this->extractPriorityTokens($profileName);
        if ($priorityTokens === []) {
            return true;
        }

        $haystack = Str::lower($domain . ' ' . Str::ascii($domain));
        $matched = 0;
        foreach ($priorityTokens as $token) {
            if ($this->containsTokenFlexible($haystack, $token)) {
                $matched++;
            }
        }

        return $matched >= 1;
    }

    /**
     * @param  array<string,mixed>  $suggested
     */
    private function extractWebsiteFromMentionSignals(array $suggested, string $profileName): ?string
    {
        $priorityTokens = $this->extractPriorityTokens($profileName);

        foreach ((array) ($suggested['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $website = trim((string) ($mention['source_website'] ?? data_get($mention, 'raw_payload.source_website', '')));
            if ($website === '' || $this->isDirectoryWebsite($website)) {
                continue;
            }

            if ($priorityTokens !== []) {
                $haystack = Str::lower($website . ' ' . (string) ($mention['title'] ?? '') . ' ' . (string) ($mention['url'] ?? ''));
                $matched = 0;
                foreach ($priorityTokens as $token) {
                    if ($this->containsTokenFlexible($haystack, $token)) {
                        $matched++;
                    }
                }
                if ($matched === 0) {
                    continue;
                }
            }

            return $this->normalizeAbsoluteUrl($website);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $suggested
     */
    private function discoverWebsiteFromTop20(string $name, string $city, array $suggested): ?string
    {
        $urls = [];

        foreach ((array) ($suggested['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $url = trim((string) ($source['url'] ?? ''));
            if ($url !== '' && str_contains(Str::lower($url), 'top20.ua/')) {
                $urls[] = $this->normalizeAbsoluteUrl($url);
            }
        }

        foreach ((array) ($suggested['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }
            $url = trim((string) ($mention['url'] ?? ''));
            if ($url !== '' && str_contains(Str::lower($url), 'top20.ua/')) {
                $urls[] = $this->normalizeAbsoluteUrl($url);
            }
        }

        if ($urls === []) {
            $stub = new Profile([
                'name' => $name,
                'city' => $city,
                'website' => $suggested['website'] ?? null,
                'phone' => $suggested['phone'] ?? null,
            ]);
            $urls = $this->discoverTop20CandidateUrls($stub, $suggested);
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return null;
        }

        $timeout = max(3, min(20, (int) config('ai_enrichment.top20.timeout', 12)));

        foreach (array_slice($urls, 0, 3) as $url) {
            try {
                $html = (string) Http::timeout($timeout)->get($url)->throw()->body();
            } catch (\Throwable) {
                continue;
            }

            if ($html === '') {
                continue;
            }

            $meta = $this->parseTop20Microdata($html);
            $website = trim((string) ($meta['website'] ?? ''));
            if ($website !== '' && ! $this->isDirectoryWebsite($website)) {
                return $this->normalizeAbsoluteUrl($website);
            }
        }

        return null;
    }

    private function isDirectoryWebsite(string $url): bool
    {
        $normalized = $this->normalizeAbsoluteUrl($url);
        $host = Str::lower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
        $path = Str::lower((string) (parse_url($normalized, PHP_URL_PATH) ?? ''));

        if ($host === '') {
            return true;
        }

        $directoryHosts = [
            '048.ua', '2gis.', 'opendatabot.', 'youcontrol.', 'ua-region.',
            'spravka.', 'list.in.ua', 'ua.kompass.com', 'flagma.', 'poshuk.com',
            'yellowpages.', 'facebook.com', 'instagram.com',
        ];

        foreach ($directoryHosts as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }

        if (str_contains($path, '/catalog') || str_contains($path, '/companies') || str_contains($path, '/company')) {
            return true;
        }

        return false;
    }

    private function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $suggested
     * @return array<string, mixed>
     */
    private function enrichSuggestedLogo(array $suggested): array
    {
        $existingLogo = trim((string) ($suggested['logo_url'] ?? ''));
        if ($existingLogo !== '' && filter_var($existingLogo, FILTER_VALIDATE_URL)) {
            $directoryLogo = $this->isDirectoryWebsite($existingLogo);
            $storedPath = $this->storeRemoteProfileLogo($existingLogo, (string) ($suggested['name'] ?? ''));
            if ($storedPath !== null) {
                $suggested['logo_url'] = $storedPath;

                return $suggested;
            }

            // Directory logos are often hotlink-protected or generic.
            // If we failed to store them locally, continue discovery from official website/social.
            if ($directoryLogo) {
                unset($suggested['logo_url']);
            }

            if (! $this->looksLikeHtmlPageUrl($existingLogo)
                && ! $this->isGenericLogoCandidateUrl($existingLogo)
                && ! $this->isSensitiveLogoUrl($existingLogo)
                && ! $directoryLogo
            ) {
                return $suggested;
            }

            unset($suggested['logo_url']);
        }

        $profileName = trim((string) ($suggested['name'] ?? ''));
        $candidates = [];
        $website = $this->normalizeAbsoluteUrl((string) ($suggested['website'] ?? ''));

        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL)) {
            try {
                $html = (string) Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 DOVIRA enrichment logo finder'])
                    ->get($website)
                    ->throw()
                    ->body();

                $logo = $this->extractLogoUrlFromHtml($html, $website);
                if ($logo !== null) {
                    $candidates[] = [
                        'url' => $logo,
                        'source_type' => 'official_website_logo',
                        'title' => 'Лого з офіційного сайту',
                        'confidence_score' => 92,
                    ];
                }
            } catch (\Throwable) {
                $candidates[] = [
                    'url' => rtrim($website, '/') . '/favicon.ico',
                    'source_type' => 'official_website_favicon',
                    'title' => 'Favicon офіційного сайту',
                    'confidence_score' => 70,
                ];
            }
        }

        foreach ($this->collectSocialLogoCandidates($suggested) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->collectAiLogoCandidates($suggested) as $candidate) {
            $candidates[] = $candidate;
        }

        if ((bool) config('ai_enrichment.google_maps.use_for_logo', false)) {
            $googleCandidate = $this->discoverGoogleMapsLogoCandidate($suggested);
            if ($googleCandidate !== null) {
                $candidates[] = $googleCandidate;
            }
        }

        if ($candidates === [] && $website !== '' && filter_var($website, FILTER_VALIDATE_URL)) {
            $candidates[] = [
                'url' => rtrim($website, '/') . '/favicon.ico',
                'source_type' => 'official_website_favicon',
                'title' => 'Favicon офіційного сайту',
                'confidence_score' => 65,
            ];
        }

        usort($candidates, fn (array $a, array $b) => (int) ($b['confidence_score'] ?? 0) <=> (int) ($a['confidence_score'] ?? 0));

        foreach ($candidates as $candidate) {
            $url = trim((string) ($candidate['url'] ?? ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            if ($this->isGenericLogoCandidateUrl($url)) {
                continue;
            }

            $storedPath = $this->storeRemoteProfileLogo($url, $profileName);
            if ($storedPath === null && $this->isSensitiveLogoUrl($url)) {
                continue;
            }
            if ($storedPath === null && ! $this->isLikelyReachableImageUrl($url)) {
                continue;
            }

            $suggested['logo_url'] = $storedPath ?: $url;
            $suggested['sources'][] = [
                'source_type' => (string) ($candidate['source_type'] ?? 'profile_logo'),
                'title' => (string) ($candidate['title'] ?? 'Зображення профілю'),
                'url' => $this->stripSensitiveLogoUrl($url),
                'found_fields' => ['logo_url'],
                'confidence_score' => (int) ($candidate['confidence_score'] ?? 70),
            ];

            return $suggested;
        }

        return $suggested;
    }

    private function isLikelyReachableImageUrl(string $url): bool
    {
        try {
            $head = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 DOVIRA enrichment image probe'])
                ->head($url);

            if ($head->successful()) {
                $contentType = Str::lower((string) $head->header('Content-Type', ''));
                if (str_contains($contentType, 'image/')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Ignore and fallback to GET probe.
        }

        try {
            $get = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 DOVIRA enrichment image probe'])
                ->get($url);

            if (! $get->successful()) {
                return false;
            }

            $contentType = Str::lower((string) $get->header('Content-Type', ''));

            return str_contains($contentType, 'image/');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $suggested
     * @return array<int, array{url:string,source_type:string,title:string,confidence_score:int}>
     */
    private function collectSocialLogoCandidates(array $suggested): array
    {
        $urls = [];

        foreach ((array) ($suggested['social_links'] ?? []) as $network => $url) {
            $url = $this->normalizeAbsoluteUrl((string) $url);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[(string) $network] = $url;
            }
        }

        foreach ([...((array) ($suggested['sources'] ?? [])), ...((array) ($suggested['external_mentions'] ?? []))] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $url = $this->normalizeAbsoluteUrl((string) ($entry['url'] ?? ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
            if (str_contains($host, 'instagram.')) {
                $urls['instagram'] = $url;
            } elseif (str_contains($host, 'facebook.')) {
                $urls['facebook'] = $url;
            }
        }

        $candidates = [];
        foreach ($urls as $network => $url) {
            $image = $this->extractPagePreviewImage($url);
            if ($image === null) {
                continue;
            }

            $networkLabel = match ((string) $network) {
                'instagram' => 'Instagram',
                'facebook' => 'Facebook',
                default => ucfirst((string) $network),
            };

            $candidates[] = [
                'url' => $image,
                'source_type' => Str::lower((string) $network) . '_profile_image',
                'title' => 'Зображення з профілю ' . $networkLabel,
                'confidence_score' => Str::lower((string) $network) === 'instagram' ? 88 : 82,
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $suggested
     * @return array<int, array{url:string,source_type:string,title:string,confidence_score:int}>
     */
    private function collectAiLogoCandidates(array $suggested): array
    {
        if ((string) config('ai_enrichment.provider', 'local') !== 'openai') {
            return [];
        }

        if (! (bool) config('ai_enrichment.openai.web_search', false) || blank(config('ai_enrichment.openai.api_key'))) {
            return [];
        }

        $name = trim((string) ($suggested['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $context = [
            'name' => $name,
            'category' => $suggested['category'] ?? null,
            'city' => $suggested['city'] ?? null,
            'phone' => $suggested['phone'] ?? null,
            'website' => $suggested['website'] ?? null,
            'social_links' => $suggested['social_links'] ?? [],
            'known_sources' => array_values(array_filter(array_map(
                fn ($entry) => is_array($entry) ? ($entry['url'] ?? null) : null,
                [...((array) ($suggested['sources'] ?? [])), ...((array) ($suggested['external_mentions'] ?? []))]
            ))),
        ];

        $timeout = max(6, min(25, (int) config('ai_enrichment.openai.logo_search_timeout', 12)));

        try {
            $response = Http::withToken((string) config('ai_enrichment.openai.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry((int) config('ai_enrichment.openai.retries', 1), (int) config('ai_enrichment.openai.retry_sleep_ms', 750))
                ->post((string) config('ai_enrichment.openai.endpoint'), [
                    'model' => (string) config('ai_enrichment.openai.model', 'gpt-4.1-mini'),
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => $this->aiLogoSearchSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Find official logo/profile image candidates for this exact DOVIRA profile. Context JSON: '
                                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                    'tools' => [
                        ['type' => (string) config('ai_enrichment.openai.web_search_tool', 'web_search_preview')],
                    ],
                    'tool_choice' => 'auto',
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'dovira_profile_logo_search',
                            'schema' => $this->aiLogoSearchSchema(),
                            'strict' => true,
                        ],
                    ],
                ])
                ->throw()
                ->json();
        } catch (\Throwable) {
            return [];
        }

        $decoded = $this->decodeOpenAiJsonPayload(is_array($response) ? $response : []);
        $candidates = [];

        foreach ((array) ($decoded['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $pageUrl = $this->normalizeAbsoluteUrl((string) ($candidate['page_url'] ?? ''));
            $imageUrl = $this->normalizeAbsoluteUrl((string) ($candidate['image_url'] ?? ''));
            $url = '';

            if ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL) && ! $this->looksLikeHtmlPageUrl($imageUrl)) {
                $url = $imageUrl;
            }

            if ($url === '' && $pageUrl !== '' && filter_var($pageUrl, FILTER_VALIDATE_URL)) {
                $preview = $this->extractPagePreviewImage($pageUrl);
                if ($preview !== null) {
                    $url = $preview;
                }
            }

            if ($url === '' || $this->isGenericLogoCandidateUrl($url)) {
                continue;
            }

            $sourceType = Str::lower(trim((string) ($candidate['source_type'] ?? 'ai_logo_search')));
            $sourceType = preg_replace('/[^a-z0-9_]+/', '_', $sourceType) ?: 'ai_logo_search';

            $candidates[] = [
                'url' => $url,
                'source_type' => 'ai_' . $sourceType,
                'title' => (string) (($candidate['title'] ?? '') ?: 'Зображення профілю, знайдене AI'),
                'confidence_score' => max(55, min(94, (int) ($candidate['confidence_score'] ?? 75))),
            ];
        }

        return $candidates;
    }

    private function aiLogoSearchSystemPrompt(): string
    {
        return implode("\n", [
            'You find a logo or profile image for a Ukrainian business/person profile.',
            'Use web search. Match the exact entity, not only the generic category.',
            'For typos/transliterations, infer likely spelling if evidence is strong, e.g. DENS/N dental office Bершадь may match DentService or DENT SERVICE in Bershad.',
            'Prefer official Instagram/Facebook profile images, official website logo/og:image, then Google Business profile first photo.',
            'Reject directories, unrelated businesses, generic category icons, stock photos, and unrelated people.',
            'Return direct image_url when available. If only a profile/page URL is available, return page_url and leave image_url null.',
            'Never invent URLs. Lower confidence if the match is based only on approximate spelling.',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function aiLogoSearchSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_type' => [
                                'type' => 'string',
                                'description' => 'instagram, facebook, google_maps, official_website, or other precise source type.',
                            ],
                            'title' => ['type' => ['string', 'null']],
                            'page_url' => ['type' => ['string', 'null']],
                            'image_url' => ['type' => ['string', 'null']],
                            'confidence_score' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                            ],
                            'reason' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['source_type', 'title', 'page_url', 'image_url', 'confidence_score', 'reason'],
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['candidates', 'warnings'],
        ];
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array<string,mixed>
     */
    private function decodeOpenAiJsonPayload(array $response): array
    {
        $text = trim((string) ($response['output_text'] ?? ''));

        if ($text === '') {
            foreach ((array) ($response['output'] ?? []) as $output) {
                foreach ((array) data_get($output, 'content', []) as $content) {
                    $type = (string) data_get($content, 'type', '');
                    if (in_array($type, ['output_text', 'text'], true)) {
                        $text .= (string) data_get($content, 'text', '');
                    }
                }
            }
        }

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractPagePreviewImage(string $url): ?string
    {
        try {
            $html = (string) Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 DOVIRA enrichment logo finder',
                    'Accept-Language' => 'uk,en;q=0.8',
                ])
                ->get($url)
                ->throw()
                ->body();
        } catch (\Throwable) {
            return null;
        }

        return $this->extractLogoUrlFromHtml($html, $url);
    }

    /**
     * @param  array<string,mixed>  $suggested
     * @return array{url:string,source_type:string,title:string,confidence_score:int}|null
     */
    private function discoverGoogleMapsLogoCandidate(array $suggested): ?array
    {
        if (! (bool) config('ai_enrichment.google_maps.enabled', false) || blank(config('ai_enrichment.google_maps.api_key'))) {
            return null;
        }

        $name = trim((string) ($suggested['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $apiKey = (string) config('ai_enrichment.google_maps.api_key');
        $language = (string) config('ai_enrichment.google_maps.language_code', 'uk');
        $timeout = max(2, min(10, (int) config('ai_enrichment.google_maps.timeout', 4)));
        $city = trim((string) ($suggested['city'] ?? ''));
        $phone = trim((string) ($suggested['phone'] ?? ''));
        $phoneDigits = $this->normalizePhoneDigits($phone);
        $websiteDomain = $this->extractDomain((string) ($suggested['website'] ?? ''));
        $queries = array_values(array_unique(array_filter([
            trim($name . ' ' . $city),
            trim($name . ' Google Maps'),
            $phone,
            $phoneDigits,
            $websiteDomain,
        ])));

        if ($queries === []) {
            return null;
        }

        $places = [];
        foreach (array_slice($queries, 0, 4) as $query) {
            try {
                $response = Http::withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'places.id,places.name,places.displayName,places.formattedAddress,places.googleMapsUri,places.internationalPhoneNumber,places.nationalPhoneNumber,places.websiteUri,places.photos',
                ])
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->post((string) config('ai_enrichment.google_maps.search_endpoint'), [
                        'textQuery' => $query,
                        'languageCode' => $language,
                        'maxResultCount' => max(1, min(5, (int) config('ai_enrichment.google_maps.max_result_count', 8))),
                        'regionCode' => 'UA',
                    ])
                    ->throw();
            } catch (\Throwable) {
                continue;
            }

            foreach ((array) data_get($response->json(), 'places', []) as $place) {
                if (! is_array($place)) {
                    continue;
                }

                $resourceName = (string) (data_get($place, 'name') ?: (data_get($place, 'id') ? 'places/' . data_get($place, 'id') : ''));
                if ($resourceName !== '') {
                    $places[$resourceName] = $place;
                }
            }
        }

        $profileStub = new Profile([
            'name' => $name,
            'city' => $city,
            'phone' => $phone,
            'website' => $suggested['website'] ?? null,
        ]);

        foreach ($places as $place) {
            if (! $this->isGoogleMapsPlaceRelevantToResolvedProfile($place, $profileStub)) {
                continue;
            }

            $photoName = trim((string) data_get($place, 'photos.0.name', ''));
            if ($photoName === '') {
                continue;
            }

            return [
                'url' => rtrim((string) config('ai_enrichment.google_maps.details_endpoint'), '/') . '/' . ltrim($photoName, '/') . '/media?maxWidthPx=800&maxHeightPx=800&key=' . rawurlencode($apiKey),
                'source_type' => 'google_maps_photo',
                'title' => 'Фото з Google Maps',
                'confidence_score' => 80,
            ];
        }

        return null;
    }

    private function storeRemoteProfileLogo(string $url, string $profileName): ?string
    {
        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 DOVIRA enrichment image fetcher'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->body();
        if ($content === '' || strlen($content) < 256 || strlen($content) > 5 * 1024 * 1024) {
            return null;
        }

        $contentType = Str::lower((string) $response->header('Content-Type', ''));
        if (! str_contains($contentType, 'image/')) {
            return null;
        }

        if (str_contains($contentType, 'svg')) {
            return null;
        }

        $extension = match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };

        $slug = Str::slug(Str::ascii($profileName)) ?: 'profile';
        $path = 'profile-logos/ai-' . $slug . '-' . substr(sha1($url), 0, 12) . '.' . $extension;

        return Storage::disk('public')->put($path, $content) ? $path : null;
    }

    private function stripSensitiveLogoUrl(string $url): string
    {
        if (! $this->isSensitiveLogoUrl($url)) {
            return $url;
        }

        return (string) preg_replace('/([?&])key=[^&]+/i', '$1key=hidden', $url);
    }

    private function isSensitiveLogoUrl(string $url): bool
    {
        return str_contains($url, 'places.googleapis.com/') || str_contains($url, 'key=');
    }

    private function looksLikeHtmlPageUrl(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));

        if (str_contains($host, 'instagram.') || str_contains($host, 'facebook.') || str_contains($host, 'maps.google.')) {
            return true;
        }

        return ! preg_match('/\.(jpe?g|png|webp|gif)(\?.*)?$/i', $path);
    }

    private function isGenericLogoCandidateUrl(string $url): bool
    {
        $lower = Str::lower($url);

        return str_contains($lower, 'google.com/favicon')
            || str_contains($lower, 'gstatic.com/images/branding')
            || str_contains($lower, 'facebook.com/images/fb_icon')
            || str_contains($lower, 'static.xx.fbcdn.net/rsrc.php')
            || str_contains($lower, '/favicon.ico');
    }

    private function extractLogoUrlFromHtml(string $html, string $baseUrl): ?string
    {
        $patterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/iu',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/iu',
            '/<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']/iu',
            '/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $html, $m)) {
                continue;
            }

            $resolved = $this->absolutizeUrl($baseUrl, (string) ($m[1] ?? ''));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function absolutizeUrl(string $baseUrl, string $candidate): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($candidate === '' || str_starts_with(Str::lower($candidate), 'data:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        } elseif (! preg_match('#^https?://#i', $candidate)) {
            if (str_starts_with($candidate, '/')) {
                $parts = parse_url($baseUrl);
                $host = (string) ($parts['host'] ?? '');
                $scheme = (string) ($parts['scheme'] ?? 'https');
                if ($host === '') {
                    return null;
                }
                $candidate = $scheme . '://' . $host . $candidate;
            } else {
                $candidate = rtrim($baseUrl, '/') . '/' . ltrim($candidate, '/');
            }
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
    }
}
