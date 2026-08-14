# Phase 4 Test Matrix - Monitoring & Analysis

| ID | Requirement / behavior | Automated coverage | Expected |
|---|---|---|---|
| P4-T01 | Center Admin monitoring cannot include another Center | `Phase4MonitoringAnalysisTest::test_center_admin_monitoring_is_restricted_to_own_center` | Only assigned Center totals/rows |
| P4-T02 | BN Karyalay analysis is Female-specific | `test_bn_karyalay_analysis_is_locked_to_female_karyakar_scope` | `gender=female` forced server-side |
| P4-T03 | Karyakar report/analysis is own assigned work only | `test_karyakar_reports_are_locked_to_own_assignments_not_whole_center` | Other Karyakar Groups excluded |
| P4-T04 | CSV export reuses role scope | `test_report_csv_export_does_not_include_out_of_scope_center` | Out-of-scope Center absent |
| P4-T05 | Karyakar Audit Log is own relevant actions | `test_karyakar_audit_view_only_contains_own_actions` | Other Center-user audit row excluded |
| P4-T06 | Center/Zone performance denominator is active Family assignments | service implementation + manual acceptance | Completed + Pending = Assigned |
| P4-T07 | Date range affects completion evidence | service implementation + manual acceptance | Home Visits outside range excluded from period completion/trend |
| P4-T08 | Gender filter | service implementation + UI acceptance | Karyakar/progress analysis narrows to selected gender |
| P4-T09 | Category filter | service implementation + UI acceptance | Karyakar/progress analysis narrows to selected category |
| P4-T10 | Center-wise Family Registration report | `ReportService` + route acceptance | scoped screen + CSV |
| P4-T11 | Center-wise Karyakar report | `ReportService` + route acceptance | scoped screen + CSV |
| P4-T12 | Group-wise Karyakar report | `ReportService` + route acceptance | scoped screen + CSV |
| P4-T13 | Area-wise Assignment report | `ReportService` + route acceptance | scoped screen + CSV |
| P4-T14 | Target Assignment report | `ReportService` + route acceptance | scoped screen + CSV |
| P4-T15 | Target Completion report | `ReportService` + route acceptance | scoped progress + CSV |
| P4-T16 | Pending Sankalp Family report | `ReportService` + route acceptance | active assignments without Home Visit |
| P4-T17 | Home Visit Completion report | `ReportService` + route acceptance | scoped completion rows + CSV |
| P4-T18 | Center Performance Summary | `ReportService` + analytics service | completion/pending/percentage by Center |
| P4-T19 | Organization-wide Summary | `ReportService` + analytics service | permitted-scope summary |
| P4-T20 | Enhanced Audit Log old/new/reason view | controller + UI acceptance | detailed trace visible within scope |
| P4-T21 | Zone-wise leaderboard | analytics service + UI acceptance | ranked by completion % |
| P4-T22 | Center-wise leaderboard | analytics service + UI acceptance | ranked by completion % |
| P4-T23 | Zone Target vs Completed view | analytics service + UI acceptance | assigned/completed progress displayed |

Full runtime execution of automated tests is deferred until dependencies are available / Phase 7 stabilization. Static source validation is included in the Phase 4 handoff.
