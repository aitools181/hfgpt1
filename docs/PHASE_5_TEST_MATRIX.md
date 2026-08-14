# Phase 5 Test Matrix - Bal Pravruti

Version: **0.6.0**

| ID | Requirement / Risk | Automated coverage | Expected result |
|---|---|---|---|
| P5-T01 | Center Admin Bal permission and scoped Group creation | `Phase5BalPravrutiTest::test_center_admin_can_create_exact_three_children_one_sanchalak_group` | Creates `GND-BAL-001`; exactly 3 active child assignments; exactly 1 linked Sanchalak |
| P5-T02 | Child eligibility | `test_group_creation_rejects_non_child_member_over_age_twelve` | Age >12 member rejected and no Bal Group persists |
| P5-T03 | Assigned Sanchalak-only completion | `test_only_assigned_sanchalak_can_submit_bal_completion_report` | Other Sanchalak receives 403; assigned Sanchalak can submit |
| P5-T04 | Nirdeshak assigned scope isolation | `test_nirdeshak_is_limited_to_explicitly_assigned_bal_groups` | Only explicitly supervised Group is returned |
| P5-T05 | Main analysis includes Bal completion count | `test_bal_completed_count_contributes_to_main_center_and_overall_analysis` | Center summary has Bal count and `overall = main + Bal` |
| P5-T06 | Exactly 3 children request shape | Group store validation `array|size:3` + `distinct` | 2/4/duplicate child payload is rejected |
| P5-T07 | Sanchalak identity | `BalPravrutiService::createGroup` | Must be Approved Karyakar, same Center, linked to Sanchalak user |
| P5-T08 | Society/Center isolation | `assertAreaSocietyCenter` | Cross-Center Society/Area rejected |
| P5-T09 | Completion count consistency | `submitCompletion` | `families_completed > families_visited` rejected |
| P5-T10 | Bal role routing | `DashboardController` + RBAC seed | Nirdeshak/Nirikshak/Sanchalak root dashboard redirects to separate Bal dashboard; no main report permission |
| P5-T11 | BN female analysis scope | `BalPravrutiService::filters` | Gender is server-forced to Female for BN Karyalay analysis |
| P5-T12 | Bal audit scope | `AuditLogController::applyBalAuditScope` | Bal field/supervision roles can see assigned Group-related Bal audit records without Center-wide leakage |

## Static release checks in this build environment

- PHP syntax lint across application, migrations, routes and tests.
- JSON validation for `composer.json` and `package.json`.
- YAML parse validation for `docker-compose.yml`.
- TypeScript/TSX parser/static syntax pass using the locally installed TypeScript compiler; dependency-resolution errors are excluded because project `node_modules` is intentionally not present in this build environment.
- Release manifest SHA-256 verification.
- ZIP integrity verification.

## Deferred full runtime stabilization

Per project workflow, the complete dependency install, Laravel/PHPUnit runtime suite, Vite production build, Docker Compose runtime, PostgreSQL concurrency behavior and Coolify deployment validation remain part of Phase 7 production hardening. Phase 5 still ships the feature tests above so they run once normal project dependencies are installed.
