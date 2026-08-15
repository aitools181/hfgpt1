# Final Handoff - SMVS Happy Family Portal v1.0.4

## Package status

This is the cumulative audited source package for Phases 0-7 plus the v1.0.1 stabilization/security pass, v1.0.2 Coolify deployment hotfix, v1.0.3 Super Admin/UI access hotfix, and v1.0.4 password-reset/RBAC hardening pass. It is designed for the user's intended workflow: extract -> push to GitHub -> let GitHub CI validate -> deploy the repository with Docker Compose in Coolify.

## v1.0.2 Coolify deployment hotfix

The public `web` service now contains both Nginx and PHP-FPM, with FastCGI bound to `127.0.0.1:9000`. This removes the separate `web -> app:9000` network hop that could surface as a Coolify 502 even when the Nginx container itself was running. Worker and scheduler remain separate containers. Critical Compose variables now fail fast, and the web image validates Nginx/PHP-FPM before accepting traffic.

## v1.0.3 Super Admin access and navigation hotfix

This release fixes the production issues reported after v1.0.2: Super Admin My Target now has an admin-preview empty state instead of 403 when no approved Karyakar exists; Bal Dashboard/Analysis no longer issue PostgreSQL ambiguous-column errors; Reminders/Alerts has schema/orphan hardening; desktop sidebar and main content scroll independently; the active navigation item remains highlighted; and sidebar scroll position persists across Inertia navigation. A repair migration and schema-aware readiness check are included for upgraded deployments.


## v1.0.4 password reset and RBAC hardening

- Added a dedicated `reset_user_passwords` permission. It is seeded to Super Admin by default and can be granted to any other role from Settings -> Roles & Permission Matrix.
- Only Super Admin can grant or remove the `reset_user_passwords` permission itself; this remains protected even if general `manage_roles` capability is delegated.
- Reset writes lock the target user row before advancing `session_version`, preventing concurrent-reset session-revocation races.
- Delegated password reset is constrained by organizational scope and role authority: non-Super-Admin users can reset only equal/lower-authority accounts fully contained in their permitted scope; Super Admin can reset all accounts including their own.
- Password reset now has its own protected endpoint and cannot be bypassed through generic User update.
- Password resets rotate the remember token, increment `session_version`, set `password_changed_at`, revoke stale sessions, and create a redacted Activity/Audit Log entry without storing the password.
- Added password reset UI to User & Password Management, including confirmation, optional reason, protected-account indicators, and self-reset logout behavior.
- Hardened delegated `manage_users` so a Center/Zone role cannot create organization-wide roles, cross scope boundaries, or obtain organization-wide user counts.
- Extended `/health/ready` to verify the password-security schema columns and added offline static-integrity gates to release checks and CI.

## Functional completion

The package contains the full SRS workflow and the support modules identified in the Full Portal Wireframe. Requirement mapping is maintained in `REQUIREMENT_TRACEABILITY.md`; source ambiguities are preserved in `DECISIONS_PENDING.md` rather than silently changed.

## Production architecture

- Laravel 13 / PHP 8.4 application
- React 19 + Inertia 3 + TypeScript frontend
- PostgreSQL 17
- Redis 8 for cache/session/queue
- Nginx web service
- Queue worker and Laravel scheduler services
- persistent PostgreSQL, Redis, public-upload and private-import volumes
- readiness endpoint `/health/ready`

## Before first production deployment

1. Push the extracted source to a private GitHub repository.
2. Confirm GitHub Actions CI passes.
3. In Coolify, deploy the repository as Docker Compose and point the domain to the `web` service port 80.
4. Configure `.env` values in Coolify, especially `APP_KEY`, `APP_URL`, `DB_PASSWORD`, `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD`.
5. Keep `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `PILOT_DATA=false` and `SEED_ON_BOOT=true` for the first baseline deployment.
6. Verify `/health/ready` returns HTTP 200.
7. Sign in and immediately replace the bootstrap Super Admin password.
8. Execute the production smoke matrix in `FINAL_ACCEPTANCE_MATRIX.md` using pilot records from at least two Centers and two Zones.
9. Run and verify a backup before importing real production data.

## Validation performed in this build environment

The v1.0.1 audit also added targeted regression coverage and corrected security/scope, Docker/Redis, queued import, Family edit, correction-request, transfer/target-lifecycle and target-synchronization defects. Detailed evidence is in `FULL_CODE_AUDIT.md`.

- PHP syntax lint across application/config/database/routes/tests
- TypeScript/TSX offline parser/transpile validation using the locally available TypeScript compiler with `--noCheck` because project `node_modules` are not available
- JSON manifest parsing
- Docker Compose YAML structural validation
- release-manifest SHA-256 verification
- final ZIP integrity verification

## Validation not executable in this build environment

The environment has no Composer binary, no Docker CLI and no external package-network access. Therefore `composer install`, real `npm install`, `php artisan test`, the actual Vite bundle and live Docker/Compose runtime were not executed here. The included GitHub CI runs those checks in a networked runner before production deployment.
