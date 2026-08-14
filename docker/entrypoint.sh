#!/bin/sh
set -eu

mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempts=0
    until php artisan migrate --force --no-interaction; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Database migrations could not complete after 30 attempts."
            exit 1
        fi
        echo "Database is not ready yet; retrying migrations in 2 seconds..."
        sleep 2
    done

    if [ "${SEED_ON_BOOT:-false}" = "true" ]; then
        php artisan db:seed --force --no-interaction
    fi

    php artisan storage:link >/dev/null 2>&1 || true
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
