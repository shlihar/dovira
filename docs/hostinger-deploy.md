# Hostinger Deploy Guide for Dovira

## What This Project Needs

This project is not just a plain Laravel site:

- Laravel 12 + Filament admin
- Vite build for frontend assets
- background queue worker for AI enrichment
- scheduled recovery task every 5 minutes

Relevant project entry points:

- [composer.json](/Users/andriishlikhar/Desktop/project/dovira/composer.json:37)
- [routes/console.php](/Users/andriishlikhar/Desktop/project/dovira/routes/console.php:76)
- [docs/ai-enrichment-production.md](/Users/andriishlikhar/Desktop/project/dovira/docs/ai-enrichment-production.md:1)

Because of that, the best production target is a VPS, not a basic shared hosting setup.

## Recommendation

### Best option: Hostinger VPS

Use VPS if you want:

- stable queue workers
- scheduler running continuously
- Redis for queue/cache/session
- easy `git pull` deploys
- predictable behavior under load

### Acceptable only as a temporary fallback: Hostinger Web/Cloud hosting

You can run the web part of Laravel there, but this project has queue and scheduler requirements. On shared-style hosting you will likely hit one or more of these limits:

- no proper process manager for long-running workers
- harder queue reliability
- SQLite locking under concurrent jobs
- deploy steps become manual and fragile

If AI enrichment and queued jobs matter in production, do not treat Web/Cloud hosting as the final architecture.

## Recommended Server Layout

### VPS layout

Use a release-based structure:

```text
/var/www/dovira
├── current -> /var/www/dovira/releases/2026-06-12-120000
├── releases
│   ├── 2026-06-12-120000
│   └── 2026-06-15-091500
└── shared
    ├── .env
    ├── storage
    └── bootstrap/cache
```

Nginx or Apache document root must point to:

```text
/var/www/dovira/current/public
```

This is the cleanest setup for rollback and low-risk deploys.

### Hostinger Web/Cloud layout

If you must stay on regular hosting, keep the Laravel app one level above `public_html`.

Typical layout from Hostinger docs:

```text
/home/uXXXXX/domains/your-domain.com
├── dovira
│   ├── app
│   ├── bootstrap
│   ├── config
│   ├── database
│   ├── public
│   ├── resources
│   ├── routes
│   ├── storage
│   ├── vendor
│   └── artisan
└── public_html
```

Then:

1. keep the full Laravel project in `dovira/`
2. copy or move only the contents of `dovira/public/` into `public_html/`
3. adjust `public_html/index.php` so it points to `../dovira/vendor/autoload.php` and `../dovira/bootstrap/app.php`

Do not place the whole Laravel app directly inside `public_html`.

## Production Stack for This Repo

Preferred env for production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
AI_ENRICHMENT_QUEUE_CONNECTION=redis
AI_ENRICHMENT_AUTO_START_WORKER=false
```

If Redis is unavailable, use MySQL for primary data and database queue only as a compromise. Avoid SQLite in production for this app.

## Deploy Strategy

### Best workflow

1. Store code in GitHub.
2. Deploy to Hostinger over SSH, not by dragging files in File Manager.
3. Keep `.env` only on the server.
4. Build assets on the server or upload already built `public/build`.
5. Restart queue workers after each deploy.

### Minimal manual deploy on VPS

Inside the current release:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
sudo supervisorctl restart dovira-ai-enrichment:*
```

If you use a release-based structure, run these in the new release folder and switch the `current` symlink only after the build succeeds.

## Required Background Processes

This repo defines:

- worker command: `php -d max_execution_time=0 artisan dovira:ai-enrichment:work`
- scheduled recovery task every 5 minutes

See:

- [routes/console.php](/Users/andriishlikhar/Desktop/project/dovira/routes/console.php:76)
- [routes/console.php](/Users/andriishlikhar/Desktop/project/dovira/routes/console.php:132)

### VPS worker

Run the worker under Supervisor:

```ini
[program:dovira-ai-enrichment]
command=php -d max_execution_time=0 /var/www/dovira/current/artisan dovira:ai-enrichment:work
directory=/var/www/dovira/current
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/dovira/shared/storage/logs/ai-enrichment-worker.log
stopwaitsecs=1900
```

### VPS scheduler

Either use cron:

```cron
* * * * * cd /var/www/dovira/current && php artisan schedule:run >> /dev/null 2>&1
```

or run:

```bash
php artisan schedule:work
```

under Supervisor as a separate process.

## Shared Hosting Reality Check

If you deploy this app on regular Hostinger Web/Cloud hosting:

- the site itself may open
- Filament may work
- static assets may load
- cron can run `php artisan schedule:run`

But long-running queue workers are the weak point. That is the reason VPS is the safer target for this repository.

## Easiest Low-Risk Setup

If you want the simplest setup that will still scale:

1. use Hostinger VPS
2. Ubuntu 22.04 or newer
3. Nginx
4. PHP 8.2+
5. MySQL or PostgreSQL
6. Redis
7. Supervisor for worker and scheduler
8. deploy with Git over SSH

## Hostinger Notes

These points were verified against Hostinger documentation on June 12, 2026:

- regular Hostinger hosting supports SSH on eligible plans
- Hostinger supports cron jobs from hPanel
- Hostinger documents Laravel on Web/Cloud hosting by keeping the app above `public_html`
- Hostinger also recommends VPS for full Laravel server control
- Hostinger Git deployment exists, but this project still needs post-deploy Laravel commands

## Practical Conclusion

For this repository:

- choose VPS if this is a real production environment
- use `public` as the web root on VPS
- keep `.env`, `storage`, and logs outside releases
- deploy changes with `git pull` plus Laravel build/cache commands
- manage worker and scheduler separately from the web process

If needed, the next step is to add:

- a ready-to-run `scripts/deploy-hostinger-vps.sh`
- a Supervisor config template
- an Nginx site config for your domain

## Ready-To-Use Files In This Repo

This repo now includes a Hostinger VPS deployment bundle:

- [scripts/deploy-hostinger-vps.sh](/Users/andriishlikhar/Desktop/project/dovira/scripts/deploy-hostinger-vps.sh:1)
- [deploy/hostinger-vps/.env.production.example](/Users/andriishlikhar/Desktop/project/dovira/deploy/hostinger-vps/.env.production.example:1)
- [deploy/hostinger-vps/nginx/dovira.conf](/Users/andriishlikhar/Desktop/project/dovira/deploy/hostinger-vps/nginx/dovira.conf:1)
- [deploy/hostinger-vps/supervisor/dovira-ai-enrichment.conf](/Users/andriishlikhar/Desktop/project/dovira/deploy/hostinger-vps/supervisor/dovira-ai-enrichment.conf:1)
- [deploy/hostinger-vps/supervisor/dovira-scheduler.conf](/Users/andriishlikhar/Desktop/project/dovira/deploy/hostinger-vps/supervisor/dovira-scheduler.conf:1)
- [deploy/hostinger-vps/cron/laravel-scheduler.cron](/Users/andriishlikhar/Desktop/project/dovira/deploy/hostinger-vps/cron/laravel-scheduler.cron:1)

Use Supervisor scheduler or the cron file. Do not use both at the same time.

## First Deploy Walkthrough

### 1. Install server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server git unzip curl supervisor
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-xml php8.2-mbstring php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl php8.2-gd php8.2-redis
```

Install Composer and Node.js 20 after that.

### 2. Create directories

```bash
sudo mkdir -p /var/www/dovira/releases
sudo mkdir -p /var/www/dovira/shared/storage
sudo mkdir -p /var/www/dovira/shared/bootstrap/cache
sudo chown -R $USER:$USER /var/www/dovira
```

### 3. Clone the project

```bash
cd /var/www/dovira/releases
git clone YOUR_REPOSITORY_URL 2026-06-12-001
ln -sfn /var/www/dovira/releases/2026-06-12-001 /var/www/dovira/current
cd /var/www/dovira/current
```

### 4. Create production env

```bash
cp deploy/hostinger-vps/.env.production.example /var/www/dovira/shared/.env
nano /var/www/dovira/shared/.env
```

Set real values for:

- `APP_URL`
- DB credentials
- SMTP credentials
- `OPENAI_API_KEY`
- `GOOGLE_MAPS_API_KEY`

### 5. Prepare MySQL database

```sql
CREATE DATABASE dovira CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dovira'@'127.0.0.1' IDENTIFIED BY 'change_me';
GRANT ALL PRIVILEGES ON dovira.* TO 'dovira'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### 6. Run first deploy

```bash
chmod +x scripts/deploy-hostinger-vps.sh
SHARED_DIR=/var/www/dovira/shared ./scripts/deploy-hostinger-vps.sh
```

The deploy script will:

- link `.env`
- link shared `storage`
- install Composer dependencies
- install Node dependencies
- build Vite assets
- run migrations
- refresh Laravel caches
- restart queue workers if Supervisor is already configured

### 7. Install Nginx config

Copy the template:

```bash
sudo cp deploy/hostinger-vps/nginx/dovira.conf /etc/nginx/sites-available/dovira
sudo nano /etc/nginx/sites-available/dovira
```

Replace:

- `example.com`
- `www.example.com`
- PHP socket path if your server uses another PHP version

Then enable it:

```bash
sudo ln -sfn /etc/nginx/sites-available/dovira /etc/nginx/sites-enabled/dovira
sudo nginx -t
sudo systemctl reload nginx
```

### 8. Install Supervisor configs

```bash
sudo cp deploy/hostinger-vps/supervisor/dovira-ai-enrichment.conf /etc/supervisor/conf.d/
sudo cp deploy/hostinger-vps/supervisor/dovira-scheduler.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

If you prefer cron for scheduler instead of Supervisor:

```bash
crontab -e
```

and paste:

```cron
* * * * * cd /var/www/dovira/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

In that case do not enable `dovira-scheduler.conf`.

### 9. Enable HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

## Regular Deploy Workflow

For the current release:

```bash
cd /var/www/dovira/current
git pull origin main
SHARED_DIR=/var/www/dovira/shared ./scripts/deploy-hostinger-vps.sh
```

## Health Checks

After deploy, verify:

```bash
php artisan about
php artisan migrate:status
php artisan dovira:ai-enrichment:status
sudo supervisorctl status
sudo systemctl status nginx
sudo systemctl status php8.2-fpm
sudo systemctl status redis-server
```
