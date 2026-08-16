# Coolify Runtime Diagnosis

Use this when the Coolify card shows **Running** but the site is unavailable or returns 502.

## 1. Distinguish liveness from readiness

- `GET /up` should return HTTP 200 when Nginx + PHP-FPM can serve Laravel; Docker/Coolify health checks and the self-healing watchdog use this dependency-light route.
- `GET /health/live` is an operator-facing liveness endpoint.
- `GET /health/ready` should return HTTP 200 only when PostgreSQL, Redis/cache and the required database schema are ready.

## 2. Inspect the deployed container health

From the Coolify server terminal, first list this stack's containers:

```sh
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
```

Then inspect the web container:

```sh
docker inspect <web-container-name> --format '{{json .State}}'
```

Important fields:

- `Status`
- `Running`
- `Restarting`
- `ExitCode`
- `OOMKilled`
- `Health.Status`

Check restart count:

```sh
docker inspect <web-container-name> --format 'restart_count={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}'
```

## 3. Logs

```sh
docker logs --tail 200 <web-container-name>
docker logs --tail 200 <db-container-name>
docker logs --tail 200 <redis-container-name>
```

v1.0.6 emits an explicit message if PHP-FPM/Nginx exits unexpectedly or if the internal `/up` watchdog reaches its consecutive-failure threshold. It then intentionally terminates the web container so Docker can restart it.

## 4. Server-level interruptions

If all project containers stopped at the same time, inspect server/Docker events around that time. Common infrastructure causes include a server reboot, Docker daemon restart, disk exhaustion or an out-of-memory event.

## 5. GitHub repository visibility

Changing a repository from public to private does not directly stop containers that are already running. It does change whether Coolify can fetch the source on the next deploy/rebuild.

For a private repository use one of:

- Coolify GitHub App with this repository selected, or
- a repository-specific deploy key.

If the resource was originally created as a public-repository source and the repository is later made private without authenticated access, the next source fetch/deploy can fail.
