# v1.0.10 Login 500 Audit and Fix

## Reported symptom

The production login page rendered correctly, but submitting a valid email/password produced Laravel's generic `500 Server Error` response inside the Inertia request flow.

A screenshot alone does not contain the server exception/stack trace, so it cannot prove which single runtime exception occurred. The code review did, however, identify multiple confirmed paths where a successful credential check could still become a 500. v1.0.10 removes or contains all of those paths.

## Confirmed failure paths found in the code

### 1. Authentication audit write was availability-critical

After successful `Auth::attempt()`, `LoginController` called `AuditTrail::record()` directly. Any `audit_logs` schema drift, write failure, constraint issue or temporary database exception therefore converted otherwise-valid credentials into HTTP 500.

**Fix:** authentication login/logout uses `recordSafely()`. The failure is logged with structured diagnostics but does not block the authenticated session. Normal business mutations still use strict auditing.

### 2. `last_login_at` tracking was availability-critical

A successful login immediately updated `users.last_login_at`. A missing/drifted column or transient write failure also converted the valid login into HTTP 500.

**Fix:** last-login tracking is now a non-blocking operational side effect. The authentication itself remains valid and the failure is logged.

### 3. Dashboard failure can appear as a login failure in Inertia

The login request redirects to `/`. Inertia follows that redirect inside the same navigation. If the first Dashboard monitoring query throws, the user can see a 500 while the browser still visually appears to be on the login flow.

**Fix:** Dashboard monitoring, Karyakar field summary and managed-user count are independently contained. A failing optional dashboard dataset produces a visible warning and a usable dashboard/navigation rather than a generic 500.

### 4. Session-storage permission errors were hidden at container startup

The bootstrap script previously used:

`chown -R www-data:www-data storage bootstrap/cache || true`

The `|| true` meant a failed ownership change was intentionally ignored. Login is one of the first places that must regenerate/write the session, so a runtime volume-permission problem could stay hidden until credentials were submitted.

**Fix:** bootstrap now fails deployment if ownership cannot be applied and performs a `www-data` write probe against sessions, cache, views, logs and bootstrap cache. Production is no longer allowed to start in a state where login session writes are known to fail.

### 5. Readiness did not validate the full authentication foundation

The old readiness endpoint checked only selected operational tables and did not verify `roles`, `permissions`, `user_roles`, `role_permissions`, `audit_logs`, or an actual write into the file-session directory.

**Fix:** `/health/ready` now exposes `auth_schema` and `session_storage` checks plus exact `auth_missing_tables` / `auth_missing_columns` arrays.

## Production schema repair

`2026_08_18_010001_repair_authentication_foundation.php` is an idempotent forward-only repair migration. It repairs password/session tracking fields, RBAC pivots and the audit table where safe, and refuses to fake-recreate the core `users` identity table if that table is actually lost.

## Startup authentication preflight

After migrations and production-safe seeding, the web bootstrap now runs:

`php artisan happy-family:auth-preflight --no-interaction`

The command verifies:

- database connectivity;
- all auth/RBAC/audit tables and required columns;
- rollback-only `audit_logs` write capability;
- configured Super Admin exists, is active and is linked to `super_admin`.

A broken auth foundation therefore fails the deployment before traffic instead of becoming a user-facing login 500.

## CI regression coverage

The Compose runtime CI now exercises a real production-style browser flow using the file session driver:

1. GET `/login` and obtain the CSRF cookie/session;
2. POST the configured Super Admin credentials;
3. assert redirect success;
4. reuse the authenticated cookie;
5. GET `/` and require HTTP 200.

This specifically covers the class of defect that `/health/ready` alone cannot catch.

## Offline validation performed in this environment

- all PHP source syntax: PASS;
- static route/permission/Inertia integrity: PASS;
- Docker/CI YAML parse: PASS;
- shell syntax: PASS;
- web PHP-FPM/Nginx recovery simulation: PASS;
- runtime healthcheck behavior: PASS;
- bootstrap invalid-key/URL and writable-directory checks: PASS;
- worker/scheduler in-container recovery: PASS;
- 100,000-row CSV streaming safety: PASS (about 2 MB observed peak parser memory in the harness);
- Composer/vendor and npm dependencies are not available in this execution environment, so the real Laravel PHPUnit suite, Vite build and actual Docker Compose runtime remain mandatory GitHub CI gates.

## Deployment verification

After deploying v1.0.10, first verify:

`/health/ready`

Expected critical checks:

- `database: true`
- `schema: true`
- `auth_schema: true`
- `storage: true`
- `session_storage: true`
- `redis: true`
- `status: ready`

Then perform a Super Admin login and confirm Dashboard opens without a generic 500.
