# Phase 5 Handoff - Bal Pravruti

Release: **SMVS Happy Family Portal v0.6.0**  
Date: **2026-08-13**  
Package type: **Cumulative - Phase 0 through Phase 5**

## Delivered functional scope

Phase 5 implements the separate Bal Pravruti workflow required by the SRS rather than reusing the main Sankalp two-Karyakar / ten-Family Group model.

### Bal Group management

- Separate Bal Group records and Center-local codes such as `GND-BAL-001`.
- Exactly **3 distinct children + 1 Sanchalak** at creation.
- Child candidates are active Family Members age 0-12 (DP-011).
- Sanchalak must be an **Approved Sankalp Karyakar** linked to a portal `sanchalak` user in the same Center.
- Optional Sampark Area and Society.
- Nirdeshak and Nirikshak supervisor assignment at Group creation.
- Full Group detail showing the 3 children, Sanchalak, supervision and completion history.

### Bal role scope

- Karyalay/Super Admin: organization-wide Bal access.
- BN Karyalay Admin: configured organization administration with Female Sanchalak analysis lock.
- Zonal Admin: assigned Zone.
- Center Admin: assigned Center under DP-010.
- Nirdeshak: explicitly assigned Bal Groups only.
- Nirikshak: explicitly assigned Bal Groups only.
- Sanchalak: own assigned Bal Groups only.
- Bal-only roles are redirected from the main dashboard to the separate Bal dashboard and do not receive main-project Reports & Analysis permission.

### Bal completion entry

Only the **assigned Sanchalak** can submit the completion report for a Bal Group. The report stores:

- Society
- Families Visited
- Families Completed
- optional Mobile Number
- optional known Sankalp Family link
- Family / Head name
- relevant Family details
- completion date
- submitting Sanchalak/user

The server prevents completed count from exceeding visited count and validates Center consistency.

### Separate Bal Dashboard & Analysis

Role-scoped Bal views include:

- active Bal Groups
- assigned children
- distinct Sanchalaks
- completion report count
- Families Visited
- Families Completed
- visited-to-completed rate
- Center analysis
- Zone analysis
- Group analysis
- child Male/Female distribution
- Sanchalak Category filter/distribution
- date-filtered completion trend

### Contribution to main analysis

At Karyalay/Super Admin, Zone and Center scope the main monitoring service now exposes:

- `completedFamilies` - main Sankalp completion count
- `balCompletedFamilies` - Bal Pravruti completed-family count
- `overallCompletedFamilies` - main + Bal

Center/Zone rows similarly expose `completed`, `bal_completed`, and `overall_completed`. The main assignment percentage is intentionally not converted to a combined percentage because the SRS does not define a Bal denominator (DP-012).

### Audit traceability

Bal Group creation, child assignments, supervisor assignments and completion reports create Audit Log records. Nirdeshak/Nirikshak/Sanchalak audit access is narrowed to their assigned Bal Group records (plus their own actions), instead of exposing the whole Center.

## Main files added

- `database/migrations/2026_08_13_050001_create_phase5_bal_pravruti_tables.php`
- `app/Models/BalGroup.php`
- `app/Models/BalGroupChild.php`
- `app/Models/BalGroupSupervisor.php`
- `app/Models/BalCompletionReport.php`
- `app/Services/Bal/BalPravrutiService.php`
- `app/Http/Controllers/Bal/*`
- `resources/js/pages/bal/dashboard.tsx`
- `resources/js/pages/bal/groups.tsx`
- `resources/js/pages/bal/group-detail.tsx`
- `resources/js/pages/bal/completions.tsx`
- `resources/js/pages/bal/analysis.tsx`
- `tests/Feature/Phase5BalPravrutiTest.php`

## Existing files materially updated

- RBAC seed and Sanchalak-to-Karyakar user linkage
- main dashboard routing/navigation
- main Monitoring Analytics + report outputs for Bal completion contribution
- Audit Log Bal scope
- RTM, roadmap, architecture, business rules and decision register

## Requirement decisions retained

- **DP-010:** conflicting Center Admin Bal access statements - current seed follows the explicit Bal hierarchy.
- **DP-011:** child = active Family Member age 0-12 based on Bal/Balika category rule.
- **DP-012:** Bal counts contribute to overall counts; no invented combined percentage.
- **DP-013:** Nirdeshak/Nirikshak assignment is optional per Group until cardinality is confirmed.

## Validation / environment limitation

This package is statically release-checked. The current build environment does not contain project `vendor/` or `node_modules/`, so full `php artisan test`, Vite bundle and Docker runtime validation are intentionally deferred to Phase 7, consistent with the project batch policy. Test source for Phase 5 is included now.

## Next full phase

**Phase 6 - Wireframe Support Modules**: announcements, Family Time + schedule, supportive content library/share, testimonials/feedback, Guruji video/motivation, leaderboards extension, inventory/stock, sticky notes and Contact/Support.
