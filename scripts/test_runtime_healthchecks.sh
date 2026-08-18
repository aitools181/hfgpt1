#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
cleanup() {
    [ -n "${QPID:-}" ] && kill "$QPID" 2>/dev/null || true
    [ -n "${SPID:-}" ] && kill "$SPID" 2>/dev/null || true
    [ -n "${WPID1:-}" ] && kill "$WPID1" 2>/dev/null || true
    [ -n "${WPID2:-}" ] && kill "$WPID2" 2>/dev/null || true
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

# With Docker Compose init:true, PID 1 is an init process. Background supervisors
# persist the actual workload PID; health checks verify that exact child and its
# command line rather than accidentally matching an unrelated PHP process.
mkdir -p "$TMP/run"
bash -c 'exec -a "php -d memory_limit=288M artisan queue:work redis --queue=imports,default" sleep 30' & QPID=$!
printf '%s\n' "$QPID" > "$TMP/run/worker.pid"
sleep 0.2
sed "s#/run/happy-family#$TMP/run#g" docker/runtime-healthcheck.sh > "$TMP/background-healthcheck.sh"
chmod +x "$TMP/background-healthcheck.sh"
"$TMP/background-healthcheck.sh" worker
echo "Worker exact-child healthcheck PASS"

bash -c 'exec -a "php -d memory_limit=128M artisan schedule:work" sleep 30' & SPID=$!
printf '%s\n' "$SPID" > "$TMP/run/scheduler.pid"
sleep 0.2
"$TMP/background-healthcheck.sh" scheduler
echo "Scheduler exact-child healthcheck PASS"

# Web health must verify both supervised process PIDs, direct Nginx liveness and
# the independent FPM status listener. Mock curl so no network server is needed.
sleep 30 & WPID1=$!
sleep 30 & WPID2=$!
echo "$WPID1" > "$TMP/run/php-fpm.pid"
echo "$WPID2" > "$TMP/run/nginx.pid"

cat > "$TMP/curl" <<'SH2'
#!/bin/sh
url=""
for arg in "$@"; do case "$arg" in http://*|https://*) url="$arg";; esac; done
case "$url" in
  */__container_health) printf 'ok\n'; exit 0;;
  */__fpm_health) printf 'pong\n'; exit 0;;
  */__laravel_health) printf '{"status":"alive"}\n'; exit 0;;
  *) exit 22;;
esac
SH2
chmod +x "$TMP/curl"

sed "s#/run/happy-family#$TMP/run#g" docker/runtime-healthcheck.sh > "$TMP/web-healthcheck.sh"
chmod +x "$TMP/web-healthcheck.sh"
PATH="$TMP:$PATH" "$TMP/web-healthcheck.sh" web
echo "Web PID + Nginx + independent FPM + Laravel liveness healthcheck PASS"

kill "$WPID1"
wait "$WPID1" 2>/dev/null || true
WPID1=""
if PATH="$TMP:$PATH" "$TMP/web-healthcheck.sh" web; then
    echo "Web dead-PID healthcheck FAILED" >&2
    exit 1
fi
echo "Web dead-PID rejection PASS"

# Restore a live FPM PID, but make the FPM ping invalid. This must also be
# unhealthy even if Nginx itself responds.
sleep 30 & WPID1=$!
echo "$WPID1" > "$TMP/run/php-fpm.pid"
cat > "$TMP/curl" <<'SH2'
#!/bin/sh
url=""
for arg in "$@"; do case "$arg" in http://*|https://*) url="$arg";; esac; done
case "$url" in
  */__container_health) printf 'ok\n'; exit 0;;
  */__fpm_health) printf 'not-pong\n'; exit 0;;
  */__laravel_health) printf '{"status":"alive"}\n'; exit 0;;
  *) exit 22;;
esac
SH2
chmod +x "$TMP/curl"
if PATH="$TMP:$PATH" "$TMP/web-healthcheck.sh" web; then
    echo "Web invalid-FPM-ping healthcheck FAILED" >&2
    exit 1
fi
echo "Web invalid-FPM-ping rejection PASS"

# Restore valid FPM ping but make the isolated Laravel bootstrap request fail.
cat > "$TMP/curl" <<'SH2'
#!/bin/sh
url=""
for arg in "$@"; do case "$arg" in http://*|https://*) url="$arg";; esac; done
case "$url" in
  */__container_health) printf 'ok\n'; exit 0;;
  */__fpm_health) printf 'pong\n'; exit 0;;
  */__laravel_health) exit 22;;
  *) exit 22;;
esac
SH2
chmod +x "$TMP/curl"
if PATH="$TMP:$PATH" "$TMP/web-healthcheck.sh" web; then
    echo "Web invalid-Laravel-liveness healthcheck FAILED" >&2
    exit 1
fi
echo "Web invalid-Laravel-liveness rejection PASS"
