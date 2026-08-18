# v1.0.6 Self-Healing Web Watchdog

> **Superseded by v1.0.7:** the v1.0.6 three-failure hard-exit watchdog was too aggressive under transient PHP-FPM starvation. See `V1_0_7_RUNTIME_FAILURE_AUDIT.md`.

## Goal

Recover automatically when Nginx and PHP-FPM processes still exist but the HTTP/Laravel request path has become unresponsive.

## Recovery sequence

1. `happy-family-web` supervises Nginx and PHP-FPM PIDs.
2. After a 30-second startup grace period it requests `http://127.0.0.1/up` every 10 seconds.
3. Each request has a 5-second connect/total timeout.
4. A successful request clears the consecutive-failure counter.
5. Three consecutive failures cause the supervisor to log the condition and exit non-zero.
6. Docker's `restart: unless-stopped` policy starts the web container again.

## Why `/up` and not `/health/ready`

`/up` is Laravel's dependency-light liveness route and verifies Nginx -> PHP-FPM -> Laravel. `/health/ready` intentionally checks PostgreSQL, Redis/cache and required schema. Restarting the web container cannot repair a database or Redis outage, so dependency failures must not trigger a restart loop.

## Configuration

```env
WEB_WATCHDOG_ENABLED=true
WEB_WATCHDOG_URL=http://127.0.0.1/up
WEB_WATCHDOG_INTERVAL_SECONDS=10
WEB_WATCHDOG_TIMEOUT_SECONDS=5
WEB_WATCHDOG_FAILURE_THRESHOLD=3
WEB_WATCHDOG_START_GRACE_SECONDS=30
```

Production should normally keep the defaults.

## Operator diagnosis after a restart

Review logs immediately before the restart. Look for `watchdog reached failure threshold`, unexpected PHP-FPM/Nginx exit, OOM/137 exit status, disk-full errors, or host/Docker restart events. Automatic recovery keeps availability higher but does not erase the need to identify recurring root causes.
