#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  echo ".env is required in the project directory." >&2
  exit 1
fi
set -a
. ./.env
set +a

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="${1:-backups/$stamp}"
mkdir -p "$out"

echo "Backing up PostgreSQL..."
docker compose exec -T db pg_dump -U "${DB_USERNAME:-happy_family}" -d "${DB_DATABASE:-happy_family}" --clean --if-exists --no-owner --no-privileges > "$out/database.sql"

echo "Backing up public uploads and private import source files..."
docker compose exec -T app sh -c 'mkdir -p /var/www/html/storage/app/public /var/www/html/storage/app/private && tar -C /var/www/html/storage/app -czf - public private' > "$out/storage-app.tar.gz"

cp VERSION "$out/VERSION"
sha256sum "$out/database.sql" "$out/storage-app.tar.gz" "$out/VERSION" > "$out/SHA256SUMS"
echo "Backup complete: $out"
