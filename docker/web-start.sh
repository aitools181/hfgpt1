#!/bin/sh
set -eu

echo "[web] Validating Nginx and PHP-FPM configuration..."
nginx -t
php-fpm -tt

echo "[web] Starting PHP-FPM on 127.0.0.1:9000..."
php-fpm -D

attempts=0
until php -r '$s=@fsockopen("127.0.0.1",9000,$e,$m,1); if (!$s) { exit(1); } fclose($s);'; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 20 ]; then
        echo "[web] ERROR: PHP-FPM did not become reachable on port 9000."
        exit 1
    fi
    sleep 1
done

echo "[web] PHP-FPM is reachable; starting Nginx on port 80..."
exec nginx -g 'daemon off;'
