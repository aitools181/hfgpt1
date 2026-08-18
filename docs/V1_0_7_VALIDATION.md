> **SUPERSEDED:** Historical v1.0.7 document. Use the v1.0.8 failure-prevention audit and current deployment/runbook documents for production.

# v1.0.7 Validation Report

Date: 2026-08-18

## Result

Offline source/runtime-harness validation passed for the v1.0.7 runtime-failure fix.

## Executed checks

| Check | Result |
|---|---|
| PHP syntax | PASS - 144 files |
| Static source integrity | PASS |
| Inertia page references | PASS - 32 pages |
| Named route uniqueness/reference checks | PASS - 83 routes |
| Seeded permission consistency | PASS - 43 permissions |
| Runtime watchdog transient failure | PASS - soft reload, no false container exit |
| Runtime watchdog persistent failure | PASS - escalates only after soft recoveries |
| Critical PHP-FPM process death | PASS - supervisor exits for Docker restart |
| Worker child-process healthcheck | PASS |
| Scheduler child-process healthcheck | PASS |
| Web PID + Nginx-direct healthcheck | PASS |
| Dead web PID rejection | PASS |
| Docker Compose YAML | PASS |
| GitHub Actions YAML | PASS |
| Composer/package/tsconfig JSON | PASS |
| Shell syntax | PASS - 9 scripts |
| TypeScript/TSX syntax transpile | PASS - 37 files |
| Nginx configuration parser | PASS |
| Nginx direct `__container_health` runtime probe | PASS - HTTP 200 |
| Secret/unwanted artifact scan | PASS |
| Merge conflict/debug call scan | PASS |
| Existing PHPUnit inventory | 17 files / 96 test methods / ~302 assertion calls |
| Release manifest verification | PASS - 245 entries |

## Confirmed bugs fixed

1. v1.0.6 could intentionally exit the web container after only three short `/up` failures. A busy PHP-FPM application pool could create these failures even though the Nginx/PHP-FPM master processes had not crashed.
2. Worker and scheduler healthchecks inspected `/proc/1/cmdline` while Compose used `init: true`; PID 1 is the init process, not the Laravel worker/scheduler.
3. PHP-FPM had no explicit request timeout, worker recycling or production pool limits, increasing starvation/leak/OOM risk.
4. CI used `pkill` for the restart test although that utility is not guaranteed in the production image; the test now uses the supervised PHP-FPM PID file.

## Environment limitation

The execution environment used for this audit does not contain Docker, Composer-installed `vendor/`, or project `node_modules`. Therefore the real Laravel/PostgreSQL/Redis integration suite, full TypeScript typecheck, Vite production build and Docker Compose runtime cannot be executed locally here. GitHub Actions is configured to perform those gates using the actual dependencies and Docker engine, including web restart recovery plus worker/scheduler health checks.
