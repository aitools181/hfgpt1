# Full Code Audit - SMVS Happy Family Portal v1.0.8

Audit date: 2026-08-18

This cumulative audit covers the complete portal plus the production hotfixes through v1.0.8. Functional/SRS traceability remains in `REQUIREMENT_TRACEABILITY.md`; this document focuses on code, data integrity, security, scale and runtime availability.

## Areas re-reviewed

- authentication, active-user/session invalidation and password reset RBAC;
- role/permission delegation and Zone/Center data isolation;
- Family/Karyakar import/manual registration and approval;
- exact Group composition and Family assignment/transfer invariants;
- Targets/Home Visits/reminders/badges;
- Bal Pravruti roles/groups/completions/analysis;
- reports/CSV exports and large-data query behavior;
- audit/support/inventory/content modules;
- PostgreSQL migrations/indexes and race-sensitive transactions;
- Redis queue behavior and queued import retry/idempotency;
- Nginx/PHP-FPM lifecycle, health checks and process supervision;
- Docker/Coolify startup ordering, restart behavior, persistence and resource limits;
- build/CI/release tooling.

## v1.0.8 availability defects/risk paths corrected

1. Large reports no longer load complete detail result sets into PHP memory; previews are capped and CSV rows stream lazily.
2. Long CSV exports have a dedicated FPM pool and cannot consume the normal interactive pool.
3. CSV/TSV/XLSX imports are streamed/bounded and maliciously compressed XLSX content is rejected.
4. Web FPM/Nginx failure is recovered in-container first; repeated unrecoverable failure escalates to Docker `restart: always`.
5. A still-running but non-responsive Nginx/FPM is detected by dedicated infrastructure probes and recycled.
6. Laravel liveness is isolated from normal interactive workers and from PostgreSQL/Redis readiness, removing the old false-positive restart mechanism.
7. Worker/scheduler child exit no longer means the Compose service exits; an in-container supervisor respawns with backoff.
8. Worker/scheduler deployment startup waits for web migration/bootstrap completion.
9. Service memory/PID ceilings, DB/Redis runtime limits and production host RAM preflight reduce host OOM risk.
10. Redis readiness verifies write ability, detecting `noeviction` memory exhaustion before queue submission failures become unexplained.
11. Nginx/runtime/background log growth is bounded and a log-write failure cannot kill a supervisor.
12. PostgreSQL connection/query timeouts and deployment dependency retries bound several indefinite/transient stalls.
13. Large assignment catalogs are bounded or on-demand and dashboard/inactivity repeated-query paths were reduced.
14. Production-scale indexes were added for high-frequency lookup/report/inactivity paths.
15. Heavy login/import/export actions have basic throttling to reduce burst-driven resource pressure.

Detailed cause/recovery mapping is in `V1_0_8_FAILURE_PREVENTION_AUDIT.md`.

## Security and data-integrity invariants retained

- Super Admin has password-reset authority by default; delegation is explicit and scope/rank constrained.
- Only Super Admin may grant/revoke the reset-password permission itself.
- Password reset invalidates stale sessions and never logs password content.
- Center/Zone authorization is enforced server-side rather than UI-only.
- Groups remain exactly 2 approved Karyakars and exactly 10 Families when active, with Fixed/Remaining rules enforced server-side.
- duplicate active Family assignment is prevented and transfers are audited.
- Family/Karyakar/Area/Society/Target/Bal changes remain scoped and auditable.
- private imports remain on private persistent storage and are removed after terminal processing.

## Honest limitation

A finite source audit cannot prove that software will never fail. External host/network/storage/Docker failures and undiscovered defects remain possible. The release therefore combines prevention, bounded resource use, automatic recovery, persistent diagnostics, CI fault injection and backup/restore procedures rather than claiming an impossible zero-failure guarantee.
