<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Models\Profile;
use App\Services\AiEnrichment\Contracts\ProfileEnrichmentProvider;
use App\Services\Database\SqliteToMysqlMigrator;
use App\Services\Top20BulkImportService;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dovira:test-ai-enrichment {name} {--city=Київ} {--category=Адвокати}', function () {
    $category = Category::query()
        ->whereNull('parent_id')
        ->where('name', (string) $this->option('category'))
        ->first();

    $batch = new AiEnrichmentBatch([
        'default_country' => 'Україна',
        'language' => 'uk',
        'options' => [
            'find_website' => true,
            'find_socials' => true,
            'find_reviews' => true,
            'generate_description' => true,
            'generate_seo' => true,
        ],
    ]);

    $result = app(ProfileEnrichmentProvider::class)
        ->enrich($batch, ['name' => (string) $this->argument('name')], $category, (string) $this->option('city'));

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
})->purpose('Test configured DOVIRA AI profile enrichment provider');

Artisan::command('dovira:ai-enrichment:status', function () {
    /** @var AiEnrichmentQueueWorkerManager $manager */
    $manager = app(AiEnrichmentQueueWorkerManager::class);
    $queue = $manager->workerQueueArgument();
    $connection = $manager->queueConnectionName();

    $this->info('AI enrichment queue status');
    $this->line('Connection: ' . $connection);
    $this->line('Queue: ' . $queue);
    $this->line('Running workers: ' . count($manager->runningWorkerPids()));
    $this->line('Queued jobs: ' . $manager->pendingJobsCount());
    $this->line('Reserved jobs: ' . $manager->reservedJobsCount());
    $this->line('Processing batches: ' . AiEnrichmentBatch::query()->where('status', 'processing')->count());
    $this->line('Queued batches: ' . AiEnrichmentBatch::query()->where('status', 'queued')->count());
})->purpose('Show AI enrichment worker, queue, and batch status');

Artisan::command('dovira:ai-enrichment:stop {--force}', function () {
    /** @var AiEnrichmentQueueWorkerManager $manager */
    $manager = app(AiEnrichmentQueueWorkerManager::class);
    $stopped = $manager->stopAllWorkers((bool) $this->option('force'));
    $this->info("Stopped AI enrichment workers: {$stopped}");
})->purpose('Stop all running AI enrichment queue workers');

Artisan::command('dovira:ai-enrichment:kick', function () {
    /** @var AiEnrichmentQueueWorkerManager $manager */
    $manager = app(AiEnrichmentQueueWorkerManager::class);
    $running = $manager->startManagedWorker();
    $this->info('AI enrichment worker ensured');
    $this->line('Queued jobs: ' . $manager->pendingJobsCount());
    $this->line('Reserved jobs: ' . $manager->reservedJobsCount());
    $this->line('Running workers: ' . count($manager->runningWorkerPids()));
    if (! $running) {
        $this->warn('Worker was not started because there are no pending AI jobs or the queue connection is not managed locally.');
    }
})->purpose('Start the managed AI enrichment worker if pending jobs exist');

Artisan::command('dovira:ai-enrichment:work {--once} {--stop-when-empty} {--queue=}', function () {
    /** @var AiEnrichmentQueueWorkerManager $manager */
    $manager = app(AiEnrichmentQueueWorkerManager::class);
    $queue = (string) ($this->option('queue') ?: $manager->workerQueueArgument());

    $this->call('queue:work', array_filter([
        'connection' => $manager->queueConnectionName(),
        '--queue' => $queue,
        '--sleep' => max(1, (int) config('ai_enrichment.worker.sleep', 1)),
        '--tries' => max(1, (int) config('ai_enrichment.worker.tries', 3)),
        '--timeout' => max(60, (int) config('ai_enrichment.worker.timeout', 1800)),
        '--max-time' => max(300, (int) config('ai_enrichment.worker.max_time', 3600)),
        '--once' => (bool) $this->option('once') ?: null,
        '--stop-when-empty' => (bool) $this->option('stop-when-empty') ?: null,
    ], fn ($value) => $value !== null));
})->purpose('Run the configured AI enrichment queue worker');

Artisan::command('dovira:ai-enrichment:recover {--minutes=15}', function () {
    /** @var AiEnrichmentQueueWorkerManager $manager */
    $manager = app(AiEnrichmentQueueWorkerManager::class);
    $minutes = max(1, (int) $this->option('minutes'));

    $result = $manager->restartWorkerAndRecoverQueue();

    $threshold = now()->subMinutes($minutes);
    $requeued = 0;
    AiEnrichmentBatch::query()
        ->where('status', 'processing')
        ->where(function ($query) use ($threshold): void {
            $query->whereNull('started_at')->orWhere('started_at', '<', $threshold);
        })
        ->orderBy('id')
        ->chunkById(100, function ($batches) use (&$requeued): void {
            foreach ($batches as $batch) {
                $batch->update([
                    'status' => 'queued',
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                \App\Jobs\ProcessAiEnrichmentBatch::dispatch($batch->id);
                $requeued++;
            }
        });

    if ($requeued > 0) {
        $manager->startManagedWorker();
    }

    $this->info('AI enrichment recovery completed');
    $this->line('Stopped workers: ' . $result['stopped_workers']);
    $this->line('Released reserved jobs: ' . $result['released_jobs']);
    $this->line('Restored jobs: ' . $result['restored_jobs']);
    $this->line('Running workers: ' . count($manager->runningWorkerPids()));
    $this->line('Requeued stuck batches: ' . $requeued);
})->purpose('Recover stuck AI enrichment queue and requeue stale processing batches');

Artisan::command('dovira:db:import-sqlite {source?} {--connection=} {--truncate}', function () {
    $source = (string) ($this->argument('source') ?: database_path('database.sqlite'));
    $connection = $this->option('connection') ? (string) $this->option('connection') : null;
    $truncate = (bool) $this->option('truncate');

    $this->warn('This command imports data from a SQLite file into the configured destination database.');
    $this->line('Source: ' . $source);
    $this->line('Destination connection: ' . ($connection ?: (string) config('database.default')));

    if (! $truncate) {
        $this->warn('Destination tables will NOT be truncated. Use --truncate for a clean import.');
    }

    /** @var SqliteToMysqlMigrator $migrator */
    $migrator = app(SqliteToMysqlMigrator::class);
    $summary = $migrator->migrate($source, $connection, $truncate);

    $this->info('SQLite import completed.');

    foreach ((array) ($summary['tables'] ?? []) as $table => $count) {
        $this->line(sprintf('%s: %d rows', $table, $count));
    }
})->purpose('Import all data from a SQLite backup into the destination database connection');

Artisan::command('dovira:localize-imported-media {--profile-id=*} {--id-from=} {--id-to=} {--limit=0} {--with-review-avatars}', function () {
    /** @var Top20BulkImportService $service */
    $service = app(Top20BulkImportService::class);
    $profileIds = collect((array) $this->option('profile-id'))
        ->map(fn ($id) => (int) $id)
        ->filter(fn (int $id) => $id > 0)
        ->values();
    $idFrom = filled($this->option('id-from')) ? max(1, (int) $this->option('id-from')) : null;
    $idTo = filled($this->option('id-to')) ? max(1, (int) $this->option('id-to')) : null;
    $limit = max(0, (int) $this->option('limit'));
    $withReviewAvatars = (bool) $this->option('with-review-avatars');

    $query = Profile::query()
        ->where(function ($profiles) use ($withReviewAvatars): void {
            $profiles
                ->where('logo_url', 'like', 'http%')
                ->orWhere('logo_url', 'like', 'top20/%')
                ->orWhere('logo_url', 'like', '/storage/top20/%')
                ->orWhere('banner_url', 'like', 'http%')
                ->orWhere('banner_url', 'like', 'top20/%')
                ->orWhere('banner_url', 'like', '/storage/top20/%')
                ->orWhere('og_image_url', 'like', 'http%')
                ->orWhere('og_image_url', 'like', 'top20/%')
                ->orWhere('og_image_url', 'like', '/storage/top20/%')
                ->orWhere('gallery', 'like', '%http%')
                ->orWhere('gallery', 'like', '%top20/%');
            if ($withReviewAvatars) {
                $profiles->orWhereHas('reviews', function ($reviews): void {
                    $reviews
                        ->where('external_review_author_avatar_url', 'like', 'http%')
                        ->orWhere('external_review_author_avatar_url', 'like', 'top20/%')
                        ->orWhere('external_review_author_avatar_url', 'like', '/storage/top20/%');
                });
            }
        })
        ->orderBy('id');

    if ($profileIds->isNotEmpty()) {
        $query->whereIn('id', $profileIds->all());
    }

    if ($idFrom !== null) {
        $query->where('id', '>=', $idFrom);
    }

    if ($idTo !== null) {
        $query->where('id', '<=', $idTo);
    }

    if ($limit > 0) {
        $query->limit($limit);
    }

    $profilesUpdated = 0;
    $reviewsUpdated = 0;
    $processed = 0;

    $query->chunkById(25, function ($profiles) use ($service, $withReviewAvatars, &$profilesUpdated, &$reviewsUpdated, &$processed): void {
        foreach ($profiles as $profile) {
            $processed++;
            $result = $service->localizePersistedProfileMedia($profile, $withReviewAvatars);
            if (($result['profile_updated'] ?? false) === true) {
                $profilesUpdated++;
            }

            $reviewsUpdated += (int) ($result['reviews_updated'] ?? 0);
            $this->line(sprintf(
                '#%d %s | profile: %s | review avatars: %d',
                $profile->id,
                $profile->name,
                (($result['profile_updated'] ?? false) === true ? 'updated' : 'skip'),
                (int) ($result['reviews_updated'] ?? 0)
            ));
        }
    });

    $this->newLine();
    $this->info('Imported media localization completed');
    $this->line('Profiles processed: ' . $processed);
    $this->line('Profiles updated: ' . $profilesUpdated);
    $this->line('Review avatars updated: ' . $reviewsUpdated);
})->purpose('Download imported profile media into local storage and replace external URLs');

Schedule::command('dovira:ai-enrichment:recover --minutes=20')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('dovira:pro:expire-subscriptions')
    ->hourly()
    ->withoutOverlapping();
