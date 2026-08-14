# Phase 4 Handoff - Monitoring & Analysis

Version: **0.5.0**  
Scope: cumulative Phase 0 + Phase 1 + Phase 2 + Phase 3 + Phase 4

## Delivered

### Role-scoped dashboard and analysis

- Super Admin / Karyalay Admin organization-wide campaign summary.
- Zonal Admin data constrained to the assigned Zone.
- Center Admin / Computer Op. data constrained to the assigned Center.
- Karyakar analysis locked server-side to the Karyakar record linked to the signed-in portal user and its active Groups.
- BN Karyalay analysis locked server-side to `gender=female`.
- Center and Zone drill-down tables.
- Center-wise and Zone-wise leaderboards.
- Zone/Center Assigned vs Completed progress visualization.
- Karyakar gender distribution.
- Sankalp Karyakar Category distribution.
- completion trend by day with a maximum 90-day chart window.
- Dashboard quick actions for the permissions held by the signed-in user.

### Required reports

The Phase 4 report module implements the ten minimum reports in SRS Section 22:

1. Center-wise Sankalp Family Registration
2. Center-wise Karyakar
3. Group-wise Karyakar
4. Area-wise Assignment
5. Target Assignment
6. Target Completion
7. Pending Sankalp Family
8. Home Visit Completion
9. Center Performance Summary
10. Organization-wide Summary

All report routes use the same role-scope service as the portal. Relevant filters include Center, Group, Karyakar, Area, Gender, Category, Status and date range. Every report can be exported to CSV through the same scoped report service.

### Activity/Audit Log view

- Center, module, action, role, date and search filters.
- Old Value and New Value JSON detail disclosure.
- Reason/change-note column.
- Center name display.
- Karyakar and Bal field roles are restricted to their own audit actions.
- Center/Zone administrators remain restricted to permitted organizational logs.

## Important implementation choices

- Current campaign completion is derived from active Group Family assignments and their unique Home Visit completion record.
- Target quantity is shown separately from Group Family completion because SRS DP-006 (10-Family Group versus example target of 100) is still unresolved.
- BN Karyalay's Female analysis lock is enforced on the server. Passing `gender=male` does not broaden/change the analysis scope.
- No new reporting snapshot tables are introduced; reports are generated from authoritative operational data to avoid stale duplicated totals.

## Main code added/changed

- `app/Services/Monitoring/MonitoringAnalyticsService.php`
- `app/Services/Monitoring/ReportService.php`
- `app/Http/Controllers/Monitoring/AnalysisController.php`
- `app/Http/Controllers/Monitoring/ReportController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/Admin/AuditLogController.php`
- `app/Models/AuditLog.php`
- `resources/js/pages/monitoring/analysis.tsx`
- `resources/js/pages/monitoring/reports.tsx`
- `resources/js/pages/dashboard.tsx`
- `resources/js/pages/admin/audit-logs.tsx`
- `resources/js/layouts/app-layout.tsx`
- `routes/web.php`
- `tests/Feature/Phase4MonitoringAnalysisTest.php`

## Validation completed in this build environment

- PHP syntax lint across application, routes, migrations, seeders and tests.
- TypeScript/TSX syntax transpilation check across `resources/js`.
- JSON syntax validation for `composer.json` and `package.json`.
- Docker Compose YAML parse validation.
- release manifest SHA-256 verification.
- final ZIP integrity verification.

## Runtime validation deferred

This environment does not contain Composer `vendor/` or project `node_modules/`, so the full Laravel/PHPUnit runtime suite and Vite production build are deferred. Docker CLI/runtime is also not available here. Per the project development policy, full environment/deployment stabilization remains in Phase 7 after all functional phases are complete.

## Next phase

Phase 5 - Bal Pravruti:

- separate Bal Pravruti data model and role scope
- 3 children + 1 Sanchalak Group structure
- Nirdeshak / Nirikshak / Sanchalak workflows
- Bal family completion entry/report
- separate Bal Dashboard and Analysis
- contribution of Bal completion into main Zone/Center/Karyalay totals
