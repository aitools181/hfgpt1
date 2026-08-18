# Final Handoff - SMVS Happy Family Portal v1.0.12

## Release purpose

v1.0.12 is the cumulative full portal retaining the mobile UI, runtime hardening and login/session fixes, plus a confirmed Redis queue timeout correction and production DNS/host diagnostics. Use this release instead of v1.0.11 or earlier.


## Mobile UI

- Desktop keeps the independent persistent left sidebar and scrolling content pane.
- Phone/tablet uses a sticky app bar, role-aware bottom navigation and a full permitted-menu bottom sheet.
- All data tables render as labeled stacked record cards below 640px, while wider screens keep normal tables.
- Forms, buttons, modals and page spacing use touch-friendly sizing and safe-area padding.
- `manifest.webmanifest` plus 192/512 application icons allow supported mobile browsers to use standalone/home-screen presentation.

## Production runtime

- `web`: Nginx + supervised PHP-FPM with separate interactive, control, report and health pools.
- `worker`: persistent supervisor with recycled/restarted Laravel queue child.
- `scheduler`: persistent supervisor with restarted Laravel scheduler child.
- `db`: PostgreSQL 17 with persistent volume, healthcheck and bounded runtime settings.
- `redis`: Redis 8/AOF queue service with persistent volume, healthcheck and no-eviction policy.
- Docker Compose restart policy: `always` on every long-running service.
- public domain: web service only, port 80 behind Coolify HTTPS proxy.

## Upgrade in place

1. Back up production.
2. Replace repository contents with this release and push the exact commit to the private GitHub repository.
3. Require GitHub Actions CI to pass.
4. Redeploy the existing Coolify Docker Compose resource; do not delete persistent volumes.
5. Confirm `web`, `worker`, `scheduler`, `db` and `redis` are Running/Healthy.
6. Confirm `/up`, `/health/live` and `/health/ready`.
7. Run the focused acceptance matrix before real production work resumes.

## Recommended minimum host

The supplied preflight defaults to at least 4.5 GB host RAM and 10 GB free root disk. The default service memory ceilings total about 2.9 GB, intentionally leaving headroom for Coolify, Docker, Traefik and Linux. More RAM/disk is recommended as data/import/report volume grows.

## Important health semantics

- Docker/Coolify web health: real supervised PIDs + direct Nginx + dedicated FPM ping + isolated Laravel liveness.
- `/up`: dependency-light Laravel liveness.
- `/health/ready`: PostgreSQL + file cache + Redis read/write + required schema + writable storage + free disk.

A Redis/database outage therefore does not deliberately kill the web container; readiness identifies the dependency problem while the relevant service restart policy/recovery path works.

## Diagnostics

If any future outage occurs, do not redeploy before preserving evidence when possible. On the Coolify host run:

```bash
scripts/runtime_diagnose_host.sh <web-container-name-or-id>
```

Review `OOMKilled`, exit code, restart count/policy, health history, Docker events, and the web supervisor messages immediately before recovery/exit.

See `V1_0_8_FAILURE_PREVENTION_AUDIT.md` and `V1_0_8_VALIDATION.md` for the detailed engineering/validation record.
