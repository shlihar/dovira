#!/usr/bin/env bash
#
# Деплой Dovira на хостинг adm.tools одною командою.
#   ./deploy.sh --dry   — показати, що зміниться, без заливки
#   ./deploy.sh         — залити зміни + скинути кеші Laravel на сервері
#
# Вхід за SSH-ключем (доданий у панелі adm.tools → SSH-доступ → ключі).
# НІКОЛИ не синхронізує: .env, storage/, vendor/ (composer.lock звірений,
# vendor на сервері свій), медіа з завантаженнями (public/media,
# public/catalog-media, public/storage) і локальні sqlite-бази.
# Без --delete: файли на сервері, яких нема локально, не видаляються.

set -euo pipefail
cd "$(dirname "$0")"

HOST="nu625309@nu625309.ftp.tools"
REMOTE="/home/nu625309/mydovira.com/www"
# Консольний php за замовчуванням на сервері — 8.2 і падає на platform_check;
# робочий бінарник для artisan — php84.
PHP_REMOTE="/usr/local/php84/bin/php"

DRY=""
if [[ "${1:-}" == "--dry" || "${1:-}" == "-n" ]]; then
    DRY="-n"
    echo "=== СУХИЙ ПРОГІН (нічого не заливається) ==="
fi

rsync -rlptzv $DRY \
    --exclude '.git/' \
    --exclude '.env' \
    --exclude '.env.*' \
    --exclude '.DS_Store' \
    --exclude '*.log' \
    --exclude 'bootstrap/cache/' \
    --exclude 'public/media/' \
    --exclude 'public/catalog-media/' \
    --exclude 'public/storage' \
    --exclude 'public/tmp/' \
    --exclude 'public/hot' \
    --exclude 'database/*.sqlite*' \
    --exclude 'database/queue.sqlite' \
    app bootstrap artisan composer.json composer.lock \
    config database lang public resources routes \
    "$HOST:$REMOTE/"

if [[ -z "$DRY" ]]; then
    echo "=== Скидаю кеші Laravel на сервері ==="
    ssh "$HOST" "cd $REMOTE && $PHP_REMOTE artisan optimize:clear"
    echo "=== Готово. Перевірка: https://mydovira.com/ ==="
    curl -s -o /dev/null -w "https://mydovira.com/ → HTTP %{http_code}\n" --max-time 20 https://mydovira.com/
fi
