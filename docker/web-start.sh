#!/bin/sh
set -eu

PHP_PID=""
NGINX_PID=""

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

# Keep PID 1 alive only while both critical processes are alive. Docker's
# restart policy can then recover from an unexpected Nginx or PHP-FPM exit.
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

    sleep 2
done
