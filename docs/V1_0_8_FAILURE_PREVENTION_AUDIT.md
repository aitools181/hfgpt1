# v1.0.8 Failure Prevention Audit

Audit date: 2026-08-18

## Goal

This pass specifically reviewed the failure modes previously observed in Coolify: a service could show Running/Healthy, later become unavailable, or require a manual redeploy. The review covered Nginx, PHP-FPM, Laravel liveness, queue/scheduler lifecycle, Redis/PostgreSQL dependency behavior, memory/OOM pressure, disk/log growth, large reports/imports, startup migration races and Docker restart behavior.

No software stack can truthfully guarantee zero failures: host power loss, provider/network outage, Docker daemon failure, filesystem/storage failure, kernel failure, corrupted persistent data or a bad external dependency can still cause downtime. v1.0.8 is designed so the known application/container failure paths are bounded, diagnosed and automatically recovered wherever recovery is safe.

## Confirmed/credible failure paths and v1.0.8 treatment

| Failure path | Why it could happen | v1.0.8 containment/recovery |
|---|---|---|
| PHP-FPM master exits | OOM/SIGKILL/native crash/external signal | web supervisor detects PID death, captures runtime/OOM evidence, restarts FPM in-container with bounded backoff |
| PHP-FPM master alive but not serving | stuck control/listener/worker state | dedicated control pool + repeated FPM ping probes recycle FPM even when PID is still alive |
| Nginx master exits | OOM/external signal/native failure | supervisor detects exit and restarts Nginx in-container |
| Nginx alive but unresponsive | worker/socket hang | Nginx-direct probe detects repeated non-response and recycles Nginx |
| Laravel path temporarily busy | normal worker saturation / long request | isolated health FPM pool prevents interactive traffic from causing a false liveness failure |
| Persistent web failure | repeated FPM/Nginx/Laravel recovery cannot restore service | supervisor exits only after bounded recovery budget; Docker `restart: always` is the final recovery layer |
| Redis unavailable | Redis restart/network/transient queue failure | web sessions/cache are file based, so the portal remains available; queue worker self-restarts/backoffs; deep readiness reports Redis degraded |
| Redis responds but is out of writable memory | `noeviction` can answer PING yet reject writes | `/health/ready` performs a tiny expiring Redis write/read/delete check, not just PING |
| Queue worker exits intentionally or crashes | `--max-time`, `--max-jobs`, transient dependency error | persistent background supervisor respawns the worker inside the same container with exponential backoff |
| Scheduler exits/crashes | transient DB/app error | persistent background supervisor respawns scheduler without requiring a Compose redeploy |
| Background job races migrations | worker/scheduler could start as soon as DB was healthy | worker and scheduler now wait for the migrated, healthy web service at deployment startup |
| Host/container OOM from large reports | prior unbounded Eloquent `get()` / high PHP limits | preview cap, lazy DB streaming, dedicated report pool, process memory limits and service memory ceilings |
| Host/container OOM from large imports | XLSX/CSV could allocate whole input/results | row streaming, XMLReader XLSX parser, zip-bomb/shared-string/row/cell limits, bounded worker PHP memory |
| Host OOM caused by one service | no resource boundaries | memory and PID ceilings on web/worker/scheduler/PostgreSQL/Redis; conservative PostgreSQL/Redis limits |
| File-descriptor exhaustion | low inherited `nofile` limit under sustained socket/file activity | explicit 65,535 soft/hard `nofile` limit for every runtime service |
| Disk exhaustion from logs | Debian Nginx file logs / runtime logs can grow | Nginx logs to stdout/stderr, Docker JSON log rotation, bounded supervisor log rotation, best-effort log writes |
| Diagnostic log path becomes read-only/full | `set -e` logging failure could kill supervisor | supervisor/background logging is non-fatal and continues via stderr when persistent file logging fails |
| PostgreSQL query hangs too long | blocked/expensive query | server statement/idle-transaction timeouts and `PGCONNECT_TIMEOUT` bound connection/query stalls |
| Deployment starts with incomplete schema | background services race web bootstrap | web runs migrations before it starts serving; worker/scheduler wait for web health |
| Transient package registry/build failure | network hiccup during NPM/Composer/APT | Docker build dependency steps have retry behavior and support lockfiles when present |
| Repeated expensive endpoint bursts | login/import/export abuse or accidental repeated actions | endpoint throttles plus isolated report worker |

## Process architecture

```text
Coolify / Traefik
       |
       v
web container (restart: always, memory/PID bounded)
  |- Nginx
  |- PHP-FPM main interactive pool
  |- PHP-FPM control/ping pool
  |- PHP-FPM report-export pool
  |- PHP-FPM isolated Laravel-health pool
  `- supervisor/watchdog

worker container
  `- persistent supervisor -> Laravel queue child (recycled/restarted in-container)

scheduler container
  `- persistent supervisor -> Laravel scheduler child (restarted in-container)

PostgreSQL and Redis have their own restart policies, healthchecks, persistent volumes and resource ceilings.
```

## Why `Running` can no longer be treated as sufficient

v1.0.8 distinguishes four signals:

- Process existence: supervised PID is alive.
- Infrastructure responsiveness: Nginx direct endpoint and dedicated FPM ping respond.
- Laravel liveness: isolated `/up` execution responds without DB/Redis dependency checks.
- Deep readiness: `/health/ready` checks PostgreSQL, file cache, Redis read/write, required schema, writable storage and free disk.

This prevents a process that is merely alive from being mistaken for a usable service, while also avoiding destructive restart loops when PostgreSQL/Redis is the real dependency problem.

## Memory failure prevention

Default container ceilings are approximately:

- web: 1280 MB
- worker: 448 MB
- scheduler: 192 MB
- PostgreSQL: 768 MB
- Redis: 256 MB

The production host preflight therefore requires at least 4.5 GB RAM by default, leaving additional space beyond these service ceilings for Coolify, Docker, Traefik and the OS. A larger host is preferable as real usage grows.

The web interactive FPM pool is bounded and recycled. CSV exports use a separate one-child pool. Import jobs have a lower PHP process limit than the worker container limit, so a malformed/oversized import fails as a job rather than consuming the whole host.

## Remaining external failure classes

v1.0.8 cannot automatically repair hardware/provider/network failure, a stopped Docker daemon, filesystem corruption, a destroyed persistent volume, database corruption, an invalid application release that passes no healthcheck, or an exhausted host disk caused by legitimate database/upload growth. These require infrastructure monitoring, external backups and operational response. `scripts/runtime_diagnose_host.sh`, `scripts/production_preflight.sh`, backup/restore tooling and GitHub CI are included for those cases.
