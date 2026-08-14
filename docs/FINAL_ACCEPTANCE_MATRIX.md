# Final Acceptance Matrix - v1.0.2

This matrix maps the uploaded SRS page structure, integrated additions and wireframe support screens to implementation evidence and production acceptance checks.

| Area | Implementation evidence | Automated evidence | Production/UAT acceptance |
|---|---|---|---|
| 1 Login / authentication | Login controller, auth middleware, throttling | Phase 0 feature tests | Sign in/out; unauthenticated routes redirect; wrong credentials rejected |
| 2 Dashboard Overview | role-aware DashboardController/UI | Phase 4 tests | Verify Super/Zone/Center/Karyakar summaries against seeded records |
| 3 Sankalp Karyakar | registration models/controllers/UI | Phase 1/2 tests | Search, Gender/Category/status filters and Group assignments |
| 4 Sankalp Family | Family/Member models, list/detail | Phase 1/2 tests | Family ID, head, members, counts, Area/Society/Group visible within scope |
| 5 SMVS Global Import | queued ProcessRegistrationImport + RegistrationImportService + TabularFileReader | ImportStreamingTest + Phase 1/regression tests | Import Center-specific sample; verify Family/Member identities, original registration provenance on re-import, batch completion and worker health |
| 6 Karyakar Registration/Nomination | manual + Family-ID nomination | Phase 1 tests | Nominate selected Family Member; Category auto-fills from Age/Gender |
| 7 Karyakar Approval | pending/approved/rejected service/UI | Phase 1 tests | Approve/reject; only approved appears for Group selection |
| 8 Group Management | GroupRules/GroupAssignmentService | Phase 2 tests | Reject 1/3 Karyakars and invalid gender combination; allow valid exactly-2 Group |
| 9 Group Detail | Group detail UI + assignments | Phase 2/3 tests | Show exactly 2 Karyakars, 10 slots, Area/Society and progress |
| 10 Family Assignment | fixed/remaining assignment service | Phase 2 tests | Activate only with exactly 10 Families: 5-6 fixed, 4-5 remaining |
| 11 Multiple Group Assignment | group_karyakars many-to-many | Phase 2 tests | Same Karyakar can appear in multiple active Groups |
| 12 Sampark Area & Society | imports, master data, assignment service | Phase 1/2 tests | Same-Center assignments work; cross-Center assignment rejected |
| 13 Target Management | Target model/service/UI | Phase 2/3 tests | Create scoped target and verify completed/remaining/% after visits |
| 14 Home Visit / My Target | mobile field UI + HomeVisitService | Phase 3 tests | Karyakar sees only assigned Groups/Families and can complete eligible Family |
| 15 Completion Checklist | My Target checklist | Phase 3 tests | Fixed/Remaining and Completed/Pending states update correctly |
| 16 Gender & Category Filters | registration/monitoring/report/Bal filters | Phase 1/4/5 tests | Validate Male/Female and all eight categories where relevant |
| 17 Reports & Analysis | MonitoringAnalyticsService/ReportService | Phase 4 tests | Validate ten SRS reports, role scope, filters and CSV export |
| 18 Reminders & Alerts | InactivityService + scheduler command | Phase 3 tests | 4-day Reminder, 7-day Alert, retained history and resolution after activity |
| 19 Activity/Audit Logs | AuditTrail + audit UI | Phase 2/4/6 tests | Verify actor/role/time/Center/action/old/new/reason for administrative changes |
| 20 Karyalay Shared Content | SharedContent module | Phase6SupportModulesTest | Create/view Quote, Aagna, Sankalp, Vachan, Ashirwad, video and file/link content |
| 21 Bal Dashboard | Bal dashboard/analysis | Phase 5 tests | Verify assigned Bal scope and Center/Zone/Karyalay aggregates |
| 22 Bal Entry/Completion | BalPravrutiService/completion controller | Phase 5 tests | Exactly 3 children + 1 Sanchalak; assigned Sanchalak submits completion |
| 23 User & Role Management | users UI + editable permission matrix | PermissionMatrixTest | Create user, assign role/scope, update role permissions, verify effective access |
| 24 BN Karyalay Admin | role seed + female analysis locks | Phase 4/5 + permission tests | Verify permitted admin access and female-specific analysis; review DP-001 with SMVS |
| 25 Settings / Master Data | SettingsController/UI | permission tests + syntax checks | Create Area/Society; verify Categories; edit role permissions |
| Duplicate Family prevention | partial unique DB constraint + transaction guard | Phase 2 + ProductionHardeningTest | Concurrent/duplicate active Family assignment is rejected |
| Authorized Family transfer | transfer transaction + audit | Phase 2 tests | Old assignment closes, new becomes active, reason/audit retained |
| Click-to-call | `tel:` links | Phase 3 tests/manual | Phone link appears only when mobile exists; no WhatsApp automation |
| Completion popup | CompletionReportService/UI modal | Phase 3 tests | Verify Zone/Center/completed/pending/ratio values after completion |
| Motivation badges | BadgeService | Phase 3 tests | Verify 3/6/9/12/15 milestones |
| Bal contribution to overall | monitoring analytics | Phase 5 tests | Main Completed + Bal Completed = Overall Completed; no fabricated combined % |
| Announcements | AnnouncementController/UI | Phase6SupportModulesTest | Global + Center visibility; draft/publish/expiry behavior |
| Family Time | schedule/completion module | Phase6SupportModulesTest | Create schedule; user completion is idempotent per date |
| Content sharing | Web Share/clipboard UI | frontend static check/manual | Supported browser shares/copies selected content link/text |
| Testimonials/Feedback | Testimonial workflow | Phase6SupportModulesTest | Submit, publish/reject and scope visibility |
| Guruji video/highlights | Shared Content video type | Phase 6 tests/manual | Publish link/file video and view from permitted user |
| Motivation slider | motivation content carousel | frontend static check/manual | Multiple motivation entries render horizontally and remain responsive |
| Zone/Center leaderboards | monitoring analytics | Phase 4 tests | Rankings reflect scoped completion data |
| Inventory/Stock | InventoryService/UI | Phase6SupportModulesTest | Inward increases; outward decreases; stock cannot become negative; audit recorded |
| Sticky Notes | owner-scoped module | Phase6SupportModulesTest | User cannot view/update another user's note |
| Contact/Support | SupportRequest workflow | Phase6SupportModulesTest | User submits; permitted manager sees scoped ticket and resolves it |
| Correction/Change Request | CorrectionRequest workflow | RegressionAuditTest | User submits scoped change request; authorized reviewer records decision/note; cross-scope records stay hidden |
| Sankalp Family edit/correction | audited Family/Member update workflow | RegressionAuditTest | Update permitted fields with mandatory reason; block unsafe Karyakar-linked Age/Gender direct changes and active-assigned Family deactivation |
| Transfer target lifecycle | GroupAssignmentService | RegressionAuditTest | Transfer from an active Group returns source to draft and closes/audits any open source target |
| Multi-Center isolation | OrganizationalScope + guards | Phase 0/2/4/5/6 + PermissionMatrixTest | Repeat core workflows using two Centers and confirm no cross-Center access |
| Responsive/mobile | responsive layout + mobile My Target | frontend/manual | Test phone/tablet/desktop widths; field completion remains usable |
| Security headers | SecurityHeaders middleware + Nginx | ProductionHardeningTest | Verify CSP, nosniff, frame/referrer/permissions and HTTPS HSTS in production |
| Readiness | `/health/ready` | ProductionHardeningTest | DB/cache healthy returns 200; dependency failure returns 503 |
| Backup/restore | scripts/backup.sh + scripts/restore.sh | shell/static review | Create backup, verify SHA256, restore in staging, recheck readiness and record counts |
| CI/build | GitHub Actions | `.github/workflows/ci.yml` | CI must pass Composer validation/install, TypeScript, Vite, Laravel tests and image builds |
| Coolify deployment | Docker Compose + runbook | compose structural validation | Persistent volumes survive redeploy; worker/scheduler healthy; HTTPS domain works |

## Release gate

Do not treat a new production environment as accepted until:

1. GitHub CI is green for the exact commit being deployed.
2. `/health/ready` is HTTP 200 after deployment.
3. The two-Center/two-Zone scope smoke test passes.
4. The Group composition and duplicate Family rules pass with pilot data.
5. Home Visit, reports, reminders, Bal completion and support modules pass their UAT rows above.
6. A staging backup/restore rehearsal succeeds.
7. `PILOT_DATA=false` is confirmed before importing real organizational data.
