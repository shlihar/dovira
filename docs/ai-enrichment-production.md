# AI Enrichment Production Setup

AI enrichment must run as a background workload. Web requests should only create batches and dispatch jobs. Workers must be managed by the hosting process manager.

## Recommended Production Env

Use PostgreSQL for primary DB and Redis for queues/cache/sessions:

```env
DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=redis
CACHE_STORE=redis

QUEUE_CONNECTION=redis
AI_ENRICHMENT_QUEUE_CONNECTION=redis
AI_ENRICHMENT_QUEUE=ai-enrichment
AI_ENRICHMENT_AUTO_START_WORKER=false
AI_ENRICHMENT_QUEUE_WORKER_PROCESSES=3
AI_ENRICHMENT_QUEUE_WORKER_SLEEP=1
AI_ENRICHMENT_QUEUE_WORKER_TRIES=3
AI_ENRICHMENT_QUEUE_WORKER_TIMEOUT=1800
AI_ENRICHMENT_QUEUE_WORKER_MAX_TIME=3600
REDIS_QUEUE_RETRY_AFTER=2400
```

Why:

- `AI_ENRICHMENT_AUTO_START_WORKER=false` prevents PHP web requests from starting shell processes.
- PostgreSQL + Redis avoids SQLite locking under concurrent jobs.
- `REDIS_QUEUE_RETRY_AFTER` must be greater than worker timeout, otherwise long AI jobs can be retried while still running.

## Render Blueprint (Production)

This repo includes a production blueprint:

- [render.production.yaml](/Users/andriishlikhar/Desktop/project/dovira/render.production.yaml)
- [scripts/render-start-web.sh](/Users/andriishlikhar/Desktop/project/dovira/scripts/render-start-web.sh)
- [scripts/render-start-worker.sh](/Users/andriishlikhar/Desktop/project/dovira/scripts/render-start-worker.sh)

It provisions:

1. `dovira-web` (web service)
2. `dovira-queue-worker` (AI/background worker)
3. `dovira-scheduler` (`php artisan schedule:work`)
4. `dovira-db` (PostgreSQL)
5. `dovira-redis` (key value)

After first deploy, run migrations once:

```bash
php artisan migrate --force
```

## Worker Command

Run the configured worker with:

```bash
php -d max_execution_time=0 artisan dovira:ai-enrichment:work
```

This wraps Laravel `queue:work` and uses `AI_ENRICHMENT_QUEUE_CONNECTION`, `AI_ENRICHMENT_QUEUE`, timeout, tries, sleep and max-time from config.

## Supervisor Example

Create `/etc/supervisor/conf.d/dovira-ai-enrichment.conf`:

```ini
[program:dovira-ai-enrichment]
process_name=%(program_name)s_%(process_num)02d
command=php -d max_execution_time=0 /var/www/dovira/artisan dovira:ai-enrichment:work
directory=/var/www/dovira
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/www/dovira/storage/logs/ai-enrichment-worker.log
stopwaitsecs=1900
```

Apply:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart dovira-ai-enrichment:*
```

Start with `numprocs=2` or `3`. Increase only after checking OpenAI/Google rate limits and server CPU/RAM.

## Deploy Steps

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
sudo supervisorctl restart dovira-ai-enrichment:*
```

## Operations

Status:

```bash
php artisan dovira:ai-enrichment:status
```

Recover stale local database queue state:

```bash
php artisan dovira:ai-enrichment:recover --minutes=15
```

Stop local auto-started workers:

```bash
php artisan dovira:ai-enrichment:stop --force
```

On production with Redis, recovery should usually be `queue:restart` plus Supervisor restart:

```bash
php artisan queue:restart
sudo supervisorctl restart dovira-ai-enrichment:*
```

On Render (worker service), restart the worker service after `queue:restart`.

## Automatic Recovery

Scheduler now runs:

```bash
php artisan dovira:ai-enrichment:recover --minutes=20
```

every 5 minutes via Laravel Scheduler.  
This resets stale `processing` tasks and re-queues stale batches without manual intervention.

## Speed Modes

Use batch option `speed_mode`:

- `fast`: fastest, minimal secondary searches.
- `standard`: default, balanced.
- `deep`: slowest, best for difficult profiles or when sources are missing.

Recommended workflow for large imports:

1. Run `fast` or `standard` for the full list.
2. Re-run only weak/no-source profiles in `deep` mode.
3. Keep worker count conservative until external API limits are measured.
