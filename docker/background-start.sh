#!/bin/sh
set -eu

ROLE="${1:-}"
RUN_DIR=${BACKGROUND_RUN_DIR:-/run/happy-family}
LOG_DIR=${BACKGROUND_LOG_DIR:-/var/www/html/storage/logs}
MIN_BACKOFF=${BACKGROUND_RESTART_BACKOFF_SECONDS:-3}
MAX_BACKOFF=${BACKGROUND_RESTART_MAX_BACKOFF_SECONDS:-30}
STABLE_RESET_SECONDS=${BACKGROUND_STABLE_RESET_SECONDS:-300}
LOG_MAX_BYTES=${BACKGROUND_LOG_MAX_BYTES:-5242880}
CHILD_PID=""
STOPPING=0

case "$ROLE" in
  worker) LOG_FILE="$LOG_DIR/runtime-worker.log" ;;
  scheduler) LOG_FILE="$LOG_DIR/runtime-scheduler.log" ;;
  *) echo "[background] ERROR: role must be worker or scheduler" >&2; exit 2 ;;
esac

is_positive_integer() {
  case "${1:-}" in ''|*[!0-9]*|0) return 1 ;; *) return 0 ;; esac
}
for value_name in MIN_BACKOFF MAX_BACKOFF STABLE_RESET_SECONDS LOG_MAX_BYTES; do
  eval "value=\${$value_name}"
  if ! is_positive_integer "$value"; then
    echo "[background] ERROR: invalid $value_name='$value'" >&2
    exit 2
  fi
done

mkdir -p "$RUN_DIR" "$LOG_DIR"

rotate_log() {
  [ -f "$LOG_FILE" ] || return 0
  size=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
  if [ "$size" -gt "$LOG_MAX_BYTES" ]; then
    mv -f "$LOG_FILE" "$LOG_FILE.1" 2>/dev/null || : > "$LOG_FILE"
  fi
}

log_event() {
  message="$1"
  stamp=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date)
  # Never let a full/unwritable log volume terminate the supervisor.
  rotate_log || true
  printf '%s [%s] %s\n' "$stamp" "$ROLE" "$message" >&2 || true
  printf '%s [%s] %s\n' "$stamp" "$ROLE" "$message" >> "$LOG_FILE" 2>/dev/null || true
}

pid_running() {
  pid="$1"
  [ -n "$pid" ] || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  if [ -r "/proc/$pid/stat" ]; then
    state=$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || echo unknown)
    [ "$state" = "Z" ] && return 1
  fi
  return 0
}

stop_child() {
  [ -n "$CHILD_PID" ] || return 0
  if pid_running "$CHILD_PID"; then
    kill -TERM "$CHILD_PID" 2>/dev/null || true
    waited=0
    while pid_running "$CHILD_PID" && [ "$waited" -lt 20 ]; do
      waited=$((waited + 1))
      sleep 1
    done
    if pid_running "$CHILD_PID"; then
      log_event "WARNING child pid=$CHILD_PID ignored TERM; sending KILL"
      kill -KILL "$CHILD_PID" 2>/dev/null || true
    fi
  fi
  wait "$CHILD_PID" 2>/dev/null || true
  CHILD_PID=""
  rm -f "$RUN_DIR/$ROLE.pid" 2>/dev/null || true
}

shutdown() {
  STOPPING=1
  trap - TERM INT
  log_event "termination signal received; stopping child"
  stop_child
  exit 0
}
trap shutdown TERM INT
trap 'log_event "SIGHUP ignored by background supervisor"' HUP

start_child() {
  if [ "$ROLE" = "worker" ]; then
    php -d memory_limit=288M artisan queue:work redis --queue=imports,default --sleep=2 --tries=3 --timeout=900 --memory=240 --max-jobs=200 --max-time=1800 &
  else
    php -d memory_limit=128M artisan schedule:work &
  fi
  CHILD_PID=$!
  printf '%s\n' "$CHILD_PID" > "$RUN_DIR/$ROLE.pid"
  log_event "started child pid=$CHILD_PID"
}

backoff=$MIN_BACKOFF
while [ "$STOPPING" -eq 0 ]; do
  started_at=$(date +%s)
  start_child

  set +e
  wait "$CHILD_PID"
  rc=$?
  set -e
  finished_at=$(date +%s)
  runtime=$((finished_at - started_at))
  rm -f "$RUN_DIR/$ROLE.pid" 2>/dev/null || true
  CHILD_PID=""

  [ "$STOPPING" -eq 1 ] && break

  # queue:work intentionally exits on --max-time/--max-jobs. Scheduler can also
  # exit after a transient dependency error. Keep the container alive and
  # restart the workload in-process instead of exposing a Docker-level outage.
  log_event "child exited rc=$rc runtime=${runtime}s; restarting after ${backoff}s"
  if [ "$runtime" -ge "$STABLE_RESET_SECONDS" ]; then
    backoff=$MIN_BACKOFF
  fi
  sleep "$backoff"
  next=$((backoff * 2))
  if [ "$next" -gt "$MAX_BACKOFF" ]; then next=$MAX_BACKOFF; fi
  backoff=$next
done

stop_child
exit 0
