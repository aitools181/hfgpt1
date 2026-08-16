# v1.0.5 Runtime Health & Coolify Hardening

## Why this release exists

The previous web image ran Nginx as the foreground container process while PHP-FPM was daemonized in the background. If PHP-FPM exited unexpectedly, Docker could still report the container as running because Nginx remained alive. The user-facing symptom could therefore be a 502 Bad Gateway even though the web container itself still appeared to be running.

## Changes

- Added `/health/live` for process-level liveness. It does not depend on PostgreSQL or Redis.
- Retained `/health/ready` as the deep readiness diagnostic for PostgreSQL, Redis/cache and required schema.
- Dockerfile and Compose web health checks now use `/health/live`.
- Increased the web health-check startup grace period to 90 seconds for migrations/bootstrap.
- Nginx and PHP-FPM are now supervised together by `docker/web-start.sh`.
- If either PHP-FPM or Nginx exits unexpectedly, the web container exits non-zero so Docker's restart policy can recover it.
- Added `init: true` and graceful stop windows to the web, worker and scheduler services.
- Added health checks to the queue worker and scheduler containers so the full long-running stack has explicit health checks.
- PostgreSQL and Redis health checks remain enabled.

## Operational interpretation

Use `/health/live` for container liveness and Coolify routing health. Use `/health/ready` when diagnosing whether the application can actually use its database, cache and required schema.

A healthy production deployment should return HTTP 200 from both endpoints under normal conditions.

## Private GitHub repository note

Changing a GitHub repository from public to private does not itself stop already-running Docker containers. However, subsequent deployments/rebuilds require Coolify to have authenticated access to the private repository. Configure the resource with a Coolify GitHub App or a repository deploy key before relying on a private repository.
