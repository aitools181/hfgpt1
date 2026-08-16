# Coolify Deployment Guide - SMVS Happy Family Portal v1.0.4

The repository is deployment-ready as a Docker Compose application. Coolify should build the repository; do not upload `vendor/` or `node_modules/`.

## 1. GitHub repository

Extract the final ZIP into an empty folder and push the source to a private GitHub repository:

```bash
git init
git add .
git commit -m "SMVS Happy Family Portal v1.0.4"
git branch -M main
git remote add origin <YOUR_PRIVATE_REPOSITORY>
git push -u origin main
```

Keep `.env` and production backups out of Git. The supplied `.gitignore` already excludes them.

## 2. Coolify resource

Create a new application from the GitHub repository and choose **Docker Compose**. Use repository root `docker-compose.yml`.

The services are:

- `web` - public HTTP service containing both Nginx and Laravel PHP-FPM; migrations/seed bootstrap
- `worker` - Redis queue worker
- `scheduler` - Laravel scheduler; creates inactivity Reminder/Alert events
- `db` - PostgreSQL 17
- `redis` - Redis 8

Attach the public domain to the **web service, port 80**. Let Coolify terminate HTTPS and proxy to Nginx.

## 3. Required environment variables

Configure these in Coolify; do not commit their real values:

```env
APP_NAME="SMVS Happy Family"
APP_ENV=production
APP_KEY=base64:<GENERATED_LARAVEL_KEY>
APP_DEBUG=false
APP_URL=https://your-real-domain.example
APP_TIMEZONE=Asia/Kolkata
APP_LOCALE=en
LOG_CHANNEL=stderr

DB_DATABASE=happy_family
DB_USERNAME=happy_family
DB_PASSWORD=<LONG_RANDOM_DATABASE_PASSWORD>
DB_SSLMODE=prefer

SESSION_SECURE_COOKIE=true
REDIS_PASSWORD=

SEED_ON_BOOT=true
SUPER_ADMIN_NAME="SMVS Super Admin"
SUPER_ADMIN_EMAIL=<INITIAL_ADMIN_EMAIL>
SUPER_ADMIN_PASSWORD=<UNIQUE_PASSWORD_AT_LEAST_16_CHARS>

PILOT_DATA=false
PILOT_PASSWORD=
```

`REDIS_PASSWORD` may be left blank for an internal-only Redis service or set to a strong secret. The supplied Compose file propagates the value to Redis, enables `requirepass` when non-empty, and authenticates the Redis healthcheck and Laravel services consistently.

Generate `APP_KEY` before the first Compose deployment:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

Copy the printed value into Coolify and redeploy. `APP_KEY`, `APP_URL`, and `DB_PASSWORD` are required by Compose so a missing critical value fails early instead of surfacing later as a 502.

## 4. Persistent storage

The Compose file defines four named volumes and they must remain persistent across redeploys:

- `postgres_data` - database
- `redis_data` - Redis persistence
- `app_storage` - uploaded Shared Content/public files
- `app_private` - private SMVS import uploads/source files

Never replace/delete these volumes during a normal application update.

## 5. First deployment behavior

The `web` container waits for PostgreSQL and Redis, runs migrations, optionally runs the idempotent baseline seeder, creates the public storage link, caches configuration/routes, validates Nginx/PHP-FPM, starts PHP-FPM on `127.0.0.1:9000`, and finally starts Nginx on port 80.

With `SEED_ON_BOOT=true`, roles/permissions are synchronized and the bootstrap Super Admin is created only if the configured account does not already exist. Keep `PILOT_DATA=false` in production.

## 6. Health and smoke checks

After deployment:

1. Open `https://<domain>/health/ready` and confirm HTTP 200 with database/cache `true`.
2. Open the login page and sign in as the bootstrap Super Admin.
3. Change the initial password through the administrative workflow/process immediately.
4. Verify worker and scheduler containers remain running.
5. Run the acceptance smoke checks in `FINAL_ACCEPTANCE_MATRIX.md` before importing real data.

The Dockerfile and Compose `web` healthchecks both call `/health/ready`, so a failed database/cache dependency makes the web container report unhealthy rather than falsely healthy. Nginx talks to PHP-FPM over `127.0.0.1:9000` inside the same container, eliminating the earlier `web -> app:9000` FastCGI dependency.

## 7. Pilot/UAT data (staging only)

For a temporary staging deployment you may set:

```env
PILOT_DATA=true
PILOT_PASSWORD=<STAGING_PASSWORD_AT_LEAST_16_CHARS>
```

The deterministic pilot seeder creates sample organization, Center/Area/Society, users, Karyakars, Families, a valid 2-Karyakar/10-Family Group, Home Visits, Bal data and support records.

**Never enable `PILOT_DATA` in production.** Disable it and rebuild a clean production database before real data import.

## 8. Backups before updates/imports

From a host with Docker access and the production `.env`:

```bash
scripts/backup.sh
```

The backup contains a PostgreSQL dump, public uploaded storage, private import storage, release VERSION and SHA-256 checksums. See `BACKUP_RESTORE.md`.

Before any major import or application upgrade, take a fresh backup and verify the output files exist.

## 9. Restore rehearsal

Restore is destructive and requires an explicit confirmation flag:

```bash
CONFIRM_RESTORE=YES scripts/restore.sh backups/<timestamp>
```

Rehearse this in staging first. After restore, confirm `/health/ready`, login, record counts, uploads and a representative report.

## 10. Update workflow

For each future release:

1. Back up production.
2. Push the reviewed commit/tag to GitHub.
3. Wait for GitHub CI to pass.
4. Deploy that exact commit in Coolify.
5. Watch migration/application/worker/scheduler logs.
6. Verify `/health/ready`.
7. Run a focused smoke test before announcing completion.

## 11. Security checklist

- private GitHub repository
- `APP_DEBUG=false`
- HTTPS domain only
- `SESSION_SECURE_COOKIE=true`
- long unique `DB_PASSWORD`
- no production `.env` in Git or ZIP
- no default bootstrap password left active
- database/Redis not exposed publicly
- regular backups stored outside the application server
- review Audit Logs after administrative transfers/permission changes
- keep host/Coolify/Docker images patched

## 12. Large SMVS imports

For large Center imports, prefer CSV/TSV. The CSV/TSV reader is streaming and avoids loading the entire file into PHP memory. Uploaded imports are stored on the private persistent volume and processed by the dedicated Redis `imports` queue/worker, so the browser request only queues the work. XLSX remains supported for normal operational files but is more memory intensive. Keep the `worker` service healthy until each Import Batch reaches a final status.

## 13. Production sign-off

The exact release commit is production-ready only after GitHub CI and the target-environment acceptance gates in `FINAL_ACCEPTANCE_MATRIX.md` pass. The offline build environment used to assemble this package could not run Docker or install external Composer/NPM dependencies, so those runtime checks are deliberately delegated to CI/Coolify rather than falsely claimed as executed.


## v1.0.4 upgrade note (password reset + RBAC hardening)

Upgrade the existing Docker Compose resource in place; do not delete the PostgreSQL volume. The `web` bootstrap runs migrations and production-safe seeders automatically when `RUN_MIGRATIONS=true` / `SEED_ON_BOOT=true`.

v1.0.4 adds `users.session_version`, `users.password_changed_at`, and the `reset_user_passwords` permission. The new permission is attached to Super Admin by default. Other roles receive it only when Super Admin explicitly checks **Reset User Passwords** under Settings -> Roles & Permission Matrix.

After deployment:

1. Confirm `/health/ready` returns `status=ready`, `checks.schema=true`, and empty `missing_columns`.
2. Sign in as Super Admin -> Users -> reset a staging/test user's password.
3. Confirm the old password no longer authenticates and any old session is rejected on the next request.
4. Optionally grant `reset_user_passwords` to a test Center Admin and confirm it can reset a same-Center equal/lower role but cannot reset a Zonal/Super Admin or a user outside that Center.
5. Review Activity/Audit Logs for `users / password_reset`; the audit must contain metadata only, never the new password.

## v1.0.3 upgrade note (Super Admin / Bal / sidebar hotfix)

When upgrading from v1.0.2 to v1.0.3, use the same Docker Compose resource and redeploy the new commit. The `web` bootstrap runs `php artisan migrate --force`; v1.0.3 includes a repair migration for missing `inactivity_events` and Bal Pravruti operational tables from early deployment candidates.

After deployment verify:

1. `https://YOUR_DOMAIN/health/ready` returns HTTP 200 with `database`, `cache`, and `schema` all `true` and an empty `missing_tables` array.
2. Super Admin can open `/field/my-target` even when no approved Karyakar exists; an empty admin-preview state is expected until a Karyakar is approved.
3. Super Admin can open `/field/reminders`, `/bal-pravruti`, and `/bal-pravruti/analysis` without HTTP 500.
4. On desktop, the left navigation and right content pane scroll independently; the active navigation item remains highlighted and the sidebar retains its scroll position across navigation.

## v1.0.5 Health checks and private GitHub repositories

The Compose stack defines explicit health checks for `web`, `worker`, `scheduler`, `db`, and `redis`. The public web container uses `GET /health/live` for liveness. `GET /health/ready` is the deeper dependency/schema diagnostic.

For a private GitHub repository, configure Coolify with either a GitHub App that has access to this repository or a repository deploy key. A resource configured only as a public-repository source will not be able to fetch new commits after the repository becomes private. Changing visibility alone does not terminate already-running containers, but a later redeploy/rebuild can fail if source access is no longer valid.

