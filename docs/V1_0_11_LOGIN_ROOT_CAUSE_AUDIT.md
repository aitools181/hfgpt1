# v1.0.11 Login Root-Cause Audit

## Incident observed

The reported production symptom is an HTTP 500 specifically on `POST /login` while the login page itself can render. A separate screenshot shows Firefox `Server Not Found` for `/health/ready`.

These are two different failure classes:

- `POST /login -> 500` means an HTTP request reached an application/proxy path and failed server-side.
- `Server Not Found` is name-resolution/connectivity before Laravel handles the request. No PHP route can repair DNS; DNS/proxy/host availability must be checked separately when that symptom occurs.

The production Laravel exception stack trace from the original failing request was not available in the supplied screenshots. Therefore this audit does not claim one historical exception with false certainty. It instead identifies and removes confirmed code paths that can explain a POST-only login 500 and adds deterministic diagnostics for any future incident.

## Confirmed POST-only failure paths found in v1.0.10

### 1. Login route used framework throttle before controller recovery logic

`POST /login` had `throttle:10,1`. The middleware executes before the controller and uses the configured cache backend. v1.0.10 used a local file cache. A file-cache write/read problem therefore could fail the POST before the controller's fail-open `RateLimiter` wrappers ran. `GET /login` did not use that throttle, which makes this failure path consistent with a POST-only error.

**v1.0.11 fix:** the route-level throttle is removed for login. The controller keeps its bounded five-attempt rate limit, but every cache operation is fail-open and logged. Authentication availability is not coupled to throttle-storage availability.

### 2. Authentication session state depended on a container filesystem volume

v1.0.10 used the file session driver. A login POST reads, migrates and persists session state; a simple login GET is less likely to expose every persistence failure. Container recreation, ownership drift, volume state or session write errors could therefore become a 500 on credential submission.

**v1.0.11 fix:** sessions are database-backed in PostgreSQL. A production migration creates/repairs the `sessions` table. Startup auth preflight performs a real insert/read/delete round trip and refuses to serve traffic if it fails. Session state is no longer dependent on `storage/framework/sessions` or an `app_sessions` volume.

### 3. Cache availability was unnecessarily coupled to local filesystem state

Login rate limiting and several framework operations used the default cache backend. A writable-directory problem could turn a nonessential cache operation into an authentication failure.

**v1.0.11 fix:** default cache is database-backed with production migrations for `cache` and `cache_locks`. The login controller's rate limiter remains fail-open. Startup preflight verifies an actual cache round trip.

### 4. Successful authentication performed a redundant second session regeneration

Laravel's session guard already migrates the session identifier during successful authentication. v1.0.10 then performed another explicit session regeneration, adding an unnecessary second persistence point.

**v1.0.11 fix:** the redundant explicit regeneration is removed. The app only stores its `auth_session_version` marker after authentication succeeds.

### 5. Health diagnostics were still registered in the normal web route file

Normal web routes pass through cookies, session, shared session errors, CSRF and Inertia middleware. A broken session/auth infrastructure can therefore obscure the diagnostic route itself.

**v1.0.11 fix:** `/health/live` and `/health/ready` are registered from `bootstrap/app.php` as additional routes outside the `web` middleware group. Nginx routes them to the dedicated health PHP-FPM pool rather than the normal application worker pool.

### 6. Authentication errors lacked a request correlation identifier

A browser screenshot showing only `500` does not identify the server exception.

**v1.0.11 fix:** every request receives an `X-Request-ID`. The same request ID is attached to Laravel log context, along with method and path. A future single 500 can be correlated directly with the exact server log entry.

## Additional containment retained

- Audit-log writes after successful login are non-blocking.
- `last_login_at` is non-blocking.
- Dashboard monitoring, field summary and managed-user counts degrade independently instead of turning a valid login into a generic 500.
- Authentication/schema preflight checks users, roles, permissions, pivots, audit logs, database sessions and database cache.
- Existing Super Admin linkage is validated without forcibly replacing a password that may have been changed through the portal.

## DNS / `Server Not Found`

`Server Not Found` must be treated separately from application 500s. At the time of this audit, the build environment also could not resolve `hfgpt1.divyaivan.com` through its resolver. That does not prove the user's local DNS state at all times, but it reinforces that DNS resolution needs an independent check when the browser shows this exact error.

The application now exposes three diagnostics once the hostname reaches the server:

- `/up` - Laravel boot health.
- `/health/live` - process/application liveness.
- `/health/ready` - database, cache, Redis, required schema, auth schema, session backend, writable storage and free disk readiness.

## Remaining deployment reproducibility risk

The repository currently does not include `composer.lock` or `package-lock.json`. Therefore a clean online build may resolve newer compatible transitive dependencies on different redeploys. This is not the confirmed cause of the reported POST 500, but deterministic production releases should generate and commit both lockfiles from a trusted online build and then use them for future deployments.

## Acceptance condition

This source-level fix is considered production-accepted only after the hosted CI succeeds with real Composer/NPM dependencies and the Docker Compose smoke test completes:

1. migrations and auth preflight;
2. `/health/ready` returns 200;
3. GET login obtains cookies/CSRF token;
4. POST login using the seeded test Super Admin succeeds;
5. authenticated Dashboard returns 200;
6. PostgreSQL session row is created;
7. worker/scheduler health and recovery checks pass.

## One-command production diagnosis

If login ever fails again, run before redeploying:

```bash
./scripts/diagnose_login_runtime.sh https://hfgpt1.divyaivan.com
```

It records Compose service health/restart state, HTTP health/login status, authentication preflight, session/cache table presence and filtered recent web errors. Preserve this output because it distinguishes application exceptions from container, OOM, database and DNS failures.
