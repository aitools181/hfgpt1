> **SUPERSEDED:** Historical v1.0.7 document. Use the v1.0.8 failure-prevention audit and current deployment/runbook documents for production.

# v1.0.7 Runtime Failure Audit

## Why v1.0.6 could stop after being healthy

The source review found a concrete false-exit path in v1.0.6.

The web watchdog called Laravel `/up` every 10 seconds with a 5-second timeout and terminated the entire web container after only 3 consecutive failures. `/up` is served through the same Nginx -> PHP-FPM application pool as normal requests. Therefore a temporarily busy/starved PHP-FPM pool could make `/up` miss the short timeout even when the Nginx and PHP-FPM master processes were still alive. The watchdog then intentionally exited the container.

This behavior was especially risky because the FPM pool inherited image defaults and had no explicit `request_terminate_timeout`, `pm.max_requests`, or production pool sizing. A few slow/blocked requests could occupy the small pool long enough to trip the watchdog.

## Additional confirmed runtime bug

`worker` and `scheduler` used healthchecks that searched `/proc/1/cmdline`. The Compose services also set `init: true`, which inserts an init process as PID 1. Therefore those healthchecks were checking the init process instead of the Laravel queue/scheduler child and could report false unhealthy status.

## v1.0.7 fixes

1. **Authoritative process supervision**
   - Actual Nginx/PHP-FPM process death still exits the web container immediately.
   - Docker `restart: unless-stopped` can then restart the container.

2. **Soft-recovery application watchdog**
   - Default check interval: 15 seconds.
   - Timeout: 5 seconds.
   - Failure threshold: 6 consecutive checks.
   - Startup grace: 60 seconds.
   - On threshold, PHP-FPM receives `SIGUSR2` to reload workers instead of killing the container.
   - A 45-second recovery grace follows each reload.
   - Only persistent failure after 2 soft recoveries exits the container.

3. **Docker/Coolify liveness separated from Laravel request load**
   - Nginx serves `/__container_health` directly without PHP.
   - Runtime healthcheck additionally confirms supervised Nginx and PHP-FPM PIDs are alive.
   - Deep dependency status remains at `/health/ready`.

4. **PHP-FPM starvation controls**
   - Explicit dynamic pool sizing.
   - `pm.max_children = 4`.
   - `pm.max_requests = 500` worker recycling.
   - `request_terminate_timeout = 180s`.
   - `request_slowlog_timeout = 20s`.

5. **Correct worker/scheduler healthchecks**
   - Process discovery scans actual `/proc/*/cmdline` children and no longer assumes PID 1 is the Laravel process.

6. **Failure evidence preserved in logs**
   - Before unrecoverable exit, web logs cgroup memory usage, memory/OOM events, load and disk usage.
   - `scripts/runtime_diagnose_host.sh` can inspect Docker restart policy, restart count, OOMKilled, exit code, health history and recent events on the Coolify host.

## Aggressive offline tests performed

- PHP syntax: all 144 PHP source/test files pass.
- Static source integrity: routes, Inertia pages, permissions, runtime invariants pass.
- Docker Compose and GitHub Actions YAML parse successfully.
- JSON manifests parse successfully.
- All shell scripts pass `sh -n`.
- Watchdog transient-failure test: threshold causes a PHP-FPM soft reload and **does not exit** the container.
- Watchdog persistent-failure test: only repeated unrecoverable failure escalates to container exit.
- Critical PHP-FPM process-death test: immediate supervisor failure remains intact.
- Worker healthcheck test with a child process behind an init-style PID arrangement passes.
- Scheduler healthcheck child-process test passes.
- Web PID + direct HTTP liveness test passes and rejects a dead supervised PID.
- Nginx config syntax test passes using the installed Nginx parser.
- Direct Nginx liveness endpoint was started locally and returned HTTP 200.

## Runtime test limitation in this execution environment

The local execution environment does not provide Docker or Composer dependencies, so the complete Laravel/PostgreSQL/Redis container integration suite cannot be executed here. GitHub Actions has been expanded to run the real Compose runtime tests, verify worker/scheduler health and deliberately kill PHP-FPM to prove Docker restart recovery.

## How to identify a future exit exactly

On the Coolify host, find the web container and run:

```bash
scripts/runtime_diagnose_host.sh <web-container-name-or-id>
```

The important fields are `RestartPolicy`, `RestartCount`, `ExitCode`, `OOMKilled`, Docker health history and the lines immediately before the exit.
