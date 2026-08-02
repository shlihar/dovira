<?php

namespace App\Services\Queue;

use App\Models\AiEnrichmentBatch;
use App\Models\AiEnrichmentTask;
use App\Jobs\ProcessAiEnrichmentBatch;
use App\Jobs\ProcessAiEnrichmentTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Carbon;

class AiEnrichmentQueueWorkerManager
{
    public function ensureRunning(): void
    {
        if (! (bool) config('ai_enrichment.worker.auto_start', false)) {
            return;
        }

        $this->startManagedWorker();
    }

    public function startManagedWorker(): bool
    {
        if ($this->queueConnectionName() !== 'database') {
            return false;
        }

        if (! function_exists('exec')) {
            return false;
        }

        $lockFile = storage_path('app/ai-enrichment-worker-manager.lock');
        $lockHandle = @fopen($lockFile, 'c+');
        if (! $lockHandle) {
            return false;
        }

        try {
            if (! @flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return $this->isRunning();
            }

            $this->ensureQueueInfrastructure();

            if (! $this->isRunning()) {
                $this->releaseStaleReservedJobs();
            }

            $this->restoreDispatchableAiWork();

            if ($this->isRunning()) {
                return true;
            }

            if ($this->pendingJobsCount() === 0) {
                return false;
            }

            $this->startWorker();

            return $this->isRunning();
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private function restoreDispatchableAiWork(): int
    {
        if ($this->queueConnectionName() !== 'database') {
            return 0;
        }

        $restored = 0;

        AiEnrichmentBatch::query()
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit(max(1, (int) config('ai_enrichment.worker.restore_batch_limit', 25)))
            ->get()
            ->each(function (AiEnrichmentBatch $batch) use (&$restored): void {
                ProcessAiEnrichmentBatch::dispatch($batch->id);
                $restored++;
            });

        $priorityQueue = (string) config('ai_enrichment.priority_queue', 'ai-enrichment-priority');
        if ($this->pendingJobsCountForQueues([$priorityQueue]) === 0) {
            AiEnrichmentTask::query()
                ->where('status', 'pending')
                ->whereHas('batch', fn ($query) => $query->where('status', 'processing'))
                ->orderBy('batch_id')
                ->orderBy('row_number')
                ->limit(max(1, (int) config('ai_enrichment.worker.restore_task_limit', 100)))
                ->get()
                ->each(function (AiEnrichmentTask $task) use (&$restored): void {
                    ProcessAiEnrichmentTask::dispatch($task->id);
                    $restored++;
                });
        }

        return $restored;
    }

    /**
     * @return array<int>
     */
    public function runningWorkerPids(): array
    {
        if (! function_exists('exec')) {
            return [];
        }

        $queues = strtolower($this->workerQueueArgument());
        $connection = strtolower($this->queueConnectionName());
        $patterns = [
            'artisan queue:work ' . $connection . ' --queue=' . $queues,
            'artisan dovira:ai-enrichment:work',
        ];
        $output = [];

        foreach ($patterns as $pattern) {
            $patternOutput = [];
            $exitCode = 1;
            @exec('pgrep -fal ' . escapeshellarg($pattern), $patternOutput, $exitCode);
            if ($exitCode === 0 && $patternOutput !== []) {
                $output = [...$output, ...$patternOutput];
            }
        }

        $pids = [];

        foreach ($output as $line) {
            if (! preg_match('/^\s*(\d+)\s+/', (string) $line, $m)) {
                continue;
            }

            $pid = (int) $m[1];
            if ($pid === getmypid()) {
                continue;
            }

            if ($this->isExpectedWorkerProcess($pid)) {
                $pids[] = $pid;
            }
        }

        return array_values(array_unique($pids));
    }

    public function stopAllWorkers(bool $force = false): int
    {
        $pids = $this->runningWorkerPids();
        if ($pids === []) {
            $this->forgetPid();

            return 0;
        }

        $signal = $force ? 9 : 15;
        foreach ($pids as $pid) {
            @exec('kill -' . $signal . ' ' . (int) $pid);
        }

        if (! $force) {
            usleep(600000);
            $leftovers = $this->runningWorkerPids();
            foreach ($leftovers as $pid) {
                @exec('kill -9 ' . (int) $pid);
            }
        }

        $this->forgetPid();

        return count($pids);
    }

    /**
     * @return array{released_jobs:int,requeued_batches:int,reset_tasks:int}
     */
    public function recoverStuckState(int $stuckMinutes = 15): array
    {
        $this->ensureQueueInfrastructure();
        $releasedJobs = $this->releaseStaleReservedJobs();

        $threshold = Carbon::now()->subMinutes(max(1, $stuckMinutes));
        $requeued = 0;
        $resetTasks = $this->resetProcessingTasks($threshold);

        AiEnrichmentBatch::query()
            ->where('status', 'processing')
            ->where(function ($query) use ($threshold): void {
                $query
                    ->whereNull('started_at')
                    ->orWhere('started_at', '<', $threshold);
            })
            ->orderBy('id')
            ->chunkById(100, function ($batches) use (&$requeued): void {
                foreach ($batches as $batch) {
                    $batch->update([
                        'status' => 'queued',
                        'started_at' => null,
                        'finished_at' => null,
                    ]);
                    ProcessAiEnrichmentBatch::dispatch($batch->id);
                    $requeued++;
                }
            });

        $this->startManagedWorker();

        return [
            'released_jobs' => $releasedJobs,
            'requeued_batches' => $requeued,
            'reset_tasks' => $resetTasks,
        ];
    }

    /**
     * @return array{stopped_workers:int,released_jobs:int,reset_tasks:int,restored_jobs:int,running_workers:int}
     */
    public function restartWorkerAndRecoverQueue(): array
    {
        $this->ensureQueueInfrastructure();

        if ($this->queueConnectionName() !== 'database') {
            return [
                'stopped_workers' => 0,
                'released_jobs' => 0,
                'reset_tasks' => 0,
                'restored_jobs' => 0,
                'running_workers' => count($this->runningWorkerPids()),
            ];
        }

        $stopped = $this->stopAllWorkers(true);
        $released = $this->releaseReservedJobs();
        $resetTasks = $this->resetProcessingTasks();
        $restored = $this->restoreDispatchableAiWork();

        $this->startManagedWorker();

        return [
            'stopped_workers' => $stopped,
            'released_jobs' => $released,
            'reset_tasks' => $resetTasks,
            'restored_jobs' => $restored,
            'running_workers' => count($this->runningWorkerPids()),
        ];
    }

    private function isRunning(): bool
    {
        $pid = $this->readPid();

        if ($pid !== null) {
            if ($this->isExpectedWorkerProcess($pid)) {
                return true;
            }

            $this->forgetPid();
        }

        $foundPid = $this->findRunningWorkerPid();
        if ($foundPid !== null) {
            $this->writePid($foundPid);

            return true;
        }

        return false;
    }

    private function startWorker(): void
    {
        if (! function_exists('exec')) {
            return;
        }

        $phpBinary = PHP_BINARY ?: 'php';
        $projectRoot = base_path();
        $artisan = base_path('artisan');
        $connection = $this->queueConnectionName();
        $queues = $this->workerQueueArgument();
        $sleep = max(1, (int) config('ai_enrichment.worker.sleep', 1));
        $tries = max(1, (int) config('ai_enrichment.worker.tries', 3));
        $timeout = max(60, (int) config('ai_enrichment.worker.timeout', 600));
        $maxTime = max(300, (int) config('ai_enrichment.worker.max_time', 3600));
        $logFile = (string) config('ai_enrichment.worker.log_file', storage_path('logs/ai-enrichment-queue-worker.log'));

        $workerCmd = sprintf(
            '%s -d max_execution_time=0 %s queue:work %s --queue=%s --sleep=%d --tries=%d --timeout=%d --max-time=%d',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($connection),
            escapeshellarg($queues),
            $sleep,
            $tries,
            $timeout,
            $maxTime,
        );

        $command = sprintf(
            // Close inherited descriptors first (especially php artisan serve socket fd),
            // then spawn worker in a clean detached shell.
            'cd %s && (for fd in $(seq 3 256); do eval "exec ${fd}>&-"; done; nohup %s </dev/null >> %s 2>&1 &)',
            escapeshellarg($projectRoot),
            $workerCmd,
            escapeshellarg($logFile),
        );

        @exec($command);

        usleep(250000);
        $pid = $this->findRunningWorkerPid();
        if ($pid !== null) {
            $this->writePid($pid);

            return;
        }

        Log::warning('Failed to auto-start AI enrichment queue worker.');
    }

    private function readPid(): ?int
    {
        $pidFile = (string) config('ai_enrichment.worker.pid_file', storage_path('app/ai-enrichment-queue-worker.pid'));

        if (! is_file($pidFile)) {
            return null;
        }

        $pid = (int) trim((string) @file_get_contents($pidFile));

        return $pid > 0 ? $pid : null;
    }

    private function writePid(int $pid): void
    {
        $pidFile = (string) config('ai_enrichment.worker.pid_file', storage_path('app/ai-enrichment-queue-worker.pid'));
        @file_put_contents($pidFile, (string) $pid);
    }

    private function forgetPid(): void
    {
        $pidFile = (string) config('ai_enrichment.worker.pid_file', storage_path('app/ai-enrichment-queue-worker.pid'));
        @unlink($pidFile);
    }

    private function isExpectedWorkerProcess(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('exec')) {
            return false;
        }

        if (function_exists('posix_kill') && ! @posix_kill($pid, 0)) {
            return false;
        }

        $output = [];
        $exitCode = 1;
        @exec('ps -o command= -p ' . (int) $pid, $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return false;
        }

        $command = strtolower(trim((string) $output[0]));
        $connection = strtolower($this->queueConnectionName());
        $queues = strtolower($this->workerQueueArgument());

        if (str_contains($command, 'pgrep -fal')
            || str_contains($command, ' rg ')
            || str_contains($command, '/bin/zsh -c')
            || str_contains($command, 'exec_command')) {
            return false;
        }

        $isQueueWork = (bool) preg_match('/^(?:[^\s]*\/)?php(?:\d+(?:\.\d+)*)?\b.*\bartisan\b\s+queue:work\b/', $command)
            && str_contains($command, 'queue:work ' . $connection)
            && str_contains($command, '--queue=' . $queues);

        $isManagedWork = (bool) preg_match('/^(?:[^\s]*\/)?php(?:\d+(?:\.\d+)*)?\b.*\bartisan\b\s+dovira:ai-enrichment:work\b/', $command)
            && (! str_contains($command, '--queue=') || str_contains($command, '--queue=' . $queues));

        return $isQueueWork || $isManagedWork;
    }

    private function findRunningWorkerPid(): ?int
    {
        if (! function_exists('exec')) {
            return null;
        }

        $output = [];
        foreach ([
            'artisan queue:work ' . strtolower($this->queueConnectionName()) . ' --queue=' . strtolower((string) config('ai_enrichment.queue', 'default')),
            'artisan queue:work ' . strtolower($this->queueConnectionName()) . ' --queue=' . strtolower($this->workerQueueArgument()),
            'artisan dovira:ai-enrichment:work',
        ] as $pattern) {
            $patternOutput = [];
            $exitCode = 1;
            @exec('pgrep -fal ' . escapeshellarg($pattern), $patternOutput, $exitCode);
            if ($exitCode === 0 && $patternOutput !== []) {
                $output = [...$output, ...$patternOutput];
            }
        }

        foreach ($output as $line) {
            if (! preg_match('/^\s*(\d+)\s+/', (string) $line, $m)) {
                continue;
            }

            $pid = (int) $m[1];
            if ($this->isExpectedWorkerProcess($pid)) {
                return $pid;
            }
        }

        return null;
    }

    public function releaseStaleReservedJobs(): int
    {
        if ($this->queueConnectionName() !== 'database') {
            return 0;
        }

        $this->ensureQueueInfrastructure();
        $workerTimeout = max(60, (int) config('ai_enrichment.worker.timeout', 600));
        $staleSeconds = max(
            $workerTimeout + 120,
            (int) config('ai_enrichment.worker.release_stale_reserved_after_seconds', 1800)
        );
        $staleBefore = time() - $staleSeconds;

        try {
            return $this->queueConnection()->table($this->queueTable())
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<', $staleBefore)
                ->update([
                    'reserved_at' => null,
                    'attempts' => 0,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to release stale reserved queue jobs.', [
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    public function releaseReservedJobs(): int
    {
        if ($this->queueConnectionName() !== 'database') {
            return 0;
        }

        $this->ensureQueueInfrastructure();

        try {
            return $this->queueConnection()->table($this->queueTable())
                ->whereIn('queue', $this->queueNames())
                ->whereNotNull('reserved_at')
                ->update([
                    'reserved_at' => null,
                    'attempts' => 0,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to release reserved queue jobs.', [
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    private function countRunningWorkers(): int
    {
        return count($this->runningWorkerPids());
    }

    public function pendingJobsCount(): int
    {
        $queues = $this->queueNames();

        if ($this->queueConnectionName() !== 'database') {
            try {
                return array_sum(array_map(
                    fn (string $queue): int => (int) Queue::connection($this->queueConnectionName())->size($queue),
                    $queues,
                ));
            } catch (\Throwable $exception) {
                Log::warning('Failed to read AI enrichment queue size.', [
                    'connection' => $this->queueConnectionName(),
                    'queue' => implode(',', $queues),
                    'message' => $exception->getMessage(),
                ]);

                return 0;
            }
        }

        $this->ensureQueueInfrastructure();

        return (int) $this->queueConnection()
            ->table($this->queueTable())
            ->whereIn('queue', $queues)
            ->count();
    }

    public function reservedJobsCount(): int
    {
        if ($this->queueConnectionName() !== 'database') {
            return 0;
        }

        $this->ensureQueueInfrastructure();

        return (int) $this->queueConnection()
            ->table($this->queueTable())
            ->whereIn('queue', $this->queueNames())
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * @return array<int, string>
     */
    public function queueNames(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('top20_bulk_import.priority_queue', 'top20-imports-priority'),
            (string) config('top20_bulk_import.queue', 'top20-imports'),
            (string) config('ai_enrichment.priority_queue', 'ai-enrichment-priority'),
            (string) config('ai_enrichment.queue', 'ai-enrichment'),
            (string) config('ai_enrichment.review_analysis.queue', 'ai-review-analysis'),
        ], fn (string $queue): bool => $queue !== '')));
    }

    public function workerQueueArgument(): string
    {
        return implode(',', $this->queueNames());
    }

    /**
     * @param  array<int, string>  $queues
     */
    private function pendingJobsCountForQueues(array $queues): int
    {
        if ($this->queueConnectionName() !== 'database') {
            return 0;
        }

        $queues = array_values(array_filter($queues, fn (string $queue): bool => $queue !== ''));
        if ($queues === []) {
            return 0;
        }

        return (int) $this->queueConnection()
            ->table($this->queueTable())
            ->whereIn('queue', $queues)
            ->count();
    }

    private function resetProcessingTasks(?Carbon $olderThan = null): int
    {
        $query = AiEnrichmentTask::query()
            ->where('status', 'processing')
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereIn('status', ['queued', 'processing']));

        if ($olderThan !== null) {
            $query->where('updated_at', '<', $olderThan);
        }

        return (int) $query->update([
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    private function queueConnection()
    {
        $connectionName = config('queue.connections.database.connection');
        if (! is_string($connectionName) || $connectionName === '') {
            $connectionName = config('database.default');
        }

        return DB::connection($connectionName);
    }

    private function queueTable(): string
    {
        $table = config('queue.connections.database.table', 'jobs');

        return is_string($table) && $table !== '' ? $table : 'jobs';
    }

    private function ensureQueueInfrastructure(): void
    {
        if ($this->queueConnectionName() !== 'database') {
            return;
        }

        $connectionName = (string) (config('queue.connections.database.connection') ?: '');
        if ($connectionName === 'queue_sqlite') {
            $dbPath = database_path('queue.sqlite');
            if (! is_file($dbPath)) {
                @touch($dbPath);
            }
        }

        try {
            $schema = $this->queueConnection()->getSchemaBuilder();
            if (! $schema->hasTable($this->queueTable())) {
                $schema->create($this->queueTable(), function ($table): void {
                    $table->id();
                    $table->string('queue')->index();
                    $table->longText('payload');
                    $table->unsignedTinyInteger('attempts');
                    $table->unsignedInteger('reserved_at')->nullable();
                    $table->unsignedInteger('available_at');
                    $table->unsignedInteger('created_at');
                });
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to ensure queue infrastructure.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function queueConnectionName(): string
    {
        $connection = config('ai_enrichment.connection', config('queue.default', 'database'));

        return is_string($connection) && $connection !== '' ? $connection : 'database';
    }

}
