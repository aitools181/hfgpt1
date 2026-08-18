#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
TMP=$(mktemp -d)
cleanup() {
  if [ -n "${SUP_PID:-}" ]; then kill -KILL "$SUP_PID" 2>/dev/null || true; fi
  pkill -KILL -f "$TMP/php" 2>/dev/null || true
  rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

cat > "$TMP/php" <<'EOS'
#!/bin/sh
set -eu
count_file="${FAKE_PHP_COUNT_FILE:?}"
count=0
[ -f "$count_file" ] && count=$(cat "$count_file")
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
mode="${FAKE_PHP_MODE:-restart_once}"
case "$mode" in
  restart_once)
    if [ "$count" -eq 1 ]; then sleep 1; exit 0; fi
    while :; do sleep 1; done
    ;;
  crash_then_stable)
    if [ "$count" -le 2 ]; then sleep 1; exit 7; fi
    while :; do sleep 1; done
    ;;
  stable)
    while :; do sleep 1; done
    ;;
  *) exit 9 ;;
esac
EOS
chmod +x "$TMP/php"

run_case() {
  role="$1"
  mode="$2"
  expected_starts="$3"
  count_file="$TMP/${role}-${mode}.count"
  log_file="$TMP/${role}-${mode}.log"
  rm -f "$count_file"

  env PATH="$TMP:$PATH" \
      FAKE_PHP_COUNT_FILE="$count_file" \
      FAKE_PHP_MODE="$mode" \
      BACKGROUND_RUN_DIR="$TMP/run-$role-$mode" \
      BACKGROUND_LOG_DIR="$TMP/log-$role-$mode" \
      BACKGROUND_RESTART_BACKOFF_SECONDS=1 \
      BACKGROUND_RESTART_MAX_BACKOFF_SECONDS=2 \
      BACKGROUND_STABLE_RESET_SECONDS=10 \
      sh docker/background-start.sh "$role" >"$log_file" 2>&1 &
  SUP_PID=$!

  ok=0
  for _ in $(seq 1 15); do
    starts=0
    [ -f "$count_file" ] && starts=$(cat "$count_file")
    if [ "$starts" -ge "$expected_starts" ]; then ok=1; break; fi
    sleep 1
  done
  [ "$ok" -eq 1 ] || { cat "$log_file"; echo "$role supervisor did not restart child" >&2; exit 1; }

  kill -TERM "$SUP_PID"
  set +e
  wait "$SUP_PID"
  rc=$?
  set -e
  SUP_PID=""
  [ "$rc" -eq 0 ] || { cat "$log_file"; echo "$role supervisor did not shut down cleanly rc=$rc" >&2; exit 1; }
  grep -q 'child exited rc=' "$log_file"
  grep -q 'termination signal received; stopping child' "$log_file"
}

run_case worker restart_once 2
echo 'Worker in-container child recycling PASS'
run_case scheduler crash_then_stable 3
echo 'Scheduler transient-crash recovery PASS'
