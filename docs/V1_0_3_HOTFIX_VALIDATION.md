# v1.0.3 Hotfix Validation

## Reported production issues addressed

1. **Super Admin -> My Target returned 403** when no approved Sankalp Karyakar was linked/available.
   - Fixed by allowing Super Admin to open the page without a linked Karyakar.
   - The page now renders an admin-preview selector or a clear empty state.

2. **Super Admin -> Bal Dashboard / Bal Analysis returned 500.**
   - Root cause found in `BalPravrutiService::childGenderDistribution()`.
   - The query joined `bal_group_children` and `family_members`, both containing a `status` column, while filtering with an unqualified `status` reference.
   - PostgreSQL therefore raised an ambiguous-column SQL error.
   - Fixed by qualifying `bal_group_children.status`.

3. **Super Admin -> Reminders / Alerts returned 500.**
   - Hardened the query against orphaned relations.
   - Added a fail-soft schema warning if the Phase 3 storage table is absent.
   - Added a repair migration for upgraded deployments where migration history and physical feature tables are inconsistent.

4. **Desktop left navigation moved back to the top after clicking a menu item.**
   - Sidebar now has independent desktop scrolling.
   - Sidebar scroll position is persisted in `sessionStorage` and restored across Inertia page navigation.

5. **Clicked menu item did not remain clearly selected.**
   - Added active-route highlighting.
   - Longest matching route wins, so `/bal-pravruti/analysis` highlights only `Bal Analysis`, not both the Bal parent and child entries.

6. **Main content and left navigation needed independent scrolling.**
   - Desktop shell now uses two independently scrollable panes: left navigation and right content.

## Additional fixes included

- `/health/ready` now validates required operational schema tables in addition to database/cache connectivity.
- Added a no-destructive-down repair migration for missing `inactivity_events` and Bal Pravruti operational tables.
- Fixed Bal completion Society validation to use the Bal Group's assigned Sampark Area.
- Aligned sidebar responsive CSS breakpoint to Tailwind `lg` (1024px).
- Added `UiAccessRegressionTest` for Super Admin My Target, Reminders/Alerts, Bal Dashboard and Bal Analysis.

## Offline validation performed

- PHP syntax lint across the complete source tree.
- TypeScript/TSX syntactic transpilation across the complete frontend source tree using TypeScript 5.8.3.
- Release file/hash validation before packaging.
- ZIP integrity validation.

## Runtime-test limitation

This build environment does not provide Docker and package downloads time out, so the full Laravel PHPUnit suite, Vite production build, and real PostgreSQL Docker integration suite cannot be executed here. The repository CI remains the final runtime gate after GitHub push. In particular, the new regression tests should be allowed to complete in GitHub Actions before production acceptance.
