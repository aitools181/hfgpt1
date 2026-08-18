# v1.0.10 Validation Report

## Scope

Authentication/login 500 elimination, post-login Dashboard failure containment, persistent file-session readiness, production auth schema drift repair, startup preflight and regression gates. All v1.0.9 mobile UI and v1.0.8 runtime hardening remain cumulative.

## Checks completed in this environment

- PHP syntax lint across application/config/database/routes/tests/scripts: PASS (147 files at validation time)
- Static source integrity (routes, permissions, Inertia pages, security/runtime/mobile/auth invariants): PASS
- Inertia page resolution catalog: PASS (32 pages)
- Named route uniqueness/controller mapping static gate: PASS (90 named routes)
- Seeded permission/reference integrity: PASS (43 permissions)
- TypeScript/TSX syntax transpile using global TypeScript 5.8.3: PASS (37 files)
- JSON manifest parsing: PASS
- Docker Compose YAML parsing: PASS
- GitHub Actions YAML parsing: PASS
- shell syntax: PASS
- PHP-FPM in-container recovery simulation: PASS
- Nginx in-container recovery simulation: PASS
- isolated Laravel watchdog recovery simulation: PASS
- web runtime healthcheck positive/negative cases: PASS
- bootstrap valid/invalid APP_KEY and APP_URL cases: PASS
- background worker child recycling: PASS
- scheduler transient-crash recovery: PASS
- 100,000-row CSV streaming test: PASS; observed parser peak memory about 2 MB in harness
- duplicate normalized import-header rejection: PASS
- oversized import-cell rejection: PASS
- release-check stages 1-9: PASS; stage 10 correctly stops because project `vendor/` and `node_modules/` are not available in this execution environment

## New regression gates added

GitHub CI now contains a real Docker browser-style login smoke path using the production file session driver:

- GET `/login`
- capture CSRF/session cookies
- POST configured Super Admin credentials
- require successful redirect
- GET authenticated `/`
- require HTTP 200

Feature tests also cover successful login when the authentication audit table or the non-critical `last_login_at` tracking column is temporarily unavailable.

## Runtime tests that remain mandatory in GitHub CI

This environment cannot obtain project Composer/NPM dependencies or run a Docker daemon. Therefore the following must be green in GitHub Actions before Coolify production acceptance:

- Composer install + Laravel PHPUnit suite
- TypeScript type checking with actual project dependencies
- Vite production build
- route/config cache generation
- Docker target builds
- real PostgreSQL + Redis Compose runtime
- `/health/ready`
- real file-session Super Admin login + Dashboard 200
- worker/scheduler health and crash recovery
- PHP-FPM fault injection and Docker recovery
