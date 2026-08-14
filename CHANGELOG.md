# Changelog

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
