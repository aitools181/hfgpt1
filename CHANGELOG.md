# Changelog

## 1.0.10 - Login 500 elimination and authentication readiness hardening - 2026-08-18

- Hardened the complete sign-in path so non-essential `last_login_at` or authentication-audit writes can never turn valid credentials into an HTTP 500 response.
- Added a safe authentication audit writer with structured server logging; strict audit behavior for normal business mutations remains unchanged.
- Added an idempotent production repair migration for authentication/RBAC/audit schema drift on long-lived Coolify databases.
- Expanded `/health/ready` with authentication-schema and actual file-session write probes, plus explicit missing auth table/column diagnostics.
- Changed the session configuration fallback to `file` to match the production Compose contract even when an environment variable is omitted.
- Removed the hidden `chown ... || true` availability trap: container bootstrap now fails before traffic if Laravel runtime/session directories cannot be owned/written by `www-data`.
- Added a startup `happy-family:auth-preflight` command that validates auth tables/columns, performs a rollback-only audit write probe, and verifies the configured Super Admin role linkage before Nginx/PHP-FPM begins serving users.
- Added dashboard fail-soft containment: if a monitoring/field/user-count query fails, the authenticated portal still opens with navigation and a visible warning instead of surfacing a post-login 500.
- Added a real Docker CI browser-path smoke test for GET login -> CSRF session -> Super Admin POST login -> authenticated Dashboard 200.
- Added regression tests for login when the audit table or non-critical login-tracking column is temporarily unavailable.
- Fixed an intermittent shell-test cleanup bug caused by broad `pkill -f` matching under nested CI shells.
- Re-ran PHP lint, route/permission/Inertia integrity, runtime supervisor simulations, health/bootstrap checks, background self-healing and 100k-row import streaming tests.

## 1.0.9 - Mobile app-style responsive UI - 2026-08-18

- Reworked the authenticated portal shell for phone/tablet use with a sticky mobile app bar, role-aware bottom navigation and a full-screen-safe More menu/bottom sheet.
- Preserved desktop independent sidebar/content scrolling and active-route highlighting while removing the old mobile chip-style navigation.
- Added safe-area handling for modern iPhone/Android devices, larger touch targets, 16px mobile form inputs, responsive action buttons and mobile bottom-sheet completion modal behavior.
- Converted all 23 data tables into app-style stacked record cards on small screens using semantic header-derived labels; desktop/tablet table presentation remains unchanged.
- Added contained horizontal table wrappers for intermediate/tablet widths so wide data never forces the whole page to scroll sideways.
- Improved the login screen into a mobile-first app entry surface with touch-friendly credential fields and secure sign-in affordances.
- Added PWA presentation metadata, manifest and application icons so supported mobile browsers can present the portal with native-app-like standalone chrome when added to the home screen.
- Added responsive CSS safeguards for two-column desktop form fragments, action rows, safe-area padding, small-screen modals, reduced-motion users and long-content overflow.
- Re-ran PHP/static-integrity/config/CSS/JSX-syntax/mobile-coverage checks; runtime failure-containment behavior from v1.0.8 remains unchanged.

## 1.0.8 - Failure containment, scale hardening and self-healing - 2026-08-18

- Removed the remaining high-memory report paths: report previews are capped at 500 rows and CSV exports stream database rows lazily instead of materializing complete result sets in PHP memory.
- Isolated long CSV exports in a dedicated PHP-FPM pool so report traffic cannot starve normal portal requests.
- Reworked CSV/TSV/XLSX imports to stream rows, added XLSX zip-bomb/row/cell/shared-string limits, and retained queued retry/idempotency behavior.
- Added production query-path indexes for Groups, Families, Targets, Home Visits, inactivity processing, imports and audit lookup.
- Reworked inactivity scanning into bounded batches and removed several N+1/repeated dashboard calculations.
- Added on-demand search endpoints/bounded option lists for large Karyakar/Family/Group/Target/Bal assignment catalogs.
- Changed web/session/cache availability so normal authenticated HTTP traffic uses persistent file sessions/cache and does not fail merely because queue Redis is temporarily unavailable.
- Added Redis write-capability verification to deep readiness, so `noeviction` max-memory exhaustion is detected before an import enqueue becomes a generic application error.
- Added explicit memory/PID ceilings and Docker JSON log rotation for every long-running Compose service, plus conservative PostgreSQL/Redis runtime limits.
- Added explicit 65,535 file-descriptor limits for all runtime services to avoid low inherited `nofile` ceilings under sustained HTTP/database/socket activity.
- Increased production headroom to 1280 MB web, 448 MB worker and 768 MB PostgreSQL defaults while keeping PHP request/job memory bounded below the container limits.
- Added dedicated PHP-FPM pools for control/ping, long report export and isolated Laravel liveness; normal interactive pool is explicitly sized/recycled.
- Web supervisor now recovers an exited PHP-FPM or Nginx master inside the same container first, with bounded restart budget and backoff; repeated unrecoverable failure escalates to Docker `restart: always`.
- Added direct Nginx and FPM control probes so a process that is still alive but no longer responsive is detected and recycled, not left as a permanently `Running` but unusable container.
- Laravel watchdog now uses an isolated liveness pool and never treats PostgreSQL/Redis dependency failure or normal interactive worker saturation as a reason to kill the web container.
- Queue worker and scheduler now run behind persistent in-container supervisors. Intentional queue recycling, transient dependency errors or a child crash respawn the child without stopping the Compose service.
- Worker/scheduler startup now waits for the migrated, healthy web service so background jobs cannot race application migrations during a new deployment.
- Runtime supervisor/background log writes are best-effort and rotated, preventing a full/read-only diagnostic log from becoming an availability failure; Nginx logs now go to stdout/stderr under Docker log rotation rather than unbounded container files.
- Added `PGCONNECT_TIMEOUT`, PostgreSQL statement/idle-transaction timeouts and deployment dependency retries to bound network/database stalls and reduce transient build failures.
- Added login/import/report-export throttles to reduce accidental or abusive resource bursts.
- Hardened Docker builds with registry/install retries and future support for `composer.lock` / `package-lock.json` when present.
- Added/expanded production host preflight, runtime diagnosis, web/background supervisor simulations, 100k-row streaming import checks, CI Compose fault injection and release integrity gates.

## 1.0.7 - Runtime failure audit and false-exit fix - 2026-08-18

- Fixed a confirmed v1.0.6 false-exit path: three short `/up` failures could intentionally terminate an otherwise running web container while PHP-FPM was merely busy/temporarily starved.
- Changed the HTTP watchdog to soft-recover PHP-FPM workers first (SIGUSR2) and reserve Docker-level restart for persistent failure after repeated recovery attempts.
- Increased watchdog grace/threshold defaults so normal transient load cannot rapidly trip a restart.
- Added a direct Nginx `__container_health` endpoint and PID-aware runtime healthcheck so Docker/Coolify liveness does not compete for an application PHP-FPM worker.
- Fixed worker/scheduler healthchecks that incorrectly inspected `/proc/1/cmdline` while `init: true` makes PID 1 the init process.
- Added explicit PHP-FPM pool limits, worker recycling, slow-request logging and a hard request timeout to reduce worker starvation/memory-leak accumulation.
- Added cgroup OOM/memory/load/disk snapshots immediately before unrecoverable web exits.
- Fixed CI restart testing to kill PHP-FPM by its supervised PID instead of relying on `pkill`, and added worker/scheduler health integration coverage.
- Added host-side runtime diagnosis tooling for restart policy, restart count, exit code, OOMKilled state, health history, logs and Docker events.

## 1.0.6 - Self-healing web watchdog - 2026-08-16

- Added an internal HTTP watchdog that exercises the complete Nginx -> PHP-FPM -> Laravel path through Laravel's dependency-light `/up` endpoint.
- After 3 consecutive liveness failures (configurable), the web supervisor exits non-zero so `restart: unless-stopped` automatically recreates the web runtime.
- Added watchdog interval, timeout, failure-threshold and startup-grace environment controls with safe production defaults.
- Switched Dockerfile/Compose web health checks to `/up` so transient PostgreSQL/Redis outages do not create pointless web restart loops; `/health/ready` remains the deep dependency/schema diagnostic.
- Added watchdog/restart invariants to release checks and deployment/operations documentation.

## 1.0.5 - Runtime health & Coolify hardening

- Added separate `/health/live` liveness and retained `/health/ready` dependency readiness.
- Added explicit Compose health checks for every long-running service.
- Reworked the web process supervisor so an unexpected PHP-FPM or Nginx exit terminates the container and activates Docker restart recovery.
- Added graceful init/stop handling for web, worker and scheduler containers.
- Added private-GitHub/Coolify deployment guidance.

## 1.0.4 - Password Reset RBAC + Full Re-test Hardening - 2026-08-15

- Added a dedicated `reset_user_passwords` permission and secure password-reset workflow for Super Admin plus explicitly delegated roles.
- Password-reset delegation itself is Super-Admin-controlled even if `manage_roles` is delegated, preventing a delegated role manager from granting itself account-reset authority.
- Password reset uses a row lock (`lockForUpdate`) so concurrent resets always advance `session_version` and cannot leave a newly-created session valid after a second reset.

- Added dedicated `reset_user_passwords` permission, enabled for Super Admin by default and grantable/revocable for any other role through the Settings permission matrix.
- Added scoped password reset UI/API with password confirmation, optional reason, audit logging, remember-token rotation and stale-session revocation.
- Added `users.session_version` and `users.password_changed_at`; login/session middleware now rejects sessions created before a subsequent password reset.
- Prevented password changes through generic `manage_users` updates so the reset permission cannot be bypassed.
- Hardened delegated User Management against privilege escalation, cross-Center/Zone user administration and organization-wide user-count leakage.
- Limited reset-only user listings to accounts the delegated role can actually reset.
- Added login flash rendering so session-expiry/self-reset messages are visible.
- Extended `/health/ready` to check password-security schema columns.
- Added static source-integrity checks to release tooling/CI and expanded password/security regression coverage.
- Re-ran complete offline syntax/config/route/permission/Inertia/security/release-integrity audits; runtime dependency/Docker gates remain mandatory in GitHub CI.

## 1.0.3 - Super Admin Access and Sidebar UX Hotfix - 2026-08-15

- Fixed Super Admin `My Target` access: the page now opens even when the Super Admin is not linked to a Sankalp Karyakar and no approved Karyakar exists; it shows an admin preview selector/empty state instead of HTTP 403.
- Fixed Bal Dashboard and Bal Analysis PostgreSQL 500 errors caused by an ambiguous unqualified `status` column in the child-gender aggregate join.
- Hardened Reminders/Alerts loading against orphaned relations and added a fail-soft schema warning instead of a generic 500 when Phase 3 storage is missing.
- Added a production repair migration for missing Phase 3 inactivity and Phase 5 Bal Pravruti tables from early deployment candidates.
- Expanded `/health/ready` to verify operational database schema tables in addition to database/cache connectivity.
- Fixed Bal completion Society validation to use the Bal Group's assigned Sampark Area.
- Made desktop sidebar and main content independently scrollable.
- Added persistent desktop sidebar scroll position across Inertia navigation.
- Added longest-route active menu highlighting so exactly the current section remains highlighted (for example Bal Analysis does not also highlight Bal Dashboard).
- Aligned desktop sidebar responsive breakpoint with Tailwind `lg`.
- Added Super Admin UI access regression tests for My Target, Reminders/Alerts, Bal Dashboard and Bal Analysis.

## 1.0.2 - Coolify Deployment Hotfix - 2026-08-14

- Reworked the production HTTP runtime so Nginx and PHP-FPM run in the same `web` container and FastCGI uses `127.0.0.1:9000`, eliminating cross-container FastCGI DNS/connectivity as a Coolify 502 failure mode.
- Removed the standalone Compose `app` service; queue worker and scheduler still use the reusable PHP application image target.
- Added explicit PHP-FPM loopback listener configuration, Nginx/PHP-FPM startup validation, local port readiness wait, and Dockerfile + Compose health checks.
- Made `APP_KEY`, `APP_URL`, and `DB_PASSWORD` fail-fast required Compose variables.
- Updated backup/restore scripts and Coolify documentation for the new runtime topology.

## 1.0.1 - Audited Final - 2026-08-14

- Performed a full source-level security, scope, business-rule, deployment and release audit against the SRS/wireframe baseline.
- Fixed a Docker fresh-database bootstrap deadlock and made migration startup retry-safe.
- Fixed private SMVS import storage, added persistent private storage, and extended backup/restore coverage.
- Fixed Redis password propagation/healthcheck behavior in Docker Compose.
- Fixed same-Zone cross-Center data leakage by ensuring only the actual Zonal Admin role grants Zone-wide access.
- Hardened global support/content permissions and null-Center privacy for Support, Family Time, Announcements, Shared Content, Testimonials and Correction Requests.
- Added inactive-session enforcement and email canonicalization/DB protection.
- Hardened Group/Family/Target invariants, including permission separation, pending Remaining Family activation blocking, active Karyakar guards and Target Area/Society synchronization after Group location changes.
- Hardened Family/Karyakar/Bal data eligibility, one-head Family rules, import atomicity and optional external-code normalization.
- Added the SRS-required Correction/Change Request workflow and audited Sankalp Family/Family Member edit workflow.
- Fixed PHP 8.4 CSV deprecation calls and included both Unit and Feature suites in PHPUnit configuration.
- Added regression tests for the audit fixes and expanded release documentation.
- Moved registration imports to the dedicated Redis `imports` queue with private-volume access and a 900-second worker timeout/retry window for large files.
- Preserved original Family registration date/user when later SMVS Global imports refresh existing Family details.
- Closed active Group targets automatically when a Family transfer makes the source Group draft, preventing orphaned active targets.

## 1.0.0 - Full Project - 2026-08-13

- Completed Phase 6 Wireframe Support Modules: Announcements, Family Time, Shared Content, browser sharing, Testimonials/Feedback, video/highlight and motivation carousel, Inventory/Stock, Sticky Notes and Contact/Support.
- Added Center/global scope controls and administrative audit entries for new support modules.
- Completed Phase 7 production hardening with editable role-permission matrix and Area/Society master management.
- Added security headers, trusted-proxy handling, production PHP settings and DB/cache readiness endpoint.
- Added streaming CSV/TSV import path for large Center data files.
- Added optional deterministic staging pilot seeder guarded by `PILOT_DATA=true`.
- Added permission, production-hardening, import-streaming and Phase 6 feature tests.
- Added GitHub CI gates for Composer validation, TypeScript, Vite build, Laravel tests and Docker target builds.
- Added backup/restore scripts, operations runbook, final acceptance matrix, final handoff and production Coolify guide.
- Updated requirement traceability so every in-scope functional SRS/wireframe row is implemented or tied to a documented source ambiguity decision.

## 0.6.0-phase5 - 2026-08-13

- Added the separate Bal Pravruti module with its own data model, routes, responsive pages and role-scoped analysis.
- Added Bal Group creation with exactly 3 active child Family Members and 1 Approved Sankalp Karyakar linked to a Sanchalak portal user.
- Added Center-prefixed Bal Group codes such as `GND-BAL-001`.
- Added explicit Nirdeshak/Nirikshak per-Group supervision scope and Sanchalak own-Group scope.
- Added assigned-Sanchalak-only completion reporting with Society, visited/completed counts, optional mobile, Family link/name/details and completion date.
- Added separate Bal Dashboard, Center/Zone/Group analysis, child gender distribution, Sanchalak Category filtering and completion trend.
- Added BN Karyalay server-locked Female Sanchalak Bal analysis.
- Added Bal completed-family contribution to main Center/Zone/Karyalay `overall completed` analysis without inventing a combined percentage denominator.
- Added Bal audit scoping and removed main-project report permission from Bal-only roles.
- Added Phase 5 feature tests and updated RTM, decisions, architecture, business rules, role matrix and handoff documentation.

## 0.5.0-phase4 - 2026-08-13

- Added role-scoped organization, Zone and Center monitoring dashboards.
- Added Center and Zone performance drill-downs, leaderboards and target-vs-completed progress.
- Added Male/Female and Sankalp Karyakar Category analysis filters.
- Added BN Karyalay female-locked analysis while preserving the configured administration scope.
- Locked Karyakar reporting/analysis to the linked Karyakar's own active assignments.
- Added completion trend and category/gender distribution analytics.
- Added all ten minimum SRS reports with role scope and relevant filters.
- Added CSV export for each report.
- Enhanced Activity/Audit Logs with filters and old/new/reason detail rendering.
- Added Phase 4 feature tests and updated RTM, architecture, business rules and handoff documentation.

## 0.4.0-phase3 - 2026-08-13

- Added mobile-first Karyakar My Target / Home Visit workflow.
- Added assigned Family checklist with Fixed/Remaining and Completed/Pending states.
- Added click-to-call for scoped Karyakar and Head-of-Family mobile numbers.
- Added transactional Home Visit records with duplicate-completion database protection.
- Added Super Admin completion override with required assigned Karyakar and reason.
- Added visit-driven Group/Karyakar target recalculation for completed/remaining/percentage/status.
- Added same-portal completion report popup with Zone, Center, completed, pending and ratio analysis.
- Added persisted motivation badges for 3/6/9/12/15 completed Family milestones.
- Added 4-day Reminder / 7-day Alert scheduler logic and scoped history UI.
- Added Phase 3 feature tests, RTM updates, ambiguity decisions, architecture and deployment notes.

## 0.3.0-phase2 - 2026-08-13

- Added Center Code based Sankalp Group creation with exactly 2 approved Karyakars.
- Added Couple / 2 Male / 2 Female server-side Group type validation.
- Added multiple-Group Karyakar assignments.
- Added exactly-10 Family composition controls with 5-6 Fixed/Locked and 4-5 Remaining activation gate.
- Added database + transactional duplicate active Family assignment prevention.
- Added authorized Family transfer workflow with closure, destination and reason/audit trace.
- Added Karyakar existing Remaining Family selection and new Family reporting/Center Admin verification.
- Added Group/Karyakar/Family Sampark Area & Society assignment with scoped validation and audit reason.
- Added target assignment model/UI for Center, Group, Karyakar, Area, Society, dates and quantity.
- Added Karyakar portal user linkage and own-Group visibility.
- Added Phase 2 feature tests and updated RTM, business rules, architecture and handoff docs.

## 0.2.0-phase1 - 2026-08-13

- Added SMVS Global Family/Member import with Family ID / Member ID identities.
- Added Sampark Area/Society import foundation.
- Added Manual Sankalp Family registration and member capture.
- Added Manual and Family-ID based Sankalp Karyakar registration/nomination.
- Added Pending / Approved / Rejected Karyakar approval workflow.
- Added server-side Age + Gender to eight-category calculation.
- Added Family/Karyakar search, filters and Phase 1 tests.

## 0.1.0-phase0 - 2026-08-13

- Added Laravel 13 + React/Inertia application foundation.
- Added login/logout and login throttling.
- Added role/permission and Zone/Center scoped access architecture.
- Added Zone, Center and User management foundation.
- Added reusable Activity/Audit Log framework.
- Added responsive Happy Family themed navigation/dashboard shell.
- Added PostgreSQL, Redis, worker, scheduler, Nginx, Docker Compose and Coolify packaging.
- Added full project Requirement Traceability Matrix and roadmap.
- Added Phase 0 feature tests and GitHub Actions CI definition.
