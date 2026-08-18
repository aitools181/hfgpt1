# Requirement Traceability Matrix

Baseline: uploaded **SMVS Happy Family Project SRS Version 3.0** plus **Full Portal Wireframe Version 2.0**.

Status values:

- `Implemented` - delivered in the cumulative v1.0.11 login/session-root-fix hardened/mobile-responsive package.
- `Decision` - source contains ambiguity recorded in `DECISIONS_PENDING.md`.

## Core and integrated SRS requirements

| ID | Requirement | Source area | Delivery | Status |
|---|---|---|---|---|
| SRS-SCOPE-01 | One centralized role-based multi-Center portal | Sec. 1-3 | Architecture, scopes | Implemented |
| SRS-SCALE-01 | Design for ~10,000 Karyakars and ~100,000 families | Sec. 2, 23 | PostgreSQL/Redis/queue architecture, indexed relational model, streaming CSV/TSV import on a dedicated background queue, CI and production runbook | Implemented P7 design; target-infrastructure load validation remains an operational sign-off step |
| SRS-ROLE-01 | Karyalay Admin / Super Admin | Sec. 4 | RBAC | Implemented |
| SRS-ROLE-02 | Zonal Admin | Sec. 4 | RBAC + Zone scope | Implemented |
| SRS-ROLE-03 | Center Admin | Sec. 4 | RBAC + Center scope | Implemented |
| SRS-ROLE-04 | Computer Op. | Sec. 4 | RBAC + Center scope | Implemented |
| SRS-ROLE-05 | Karyakar | Sec. 4 | RBAC + linked Karyakar work scope | Implemented P3/P4 |
| SRS-ROLE-06 | Nirdeshak | Sec. 4 / Bal | RBAC + assigned Bal Group supervision scope | Implemented P5 |
| SRS-ROLE-07 | Nirikshak | Sec. 4 / Bal | RBAC + assigned Bal Group inspection scope | Implemented P5 |
| SRS-ROLE-08 | Sanchalak | Sec. 4 / Bal | RBAC + linked approved Karyakar + assigned Bal Groups | Implemented P5; context DP-002 |
| SRS-ROLE-09 | BN Karyalay Admin | Sec. 4 / 29C/29G | Configurable RBAC + female-locked analysis | Implemented; interpretation recorded in DP-001 |
| SRS-ROLE-10 | Viewer role removed | Integrated change K | No Viewer role seeded | Implemented |
| SRS-CENTER-01 | Center ID/Name/Code/location/address/contact/status | Sec. 5 | `centers` table/UI | Implemented |
| SRS-CENTER-02 | Center records isolated to correct Center | Sec. 5 / business rules | Scope middleware/query services + feature tests | Implemented P0-P7 |
| SRS-IMPORT-01 | SMVS Global Center-specific Family/Member import | Integrated A / page module 9 | Import pipeline | Implemented P1 |
| SRS-IMPORT-02 | Family ID primary; Member ID secondary | Integrated A | Family identity model | Implemented P1 |
| SRS-IMPORT-03 | Sampark Area/Society import | Integrated C | Import pipeline | Import implemented P1; assignment P2 |
| SRS-FAM-01 | Manual Sankalp Family registration | Integrated E / Sec. 6 | Form/service | Implemented P1 |
| SRS-FAM-02 | Manual Family unique reference and source marker | Integrated E | DB constraint/service | Implemented P1 |
| SRS-FAM-03 | Head of Family/member details/gender counts | Page-wise module 8 | Family/member model | Implemented P1 |
| SRS-FAM-04 | Authorized Register/Edit Sankalp Family capability | Sec. 16 permission matrix / Sec. 17 forms | Center-scoped audited Family and Family Member edit workflow with mandatory change reason | Implemented v1.0.1 audit |
| SRS-KAR-01 | Manual Sankalp Karyakar registration | Integrated D | Form/service | Implemented P1 |
| SRS-KAR-02 | Karyakar Pending -> Approved/Rejected | Sec. 7 | Approval workflow | Implemented P1 |
| SRS-KAR-03 | Only approved Karyakars available for Group assignment | Sec. 7 | Domain query/guard | Implemented P2 |
| SRS-KAR-04 | Family-ID based nomination and member selection | 29F | Nomination flow | Implemented P1 |
| SRS-CAT-01 | Gender Male/Female capture | Integrated M / 29B | Form schema | Implemented P1 |
| SRS-CAT-02 | Age + Gender auto-fills read-only Category | 29B | Category calculator | Implemented P1 |
| SRS-CAT-03 | 8 categories by age/gender | 29B | Enum/calculator/tests | Implemented P1 |
| SRS-GROUP-01 | Group contains exactly 2 Sankalp Karyakars | Sec. 8 / 28 | Group service + activation guard | Implemented P2 |
| SRS-GROUP-02 | Valid combos: Couple/Husband+Wife, 2 Male, 2 Female | Sec. 8 / 28 | Server validator | Implemented P2; spouse evidence DP-005 |
| SRS-GROUP-03 | Center Code prefix in Group name | Integrated B | Atomic Center sequence | Implemented P2 |
| SRS-GROUP-04 | One Karyakar may be in multiple active Groups | 28.3 / 29E | Many-to-many assignment model | Implemented P2 |
| SRS-ASSIGN-01 | Exactly 10 Sankalp Families per Group | Sec. 8 / 28.2 | Capacity + activation guard | Implemented P2 |
| SRS-ASSIGN-02 | 5-6 Fixed/Locked Families | Sec. 9 | Assignment state + limits | Implemented P2 |
| SRS-ASSIGN-03 | 4-5 Remaining Families | Sec. 10 | Karyakar select/report workflow | Implemented P2; verification detail DP-004 |
| SRS-ASSIGN-04 | Karyakar cannot alter Fixed/Locked Families | Sec. 9 | Permission/domain guard | Implemented P2 |
| SRS-ASSIGN-05 | Duplicate active Family assignment prevented | Integrated F / 28.4 | Partial unique index + transaction locks | Implemented P2 |
| SRS-ASSIGN-06 | Authorized transfer closes old and opens new assignment | Integrated F / 28.5 | Transfer transaction + reason/audit | Implemented P2 |
| SRS-AREA-01 | Assign/change Sampark Area and Society | Sec. 11 / page module 16 | Scoped assignment service/UI | Implemented P2 |
| SRS-TARGET-01 | Targets by Center/Group/Karyakar/Area/dates/quantity | Sec. 12 | Target model/service/UI | Implemented P2 |
| SRS-TARGET-02 | Completed/Remaining/% computed | Sec. 12 | Home Visit-derived target progress service | Implemented P3 |
| SRS-VISIT-01 | Mobile-friendly My Target / Home Visit | Sec. 13 | Scoped responsive field UI | Implemented P3 |
| SRS-VISIT-02 | Assigned family checklist | Sec. 14 | Fixed/Remaining + Completed/Pending checklist | Implemented P3 |
| SRS-VISIT-03 | Mark Home Visit completed | Sec. 13-14 | Transactional unique completion service | Implemented P3 |
| SRS-CALL-01 | Click-to-call Karyakar/Family head | Integrated G | `tel:` UI on scoped records | Implemented P3 |
| SRS-BADGE-01 | Badges at 3/6/9/12/15 completions | Integrated H | persisted idempotent badge history + UI | Implemented P3 |
| SRS-POPUP-01 | Completion popup with Zone/Center/completed/pending/ratio | Integrated I | same-portal completion report modal | Implemented P3 |
| SRS-REM-01 | 4-day inactivity Reminder | 29D-A | hourly scheduler + idempotent history | Implemented P3; delivery channel DP-007 |
| SRS-REM-02 | 7-day inactivity Alert | 29D-A | hourly scheduler + escalation/history | Implemented P3; delivery channel DP-007 |
| SRS-DASH-01 | Super Admin organization dashboard | Sec. 15.1 | organization summary + Zone/Center drill-down + quick actions | Implemented P4 |
| SRS-DASH-02 | Center Admin Center-only dashboard | Sec. 15.2 | Center-scoped metrics + quick actions + analysis | Implemented P4 |
| SRS-DASH-03 | Sanchalak operational dashboard | Sec. 15.3 | assigned Bal Groups + completion history/dashboard | Implemented P5 (see DP-002) |
| SRS-DASH-04 | Karyakar simple mobile-first dashboard | Sec. 15.4 | field summary + My Target + own-assignment analysis scope | Implemented P3/P4 |
| SRS-FILTER-01 | Male/Female filter across relevant views | Integrated M | Registration, monitoring, reports and Bal analysis | Implemented P1/P4/P5 |
| SRS-FILTER-02 | Category filter across relevant views | 29B | Registration, monitoring, reports and Bal analysis | Implemented P1/P4/P5 |
| SRS-REPORT-01 | Center-wise Family Registration report | Sec. 22 | scoped report + CSV | Implemented P4 |
| SRS-REPORT-02 | Center-wise Karyakar report | Sec. 22 | scoped report + CSV | Implemented P4 |
| SRS-REPORT-03 | Group-wise Karyakar report | Sec. 22 | scoped report + CSV | Implemented P4 |
| SRS-REPORT-04 | Area-wise Assignment report | Sec. 22 | scoped report + CSV | Implemented P4 |
| SRS-REPORT-05 | Target Assignment report | Sec. 22 | scoped report + CSV | Implemented P4 |
| SRS-REPORT-06 | Target Completion report | Sec. 22 | progress report + CSV | Implemented P4 |
| SRS-REPORT-07 | Pending Sankalp Family report | Sec. 22 | active assignment without Home Visit + CSV | Implemented P4 |
| SRS-REPORT-08 | Home Visit Completion report | Sec. 22 | scoped completion report + CSV | Implemented P4 |
| SRS-REPORT-09 | Center Performance Summary | Sec. 22 | Center completion summary + CSV | Implemented P4 |
| SRS-REPORT-10 | Organization-wide Summary | Sec. 22 | permitted-scope summary + CSV | Implemented P4 |
| SRS-AUDIT-01 | Log user/role/date/time/Center/module/action/reference | 29D | `audit_logs` | Implemented |
| SRS-AUDIT-02 | Log old/new values and reason/change note | 29D | AuditTrail service | Implemented |
| SRS-AUDIT-03 | Assignment/transfer/area/society changes auditable | Integrated N / 28.5 | Assignment services + audit reason | Implemented P2 |
| SRS-FORM-01 | Correction/Change Request Form | Sec. 17 Required Forms | Scoped submission/review workflow with status and review note | Implemented v1.0.1 audit |
| SRS-CONTENT-01 | Quotes, Aagna, Sankalpo, Vachano, video links, Ashirwad | Integrated J / page 24 | Scoped Shared Content library | Implemented P6 |
| SRS-BAL-01 | Separate Bal Pravruti module | Integrated L | routes/controllers/services/models + separate navigation | Implemented P5 |
| SRS-BAL-02 | Bal Group = 3 children + 1 Sanchalak | Integrated L | server validator + exact child positions + linked Sanchalak | Implemented P5; child-age interpretation DP-011 |
| SRS-BAL-03 | Bal roles Nirdeshak/Nirikshak/Sanchalak | Integrated L1 | assigned Group scope + Bal-only dashboard/analysis | Implemented P5; Center Admin conflict DP-010 |
| SRS-BAL-04 | Bal completion report fields | Integrated L2 | Society, visited/completed, optional mobile, Family link/name/details, date | Implemented P5 |
| SRS-BAL-05 | Separate Bal Dashboard and Analysis | Integrated L3 | role-scoped Bal dashboard, center/zone/group analysis, trend | Implemented P5 |
| SRS-BAL-06 | Bal completion contributes to overall analysis | Integrated L3 | Bal + main completion counts at Center/Zone/Karyalay scope | Implemented P5; no invented Bal denominator DP-012 |
| SRS-NFR-01 | Web-based | Sec. 23 | Web application | Implemented |
| SRS-NFR-02 | Responsive/mobile-friendly | Sec. 23 / 29H | responsive shell + mobile My Target | Implemented P3 baseline |
| SRS-NFR-03 | Simple for field users | Sec. 23 | mobile card/checklist flow | Implemented P3 baseline |
| SRS-NFR-04 | Secure and role-based | Sec. 23 | RBAC/session auth | Implemented |
| SRS-NFR-05 | Multi-Center data isolation | Sec. 23 | Scoped queries/guards + permission/isolation tests | Implemented P7 |
| SRS-NFR-06 | Reliable completion record preservation | Sec. 23 | transactions, unique constraints, PostgreSQL persistence, backup/restore scripts | Implemented P3/P7 |
| SRS-NFR-07 | Auditable admin actions | Sec. 23 | Audit framework | Implemented |
| SRS-OOS-01 | No GPS tracking | Sec. 25 | Scope control | Confirmed out-of-scope |
| SRS-OOS-02 | No mandatory visit photos | Sec. 25 | Scope control | Confirmed out-of-scope |
| SRS-OOS-03 | No WhatsApp automation | Sec. 25 / Integrated G | Scope control | Confirmed out-of-scope |
| SRS-OOS-04 | No AI recommendations/predictive analytics | Sec. 25 | Scope control | Confirmed out-of-scope |
| SRS-OOS-05 | No attendance/payment/donation/public self-registration | Sec. 25 | Scope control | Confirmed out-of-scope |

## Page-wise SRS structure (25 modules)

| Page module | Requirement | Phase | Status |
|---|---|---|---|
| 1 Login | Role-based authentication and scope validation | P0 | Implemented |
| 2 Dashboard Overview | Role-wise summary and quick actions | P4 | Implemented P4 |
| 3 Sankalp Karyakar | list/search/gender/category/groups/status/profile | P1/P2 | Implemented P1/P2 |
| 4 Sankalp Family | Family ID/head/members/gender counts/area/society/group | P1/P2 | Implemented P1/P2 |
| 5 SMVS Global Import | Center-specific import | P1 | Implemented |
| 6 Karyakar Registration/Nomination | Family member nomination | P1 | Implemented |
| 7 Karyakar Approval | Pending/Approved/Rejected | P1 | Implemented |
| 8 Group Management | exactly 2 Karyakars + combo validation | P2 | Implemented |
| 9 Group Detail | 2 Karyakars + 10 Families + progress | P2/P3 | Implemented P2/P3 |
| 10 Family Assignment | 5-6 fixed + 4-5 remaining | P2 | Implemented |
| 11 Multiple Group Assignment | Karyakar in multiple groups | P2 | Implemented |
| 12 Sampark Area & Society | assign/change | P2 | Implemented |
| 13 Target Management | assign/monitor targets | P2/P3 | Implemented P2/P3 |
| 14 Home Visit/My Target | field workflow | P3 | Implemented |
| 15 Completion Checklist | fixed/selected/completed/pending | P3 | Implemented |
| 16 Gender & Category Filters | shared filters | P1/P4/P5 | Implemented |
| 17 Reports & Analysis | scoped analytics | P4 | Implemented P4 |
| 18 Reminders & Alerts | 4/7 day logic | P3 | Implemented |
| 19 Activity/Audit Logs | full trace | P0/P4 | Implemented with filters/detail P4 |
| 20 Karyalay Shared Content | shared content | P6 | Implemented |
| 21 Bal Pravruti Dashboard | separate dashboard | P5 | Implemented P5 |
| 22 Bal Pravruti Entry/Completion | operational entry | P5 | Implemented P5 |
| 23 User & Role Management | users/roles/scope/permissions | P0/P7 | Implemented, including editable role-permission matrix |
| 24 BN Karyalay Admin | female-specific admin | P0/P4 | Implemented female-locked analysis; admin-scope decision DP-001 |
| 25 Settings/Master Data | Zone/Center/Area/Society/Category/role/permission | P0/P7 | Implemented |

## Wireframe-specific/support modules

The wireframe contains operational/support screens beyond the core Section 29 page list. They remain explicitly tracked so visual requirements are not lost.

| ID | Wireframe item | Phase | Status |
|---|---|---|---|
| WF-01 | Announcements | P6 | Implemented |
| WF-02 | Family Time completion | P6 | Implemented |
| WF-03 | Family Time schedule/calendar | P6 | Implemented |
| WF-04 | Supportive Content library (PDF/video/audio/image/links) | P6 | Implemented |
| WF-05 | Content sharing actions | P6 | Implemented |
| WF-06 | Testimonials/Feedback | P6 | Implemented |
| WF-07 | Guruji short video / video highlights | P6 | Implemented through Shared Content video type |
| WF-08 | Project motivation slider | P6 | Implemented through Motivation content carousel |
| WF-09 | Zone-wise leaderboard | P4 | Implemented |
| WF-10 | Center-wise leaderboard | P4 | Implemented |
| WF-11 | Zone Target vs Completed charts | P4 | Implemented P4 |
| WF-12 | Inventory/Stock Register | P6 | Implemented |
| WF-13 | Stock inward/outward actions | P6 | Implemented with transactional stock guard |
| WF-14 | Sticky Notes (Karyakar) | P6 | Implemented owner-scoped |
| WF-15 | Contact Us / Support | P6 | Implemented request/status workflow |
| WF-16 | Mobile responsive views | P0-P6 | Implemented |
| WF-17 | Happy Family theme / purple navigation visual language | P0 onward | Implemented foundation |

## Acceptance coverage policy

The cumulative v1.0.11 source package has every in-scope functional row above implemented or explicitly governed by a documented decision. Automated tests and manual production checks are mapped in `FINAL_ACCEPTANCE_MATRIX.md`. Target-infrastructure runtime/load validation is intentionally a deployment sign-off step and is not represented as having run inside the offline build environment.
