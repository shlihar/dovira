#!/usr/bin/env bash
set -euo pipefail

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

mkdir -p storage/framework/{cache,sessions,views}

php artisan package:discover --ansi
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link || true
