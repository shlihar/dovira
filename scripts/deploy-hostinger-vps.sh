#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
SUPERVISOR_BIN="${SUPERVISOR_BIN:-supervisorctl}"
WORKER_PROGRAM="${WORKER_PROGRAM:-dovira-ai-enrichment}"
SCHEDULER_PROGRAM="${SCHEDULER_PROGRAM:-dovira-scheduler}"
SHARED_DIR="${SHARED_DIR:-}"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

ensure_path() {
    if [[ ! -e "$1" ]]; then
        echo "Required path is missing: $1" >&2
        exit 1
    fi
}

link_shared_path() {
    local shared_path="$1"
    local app_path="$2"

    if [[ ! -e "$shared_path" ]]; then
        echo "Shared path is missing: $shared_path" >&2
        exit 1
    fi

    rm -rf "$app_path"
    ln -sfn "$shared_path" "$app_path"
}

restart_supervisor_program() {
    local program="$1"

    if ! command -v "$SUPERVISOR_BIN" >/dev/null 2>&1; then
        log "Supervisor binary not found, skipping restart for ${program}"
        return 0
    fi

    if ! "$SUPERVISOR_BIN" status "$program" >/dev/null 2>&1; then
        log "Supervisor program ${program} not found, skipping"
        return 0
    fi

    "$SUPERVISOR_BIN" restart "${program}:*" >/dev/null 2>&1 || "$SUPERVISOR_BIN" restart "$program"
}

log "Starting deploy in ${APP_ROOT}"

if [[ -n "$SHARED_DIR" ]]; then
    ensure_path "$SHARED_DIR"
    mkdir -p "$SHARED_DIR/bootstrap/cache" "$SHARED_DIR/storage"
    link_shared_path "$SHARED_DIR/.env" "$APP_ROOT/.env"
    link_shared_path "$SHARED_DIR/storage" "$APP_ROOT/storage"
    mkdir -p "$APP_ROOT/bootstrap"
    link_shared_path "$SHARED_DIR/bootstrap/cache" "$APP_ROOT/bootstrap/cache"
fi

ensure_path "$APP_ROOT/artisan"

cd "$APP_ROOT"

log "Installing PHP dependencies"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

log "Installing Node dependencies"
"$NPM_BIN" ci

log "Building frontend assets"
"$NPM_BIN" run build

log "Removing Vite hot file for production"
rm -f public/hot

log "Preparing Laravel runtime directories"
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

log "Running database migrations"
"$PHP_BIN" artisan migrate --force

log "Refreshing application cache"
"$PHP_BIN" artisan storage:link || true
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

log "Restarting queue workers"
"$PHP_BIN" artisan queue:restart
restart_supervisor_program "$WORKER_PROGRAM"
restart_supervisor_program "$SCHEDULER_PROGRAM"

log "Fixing ownership for runtime directories"
chown -R "${APP_USER}:${APP_GROUP}" storage bootstrap/cache

log "Deploy completed"
