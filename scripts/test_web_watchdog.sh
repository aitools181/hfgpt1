#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT INT TERM

cat > "$TMP/nginx" <<'SH'
#!/bin/sh
if [ "${1:-}" = "-t" ]; then exit 0; fi
trap 'exit 0' TERM INT HUP
while :; do sleep 1; done
SH

cat > "$TMP/php-fpm" <<'SH'
#!/bin/sh
if [ "${1:-}" = "-tt" ]; then exit 0; fi
trap 'exit 0' TERM INT HUP
while :; do sleep 1; done
SH

cat > "$TMP/php" <<'SH'
#!/bin/sh
# The web supervisor uses PHP only for the startup fsockopen probe in this harness.
exit 0
SH
chmod +x "$TMP/nginx" "$TMP/php-fpm" "$TMP/php"

# Scenario 1: liveness always fails. The supervisor must exit 1 after the
# configured consecutive-failure threshold so Docker can restart the container.
cat > "$TMP/curl" <<'SH'
#!/bin/sh
exit 22
SH
chmod +x "$TMP/curl"

set +e
PATH="$TMP:$PATH" \
WEB_WATCHDOG_ENABLED=true \
WEB_WATCHDOG_START_GRACE_SECONDS=1 \
WEB_WATCHDOG_INTERVAL_SECONDS=1 \
WEB_WATCHDOG_TIMEOUT_SECONDS=1 \
WEB_WATCHDOG_FAILURE_THRESHOLD=3 \
sh docker/web-start.sh > "$TMP/fail.log" 2>&1
rc=$?
set -e

if [ "$rc" -ne 1 ] || ! grep -q 'watchdog reached failure threshold' "$TMP/fail.log"; then
    cat "$TMP/fail.log"
    echo "Watchdog forced-restart scenario FAILED" >&2
    exit 1
fi

echo "Watchdog forced-restart scenario PASS"

# Scenario 2: two failures followed by recovery. The counter must clear and the
# supervisor must remain alive instead of reaching the threshold.
cat > "$TMP/curl" <<SH
#!/bin/sh
COUNT_FILE="$TMP/count"
count=0
[ -f "\$COUNT_FILE" ] && count=\$(cat "\$COUNT_FILE")
count=\$((count + 1))
echo "\$count" > "\$COUNT_FILE"
[ "\$count" -le 2 ] && exit 22
exit 0
SH
chmod +x "$TMP/curl"

set +e
timeout 8s env \
PATH="$TMP:$PATH" \
WEB_WATCHDOG_ENABLED=true \
WEB_WATCHDOG_START_GRACE_SECONDS=1 \
WEB_WATCHDOG_INTERVAL_SECONDS=1 \
WEB_WATCHDOG_TIMEOUT_SECONDS=1 \
WEB_WATCHDOG_FAILURE_THRESHOLD=3 \
sh docker/web-start.sh > "$TMP/recover.log" 2>&1
rc=$?
set -e

if ! grep -q 'Watchdog recovered after 2 consecutive failure(s)' "$TMP/recover.log"; then
    cat "$TMP/recover.log"
    echo "Watchdog recovery scenario FAILED" >&2
    exit 1
fi
if grep -q 'watchdog reached failure threshold' "$TMP/recover.log"; then
    cat "$TMP/recover.log"
    echo "Watchdog recovery scenario incorrectly restarted" >&2
    exit 1
fi
# timeout(1) returns 124 because the healthy supervisor intentionally keeps running.
if [ "$rc" -ne 124 ] && [ "$rc" -ne 0 ]; then
    cat "$TMP/recover.log"
    echo "Watchdog recovery harness returned unexpected code $rc" >&2
    exit 1
fi

echo "Watchdog recovery/reset scenario PASS"
