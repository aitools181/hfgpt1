# v1.0.8 Validation Report

Date: 2026-08-18

## Offline checks executed in the build environment

- PHP syntax lint: **PASS - 145 PHP files** across application/bootstrap/config/database/routes/tests.
- POSIX shell syntax: **PASS** across Docker/runtime/release scripts.
- TypeScript/TSX parser/transpile syntax: **PASS - 37 files** (including Vite config) using the installed TypeScript compiler parser without dependency resolution.
- Static source integrity: **PASS**.
  - Inertia pages referenced: 32.
  - Named routes: 90 unique.
  - Seeded permissions: 43.
  - Used route/navigation permissions: 39, all defined.
- JSON manifests / PHPUnit XML / Docker Compose YAML / GitHub Actions YAML: **PASS**.
- Nginx configuration syntax (`nginx -t` with the production server block and FastCGI params): **PASS**.
- Web supervisor fault simulations: **PASS** for PHP-FPM crash recovery, Nginx crash recovery, bounded escalation and isolated-Laravel watchdog recovery without immediate false exit.
- Worker/scheduler background supervisor simulations: **PASS** for intentional worker recycling and scheduler transient-crash recovery.
- Runtime healthcheck simulations: **PASS** for web/worker/scheduler, dead PID rejection, invalid FPM ping rejection and invalid Laravel-liveness rejection.
- Bootstrap fail-fast tests: **PASS** for valid configuration and rejection of invalid APP_KEY / APP_URL.
- Streaming import stress: **PASS** for 100,000 CSV rows with peak PHP memory approximately **2 MiB** in the standalone parser harness.
- Import safety: **PASS** for duplicate normalized-header rejection and oversized-cell rejection.
- PHPUnit source coverage present: **98 test methods / 312 assertion calls** in the repository.

## Failure-prevention invariants reviewed

- Every long-running Compose service has `restart: always`, healthcheck, memory cap, PID cap and Docker log rotation.
- Web liveness is independent of PostgreSQL/Redis readiness.
- Web sessions/cache are file-backed and sessions use a persistent volume; a Redis queue outage does not intentionally terminate the web runtime.
- Web supervisor detects both dead processes and live-but-unresponsive Nginx/FPM paths and performs bounded in-container recovery before Docker-level restart.
- Worker and scheduler supervise their child process and wait for a healthy, migrated web service during deployment startup.
- PostgreSQL has bounded connection/work memory/query timeouts; Redis has AOF, bounded memory and no-eviction semantics.
- `/health/ready` tests Redis write capability rather than accepting PING alone.
- Large report previews are bounded and CSV rows are generated lazily in a dedicated FPM pool.
- CSV/TSV/XLSX import parsing is memory-bounded; XLSX has zip-bomb and content-size guards.
- Nginx output goes to stdout/stderr; Docker and persistent supervisor logs are size bounded.
- Production host preflight requires adequate RAM/disk before rollout.

## Real runtime gates intentionally delegated to GitHub CI / Coolify

This execution environment has no Docker daemon, no installed project Composer `vendor/`, no installed project `node_modules`, and package-network access is unavailable. Therefore the following are **not** falsely claimed as locally executed:

- full Laravel `php artisan test` against installed framework dependencies;
- PostgreSQL/Redis integration suite;
- real TypeScript typecheck with React/Inertia package typings;
- Vite production build;
- real Docker image / Docker Compose runtime fault injection.

GitHub Actions is configured to perform these networked/runtime checks. Its Compose job deliberately kills queue/scheduler children and PHP-FPM, verifies in-container recovery, stops Redis to verify web liveness independence, and exhausts the bounded PHP-FPM recovery budget to verify Docker `restart: always` recovery.

## Acceptance rule

No application can guarantee zero downtime against host power loss, network/provider failure, Docker daemon failure, filesystem/database corruption or physical storage loss. For the failure classes controlled by this repository, v1.0.8 removes or contains the known failure paths and adds recovery/diagnostics.

Do not call a production deployment accepted until the **exact Git commit** passes GitHub CI, `scripts/production_preflight.sh` passes on the Coolify host, all five services are Running/Healthy, and `/health/ready` returns HTTP 200 under normal dependency conditions.
