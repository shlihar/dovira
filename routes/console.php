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

// Пошуковий індекс профілів (запити за потребою: «вакцинація тварин»,
// «виплати за загиблих»). Збирає назву, категорії, послуги й ключові слова з
// відгуків у profile_search_terms; на сервері повний прогін ~20 секунд.
Schedule::command('dovira:search:rebuild --reviews=200')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();

// Перебудова збігів для сервісних посадкових (LIKE по 700 тис. відгуків): десятки
// LIKE-запитів по відгуках не мають виконуватись у веб-запиті.
Schedule::command('dovira:service-landings:rebuild')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->runInBackground();

// Прогрів кешу головної кожні 4 год (TTL 6 год): щоб реальний відвідувач
// ніколи не платив ~8 с за холодну перебудову важких секцій.
Schedule::command('dovira:warm-home')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Щоденний диспетчер розсилок: спільний ліміт ~150 листів/день ділиться за
// пріоритетом — спершу гарячі сегменти (перегляди), потім другі дотики з
// паузою >=3 днів, і рештою добирається холодна база «Знайомство».
// Дедуп по email_logs гарантує: двічі той самий лист нікому не піде.
Artisan::command('dovira:outreach:daily {--cap=150} {--dry-run}', function () {
    $cap = max(1, (int) $this->option('cap'));
    $dryRun = (bool) $this->option('dry-run');
    // Адресатів активних кампаній «Статистика» і «top-profiles» не чіпаємо
    // іншими листами (теми з «%» — шаблони, бо містять назву профілю).
    $excludeStats = [
        'Статистика вашого профілю на Dovira за 30 днів',
        'Нагадуємо: статистика вашого профілю на Dovira',
        'Хто керує сторінкою%',
        '%на DOVIRA — а сторінкою ніхто не керує',
    ];

    $steps = [
        // 1. Другий дотик «Перегляди» — найгарячіший фолоу-ап (3+ днів, без переходу).
        ['label' => 'views · дотик 2', 'params' => ['--sequence' => 'views', '--touch' => 2, '--audience' => 'emailed', '--only-not-visited' => true, '--views-min' => 5, '--min-days-since' => 3]],
        // 2. Перший дотик «Перегляди» — нові профілі, що набрали >=5 переглядів.
        ['label' => 'views · дотик 1', 'params' => ['--sequence' => 'views', '--touch' => 1, '--audience' => 'new', '--views-min' => 5]],
        // 3. Другий дотик «Знайомство» (3+ днів після першого, без переходу).
        ['label' => 'intro · дотик 2', 'params' => ['--sequence' => 'intro', '--touch' => 2, '--audience' => 'emailed', '--only-not-visited' => true, '--min-days-since' => 3, '--exclude-subject' => $excludeStats]],
        // 4. Холодна база: перший дотик «Знайомство» — добирає решту ліміту.
        ['label' => 'intro · дотик 1', 'params' => ['--sequence' => 'intro', '--touch' => 1, '--audience' => 'new', '--exclude-subject' => $excludeStats]],
    ];

    $totalSent = 0;
    foreach ($steps as $step) {
        if ($cap <= 0) {
            break;
        }
        $params = $step['params'] + ['--category' => 'all', '--limit' => $cap];
        if ($dryRun) {
            $params['--dry-run'] = true;
        }
        $this->info('▶ ' . $step['label'] . ' (ліміт ' . $cap . ')');
        Artisan::call('dovira:outreach:email-campaign', $params, $this->output);
        $sent = \App\Console\Commands\SendOutreachEmailCampaign::$lastSentCount;
        $cap -= $sent;
        $totalSent += $sent;
    }

    $this->newLine();
    $this->info(($dryRun ? '[dry-run] ' : '') . 'Диспетчер: разом ' . $totalSent . ' листів, невикористаний ліміт ' . max(0, $cap) . '.');
})->purpose('Щоденна email-розсилка: гарячі сегменти + фолоу-апи + холодна база в межах спільного ліміту');

Schedule::command('dovira:outreach:daily')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Post-claim nurture: щодня рухаємо не-PRO профілі по листах (day 2/5/9/14).
Schedule::command('dovira:pro:nurture-claimed')
    ->dailyAt('10:00')
    ->withoutOverlapping();

// Дайджест «Вас шукали»: раз на місяць, 1-го числа.
Schedule::command('dovira:pro:views-digest')
    ->monthlyOn(1, '10:00')
    ->withoutOverlapping();

// Нічний перерахунок рейтингів і популярності: категорійні середні → ваги
// відгуків → рейтинг + позиція, дзеркалиться в profiles. Бейдж «Dovira
// рекомендує» перераховується разом (з відстрочкою втрати 14 днів).
Schedule::command('rating:recalculate')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Страховка від загублених monopay-вебхуків: кожні 30 хв звіряємо pending-
// замовлення зі статусом інвойсу (кейс 27.08: оплата пройшла, вебхук зник,
// PRO не активувався). Успіх → штатний фулфілмент; expired/failure → failed.
// Кожні 5 хв із min-age=3: клієнт, чий вебхук загубився, чекає на PRO
// хвилини, а не пів години (кейс 14.09.2026 — оплата без активації).
Schedule::command('dovira:payments:reconcile --min-age=3')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Автопродовження PRO: списання зі збереженої картки за день до завершення
// підписки (ретраї щодня, грейс 3 дні — деталі в команді).
Schedule::command('dovira:pro:renew-subscriptions')
    ->dailyAt('09:30')
    ->withoutOverlapping();

// Нагадування про завершення PRO — хвилі за 7/3/1 день до ends_at.
// Дедуп по темі листа в email_logs; режим міграції — PRO_RENEWAL_MANUAL_MODE.
Schedule::command('dovira:pro:renewal-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();

// ЗУПИНЕНО 14.09.2026. Кампанія стверджувала «відгук без вашої відповіді»,
// але імпорт Google бере лише відгуки й НЕ бере відповіді власника — бізнеси,
// що вже відповіли у Google, отримували докір за бездіяльність (скарга
// «Мережі права»). Увімкнути лише після імпорту відповідей власника.
// Schedule::command('dovira:outreach:negative-backlog --days=3650 --views-min=0 --limit=60')
//     ->dailyAt('10:15')
//     ->withoutOverlapping();

// Вертикаль «Адвокати» (станом на 13.09: 1 752 без першого дотику; досьє-
// послідовність по своїй аудиторії вже відпрацьована — тут загальна email-
// послідовність e1→e2→e3). Фолоу-апи йдуть першими, добовий обсяг ≤200.
// Дедуп по темі в email_logs усередині команди.
Schedule::command('dovira:outreach:email-campaign --sequence=email --touch=3 --audience=emailed --only-not-visited --min-days-since=7 --category=Адвокати --limit=50')
    ->dailyAt('11:00')
    ->withoutOverlapping();
Schedule::command('dovira:outreach:email-campaign --sequence=email --touch=2 --audience=emailed --only-not-visited --min-days-since=3 --category=Адвокати --limit=50')
    ->dailyAt('11:20')
    ->withoutOverlapping();
Schedule::command('dovira:outreach:email-campaign --sequence=email --touch=1 --audience=new --category=Адвокати --limit=100')
    ->dailyAt('11:40')
    ->withoutOverlapping();

// Разова реєстрація вебхука Telegram-бота (сповіщення власників: /start, /stop).
// Потрібні env: TELEGRAM_BOT_TOKEN і TELEGRAM_WEBHOOK_SECRET.
Artisan::command('dovira:telegram-set-webhook', function () {
    $token = (string) config('services.telegram.bot_token');
    $secret = (string) config('services.telegram.webhook_secret');
    if ($token === '' || $secret === '') {
        $this->error('Порожній TELEGRAM_BOT_TOKEN або TELEGRAM_WEBHOOK_SECRET у .env.');

        return 1;
    }

    $response = \Illuminate\Support\Facades\Http::asForm()->post("https://api.telegram.org/bot{$token}/setWebhook", [
        'url' => route('webhooks.telegram'),
        'secret_token' => $secret,
        'allowed_updates' => json_encode(['message']),
    ]);

    $this->line($response->body());

    return $response->json('ok') === true ? 0 : 1;
})->purpose('Зареєструвати вебхук Telegram-бота для сповіщень власників');
