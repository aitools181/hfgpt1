# Production Operations Runbook - v1.0.10

## Daily / routine checks

- Open `/health/ready`; normal status is HTTP 200 / `ready`.
- Confirm `web`, `worker`, `scheduler`, `db`, and `redis` are Running/Healthy in Coolify.
- Review repeated supervisor recovery messages, queue failures, database errors and disk warnings.
- Verify scheduled Reminder/Alert activity continues.
- Keep an external verified backup schedule; same-host volumes are not a disaster-recovery backup.

## What an automatic restart means

v1.0.8+ runtime normally recovers a single Nginx/PHP-FPM/worker/scheduler child failure without replacing its Compose container. A Docker-level web restart occurs only after the bounded in-container recovery budget is exhausted or the container is killed externally/OOMed.

Messages to look for:

```text
recovering PHP-FPM reason='PHP-FPM master exited unexpectedly'
recovering PHP-FPM reason='PHP-FPM master PID was alive but control pool was unresponsive'
recovering Nginx reason='Nginx master exited unexpectedly'
recovering Nginx reason='Nginx PID was alive but health endpoint was unresponsive'
shutdown code=1 reason=...
```

Worker/scheduler logs show child exit code/runtime/backoff and the replacement child PID.

## Incident: site unavailable but web says Running

1. Check `/up` and `/health/ready` separately.
2. If `/up` fails, inspect web supervisor/Nginx/FPM logs. Dedicated probes should recover live-but-stuck processes automatically.
3. If `/up` works but `/health/ready` is degraded, do not repeatedly restart web. Investigate the failed readiness field: DB, Redis, schema, storage or disk.
4. Run `scripts/runtime_diagnose_host.sh <web-container>` before a manual redeploy when possible.

## Incident: container Exited/restarted

Inspect Docker evidence:

```bash
docker inspect <container> --format 'ExitCode={{.State.ExitCode}} OOMKilled={{.State.OOMKilled}} Restart={{.HostConfig.RestartPolicy.Name}} RestartCount={{.RestartCount}} Finished={{.State.FinishedAt}} Error={{.State.Error}}'
```

Then inspect kernel OOM evidence:

```bash
journalctl -k --since "2 hours ago" | grep -Ei 'out of memory|oom|killed process'
```

Interpretation:

- `OOMKilled=true` / exit 137: memory pressure/SIGKILL; inspect host RAM and cgroup memory events.
- exit 143: SIGTERM; inspect Coolify/Docker deployment/stop events.
- supervisor repeated-recovery message: process/app liveness could not recover inside the configured budget.
- `RestartCount` increasing with service returning healthy: auto-restart is working as designed.

## Memory / load prevention

Do not remove the supplied container memory/PID ceilings casually. If normal measured usage approaches a limit, increase host capacity first and adjust the relevant service deliberately. The default service ceilings total roughly 2.9 GB, while production preflight requires at least 4.5 GB host RAM.

Large report preview is bounded and CSV export is isolated/streamed. Large import work belongs on the queue worker. Do not convert these paths back to unbounded `get()`/in-memory processing.

## Redis / queue

Redis is not the web session/cache dependency. If Redis is down, normal page traffic should remain available, while new async imports may be temporarily unavailable and the worker will back off/recover. `/health/ready` verifies Redis is writable, not merely reachable.

If Redis remains unhealthy, inspect its memory/disk/AOF logs. Do not switch from `noeviction` to an eviction policy for production queue data merely to hide a capacity problem; increase capacity and clear only data that is safe to lose.

## PostgreSQL

PostgreSQL has connection/query timeouts and resource-conscious defaults. A DB outage should show readiness degraded while the DB service uses Docker restart recovery. Avoid restarting the web repeatedly for a DB-only incident.

Before destructive DB remediation, capture logs and a backup when possible.

## Disk

`/health/ready` becomes degraded when application storage free space falls below the configured threshold. Docker container logs and supervisor logs are rotated, but database/uploads/backups represent legitimate data growth and must be monitored at host level.

## Release procedure

1. `scripts/production_preflight.sh` on the host.
2. CI green on exact commit.
3. verified backup.
4. deploy/redeploy exact commit.
5. verify all service health.
6. verify `/up` + `/health/ready`.
7. Super Admin smoke test: Dashboard, Users/password reset, Families/Karyakars, Groups, My Target, Reminders, Bal Dashboard/Analysis, Reports.
8. keep prior known-good Git commit available for application rollback.

## Backup / restore

Use `scripts/backup.sh` and `scripts/restore.sh`; store verified backup copies outside the application host. Practice restore in staging. See `BACKUP_RESTORE.md`.

## Private GitHub repository

Changing the GitHub repository from public to private does not terminate already-running containers. It can make a future build/redeploy fail if Coolify no longer has repository credentials. Keep the GitHub App/deploy key authorized before changing visibility.

## Security operations

Keep `APP_DEBUG=false`, HTTPS + secure session cookie enabled, database/Redis unexposed, unique secrets, and password-reset authority narrowly delegated. Review Audit Logs after administrative changes.
