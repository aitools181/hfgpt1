# Full Code Audit - SMVS Happy Family Portal v1.0.4

Audit date: 2026-08-15

## 1. Audit scope

This pass re-reviewed the cumulative Phase 0-7 project after the v1.0.3 production hotfix. The review covered authentication, RBAC, delegated permissions, user administration, password security, organizational scope isolation, registration/imports, Group/Family/Target invariants, field execution, reporting/analysis, Bal Pravruti, support modules, audit logging, migrations, readiness checks, Docker/Coolify packaging, CI and release contents.

The uploaded SRS Version 3.0 and Full Portal Wireframe Version 2.0 remain the product baseline. The password-reset capability in v1.0.4 is an explicit user-requested administrative addition rather than a silently inferred SRS requirement.

## 2. v1.0.4 defects/risk paths found and corrected

### Delegated role-manager reset escalation

A role holding `manage_roles` could previously submit the Reset User Passwords permission in the role matrix. v1.0.4 now reserves grant/removal of `reset_user_passwords` to Super Admin, while other delegated role managers may continue maintaining non-reset permissions.

### Concurrent password-reset session version race

The initial reset implementation calculated `session_version` before acquiring a database row lock. Two simultaneous resets could therefore calculate the same next version. The final v1.0.4 implementation locks the target user row, re-checks reset authority inside the transaction, validates password reuse against the locked record, and increments the current version atomically.

### Password reset / credential security

- Added a dedicated `reset_user_passwords` permission instead of treating password changes as part of generic `manage_users`.
- Super Admin receives the reset permission by default. No other role receives it automatically; Super Admin can delegate/revoke it from the Settings permission matrix.
- Added a dedicated password-reset endpoint and prohibited password fields on the generic User update endpoint, closing a permission-bypass path.
- Added password confirmation, minimum-length validation, same-password rejection, optional reset reason and redacted audit logging.
- Added `session_version` and `password_changed_at` to users. Resetting a password increments the session version, rotates the remember token, and invalidates stale authenticated sessions.
- Self-reset by Super Admin signs the current session out immediately.
- Login now renders flash success/error messages so password-reset and forced-session-expiry messages are visible.

### Delegated RBAC / scope hardening

During the re-test, a broader pre-existing risk was identified: if `manage_users` were manually delegated to a Center/Zone role, the old User controller accepted any role/scope supplied by the form. That could have allowed privilege escalation or cross-scope account creation. v1.0.4 fixes this by introducing `UserAdministrationScope`.

- Delegated user administration is limited to the actor's organizational scope.
- A delegated administrator cannot assign a role above their own authority level.
- Organization-wide roles cannot be assigned by Center-scoped administrators.
- Zonal roles require actual Zone authority; contextual `zone_id` carried by Center roles does not become Zone-wide authority.
- A delegated password reset can target only equal/lower-authority users whose complete role assignment is inside the actor's scope.
- Reset-only users see only accounts they are actually authorized to reset, avoiding unnecessary disclosure of higher-authority accounts.
- Dashboard User counts now use the same user-administration scope instead of revealing an organization-wide count to a delegated `manage_users` role.

### Deployment/readiness hardening

- `/health/ready` now checks `users.session_version` and `users.password_changed_at` in addition to required tables, so an incomplete v1.0.4 migration cannot appear schema-ready.
- Added `scripts/static_integrity_check.py` and wired it into local release checks and GitHub CI.
- Static integrity checks verify Inertia page references, route/controller methods, route-name uniqueness, permission definitions, sidebar GET routes, password-reset invariants, merge markers and debug leftovers.

## 3. Password reset authorization model

Default behavior:

| Actor | Default reset permission | Reset authority |
|---|---:|---|
| Super Admin / Karyalay Admin | Yes | Any portal account, including own account |
| BN Karyalay Admin | No | Can be explicitly granted; then organization scope excluding Super Admin/higher authority |
| Zonal Admin | No | Can be explicitly granted; then equal/lower users wholly inside assigned Zone |
| Center Admin | No | Can be explicitly granted; then equal/lower users wholly inside assigned Center |
| Computer Op. | No | Can be explicitly granted; then equal/lower users wholly inside assigned Center |
| Nirdeshak / Nirikshak / Sanchalak / Karyakar | No | Can be explicitly granted; still limited by role rank and scope |

Password values are never written to Activity/Audit Logs. Existing sessions are invalidated after reset.

## 4. Automated regression source now present

The source contains **95 named PHPUnit test methods** across Unit and Feature suites. v1.0.4 adds/extends tests for:

- Super Admin resetting another user's password.
- Super Admin resetting own password and being signed out.
- role without reset permission being denied.
- Super Admin granting reset permission through the role-permission matrix.
- non-Super role managers being unable to grant/remove `reset_user_passwords`.
- Center-scoped delegated reset of an eligible target.
- cross-Center reset denial.
- denial against Super Admin / higher-scope targets.
- reset-only user-list privacy.
- generic User update being unable to smuggle a password change.
- stale authenticated session rejection after password reset.
- delegated `manage_users` privilege-escalation and cross-Center creation denial.
- Super Admin-only default password-reset permission baseline.
- readiness schema including the password-security columns.

The existing suites continue to cover authentication, scope isolation, Family/Karyakar registration, import behavior, 2-Karyakar/10-Family Group rules, Fixed/Remaining composition, duplicate prevention/transfers, Targets, Home Visits, reminders, reports, Bal Pravruti and support modules.

## 5. Checks actually executed in this build environment

| Check | Result |
|---|---|
| PHP syntax for `app`, `bootstrap`, `config`, `database`, `routes`, `tests` | **PASS - 143 PHP files** |
| TypeScript/TSX parse/transpile check using global TypeScript `--noCheck` | **PASS - 36 resource TS/TSX files + `vite.config.ts`** |
| JSON parse (`composer.json`, `package.json`) | **PASS** |
| XML parse (`phpunit.xml`) | **PASS** |
| YAML parse (`docker-compose.yml`, GitHub Actions workflow) | **PASS** |
| Compose topology static check | **PASS - `web`, `worker`, `scheduler`, `db`, `redis`; no obsolete `app` service** |
| POSIX shell syntax | **PASS** |
| Static source-integrity script | **PASS** |
| Inertia page references | **PASS - 32 pages resolved** |
| Named route uniqueness | **PASS - 82 named routes** |
| Permission seed/use consistency | **PASS - 43 seeded; 39 used by routes/navigation** |
| Route controller/method reference scan | **PASS** |
| Sidebar GET-route consistency | **PASS** |
| Debug / merge-conflict marker scan | **PASS** |
| Forbidden release directories/secrets (`.env`, `vendor`, `node_modules`, private keys) | **PASS before packaging** |
| Password-reset static security invariants | **PASS - 12 reset/delegation/session/audit markers** |
| Migration FK order/static dependency scan | **PASS - 13 migrations / 40 created tables** |
| Pure-PHP domain smoke checks | **PASS - Karyakar category boundaries + both CSV import templates** |
| PHPUnit tests present in source | **95 named test methods** |

`npm install --no-audit --no-fund` was also attempted in this environment but timed out before creating `node_modules`; it is therefore not represented as a successful dependency/runtime test.

## 6. Runtime checks that still require CI / target infrastructure

The current execution environment does not provide Composer, project `vendor/`, project `node_modules`, or a Docker CLI/daemon. Therefore these cannot truthfully be claimed as locally passed:

- Composer dependency resolution/install.
- Laravel boot and `php artisan test` against the framework runtime.
- real `npm run types:check` with React/Inertia package typings.
- Vite production bundle.
- `docker compose config`, image build and container runtime.
- PostgreSQL/Redis migration/integration tests.
- Nginx/PHP-FPM/Coolify live smoke/load tests.

GitHub Actions remains the mandatory runtime gate. It installs dependencies, performs TypeScript checking/build, runs Laravel tests against PostgreSQL 17, compiles route/config caches, validates Compose, builds Docker targets and performs the Compose readiness smoke test.

## 7. Release acceptance rule

Before treating v1.0.4 as production accepted:

1. Push this exact source commit to the private GitHub repository.
2. Require GitHub Actions to be green.
3. Redeploy the same commit in the existing Coolify Docker Compose resource; do not delete the database volume.
4. Confirm `/health/ready` returns HTTP 200, `checks.schema=true`, and empty `missing_tables` / `missing_columns`.
5. As Super Admin, reset a test user's password and confirm the old password/session no longer works.
6. Grant `reset_user_passwords` to a staging Center Admin and confirm same-Center equal/lower reset succeeds while cross-Center/Zonal/Super Admin reset is denied.
7. Review `users / password_reset` in Activity/Audit Logs and confirm no password value is stored.
8. Execute the full `FINAL_ACCEPTANCE_MATRIX.md` before importing production data.

No finite review can prove software has zero defects. The source/static pass above has no known unresolved source-integrity defect; framework/runtime/deployment acceptance remains gated by CI and the deployed environment.
