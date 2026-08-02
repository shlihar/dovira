<?php

namespace App\Jobs;

use App\Models\AiEnrichmentTask;
use App\Services\AiProfileEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessAiEnrichmentTask implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId)
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
            (new WithoutOverlapping('ai-enrichment-task:' . $this->taskId))
                ->expireAfter($this->timeout + 120)
                ->dontRelease(),
        ];
    }

    public function handle(AiProfileEnrichmentService $service): void
    {
        $task = AiEnrichmentTask::with('batch')->findOrFail($this->taskId);
        $batch = $task->batch;

        if (! $batch || in_array($batch->status, ['archived', 'cancelled', 'completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        if (! in_array($task->status, ['pending', 'processing', 'error'], true)) {
            return;
        }

        $service->processPreparedTask($task);
    }

    public function failed(\Throwable $exception): void
    {
        $task = AiEnrichmentTask::with('batch')->find($this->taskId);
        if (! $task) {
            return;
        }

        $task->update([
            'status' => 'error',
            'error_message' => $exception->getMessage(),
            'processed_at' => now(),
        ]);

        if ($task->batch) {
            app(AiProfileEnrichmentService::class)->refreshBatchProgress($task->batch);
        }
    }
}
