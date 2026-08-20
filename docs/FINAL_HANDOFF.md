# Final Handoff - SMVS Happy Family Portal v1.0.16

## Release purpose

v1.0.16 is the cumulative portal release based on v1.0.15 bug fixes, v1.0.14 UI refinement and v1.0.12 runtime hardening. It adds portal-wide live search and automatic filtering while preserving existing scope, permissions, pagination and business rules. Use this package instead of v1.0.15 or earlier for the next deployment/retest.

## Bug-fix highlights

- Center Admin no longer sees Center creation without `manage_centers`.
- User scope labels show real Organization, Zone and Center names/codes.
- Zonal Admin and Center Admin no longer receive Target Assign from their default/existing baseline; role permissions remain independent.
- Exact duplicate two-Karyakar Groups are blocked.
- Manual Family member entry, mobile validation, duplicate Head mobile and female/member-selected Head handling are hardened, including historical Head backfill.
- Group Family assignment supports multiple eligible Families and removes assigned Families from subsequent assignment search; transfer stays in Group detail.
- Area/Society assignment instructions/search-selection behavior are clearer.
- Karyalay inward inventory publishes a Center-scoped announcement with shipment details.
- Analysis filters are selectable again and include Group member labels plus Active/Non-active Group filtering.

See `V1_0_15_BUG_FIX_VALIDATION.md` for the screenshot-to-fix matrix and retest boundary.

## Upgrade in place

1. Back up production database and persistent volumes.
2. Replace repository contents with this release and push the exact commit to the private GitHub repository.
3. Require GitHub Actions CI to pass.
4. Redeploy the existing Coolify Docker Compose resource; do not delete persistent volumes.
5. Allow Laravel migrations to run. v1.0.15 includes a role-permission correction and Family-head data backfill.
6. Confirm `web`, `worker`, `scheduler`, `db` and `redis` are Running/Healthy.
7. Confirm `/up`, `/health/live` and `/health/ready`.
8. Execute the supplied v1.0.15 bug-fix retest workbook before marking corrected cases Pass.

## Production runtime retained

- `web`: Nginx + supervised PHP-FPM with separate interactive, control, report and health pools.
- `worker`: persistent supervisor with recycled/restarted Laravel queue child.
- `scheduler`: persistent supervisor with restarted Laravel scheduler child.
- `db`: PostgreSQL 17 with persistent volume and healthcheck.
- `redis`: Redis queue service with persistent volume, healthcheck and no-eviction policy.
- Docker Compose restart policy remains `always` on long-running services.

## Validation status

Static integrity, complete PHP syntax and TSX syntax validation pass. The packaging environment has no Composer `vendor/` or NPM `node_modules/`, and dependency installation did not finish, so live Laravel tests, TypeScript semantic type-check, Vite build and deployed browser/database retests must run in CI/deployment before final acceptance.


## v1.0.16 live search note

Search text now refreshes matching data after a short debounce and select/date filters refresh automatically. Existing Search/Apply buttons remain optional fallback controls. No database migration was added in v1.0.16.
