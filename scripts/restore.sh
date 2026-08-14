#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
backup="${1:-}"
if [ -z "$backup" ] || [ ! -f "$backup/database.sql" ]; then
  echo "Usage: CONFIRM_RESTORE=YES scripts/restore.sh <backup-directory>" >&2
  exit 1
fi
if [ ! -f "$backup/storage-app.tar.gz" ] && [ ! -f "$backup/public-storage.tar.gz" ]; then
  echo "Backup directory must contain storage-app.tar.gz (or legacy public-storage.tar.gz)." >&2
  exit 1
fi
if [ "${CONFIRM_RESTORE:-NO}" != "YES" ]; then
  echo "Restore is destructive. Re-run with CONFIRM_RESTORE=YES." >&2
  exit 2
fi
if [ ! -f .env ]; then
  echo ".env is required in the project directory." >&2
  exit 1
fi
set -a
. ./.env
set +a

if [ -f "$backup/SHA256SUMS" ]; then
  (cd "$backup" && sha256sum -c SHA256SUMS)
fi

echo "Stopping background writers..."
docker compose stop worker scheduler

echo "Restoring database..."
cat "$backup/database.sql" | docker compose exec -T db psql -v ON_ERROR_STOP=1 -U "${DB_USERNAME:-happy_family}" -d "${DB_DATABASE:-happy_family}"

if [ -f "$backup/storage-app.tar.gz" ]; then
  echo "Restoring public uploads and private import source files..."
  docker compose exec -T app sh -c 'mkdir -p /var/www/html/storage/app/public /var/www/html/storage/app/private && rm -rf /var/www/html/storage/app/public/* /var/www/html/storage/app/private/*'
  cat "$backup/storage-app.tar.gz" | docker compose exec -T app tar -C /var/www/html/storage/app -xzf -
else
  echo "Restoring legacy public-only storage backup..."
  docker compose exec -T app sh -c 'mkdir -p /var/www/html/storage/app/public && rm -rf /var/www/html/storage/app/public/*'
  cat "$backup/public-storage.tar.gz" | docker compose exec -T app tar -C /var/www/html/storage/app/public -xzf -
fi

docker compose exec -T app php artisan optimize:clear
docker compose start worker scheduler

echo "Restore complete. Verify /health/ready and sign in before reopening traffic."
