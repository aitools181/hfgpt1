# Architecture

## Goal

One centralized, role-based, multi-Center web application with strict organizational scoping, auditable administrative actions, bounded memory use and automatic recovery from ordinary process failures.

## Production runtime topology (v1.0.8)

```text
Internet
  |
Coolify / Traefik HTTPS proxy
  |
web container :80  (restart: always)
  |-- Nginx
  |-- PHP-FPM [www]     :9000  interactive portal
  |-- PHP-FPM [control] :9001  isolated FPM ping/status
  |-- PHP-FPM [reports] :9002  isolated CSV export
  |-- PHP-FPM [health]  :9003  isolated Laravel liveness
  `-- web supervisor / recovery probes
       |
       +---- PostgreSQL 17  (durable source of truth)
       `---- Redis 8      (queue transport; not required for web sessions/cache)

worker container     -> Redis queue + PostgreSQL
scheduler container  -> PostgreSQL (and application services when scheduled work runs)

Persistent volumes:
- postgres_data
- redis_data
- app_storage
- app_private
- app_logs
- app_sessions
```

Nginx and PHP-FPM intentionally share the `web` container so public HTTP traffic never depends on cross-container FastCGI DNS. Background queue and scheduler workloads remain isolated in their own containers.

## Availability and failure containment

The web runtime uses layered recovery rather than treating every transient error as a reason to stop the service.

1. Docker/Compose uses `restart: always` for all long-running services.
2. `happy-family-web` supervises the Nginx and PHP-FPM master processes.
3. Direct infrastructure probes detect a process that is still present but no longer responsive.
4. An isolated Laravel `/up` probe runs through a dedicated FPM pool, so a busy normal request pool cannot create a false liveness failure.
5. Nginx/PHP-FPM are first recycled inside the same container with bounded exponential backoff.
6. Only repeated unrecoverable failure makes the web supervisor exit, allowing Docker's restart policy to recreate the runtime.
7. Queue worker and scheduler child processes are supervised in-container and restarted with bounded backoff when they recycle or crash.
8. `/health/ready` is a dependency-readiness endpoint and checks PostgreSQL, Redis queue writability, schema, writable storage and free disk. Dependency degradation does not intentionally kill an otherwise healthy web process.

The container healthcheck uses independent Nginx, FPM and Laravel liveness paths. This prevents the previous false-positive failure mode where all normal PHP workers being busy could make the entire container appear dead.

## Resource and OOM protection

The SRS scale target is approximately 10,000 Karyakars and 100,000 families. v1.0.8 prevents a single workload from consuming unbounded host memory:

- explicit Compose memory/PID ceilings for web, worker, scheduler, PostgreSQL and Redis;
- conservative PostgreSQL connection and memory settings;
- Redis `maxmemory` with `noeviction` so queue data is not silently discarded;
- bounded PHP-FPM pools and periodic worker recycling;
- a dedicated report FPM pool so a large export cannot starve interactive requests;
- browser report previews capped at 500 rows;
- CSV report export generated lazily with database cursor-style iteration;
- CSV/TSV imports streamed row-by-row;
- XLSX worksheets streamed with `XMLReader`, with zip-bomb, row, cell, column and shared-string safety bounds;
- queue workers recycle by memory/job/time limits;
- Docker JSON logs, Nginx logs and supervisor logs are bounded/rotated.

`scripts/production_preflight.sh` rejects a host below the recommended production RAM/disk floor before rollout. Resource ceilings are not a substitute for adequate host capacity; they contain a workload so it cannot consume all host memory.

## Application pattern

- Laravel owns routing, validation, authorization, transactions and persistence.
- Inertia bridges Laravel routes/controllers to React pages.
- React/TypeScript renders the responsive authenticated portal.
- PostgreSQL is the source of truth for relational domain data.
- File-backed cache and sessions keep ordinary web login/navigation available during a temporary Redis outage.
- Redis is used for queued background work such as imports.
- The scheduler runs inactivity/reminder processing and other scheduled Laravel tasks.

## Access-control design

Permissions and organizational scope are separate concepts.

- Role answers: **what may the user do?**
- Scope answers: **which Zone/Center records may the user do it to?**

`user_roles` stores role plus optional `zone_id` and `center_id`. Server-side authorization remains authoritative; frontend visibility is not treated as a security boundary.

## Audit design

`audit_logs` stores:

- actor user and role;
- timestamp;
- Zone and Center where applicable;
- module/action;
- record type/reference;
- old values;
- new values;
- reason/change note where supplied;
- IP address and user agent.

Sensitive values such as passwords and tokens are never written to the audit payload.

## Group and assignment integrity

- `groups` - Center-scoped Group master with Center Code based `group_code`, type and lifecycle.
- `group_karyakars` - exactly two active Karyakars per Group at creation; a Karyakar may appear in multiple Groups.
- `group_family_assignments` - ten-slot assignment history with `fixed` / `remaining`, source, transfer closure and reason.
- `remaining_family_reports` - controlled Karyakar-to-Center-Admin reporting for newly identified Remaining Families.
- `targets` - Center/Group/Karyakar/Area/date/quantity target assignments.

A partial unique database index permits only one active Group assignment for a Family. Service transactions also lock Group/Family rows during assignment or transfer. Group activation is blocked unless composition is exactly ten active Families with either 5 Fixed + 5 Remaining or 6 Fixed + 4 Remaining.

## Field execution

Durable operational records include:

- `home_visits` - one completion per active Group Family assignment;
- `karyakar_badges` - idempotent milestone history for 3/6/9/12/15 completed Families;
- `inactivity_events` - Reminder/Alert history, escalation and resolution.

Home Visit recording runs in a database transaction, locks the relevant assignment, validates scope and membership, records the completion, recalculates relevant targets, awards due badges and resolves inactivity state. Database uniqueness is the final duplicate-completion guard.

## Monitoring and reporting

`MonitoringAnalyticsService` applies organizational scope before aggregation. `ReportService` provides the ten minimum SRS reports. v1.0.8 uses bounded browser previews and lazy CSV row generation, and routes CSV export to the dedicated report FPM pool.

Main-project Karyakars are restricted to their own linked approved Karyakar record and active Groups. BN Karyalay analysis keeps its server-side female-scope lock.

## Bal Pravruti

Bal Pravruti remains separated from the main two-Karyakar/ten-Family Group workflow. Its own tables and services enforce three children + one Sanchalak, role-specific scope, completion reporting and separate analysis. Bal completion is added to Center/Zone/Karyalay overall completed counts without inventing a denominator that the SRS does not define.

## Support modules and storage

Announcements, Family Time, shared content, testimonials, inventory, sticky notes, support requests and correction requests use normalized records with role/scope checks. Public content and private import files are persisted on separate application volumes. Inventory changes use database transactions and row locking.

## Deployment and observability

- Coolify deploys `docker-compose.yml` and exposes only the `web` service on port 80.
- `/up` is Laravel's dependency-light liveness endpoint.
- `/health/live` reports application liveness.
- `/health/ready` reports deep dependency/schema/storage readiness.
- `scripts/runtime_diagnose_host.sh` captures container exit code, OOM flag, restart count, Docker events, host memory/disk, health history and kernel OOM evidence.
- GitHub Actions performs PHP/TypeScript/build/test checks plus a real Compose smoke/fault-injection workflow, including background child recovery, Redis degradation, PHP-FPM in-container recovery and Docker-level restart recovery.
- `scripts/backup.sh` / `scripts/restore.sh` protect PostgreSQL and durable application content with checksum verification.
