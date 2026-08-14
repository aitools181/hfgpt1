#!/bin/sh
set -eu

require_env() {
    name="$1"
    eval "value=\${$name:-}"
    if [ -z "$value" ]; then
        echo "[bootstrap] ERROR: required environment variable $name is empty or missing."
        exit 1
    fi
}

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    require_env APP_KEY
    require_env APP_URL
    require_env DB_PASSWORD
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[bootstrap] Running database migrations..."
    attempts=0
    until php artisan migrate --force --no-interaction; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "[bootstrap] ERROR: database migrations could not complete after 30 attempts."
            exit 1
        fi
        echo "[bootstrap] Database/migration not ready; retrying in 2 seconds (attempt $attempts/30)..."
        sleep 2
    done

    if [ "${SEED_ON_BOOT:-false}" = "true" ]; then
        echo "[bootstrap] Running production-safe seeders..."
        php artisan db:seed --force --no-interaction
    fi

    php artisan storage:link >/dev/null 2>&1 || true

    echo "[bootstrap] Caching Laravel configuration and routes..."
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
