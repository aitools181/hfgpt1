# v1.0.15 Bug-Fix Validation Record

Date: 2026-08-19

## Scope

This release is a focused correction pass over v1.0.14 based on the supplied testing workbook and screenshot filenames. Existing routes, data models, authorization boundaries and established workflows are retained except where a reported bug explicitly required a behavior correction.

## Implemented corrections

| Reported issue | v1.0.15 correction | Deployment retest |
|---|---|---|
| Center Admin sees Center creation | Create form is rendered only with `manage_centers`; POST/PUT remain permission protected. | Login as Center Admin; verify list is visible and create form is absent. |
| Scope displays `Center #x` / `Zone #x` | User Management now emits full Organization/Zone/Center labels with names/codes. | Review Super Admin and delegated user rows. |
| Duplicate exact two-person Group | Group creation rejects an existing Group with the same two active Karyakars. | Re-submit the same pair, then pair one member with a different Karyakar. |
| Add blank Family members repeatedly | Add is blocked until the current member has Name, Age and Relationship. | Try Add on an incomplete member row. |
| Invalid mobile accepted / unclear error | Manual Head/member and reported Remaining Family mobiles normalize and require valid 10-digit Indian format when present. | Test invalid, valid and `+91` reported-family input. |
| Female member marked Head not shown as Family Head | Marked member becomes Family `head_name`; historical marked Heads are backfilled by migration. | Register female Head, deploy migration, then search/assign the Family. |
| Only one Family can be assigned per action | Admin Group detail supports checkbox multi-select and posts `family_ids[]` atomically. | Assign two or more eligible Families in one action. |
| Duplicate Family by Head mobile | Existing Head mobile is checked before Manual Family and Karyakar-reported Family creation. | Try a second registration with the same Head mobile. |
| Karyalay Inventory detail not sent to Center | Karyalay/Super Admin inward transaction publishes a Center-scoped announcement containing item/quantity/reference/note. | Record inward stock and login as receiving Center user with Announcements permission. |
| Area/Society assignment unclear / typed search not selected | Assignment page now explains the full workflow, labels search vs selection, auto-selects a single result, and explains missing master data. | Search Group/Area, choose or auto-select, save with reason. |
| Zonal Admin sees Target Assign | Seeder baseline and corrective migration remove `assign_target` from Zonal Admin and Center Admin. | Deploy migration, re-login as both roles, verify action is absent. |
| Higher scope assumed to inherit lower-role permissions | Baselines remain explicit per role; no permission inheritance is introduced. | Compare Permission Matrix for Center Admin vs Zonal Admin. |
| Assigned Family remains in assignment dropdown | Frontend removes current active assignments immediately and refetches after composition changes; backend already excludes active assignments. | Assign Family and reopen/search; verify transfer remains only in current Group detail. |
| Analysis filters cannot be changed; no Group member/status filter | Read-only controlled-select bug fixed. Group labels include active member names and Group Status supports Active / Non-active. | Change filters, Apply, Reset, and verify filtered Group rows. |

## Source validation completed

- `python3 scripts/static_integrity_check.py`: PASS
  - Inertia pages: 32
  - Named routes: 88
  - Seeded permissions: 43
  - Used route/navigation permissions: 39
- PHP syntax: PASS across `app/`, `routes/` and `database/`.
- TS/TSX syntax transpilation: PASS across `resources/js/`.
- Role-permission pivot migration verified against the actual `role_permissions` table name.
- Testing workbook updated as a retest artifact; corrected cases remain `Not Tested` until deployed browser/database retest.

## Validation boundary

The packaging environment does not contain Composer `vendor/` or NPM `node_modules/`. Dependency installation did not complete in the isolated environment, so `php artisan test`, TypeScript type-check, Vite production build and live PostgreSQL/browser workflow retests were not claimed here. Run the normal CI/release check after dependencies are installed and then execute the updated testing workbook against the deployed build.
