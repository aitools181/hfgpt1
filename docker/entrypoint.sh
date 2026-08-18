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

validate_app_key() {
    # Laravel's default AES-256-CBC cipher requires a 32-byte key. Accept the
    # normal base64: form or a raw 32-byte key and fail deployment early rather
    # than serving intermittent 500 errors from cookie/session encryption.
    php -r '
        $key=(string)getenv("APP_KEY");
        if (str_starts_with($key,"base64:")) {
            $decoded=base64_decode(substr($key,7), true);
            if ($decoded === false || strlen($decoded) !== 32) { fwrite(STDERR,"[bootstrap] ERROR: APP_KEY must decode to exactly 32 bytes.\n"); exit(1); }
            exit(0);
        }
        if (strlen($key) !== 32) { fwrite(STDERR,"[bootstrap] ERROR: raw APP_KEY must be exactly 32 bytes.\n"); exit(1); }
    '
}

validate_app_url() {
    case "${APP_URL:-}" in
        http://*|https://*) ;;
        *) echo "[bootstrap] ERROR: APP_URL must start with http:// or https://." >&2; exit 1 ;;
    esac
}

require_env APP_KEY
require_env APP_URL
require_env DB_PASSWORD
validate_app_key
validate_app_url


mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

# Authentication depends on writable session/cache directories. Do not hide
# ownership failures and then discover them as a 500 only after credentials are
# submitted. Make permissions deterministic and fail deployment before traffic.
if ! chown -R www-data:www-data storage bootstrap/cache; then
    echo "[bootstrap] ERROR: unable to set storage/bootstrap ownership for www-data." >&2
    exit 1
fi
chmod -R u+rwX,g+rwX storage bootstrap/cache

if command -v su >/dev/null 2>&1; then
    if ! su -s /bin/sh -c '        set -eu;         for dir in storage/framework/sessions storage/framework/cache storage/framework/views storage/logs bootstrap/cache; do             probe="$dir/.hf-write-test-$$";             printf ok > "$probe";             test "$(cat "$probe")" = ok;             rm -f "$probe";         done    ' www-data; then
        echo "[bootstrap] ERROR: www-data cannot write required Laravel runtime directories." >&2
        exit 1
    fi
else
    echo "[bootstrap] WARNING: su is unavailable; ownership/mode checks were applied but user-level write probe was skipped." >&2
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[bootstrap] Running database migrations..."
    attempts=0
    until PGOPTIONS="-c statement_timeout=0 -c lock_timeout=30000" php artisan migrate --force --no-interaction --isolated; do
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

    echo "[bootstrap] Verifying authentication foundation before serving traffic..."
    php artisan happy-family:auth-preflight --no-interaction

    php artisan storage:link >/dev/null 2>&1 || true

    echo "[bootstrap] Caching Laravel configuration and routes..."
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
