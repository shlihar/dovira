<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Top20ImportBatch;
use App\Services\Top20BulkImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunTop20BulkImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 7200;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $connection = (string) config('top20_bulk_import.queue_connection', config('queue.default', 'database'));
        $queue = (string) config('top20_bulk_import.queue', 'top20-imports');

        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    // No WithoutOverlapping here: a single queue worker already processes jobs on this
    // queue one at a time, so mass-imported cities serialize naturally. WithoutOverlapping
    // was tried (releases a blocked job back to the queue when busy) but the release cycle
    // ran far faster than expected and overflowed the `jobs.attempts` tinyint column
    // (max 255) within minutes, turning every queued import into a hard MySQL failure.
    public function backoff(): array
    {
        return [30, 90, 300];
    }

    public function handle(Top20BulkImportService $service): void
    {
        $categorySlug = (string) ($this->payload['category_slug'] ?? '');
        $listingUrl = trim((string) ($this->payload['listing_url'] ?? ''));
        $categoryKey = (string) ($this->payload['category_key'] ?? '');
        $batch = filled($this->payload['batch_id'] ?? null)
            ? Top20ImportBatch::query()->find((int) $this->payload['batch_id'])
            : null;

        $category = Category::query()->where('slug', $categorySlug)->first();
        if (! $category) {
            if ($batch) {
                $batch->update([
                    'status' => 'failed',
                    'errors_count' => 1,
                    'finished_at' => now(),
                ]);
            }

            throw new \RuntimeException("Top20 import category not found: {$categorySlug}");
        }

        if ($listingUrl === '') {
            if ($batch) {
                $batch->update([
                    'status' => 'failed',
                    'errors_count' => 1,
                    'finished_at' => now(),
                ]);
            }

            throw new \RuntimeException("Top20 listing URL is empty for category key: {$categoryKey}");
        }

        if ($batch) {
            $batch->update([
                'status' => 'processing',
                'started_at' => $batch->started_at ?: now(),
                'finished_at' => null,
                'errors_count' => 0,
            ]);
        }

        Log::info('top20_bulk_import.started', [
            'category_key' => $categoryKey,
            'category_slug' => $categorySlug,
            'listing_url' => $listingUrl,
            'city_code' => $this->payload['city_code'] ?? null,
            'start_page' => $this->payload['start_page'] ?? null,
            'end_page' => $this->payload['end_page'] ?? null,
            'max_pages' => $this->payload['max_pages'] ?? null,
            'max_profiles' => $this->payload['max_profiles'] ?? null,
            'min_reviews' => $this->payload['min_reviews'] ?? null,
            'refresh_existing' => (bool) ($this->payload['refresh_existing'] ?? false),
        ]);

        try {
            $stats = $service->importCategory($listingUrl, $category, [
                'city_code' => $this->payload['city_code'] ?? null,
                'city_name' => $this->payload['city_name'] ?? null,
                'region_name' => $this->payload['region_name'] ?? null,
                'start_page' => $this->payload['start_page'] ?? null,
                'end_page' => $this->payload['end_page'] ?? null,
                'max_pages' => $this->payload['max_pages'] ?? null,
                'max_profiles' => $this->payload['max_profiles'] ?? null,
                'min_reviews' => $this->payload['min_reviews'] ?? 1,
                'subcategory_slug' => $this->payload['subcategory_slug'] ?? null,
                'refresh_existing' => (bool) ($this->payload['refresh_existing'] ?? false),
                'publish_profiles' => (bool) ($this->payload['publish_profiles'] ?? false),
                'dry_run' => false,
                'requested_by_user_id' => $this->payload['requested_by_user_id'] ?? null,
                'import_batch' => $batch,
            ]);

            if ($batch) {
                $batch->update([
                    'status' => count((array) ($stats['errors'] ?? [])) > 0 ? 'completed_with_errors' : 'completed',
                    'pages_visited' => (int) ($stats['pages_visited'] ?? 0),
                    'cards_found' => (int) ($stats['cards_found'] ?? 0),
                    'profiles_considered' => (int) ($stats['profiles_considered'] ?? 0),
                    'profiles_created' => (int) ($stats['profiles_created'] ?? 0),
                    'profiles_updated' => (int) ($stats['profiles_updated'] ?? 0),
                    'profiles_skipped' => (int) ($stats['profiles_skipped'] ?? 0),
                    'reviews_created' => (int) ($stats['reviews_created'] ?? 0),
                    'reviews_updated' => (int) ($stats['reviews_updated'] ?? 0),
                    'reviews_hidden' => (int) ($stats['reviews_hidden'] ?? 0),
                    'errors_count' => count((array) ($stats['errors'] ?? [])),
                    'finished_at' => now(),
                ]);
            }

            Log::info('top20_bulk_import.completed', [
                'category_key' => $categoryKey,
                'category_slug' => $categorySlug,
                'listing_url' => $listingUrl,
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            if ($batch) {
                $batch->update([
                    'status' => 'failed',
                    'errors_count' => max(1, (int) $batch->errors_count),
                    'finished_at' => now(),
                ]);
            }

            throw $e;
        }
    }
}
