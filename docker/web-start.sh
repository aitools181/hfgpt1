#!/bin/sh
set -eu

PHP_PID=""
NGINX_PID=""
WATCHDOG_FAILURES=0
RUN_DIR=${WEB_RUN_DIR:-/run/happy-family}
RUNTIME_LOG=${WEB_RUNTIME_LOG:-/var/www/html/storage/logs/runtime-supervisor.log}
RUNTIME_LOG_MAX_BYTES=${RUNTIME_LOG_MAX_BYTES:-5242880}
PROCESS_RESTART_MAX=${WEB_PROCESS_RESTART_MAX:-5}
PROCESS_RESTART_WINDOW=${WEB_PROCESS_RESTART_WINDOW_SECONDS:-600}
PROCESS_STOP_GRACE=${WEB_PROCESS_STOP_GRACE_SECONDS:-5}
PROCESS_RESTART_BACKOFF=${WEB_PROCESS_RESTART_BACKOFF_SECONDS:-2}
PROCESS_RESTART_MAX_BACKOFF=${WEB_PROCESS_RESTART_MAX_BACKOFF_SECONDS:-20}
PHP_RESTARTS=0
NGINX_RESTARTS=0
RESTART_WINDOW_STARTED=0
NGINX_PROBE_FAILURES=0
FPM_PROBE_FAILURES=0
INFRA_NEXT_CHECK=0

is_positive_integer() {
    case "${1:-}" in
        ''|*[!0-9]*|0) return 1 ;;
        *) return 0 ;;
    esac
}

rotate_runtime_log() {
    [ -f "$RUNTIME_LOG" ] || return 0
    size=$(wc -c < "$RUNTIME_LOG" 2>/dev/null || echo 0)
    if [ "$size" -gt "$RUNTIME_LOG_MAX_BYTES" ]; then
        mv -f "$RUNTIME_LOG" "$RUNTIME_LOG.1" 2>/dev/null || : > "$RUNTIME_LOG"
    fi
}

record_event() {
    message="$1"
    stamp=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date)
    # Runtime diagnostics must never become a new availability dependency. If
    # the persistent log volume is full/read-only, keep supervising processes
    # and still emit the event to stderr for Docker/Coolify log capture.
    rotate_runtime_log || true
    printf '%s %s\n' "$stamp" "$message" >&2 || true
    printf '%s %s\n' "$stamp" "$message" >> "$RUNTIME_LOG" 2>/dev/null || true
}

cleanup_pidfiles() {
    rm -f "$RUN_DIR/php-fpm.pid" "$RUN_DIR/nginx.pid" 2>/dev/null || true
}

pid_running() {
    pid="$1"
    kill -0 "$pid" 2>/dev/null || return 1
    if [ -r "/proc/$pid/stat" ]; then
        state=$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || echo unknown)
        [ "$state" = "Z" ] && return 1
    fi
    return 0
}

bounded_stop() {
    pid="$1"
    label="$2"
    [ -n "$pid" ] || return 0
    pid_running "$pid" || { wait "$pid" 2>/dev/null || true; return 0; }

    kill -QUIT "$pid" 2>/dev/null || true
    attempts=0
    while pid_running "$pid" && [ "$attempts" -lt "$PROCESS_STOP_GRACE" ]; do
        attempts=$((attempts + 1))
        sleep 1
    done
    if pid_running "$pid"; then
        record_event "WARNING $label ignored graceful QUIT; sending TERM"
        kill -TERM "$pid" 2>/dev/null || true
        attempts=0
        while pid_running "$pid" && [ "$attempts" -lt "$PROCESS_STOP_GRACE" ]; do
            attempts=$((attempts + 1))
            sleep 1
        done
    fi
    if pid_running "$pid"; then
        record_event "WARNING $label ignored TERM; sending KILL"
        kill -KILL "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || true
}

terminate_leftover_processes() {
    expected_comm="$1"
    label="$2"
    pids=""
    for comm_file in /proc/[0-9]*/comm; do
        [ -r "$comm_file" ] || continue
        pid=${comm_file#/proc/}
        pid=${pid%/comm}
        [ "$pid" = "1" ] && continue
        [ "$pid" = "$$" ] && continue
        comm=$(cat "$comm_file" 2>/dev/null || true)
        case "$comm" in
            "$expected_comm"|"$expected_comm"[0-9]*) ;;
            *) continue ;;
        esac
        if pid_running "$pid"; then pids="$pids $pid"; fi
    done
    [ -n "$pids" ] || return 0

    record_event "WARNING cleaning leftover $label process(es):$pids"
    for pid in $pids; do kill -TERM "$pid" 2>/dev/null || true; done
    waited=0
    while [ "$waited" -lt "$PROCESS_STOP_GRACE" ]; do
        alive=0
        for pid in $pids; do pid_running "$pid" && alive=1; done
        [ "$alive" -eq 0 ] && break
        waited=$((waited + 1))
        sleep 1
    done
    for pid in $pids; do
        if pid_running "$pid"; then
            record_event "WARNING leftover $label pid=$pid ignored TERM; sending KILL"
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
}

log_runtime_snapshot() {
    record_event "runtime snapshot begin"
    if [ -r /proc/loadavg ]; then record_event "load=$(cat /proc/loadavg)"; fi
    if [ -r /sys/fs/cgroup/memory.current ]; then
        record_event "cgroup memory.current=$(cat /sys/fs/cgroup/memory.current 2>/dev/null || echo unknown) memory.max=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo unknown)"
    fi
    if [ -r /sys/fs/cgroup/memory.events ]; then
        while IFS= read -r line; do record_event "memory.events $line"; done < /sys/fs/cgroup/memory.events
    fi
    df -h /var/www/html /tmp 2>/dev/null | while IFS= read -r line; do record_event "disk $line"; done || true
    record_event "runtime snapshot end"
}

wait_for_fpm() {
    attempts=0
    while :; do
        if php -r '$a=@fsockopen("127.0.0.1",9000,$e,$m,1); $b=@fsockopen("127.0.0.1",9001,$e2,$m2,1); $c=@fsockopen("127.0.0.1",9002,$e3,$m3,1); $d=@fsockopen("127.0.0.1",9003,$e4,$m4,1); if (!$a || !$b || !$c || !$d) { if ($a) fclose($a); if ($b) fclose($b); if ($c) fclose($c); if ($d) fclose($d); exit(1); } fclose($a); fclose($b); fclose($c); fclose($d);'; then
            return 0
        fi
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            return 1
        fi
        if [ -n "$PHP_PID" ] && ! pid_running "$PHP_PID"; then
            return 1
        fi
        sleep 1
    done
}

wait_for_nginx() {
    attempts=0
    while :; do
        if curl -fsS --connect-timeout 1 --max-time 2 http://127.0.0.1/__container_health >/dev/null 2>&1; then
            return 0
        fi
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 20 ]; then
            return 1
        fi
        if [ -n "$NGINX_PID" ] && ! pid_running "$NGINX_PID"; then
            return 1
        fi
        sleep 1
    done
}

start_php_fpm() {
    terminate_leftover_processes "php-fpm" "PHP-FPM"
    rm -f "$RUN_DIR/php-fpm.pid" 2>/dev/null || true
    record_event "starting PHP-FPM master"
    php-fpm --nodaemonize &
    PHP_PID=$!
    printf '%s\n' "$PHP_PID" > "$RUN_DIR/php-fpm.pid"
    if ! wait_for_fpm; then
        record_event "ERROR PHP-FPM listeners did not become reachable"
        return 1
    fi
    record_event "PHP-FPM ready pid=$PHP_PID"
    return 0
}

stop_php_fpm() {
    if [ -n "$PHP_PID" ]; then
        bounded_stop "$PHP_PID" "PHP-FPM"
    fi
    PHP_PID=""
    rm -f "$RUN_DIR/php-fpm.pid" 2>/dev/null || true
}

start_nginx() {
    terminate_leftover_processes "nginx" "Nginx"
    rm -f "$RUN_DIR/nginx.pid" 2>/dev/null || true
    record_event "starting Nginx"
    nginx -g 'daemon off;' &
    NGINX_PID=$!
    printf '%s\n' "$NGINX_PID" > "$RUN_DIR/nginx.pid"
    if ! wait_for_nginx; then
        record_event "ERROR Nginx health endpoint did not become reachable"
        return 1
    fi
    record_event "Nginx ready pid=$NGINX_PID"
    return 0
}

stop_nginx() {
    if [ -n "$NGINX_PID" ]; then
        bounded_stop "$NGINX_PID" "Nginx"
    fi
    NGINX_PID=""
    rm -f "$RUN_DIR/nginx.pid" 2>/dev/null || true
}

reset_restart_window_if_stable() {
    now=$(date +%s)
    if [ "$RESTART_WINDOW_STARTED" -eq 0 ]; then
        RESTART_WINDOW_STARTED=$now
        return
    fi
    if [ $((now - RESTART_WINDOW_STARTED)) -ge "$PROCESS_RESTART_WINDOW" ]; then
        if [ "$PHP_RESTARTS" -gt 0 ] || [ "$NGINX_RESTARTS" -gt 0 ]; then
            record_event "process recovery counters reset after ${PROCESS_RESTART_WINDOW}s stable window"
        fi
        PHP_RESTARTS=0
        NGINX_RESTARTS=0
        RESTART_WINDOW_STARTED=$now
    fi
}

process_recovery_delay() {
    count="$1"
    delay=$((PROCESS_RESTART_BACKOFF * count))
    if [ "$delay" -gt "$PROCESS_RESTART_MAX_BACKOFF" ]; then
        delay=$PROCESS_RESTART_MAX_BACKOFF
    fi
    printf '%s' "$delay"
}

recover_php_fpm() {
    reason="$1"
    reset_restart_window_if_stable
    PHP_RESTARTS=$((PHP_RESTARTS + 1))
    record_event "WARNING recovering PHP-FPM reason='$reason' attempt=$PHP_RESTARTS/$PROCESS_RESTART_MAX"
    log_runtime_snapshot
    stop_php_fpm
    if [ "$PHP_RESTARTS" -gt "$PROCESS_RESTART_MAX" ]; then
        return 1
    fi
    delay=$(process_recovery_delay "$PHP_RESTARTS")
    record_event "waiting ${delay}s before PHP-FPM recovery attempt"
    sleep "$delay"
    start_php_fpm
}

recover_nginx() {
    reason="$1"
    reset_restart_window_if_stable
    NGINX_RESTARTS=$((NGINX_RESTARTS + 1))
    record_event "WARNING recovering Nginx reason='$reason' attempt=$NGINX_RESTARTS/$PROCESS_RESTART_MAX"
    log_runtime_snapshot
    stop_nginx
    if [ "$NGINX_RESTARTS" -gt "$PROCESS_RESTART_MAX" ]; then
        return 1
    fi
    delay=$(process_recovery_delay "$NGINX_RESTARTS")
    record_event "waiting ${delay}s before Nginx recovery attempt"
    sleep "$delay"
    start_nginx
}

shutdown() {
    code="${1:-0}"
    reason="${2:-normal shutdown}"
    trap - TERM INT
    record_event "shutdown code=$code reason=$reason"
    stop_nginx
    stop_php_fpm
    cleanup_pidfiles
    exit "$code"
}

# Docker/Coolify normally stop a container with SIGTERM. SIGHUP is deliberately
# ignored by the supervisor so a proxy/config reload cannot accidentally turn a
# healthy application into a stopped container.
trap 'shutdown 0 "termination signal received"' TERM INT
trap 'record_event "SIGHUP received by supervisor and ignored"' HUP

WATCHDOG_ENABLED="${WEB_WATCHDOG_ENABLED:-true}"
WATCHDOG_URL="${WEB_WATCHDOG_URL:-http://127.0.0.1/__laravel_health}"
WATCHDOG_INTERVAL="${WEB_WATCHDOG_INTERVAL_SECONDS:-15}"
WATCHDOG_TIMEOUT="${WEB_WATCHDOG_TIMEOUT_SECONDS:-4}"
WATCHDOG_THRESHOLD="${WEB_WATCHDOG_FAILURE_THRESHOLD:-4}"
WATCHDOG_START_GRACE="${WEB_WATCHDOG_START_GRACE_SECONDS:-60}"
INFRA_PROBE_INTERVAL="${WEB_INFRA_PROBE_INTERVAL_SECONDS:-10}"
INFRA_PROBE_TIMEOUT="${WEB_INFRA_PROBE_TIMEOUT_SECONDS:-3}"
INFRA_PROBE_THRESHOLD="${WEB_INFRA_PROBE_FAILURE_THRESHOLD:-3}"

for value_name in PROCESS_RESTART_MAX PROCESS_RESTART_WINDOW PROCESS_STOP_GRACE PROCESS_RESTART_BACKOFF PROCESS_RESTART_MAX_BACKOFF INFRA_PROBE_INTERVAL INFRA_PROBE_TIMEOUT INFRA_PROBE_THRESHOLD RUNTIME_LOG_MAX_BYTES; do
    eval "value=\${$value_name}"
    if ! is_positive_integer "$value"; then
        record_event "ERROR invalid $value_name='$value'"
        exit 1
    fi
done

if [ "$WATCHDOG_ENABLED" = "true" ]; then
    for value_name in WATCHDOG_INTERVAL WATCHDOG_TIMEOUT WATCHDOG_THRESHOLD WATCHDOG_START_GRACE; do
        eval "value=\${$value_name}"
        if ! is_positive_integer "$value"; then
            record_event "ERROR invalid $value_name='$value'"
            exit 1
        fi
    done
fi

mkdir -p "$RUN_DIR" /var/www/html/storage/logs
cleanup_pidfiles
rotate_runtime_log

record_event "validating Nginx and PHP-FPM configuration"
nginx -t
php-fpm -tt

if ! start_php_fpm; then
    log_runtime_snapshot
    shutdown 1 "PHP-FPM failed initial startup"
fi
if ! start_nginx; then
    log_runtime_snapshot
    shutdown 1 "Nginx failed initial startup"
fi

RESTART_WINDOW_STARTED=$(date +%s)
INFRA_NEXT_CHECK=$(( $(date +%s) + 5 ))
record_event "infrastructure probes enabled interval=${INFRA_PROBE_INTERVAL}s timeout=${INFRA_PROBE_TIMEOUT}s threshold=$INFRA_PROBE_THRESHOLD"
if [ "$WATCHDOG_ENABLED" = "true" ]; then
    WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_START_GRACE ))
    record_event "isolated Laravel watchdog enabled url=$WATCHDOG_URL interval=${WATCHDOG_INTERVAL}s timeout=${WATCHDOG_TIMEOUT}s threshold=$WATCHDOG_THRESHOLD grace=${WATCHDOG_START_GRACE}s"
else
    WATCHDOG_NEXT_CHECK=0
    record_event "isolated Laravel watchdog disabled"
fi

# Prefer in-container process recovery. A single FPM/Nginx crash no longer takes
# the whole service down. Only repeated unrecoverable restarts cause container
# exit, after which Docker's restart policy provides the final recovery layer.
while :; do
    reset_restart_window_if_stable

    if [ -z "$PHP_PID" ] || ! pid_running "$PHP_PID"; then
        wait "$PHP_PID" 2>/dev/null || true
        if ! recover_php_fpm "PHP-FPM master exited unexpectedly"; then
            log_runtime_snapshot
            shutdown 1 "PHP-FPM could not be recovered after repeated failures"
        fi
        WATCHDOG_FAILURES=0
    fi

    if [ -z "$NGINX_PID" ] || ! pid_running "$NGINX_PID"; then
        wait "$NGINX_PID" 2>/dev/null || true
        if ! recover_nginx "Nginx master exited unexpectedly"; then
            log_runtime_snapshot
            shutdown 1 "Nginx could not be recovered after repeated failures"
        fi
    fi

    now=$(date +%s)
    if [ "$now" -ge "$INFRA_NEXT_CHECK" ]; then
        if curl -fsS --connect-timeout "$INFRA_PROBE_TIMEOUT" --max-time "$INFRA_PROBE_TIMEOUT" http://127.0.0.1/__container_health >/dev/null 2>&1; then
            if [ "$NGINX_PROBE_FAILURES" -gt 0 ]; then
                record_event "Nginx probe recovered after $NGINX_PROBE_FAILURES failed probe(s)"
            fi
            NGINX_PROBE_FAILURES=0
        else
            NGINX_PROBE_FAILURES=$((NGINX_PROBE_FAILURES + 1))
            record_event "WARNING Nginx direct probe failed $NGINX_PROBE_FAILURES/$INFRA_PROBE_THRESHOLD"
            if [ "$NGINX_PROBE_FAILURES" -ge "$INFRA_PROBE_THRESHOLD" ]; then
                if ! recover_nginx "Nginx PID was alive but health endpoint was unresponsive"; then
                    log_runtime_snapshot
                    shutdown 1 "Nginx could not be recovered after unresponsive health probes"
                fi
                NGINX_PROBE_FAILURES=0
                FPM_PROBE_FAILURES=0
                WATCHDOG_FAILURES=0
                INFRA_NEXT_CHECK=$(( $(date +%s) + INFRA_PROBE_INTERVAL ))
                WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_START_GRACE ))
                continue
            fi
        fi

        # FPM ping is reached through Nginx, so only score it when the direct
        # Nginx probe succeeded. This prevents an Nginx fault from being
        # misdiagnosed as a PHP-FPM fault.
        if [ "$NGINX_PROBE_FAILURES" -eq 0 ]; then
            if curl -fsS --connect-timeout "$INFRA_PROBE_TIMEOUT" --max-time "$INFRA_PROBE_TIMEOUT" http://127.0.0.1/__fpm_health 2>/dev/null | grep -qx 'pong'; then
                if [ "$FPM_PROBE_FAILURES" -gt 0 ]; then
                    record_event "PHP-FPM control probe recovered after $FPM_PROBE_FAILURES failed probe(s)"
                fi
                FPM_PROBE_FAILURES=0
            else
                FPM_PROBE_FAILURES=$((FPM_PROBE_FAILURES + 1))
                record_event "WARNING PHP-FPM control probe failed $FPM_PROBE_FAILURES/$INFRA_PROBE_THRESHOLD"
                if [ "$FPM_PROBE_FAILURES" -ge "$INFRA_PROBE_THRESHOLD" ]; then
                    if ! recover_php_fpm "PHP-FPM master PID was alive but control pool was unresponsive"; then
                        log_runtime_snapshot
                        shutdown 1 "PHP-FPM could not be recovered after unresponsive control probes"
                    fi
                    FPM_PROBE_FAILURES=0
                    WATCHDOG_FAILURES=0
                    INFRA_NEXT_CHECK=$(( $(date +%s) + INFRA_PROBE_INTERVAL ))
                    WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_START_GRACE ))
                    continue
                fi
            fi
        fi
        INFRA_NEXT_CHECK=$(( $(date +%s) + INFRA_PROBE_INTERVAL ))
    fi

    if [ "$WATCHDOG_ENABLED" = "true" ]; then
        now=$(date +%s)
        if [ "$now" -ge "$WATCHDOG_NEXT_CHECK" ]; then
            # Do not blame/restart Laravel workers while a lower-level Nginx or
            # FPM probe is already failing. The infrastructure recovery path
            # above owns those faults.
            if [ "$NGINX_PROBE_FAILURES" -gt 0 ] || [ "$FPM_PROBE_FAILURES" -gt 0 ]; then
                WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_INTERVAL ))
                sleep 2
                continue
            fi
            if curl -fsS --connect-timeout "$WATCHDOG_TIMEOUT" --max-time "$WATCHDOG_TIMEOUT" "$WATCHDOG_URL" >/dev/null 2>&1; then
                if [ "$WATCHDOG_FAILURES" -gt 0 ]; then
                    record_event "watchdog recovered after $WATCHDOG_FAILURES failed probe(s)"
                fi
                WATCHDOG_FAILURES=0
            else
                WATCHDOG_FAILURES=$((WATCHDOG_FAILURES + 1))
                record_event "WARNING isolated Laravel watchdog probe failed $WATCHDOG_FAILURES/$WATCHDOG_THRESHOLD"
                if [ "$WATCHDOG_FAILURES" -ge "$WATCHDOG_THRESHOLD" ]; then
                    if ! recover_php_fpm "isolated Laravel liveness remained unreachable"; then
                        log_runtime_snapshot
                        shutdown 1 "Laravel/FPM liveness could not be recovered"
                    fi
                    WATCHDOG_FAILURES=0
                    WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_START_GRACE ))
                    continue
                fi
            fi
            WATCHDOG_NEXT_CHECK=$(( $(date +%s) + WATCHDOG_INTERVAL ))
        fi
    fi

    sleep 2
done
