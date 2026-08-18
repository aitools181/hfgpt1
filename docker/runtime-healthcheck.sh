#!/bin/sh
set -eu

role="${1:-web}"

process_exists() {
    pattern="$1"
    for cmdline in /proc/[0-9]*/cmdline; do
        [ -r "$cmdline" ] || continue
        pid=${cmdline#/proc/}
        pid=${pid%/cmdline}
        if [ -r "/proc/$pid/stat" ]; then
            state=$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || echo unknown)
            [ "$state" = "Z" ] && continue
        fi
        command_line=$(tr '\000' ' ' < "$cmdline" 2>/dev/null || true)
        case "$command_line" in
            *"$pattern"*) return 0 ;;
        esac
    done
    return 1
}

pid_alive() {
    pid_file="$1"
    [ -s "$pid_file" ] || return 1
    pid=$(cat "$pid_file" 2>/dev/null || true)
    case "$pid" in
        ''|*[!0-9]*) return 1 ;;
    esac
    kill -0 "$pid" 2>/dev/null || return 1
    if [ -r "/proc/$pid/stat" ]; then
        state=$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || echo unknown)
        [ "$state" = "Z" ] && return 1
    fi
    return 0
}

case "$role" in
    web)
        pid_alive /run/happy-family/php-fpm.pid || exit 1
        pid_alive /run/happy-family/nginx.pid || exit 1
        curl -fsS --connect-timeout 2 --max-time 3 http://127.0.0.1/__container_health >/dev/null
        curl -fsS --connect-timeout 2 --max-time 4 http://127.0.0.1/__fpm_health | grep -qx 'pong'
        curl -fsS --connect-timeout 2 --max-time 6 http://127.0.0.1/__laravel_health >/dev/null
        ;;
    worker)
        pid_alive /run/happy-family/worker.pid || exit 1
        pid=$(cat /run/happy-family/worker.pid)
        command_line=$(tr '\000' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)
        case "$command_line" in *'php -d memory_limit=288M artisan queue:work redis'*) ;; *) exit 1 ;; esac
        ;;
    scheduler)
        pid_alive /run/happy-family/scheduler.pid || exit 1
        pid=$(cat /run/happy-family/scheduler.pid)
        command_line=$(tr '\000' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)
        case "$command_line" in *'php -d memory_limit=128M artisan schedule:work'*) ;; *) exit 1 ;; esac
        ;;
    *)
        echo "Unknown healthcheck role: $role" >&2
        exit 2
        ;;
esac
