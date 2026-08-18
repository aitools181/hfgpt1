# Coolify Deployment Guide - SMVS Happy Family Portal v1.0.9

Deploy this repository as a **Docker Compose** resource. Do not deploy the Dockerfile as a single Coolify Application and do not upload `vendor/` or `node_modules/`.

## 1. GitHub source

Push the extracted release to the private repository/branch used by Coolify. If the repository is private, Coolify must keep authenticated access through its GitHub App or deploy key. Making a repository private does not stop an already-running container; missing repository access affects later fetch/build/redeploy operations.

## 2. Compose services

The resource must show these services:

- `web` - public Nginx + PHP-FPM/Laravel runtime and migration bootstrap
- `worker` - queue supervisor/worker
- `scheduler` - scheduler supervisor
- `db` - PostgreSQL 17
- `redis` - Redis 8 queue service

Attach the domain only to `web`, container port 80. Do not attach a public domain to the other services.

## 3. Required production environment

Use Coolify environment variables; never commit the real `.env`.

```env
APP_NAME="SMVS Happy Family"
APP_ENV=production
APP_KEY=base64:<32-BYTE-LARAVEL-KEY>
APP_DEBUG=false
APP_URL=https://YOUR_DOMAIN
APP_TIMEZONE=Asia/Kolkata
APP_LOCALE=en
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_DATABASE=happy_family
DB_USERNAME=happy_family
DB_PASSWORD=<STRONG_RANDOM_PASSWORD>
DB_SSLMODE=prefer
PGCONNECT_TIMEOUT=5

SESSION_SECURE_COOKIE=true
REDIS_PASSWORD=<OPTIONAL_STRONG_INTERNAL_REDIS_PASSWORD>

SEED_ON_BOOT=true
SUPER_ADMIN_NAME="SMVS Super Admin"
SUPER_ADMIN_EMAIL=<ADMIN_EMAIL>
SUPER_ADMIN_PASSWORD=<UNIQUE_PASSWORD_AT_LEAST_16_CHARS>

PILOT_DATA=false
```

Generate the application key with:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

## 4. v1.0.8+ self-healing defaults

These values are already supplied by Compose and normally do not need to be added manually:

```env
WEB_WATCHDOG_ENABLED=true
WEB_WATCHDOG_URL=http://127.0.0.1/__laravel_health
WEB_WATCHDOG_INTERVAL_SECONDS=15
WEB_WATCHDOG_TIMEOUT_SECONDS=4
WEB_WATCHDOG_FAILURE_THRESHOLD=4
WEB_WATCHDOG_START_GRACE_SECONDS=60
WEB_PROCESS_RESTART_MAX=5
WEB_PROCESS_RESTART_WINDOW_SECONDS=600
WEB_PROCESS_STOP_GRACE_SECONDS=5
WEB_PROCESS_RESTART_BACKOFF_SECONDS=2
WEB_PROCESS_RESTART_MAX_BACKOFF_SECONDS=20
WEB_INFRA_PROBE_INTERVAL_SECONDS=10
WEB_INFRA_PROBE_TIMEOUT_SECONDS=3
WEB_INFRA_PROBE_FAILURE_THRESHOLD=3
BACKGROUND_RESTART_BACKOFF_SECONDS=3
BACKGROUND_RESTART_MAX_BACKOFF_SECONDS=30
BACKGROUND_STABLE_RESET_SECONDS=300
```

Do not set the watchdog to the deep `/health/ready` endpoint. The web watchdog intentionally tests dependency-light Laravel liveness; database/Redis outages must not create web restart storms.

## 5. Resource defaults

Current defaults:

```env
WEB_MEMORY_LIMIT=1280m
WORKER_MEMORY_LIMIT=448m
SCHEDULER_MEMORY_LIMIT=192m
DB_MEMORY_LIMIT=768m
DB_SHM_SIZE=256m
REDIS_MEMORY_LIMIT=256m
REDIS_MAXMEMORY=160mb

POSTGRES_SHARED_BUFFERS=96MB
POSTGRES_WORK_MEM=2MB
POSTGRES_MAINTENANCE_WORK_MEM=64MB
POSTGRES_MAX_CONNECTIONS=30
POSTGRES_STATEMENT_TIMEOUT=120000
POSTGRES_IDLE_TX_TIMEOUT=60000
HEALTH_MIN_DISK_FREE_MB=512
```

Before production, run `scripts/production_preflight.sh` on the Coolify server. The default check requires at least 4.5 GB RAM and 10 GB free root disk. More capacity is recommended for large imports/reports and future data growth.

## 6. Persistent volumes

Do not delete these during upgrades:

- `postgres_data`
- `redis_data`
- `app_storage`
- `app_private`
- `app_logs`
- `app_sessions`

`app_sessions` preserves authenticated file sessions across web container recreation. Redis is queue-focused; a temporary Redis outage therefore does not take down normal logged-in web requests.

## 7. Startup order

1. PostgreSQL becomes healthy.
2. `web` runs migrations and idempotent production seeders.
3. web validates/starts PHP-FPM and Nginx and becomes healthy.
4. worker/scheduler wait for healthy web, preventing background jobs from racing migrations.
5. worker also waits for healthy Redis.

If a migration/bootstrap fails, the new service must not be treated as healthy. Inspect logs rather than deleting the database.

## 8. Health checks

Test after every deployment:

```text
https://YOUR_DOMAIN/up
https://YOUR_DOMAIN/health/ready
```

Expected `/health/ready` in normal operation:

- `status=ready`
- database true
- cache true
- redis true
- schema true
- storage true
- disk true
- no missing required tables/columns

Redis readiness includes a tiny expiring write/read/delete test so a memory-full `noeviction` Redis is reported degraded even when PING still works.

## 9. Automatic recovery behavior

- One PHP-FPM/Nginx crash: in-container supervisor restarts the failed process first.
- Live PID but unresponsive Nginx/FPM: dedicated probes recycle the relevant process.
- Temporary Laravel liveness problem: isolated watchdog recovers FPM without blaming DB/Redis or busy interactive workers.
- Repeated unrecoverable web failures: supervisor exits and Docker `restart: always` recreates the web container.
- queue/scheduler child exit: background supervisor respawns the child without stopping the service container.
- db/redis process exit: Docker `restart: always` restarts the service.

## 10. Logs and diagnosis

Nginx/application/service logs go through Docker/Coolify logging with rotation. Runtime supervisor logs are bounded and non-fatal.

For a real incident, preserve evidence before redeploying when possible:

```bash
scripts/runtime_diagnose_host.sh <web-container-name-or-id>
```

Also inspect:

```bash
docker inspect <container> --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} OOMKilled={{.State.OOMKilled}} Restart={{.HostConfig.RestartPolicy.Name}} RestartCount={{.RestartCount}}'
journalctl -k --since "2 hours ago" | grep -Ei 'out of memory|oom|killed process'
```

## 11. Update procedure

1. Take a verified backup.
2. Push/tag the exact reviewed release commit.
3. Require GitHub Actions CI to be green.
4. Redeploy the same existing Coolify Compose resource.
5. Do not delete PostgreSQL/Redis/application volumes.
6. Verify all five service health states plus `/health/ready`.
7. Run the focused tester sheet/acceptance matrix.

## 12. Large imports and reports

- Prefer CSV/TSV for very large imports.
- CSV/TSV and worksheet rows stream rather than loading the complete file into memory.
- XLSX has decompression/shared-string/row/cell safety limits.
- Imports run on the queue worker with retries/backoff and a bounded PHP memory limit.
- report previews are capped; CSV export streams lazily through a dedicated FPM pool.

## 13. Production acceptance

A release is production-accepted only after the exact commit is green in GitHub CI and the deployed target passes health + smoke/acceptance checks. The packaging environment cannot execute Docker/Composer/NPM runtime integration, so those checks are intentionally performed by CI/Coolify rather than claimed without evidence.
