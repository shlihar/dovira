#!/usr/bin/env bash
set -euo pipefail

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

# Demo-safe storage/db setup for simple hosting without managed DB.
mkdir -p database storage/framework/{cache,sessions,views}
touch database/database.sqlite

php artisan package:discover --ansi
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link || true
