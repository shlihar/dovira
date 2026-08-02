<?php

namespace App\Jobs;

use App\Models\AiEnrichmentBatch;
use App\Services\AiProfileEnrichmentService;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessAiEnrichmentBatch implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId)
    {
        $this->tries = max(1, (int) config('ai_enrichment.worker.tries', 3));
        $this->backoff = max(1, (int) config('ai_enrichment.worker.backoff', 5));
        $this->timeout = max(120, (int) config('ai_enrichment.worker.timeout', 1800));
        $this->onConnection((string) config('ai_enrichment.connection', config('queue.default', 'database')));
        $this->onQueue((string) config('ai_enrichment.priority_queue', config('ai_enrichment.queue', 'default')));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ai-enrichment-batch:' . $this->batchId))
                ->expireAfter($this->timeout + 120)
                ->dontRelease(),
        ];
    }

    public static function dispatchAndEnsureWorker(int $batchId): void
    {
        $batch = AiEnrichmentBatch::find($batchId);
        if (! $batch) {
            return;
        }

        if ($batch->status === 'processing') {
            return;
        }

        static::dispatch($batchId);

        try {
            app(AiEnrichmentQueueWorkerManager::class)->ensureRunning();
        } catch (\Throwable $exception) {
            Log::warning('Failed to auto-start/kick AI enrichment queue worker.', [
                'batch_id' => $batchId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function handle(AiProfileEnrichmentService $service): void
    {
        $batch = AiEnrichmentBatch::findOrFail($this->batchId);

        if (in_array($batch->status, ['archived', 'cancelled', 'completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        if ($batch->status === 'processing' && $batch->tasks()->exists()) {
            $service->refreshBatchProgress($batch);

            return;
        }

        try {
            $tasks = $service->prepareBatchForQueue($batch);

            foreach ($tasks as $task) {
                ProcessAiEnrichmentTask::dispatch($task->id);
            }
        } catch (\Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = AiEnrichmentBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        if (! in_array($batch->status, ['completed', 'completed_with_errors', 'archived', 'cancelled'], true)) {
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
        }
    }
}
