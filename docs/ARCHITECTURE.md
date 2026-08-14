# Architecture

## Goal

One centralized, role-based, multi-Center web application with strict organizational scoping and auditable administrative actions.

## Runtime topology

```text
Internet
  |
Coolify reverse proxy / HTTPS
  |
web (Nginx)
  |
app (PHP-FPM / Laravel)
  |-- PostgreSQL
  |-- Redis
  |-- worker (Laravel queue)
  `-- scheduler (Laravel scheduler)
```

## Application pattern

- Laravel owns routing, validation, authorization, transactions and persistence.
- Inertia bridges Laravel routes/controllers to React pages.
- React renders the responsive authenticated portal.
- PostgreSQL is the source of truth for relational domain data.
- Redis provides sessions, cache and queues.
- Queue worker handles asynchronous imports/notifications in later phases.
- Scheduler supports 4-day reminders and 7-day alerts in Phase 3.

## Access-control design

Permissions and organizational scope are separate concepts.

- Role answers: **what may the user do?**
- Scope answers: **which Zone/Center records may the user do it to?**

`user_roles` stores role plus optional `zone_id` and `center_id`. This avoids hard-coding role names into every query and allows later expansion.

## Audit design

`audit_logs` stores:

- actor user and role
- timestamp
- Zone and Center where applicable
- module/action
- record type/reference
- old values
- new values
- reason/change note when supplied
- IP address and user agent

Sensitive keys such as passwords and tokens are redacted before audit storage.

## Scale considerations

The SRS target is approximately 10,000 Karyakars and 100,000 families. The design therefore uses:

- indexed relational keys
- scoped queries rather than client-side filtering
- queues for imports and future long-running work
- normalized assignment tables in later phases
- database constraints for uniqueness/business integrity
- stateless web containers with persistent database/upload volumes

## Phase 2 assignment integrity

Phase 2 uses normalized assignment records rather than storing Group membership in comma-separated fields:

- `groups` - Center-scoped Group master with Center Code based `group_code`, type and lifecycle.
- `group_karyakars` - exactly two active Karyakars per Group at creation; a Karyakar may appear in multiple Groups.
- `group_family_assignments` - 10-slot assignment history with `fixed` / `remaining`, source, transfer closure and reason.
- `remaining_family_reports` - controlled Karyakar-to-Center-Admin reporting for newly identified Remaining Families.
- `targets` - Center/Group/Karyakar/Area/date/quantity target assignments; Home Visit-driven progress is Phase 3.

A partial unique database index permits only one `active` Group assignment for a Family. Service transactions also lock the Group/Family records before assignment or transfer, so duplicate prevention is not dependent on frontend validation. Group activation is blocked unless composition is exactly 10 active Families with either 5 Fixed + 5 Remaining or 6 Fixed + 4 Remaining.

Area/Society changes for Groups, Karyakars and Families are validated against the same Center and recorded with old/new values plus a required reason. Karyakar portal users may view only Groups to which their linked Karyakar record is assigned; candidate Remaining Families are further limited to the Group's assigned Area/Society where available.

## Phase 3 field execution architecture

Phase 3 adds three durable operational record sets:

- `home_visits` - one immutable completion per Group Family assignment, with Karyakar attribution, optional current target, Area/Society snapshot, recorder and optional Super Admin override reason.
- `karyakar_badges` - idempotent milestone award history for 3/6/9/12/15 completed Families.
- `inactivity_events` - Reminder/Alert history with Group, Karyakar, optional target, recipient user, activity anchor, threshold state and resolution time.

The Home Visit service runs in a database transaction, locks the Family assignment, verifies active scope/membership, creates the unique completion, recalculates relevant targets, awards due badges, resolves inactivity state and builds the completion popup payload. The database unique key on `home_visits.group_family_assignment_id` is the final duplicate-completion guard.

Target progress is derived from persisted completion rows rather than trusting a client-supplied counter. Area/Society are snapshotted on the Home Visit so later master-data changes do not rewrite historical target attribution.

The scheduler service already present in `docker-compose.yml` runs `happy-family:inactivity-check` hourly through Laravel's scheduler. Reminder and Alert creation is idempotent through application checks plus a partial unique index on open/escalated event type per Group/Karyakar.


## Phase 4 monitoring and reporting architecture

- `MonitoringAnalyticsService` is the single aggregation layer for Dashboard, Analysis and leaderboard data. It starts from `OrganizationalScope` and never accepts a Center outside the signed-in user's permitted scope.
- Main-project `karyakar` users are additionally locked to the approved `karyakars.user_id` record so report/analysis access is limited to their own active Group assignments.
- `bn_karyalay_admin` analysis forces `gender=female`; request parameters cannot override the lock. Administrative access remains governed by the configurable RBAC decision in DP-001.
- Campaign completion uses active `group_family_assignments` as the current denominator and one `home_visits` record per assignment as completion evidence. Date filters constrain completion evidence to the requested reporting period.
- Target quantity/completed quantity are reported separately from the current 10-Family assignment completion ratio because DP-006 remains unresolved.
- `ReportService` produces the ten minimum SRS reports from live relational data; CSV export uses the same scoped query result as the on-screen report to prevent export bypass.
- Audit search applies role-aware scoping before user filters. Karyakar/Bal field roles see only their own audit actions; Center/Zone administrators see permitted organizational logs; Karyalay-level administrators see the configured full scope.


## Phase 5 Bal Pravruti architecture

Phase 5 is intentionally separated from the main two-Karyakar / ten-Family Group workflow. It uses its own relational aggregate so Bal Pravruti does not weaken or overload the main Sankalp Group invariants.

Durable tables:

- `bal_groups` - Center/Area/Society, human-readable Center-prefixed Bal Group code, exactly one linked Sanchalak Karyakar/user and lifecycle state.
- `bal_group_children` - three child positions per Group, each referencing an existing active Family Member.
- `bal_group_supervisors` - explicit Nirdeshak/Nirikshak Group-scope assignments.
- `bal_completion_reports` - Society, visited/completed counts, optional mobile/Family link, free-text relevant Family details, completion date and Sanchalak attribution.
- `bal_group_sequences` - Center-local locked numbering used for codes such as `GND-BAL-001`.

`BalPravrutiService` is the domain boundary for Group creation, child/Sanchalak validation, explicit Bal role scoping, completion submission and separate Bal analysis. Main-project `nirdeshak`, `nirikshak` and `sanchalak` users are redirected from `/` to the separate Bal dashboard and do not receive the main Reports & Analysis permission.

Scope rules:

- Super/Karyalay, BN Karyalay, Zonal Admin and the configured Center Admin Bal permission operate over their normal organization/Zone/Center scope.
- Nirdeshak and Nirikshak see only Groups explicitly assigned through `bal_group_supervisors`.
- Sanchalak sees only Groups where `bal_groups.sanchalak_user_id` matches the signed-in user.
- Only that assigned Sanchalak can create a completion report. Administrative roles may view/manage Groups but cannot impersonate the field completion submitter.

The main `MonitoringAnalyticsService` does not merge Bal reports into `home_visits`. Instead it adds `bal_completed` and `overall_completed` counts at Center/Zone/Karyalay aggregation level. This preserves the original main assignment denominator and avoids inventing a Bal completion percentage denominator that the SRS does not define.


## Phase 6 support modules

Support/engagement data is normalized into `announcements`, `family_time_schedules`, `family_time_completions`, `shared_contents`, `testimonials`, `inventory_items`, `inventory_transactions`, `sticky_notes` and `support_requests`. `SupportScopeService` applies organization/Center visibility consistently. Inventory mutation uses `InventoryService` transactions and row locking. Uploaded Shared Content persists on the `app_storage` volume.

## Phase 7 production hardening

- `SecurityHeaders` adds browser security headers; trusted-proxy handling supports Coolify HTTPS termination.
- `/health/ready` verifies PostgreSQL and cache availability and returns 503 when a dependency is degraded.
- `docker/php/conf.d/zz-production.ini` enables production PHP/opcache settings and bounded upload sizes.
- The queue worker recycles by max jobs/time to limit long-lived worker memory accumulation.
- CSV/TSV imports use generators for memory-bounded row iteration.
- Optional deterministic UAT data is guarded by `PILOT_DATA`; production default is false.
- GitHub CI validates PHP dependencies, TypeScript, the Vite production bundle, Laravel tests and both final Docker image targets.
- `scripts/backup.sh` and `scripts/restore.sh` cover PostgreSQL plus uploaded public storage with SHA-256 verification.
