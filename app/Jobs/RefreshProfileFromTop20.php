<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Models\Top20ImportBatch;
use App\Models\Top20ImportItem;
use App\Services\Top20BulkImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshProfileFromTop20 implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 600;

    public int $profileId;

    public ?int $requestedByUserId = null;

    public ?int $batchId = null;

    public ?int $itemId = null;

    public bool $publishProfile = true;

    public function __construct(
        int $profileId,
        ?int $requestedByUserId = null,
        ?int $batchId = null,
        ?int $itemId = null,
        bool $publishProfile = true,
    ) {
        $this->profileId = $profileId;
        $this->requestedByUserId = $requestedByUserId;
        $this->batchId = $batchId;
        $this->itemId = $itemId;
        $this->publishProfile = $publishProfile;

        $this->onConnection((string) config('top20_bulk_import.queue_connection', config('queue.default', 'database')));
        $this->onQueue((string) config('top20_bulk_import.priority_queue', config('top20_bulk_import.queue', 'top20-imports')));
    }

    public function uniqueId(): string
    {
        return 'refresh-profile-from-top20:' . $this->profileId;
    }

    public function handle(Top20BulkImportService $service): void
    {
        $profile = Profile::query()->find($this->profileId);
        if (! $profile) {
            return;
        }

        $batch = $this->batchId ? Top20ImportBatch::query()->find($this->batchId) : null;
        $item = $this->itemId ? Top20ImportItem::query()->find($this->itemId) : null;

        try {
            $result = $service->refreshProfileFromTop20($profile, $this->requestedByUserId, $this->publishProfile);

            if ($item) {
                $item->update([
                    'profile_id' => $profile->id,
                    'status' => 'imported',
                    'reviews_created' => (int) ($result['reviews_created'] ?? 0),
                    'reviews_updated' => (int) ($result['reviews_updated'] ?? 0),
                    'reviews_hidden' => (int) ($result['reviews_hidden'] ?? 0),
                    'profile_was_created' => (bool) ($result['profile_created'] ?? false),
                    'profile_was_updated' => (bool) ($result['profile_updated'] ?? true),
                    'error_message' => null,
                    'processed_at' => now(),
                    'raw_payload' => $result,
                ]);
            }

            if ($batch) {
                $batch->update([
                    'status' => 'completed',
                    'pages_visited' => 1,
                    'cards_found' => 1,
                    'profiles_considered' => 1,
                    'profiles_created' => (int) ($result['profile_created'] ?? 0),
                    'profiles_updated' => (int) ($result['profile_updated'] ?? 0),
                    'profiles_skipped' => 0,
                    'reviews_created' => (int) ($result['reviews_created'] ?? 0),
                    'reviews_updated' => (int) ($result['reviews_updated'] ?? 0),
                    'reviews_hidden' => (int) ($result['reviews_hidden'] ?? 0),
                    'errors_count' => 0,
                    'finished_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($item) {
                $item->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'processed_at' => now(),
                    'raw_payload' => [
                        'error' => $e->getMessage(),
                    ],
                ]);
            }

            if ($batch) {
                $batch->update([
                    'status' => 'failed',
                    'profiles_considered' => 1,
                    'profiles_skipped' => 1,
                    'errors_count' => 1,
                    'finished_at' => now(),
                ]);
            }

            throw $e;
        }
    }
}
