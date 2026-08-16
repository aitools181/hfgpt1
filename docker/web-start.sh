#!/bin/sh
set -eu

PHP_PID=""
NGINX_PID=""
WATCHDOG_FAILURES=0

is_positive_integer() {
    case "${1:-}" in
        ''|*[!0-9]*|0) return 1 ;;
        *) return 0 ;;
    esac
}

shutdown() {
    code="${1:-0}"
    trap - TERM INT HUP EXIT

    if [ -n "$NGINX_PID" ] && kill -0 "$NGINX_PID" 2>/dev/null; then
        kill -TERM "$NGINX_PID" 2>/dev/null || true
    fi
    if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
        kill -TERM "$PHP_PID" 2>/dev/null || true
    fi

    wait "$NGINX_PID" 2>/dev/null || true
    wait "$PHP_PID" 2>/dev/null || true
    exit "$code"
}

trap 'echo "[web] Received termination signal; stopping Nginx and PHP-FPM..."; shutdown 0' TERM INT HUP

WATCHDOG_ENABLED="${WEB_WATCHDOG_ENABLED:-true}"
WATCHDOG_URL="${WEB_WATCHDOG_URL:-http://127.0.0.1/up}"
WATCHDOG_INTERVAL="${WEB_WATCHDOG_INTERVAL_SECONDS:-10}"
WATCHDOG_TIMEOUT="${WEB_WATCHDOG_TIMEOUT_SECONDS:-5}"
WATCHDOG_THRESHOLD="${WEB_WATCHDOG_FAILURE_THRESHOLD:-3}"
WATCHDOG_START_GRACE="${WEB_WATCHDOG_START_GRACE_SECONDS:-30}"

if [ "$WATCHDOG_ENABLED" = "true" ]; then
    for value_name in WATCHDOG_INTERVAL WATCHDOG_TIMEOUT WATCHDOG_THRESHOLD WATCHDOG_START_GRACE; do
        eval "value=\${$value_name}"
        if ! is_positive_integer "$value"; then
            echo "[web] ERROR: $value_name must be a positive integer; got '$value'."
            exit 1
        fi
    done
fi

printf '%s\n' "[web] Validating Nginx and PHP-FPM configuration..."
nginx -t
php-fpm -tt

printf '%s\n' "[web] Starting PHP-FPM in foreground-supervised mode..."
php-fpm --nodaemonize &
PHP_PID=$!

attempts=0
until php -r '$s=@fsockopen("127.0.0.1",9000,$e,$m,1); if (!$s) { exit(1); } fclose($s);'; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
        echo "[web] ERROR: PHP-FPM did not become reachable on port 9000."
        shutdown 1
    fi
    if ! kill -0 "$PHP_PID" 2>/dev/null; then
        wait "$PHP_PID" 2>/dev/null || true
        echo "[web] ERROR: PHP-FPM exited during startup."
        shutdown 1
    fi
    sleep 1
done

printf '%s\n' "[web] Starting Nginx in foreground-supervised mode..."
nginx -g 'daemon off;' &
NGINX_PID=$!

if [ "$WATCHDOG_ENABLED" = "true" ]; then
    WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_START_GRACE ))
    printf '%s\n' "[web] Self-healing watchdog enabled: $WATCHDOG_URL every ${WATCHDOG_INTERVAL}s, timeout ${WATCHDOG_TIMEOUT}s, restart after ${WATCHDOG_THRESHOLD} consecutive failures, initial grace ${WATCHDOG_START_GRACE}s."
else
    WATCHDOG_NEXT_CHECK=0
    printf '%s\n' "[web] Self-healing watchdog disabled by WEB_WATCHDOG_ENABLED=$WATCHDOG_ENABLED."
fi

# Keep the container alive only while both critical processes are healthy.
# In addition to PID supervision, the internal HTTP watchdog exercises the full
# Nginx -> PHP-FPM -> Laravel path. Consecutive liveness failures force this
# process to exit non-zero so Docker's restart policy can automatically recover.
while :; do
    if ! kill -0 "$PHP_PID" 2>/dev/null; then
        wait "$PHP_PID" 2>/dev/null || true
        echo "[web] ERROR: PHP-FPM exited unexpectedly; terminating container so Docker can restart it."
        shutdown 1
    fi

    if ! kill -0 "$NGINX_PID" 2>/dev/null; then
        wait "$NGINX_PID" 2>/dev/null || true
        echo "[web] ERROR: Nginx exited unexpectedly; terminating container so Docker can restart it."
        shutdown 1
    fi

    if [ "$WATCHDOG_ENABLED" = "true" ]; then
        now=$(date +%s)
        if [ "$now" -ge "$WATCHDOG_NEXT_CHECK" ]; then
            if curl -fsS --connect-timeout "$WATCHDOG_TIMEOUT" --max-time "$WATCHDOG_TIMEOUT" "$WATCHDOG_URL" >/dev/null 2>&1; then
                if [ "$WATCHDOG_FAILURES" -gt 0 ]; then
                    echo "[web] Watchdog recovered after $WATCHDOG_FAILURES consecutive failure(s)."
                fi
                WATCHDOG_FAILURES=0
            else
                WATCHDOG_FAILURES=$((WATCHDOG_FAILURES + 1))
                echo "[web] WARNING: watchdog liveness check failed ($WATCHDOG_FAILURES/$WATCHDOG_THRESHOLD): $WATCHDOG_URL"

                if [ "$WATCHDOG_FAILURES" -ge "$WATCHDOG_THRESHOLD" ]; then
                    echo "[web] ERROR: watchdog reached failure threshold; terminating container so Docker can restart it."
                    shutdown 1
                fi
            fi
            WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_INTERVAL ))
        fi
    fi

    sleep 2
done
