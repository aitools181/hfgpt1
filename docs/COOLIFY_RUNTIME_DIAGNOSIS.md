# Coolify Runtime Diagnosis - v1.0.8

Use this only when a running deployment becomes unavailable, restarts, or exits. Preserve evidence before a manual redeploy when possible.

## 1. Identify containers

```bash
docker ps -a --format 'table {{.ID}}\t{{.Names}}\t{{.Status}}' | grep hfgpt1
```

## 2. Inspect the affected container

```bash
docker inspect <container> --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} OOMKilled={{.State.OOMKilled}} Restart={{.HostConfig.RestartPolicy.Name}} RestartCount={{.RestartCount}} Started={{.State.StartedAt}} Finished={{.State.FinishedAt}} Error={{.State.Error}}'
```

Expected restart policy in v1.0.8 is `always` for web/worker/scheduler/db/redis.

## 3. Review web supervisor evidence

```bash
docker logs --tail 500 <web-container>
```

Important messages:

- `recovering PHP-FPM reason='PHP-FPM master exited unexpectedly'`
- `recovering PHP-FPM reason='PHP-FPM master PID was alive but control pool was unresponsive'`
- `recovering Nginx reason='Nginx master exited unexpectedly'`
- `recovering Nginx reason='Nginx PID was alive but health endpoint was unresponsive'`
- `isolated Laravel watchdog probe failed`
- `runtime snapshot`
- `shutdown code=1 reason=...`

A single Nginx/PHP-FPM failure should normally recover in the same container. Docker restart should occur only after repeated unrecoverable web failures or an external/container-level kill.

## 4. Check OOM/kernel events

```bash
journalctl -k --since "2 hours ago" | grep -Ei 'out of memory|oom|killed process'
```

`OOMKilled=true` or exit 137 is strong evidence of memory pressure/SIGKILL. v1.0.8 adds service memory ceilings and lower per-process limits specifically to contain this risk.

## 5. Distinguish liveness from dependency readiness

```text
/up
/health/live
/health/ready
```

- `/up` failure: Laravel/FPM/Nginx application path problem.
- `/up` healthy + `/health/ready` degraded: investigate DB/Redis/schema/storage/disk; do not repeatedly restart web.
- Redis readiness tests write capability, not only PING.

## 6. Worker/scheduler

Their container should stay alive while the Laravel child is recycled/restarted in-container. Logs show `child exited rc=... runtime=...; restarting after ...`. Repeated child crashes indicate an application/dependency issue to diagnose, but should not require a Compose redeploy.

## 7. One-command helper

From the repository on the Coolify host:

```bash
scripts/runtime_diagnose_host.sh <web-container-name-or-id>
```
