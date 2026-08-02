#!/usr/bin/env sh
set -eu

php artisan config:cache

exec php -d max_execution_time=0 artisan dovira:ai-enrichment:work
