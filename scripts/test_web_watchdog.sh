#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
cleanup() {
    pkill -KILL -f "$TMP/php-fpm" 2>/dev/null || true
    pkill -KILL -f "$TMP/nginx" 2>/dev/null || true
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

stop_test_supervisor() {
    pid="$1"
    kill -TERM "$pid" 2>/dev/null || true
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        kill -0 "$pid" 2>/dev/null || break
        sleep 1
    done
    if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null || true; fi
    wait "$pid" 2>/dev/null || true
}

cat > "$TMP/php" <<'SH2'
#!/bin/sh
# Socket readiness is controlled by process behavior in this offline harness.
exit 0
SH2
chmod +x "$TMP/php"

cat > "$TMP/curl-ok" <<'SH2'
#!/bin/sh
for arg in "$@"; do case "$arg" in */__fpm_health) printf 'pong\n';; */__laravel_health) printf '{"status":"alive"}\n';; esac; done
exit 0
SH2
chmod +x "$TMP/curl-ok"

write_stable_nginx() {
cat > "$TMP/nginx" <<SH2
#!/bin/sh
if [ "\${1:-}" = "-t" ]; then exit 0; fi
count=0
[ -f "$TMP/nginx_starts" ] && count=\$(cat "$TMP/nginx_starts")
case "\$count" in ''|*[!0-9]*) count=0;; esac
count=\$((count + 1)); echo "\$count" > "$TMP/nginx_starts"
exec python3 -c 'import signal,sys,time; [signal.signal(sig, lambda *_: sys.exit(0)) for sig in (signal.SIGQUIT, signal.SIGTERM, signal.SIGINT)]; time.sleep(3600)'
SH2
chmod +x "$TMP/nginx"
}

write_stable_fpm() {
cat > "$TMP/php-fpm" <<SH2
#!/bin/sh
if [ "\${1:-}" = "-tt" ]; then exit 0; fi
count=0
[ -f "$TMP/fpm_starts" ] && count=\$(cat "$TMP/fpm_starts")
case "\$count" in ''|*[!0-9]*) count=0;; esac
count=\$((count + 1)); echo "\$count" > "$TMP/fpm_starts"
exec python3 -c 'import signal,sys,time; [signal.signal(sig, lambda *_: sys.exit(0)) for sig in (signal.SIGQUIT, signal.SIGTERM, signal.SIGINT)]; time.sleep(3600)'
SH2
chmod +x "$TMP/php-fpm"
}

# Scenario 1: PHP-FPM crash gets one successful in-container respawn before the
# bounded recovery budget is exhausted by a second crash. This proves that one
# master failure does not immediately terminate the web container.
rm -f "$TMP/fpm_starts" "$TMP/nginx_starts"
write_stable_nginx
cp "$TMP/curl-ok" "$TMP/curl"
cat > "$TMP/php-fpm" <<SH2
#!/bin/sh
if [ "\${1:-}" = "-tt" ]; then exit 0; fi
count=0
[ -f "$TMP/fpm_starts" ] && count=\$(cat "$TMP/fpm_starts")
case "\$count" in ''|*[!0-9]*) count=0;; esac
count=\$((count + 1)); echo "\$count" > "$TMP/fpm_starts"
exec python3 -c 'import time,sys; time.sleep(1); sys.exit(9)'
SH2
chmod +x "$TMP/php-fpm"
set +e
env PATH="$TMP:$PATH" WEB_WATCHDOG_ENABLED=false WEB_PROCESS_RESTART_MAX=1 WEB_PROCESS_RESTART_WINDOW_SECONDS=60 WEB_PROCESS_STOP_GRACE_SECONDS=1 RUNTIME_LOG_MAX_BYTES=1048576 sh docker/web-start.sh > "$TMP/fpm.log" 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || { cat "$TMP/fpm.log"; echo "FPM recovery harness expected final exit 1" >&2; exit 1; }
[ "$(cat "$TMP/fpm_starts")" -eq 2 ] || { cat "$TMP/fpm.log"; echo "FPM was not respawned exactly once" >&2; exit 1; }
grep -q "recovering PHP-FPM reason='PHP-FPM master exited unexpectedly' attempt=1/1" "$TMP/fpm.log"
grep -q 'PHP-FPM could not be recovered after repeated failures' "$TMP/fpm.log"
echo "PHP-FPM in-container recovery + bounded escalation PASS"

# Scenario 2: same behavior for Nginx.
rm -f "$TMP/fpm_starts" "$TMP/nginx_starts"
write_stable_fpm
cp "$TMP/curl-ok" "$TMP/curl"
cat > "$TMP/nginx" <<SH2
#!/bin/sh
if [ "\${1:-}" = "-t" ]; then exit 0; fi
count=0
[ -f "$TMP/nginx_starts" ] && count=\$(cat "$TMP/nginx_starts")
case "\$count" in ''|*[!0-9]*) count=0;; esac
count=\$((count + 1)); echo "\$count" > "$TMP/nginx_starts"
exec python3 -c 'import time,sys; time.sleep(1); sys.exit(7)'
SH2
chmod +x "$TMP/nginx"
set +e
env PATH="$TMP:$PATH" WEB_WATCHDOG_ENABLED=false WEB_PROCESS_RESTART_MAX=1 WEB_PROCESS_RESTART_WINDOW_SECONDS=60 WEB_PROCESS_STOP_GRACE_SECONDS=1 RUNTIME_LOG_MAX_BYTES=1048576 sh docker/web-start.sh > "$TMP/nginx.log" 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || { cat "$TMP/nginx.log"; echo "Nginx recovery harness expected final exit 1" >&2; exit 1; }
[ "$(cat "$TMP/nginx_starts")" -eq 2 ] || { cat "$TMP/nginx.log"; echo "Nginx was not respawned exactly once" >&2; exit 1; }
grep -q "recovering Nginx reason='Nginx master exited unexpectedly' attempt=1/1" "$TMP/nginx.log"
grep -q 'Nginx could not be recovered after repeated failures' "$TMP/nginx.log"
echo "Nginx in-container recovery + bounded escalation PASS"

# Scenario 3: repeated isolated Laravel liveness probe failures cause an
# in-container FPM restart rather than an immediate container exit. The second
# FPM instance then deliberately dies so the finite harness exits naturally.
rm -f "$TMP/fpm_starts" "$TMP/nginx_starts" "$TMP/watchdog_count"
write_stable_nginx
cat > "$TMP/php-fpm" <<SH2
#!/bin/sh
if [ "\${1:-}" = "-tt" ]; then exit 0; fi
count=0
[ -f "$TMP/fpm_starts" ] && count=\$(cat "$TMP/fpm_starts")
case "\$count" in ''|*[!0-9]*) count=0;; esac
count=\$((count + 1)); echo "\$count" > "$TMP/fpm_starts"
if [ "\$count" -eq 1 ]; then
  exec python3 -c 'import signal,sys,time; [signal.signal(sig, lambda *_: sys.exit(0)) for sig in (signal.SIGQUIT, signal.SIGTERM, signal.SIGINT)]; time.sleep(3600)'
fi
exec python3 -c 'import time,sys; time.sleep(1); sys.exit(9)'
SH2
chmod +x "$TMP/php-fpm"
cat > "$TMP/curl" <<SH2
#!/bin/sh
url=""
for arg in "\$@"; do case "\$arg" in http://*|https://*) url="\$arg";; esac; done
case "\$url" in
  */__container_health) exit 0;;
  */__fpm_health) printf 'pong\n'; exit 0;;
  */__laravel_health) exit 22;;
  *) exit 0;;
esac
SH2
chmod +x "$TMP/curl"
set +e
env PATH="$TMP:$PATH" WEB_WATCHDOG_ENABLED=true WEB_WATCHDOG_START_GRACE_SECONDS=1 WEB_WATCHDOG_INTERVAL_SECONDS=1 WEB_WATCHDOG_TIMEOUT_SECONDS=1 WEB_WATCHDOG_FAILURE_THRESHOLD=2 WEB_PROCESS_RESTART_MAX=1 WEB_PROCESS_RESTART_WINDOW_SECONDS=60 WEB_PROCESS_STOP_GRACE_SECONDS=1 RUNTIME_LOG_MAX_BYTES=1048576 sh docker/web-start.sh > "$TMP/watchdog.log" 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || { cat "$TMP/watchdog.log"; echo "Watchdog harness expected final bounded exit 1" >&2; exit 1; }
grep -q "recovering PHP-FPM reason='isolated Laravel liveness remained unreachable' attempt=1/1" "$TMP/watchdog.log"
[ "$(cat "$TMP/fpm_starts")" -eq 2 ] || { cat "$TMP/watchdog.log"; echo "Watchdog did not restart FPM" >&2; exit 1; }
echo "Watchdog isolated-Laravel recovery without false immediate exit PASS"
