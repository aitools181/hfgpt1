# Business Rules Register

This register is the development source of truth for rules extracted from the SRS. Status indicates implementation progress, not requirement importance.

| ID | Rule | Planned enforcement | Status |
|---|---|---|---|
| BR-001 | One application serves multiple Centers. | organization tables + scoped authorization | Implemented |
| BR-002 | Center-specific records must remain associated with the correct Center. | FK + server-derived Center scope | Implemented |
| BR-003 | Center Admin cannot access another Center. | policy/middleware/query scope | Implemented |
| BR-004 | Each Sankalp Karyakar Group contains exactly 2 Karyakars. | Group service + activation guard | Implemented P2 |
| BR-005 | Valid Group combinations: Couple/Husband+Wife, 2 Male, or 2 Female. | server-side gender/type validator | Implemented P2 |
| BR-006 | Group name begins with Center Code (e.g. GND-001). | per-Center locked sequence service | Implemented P2 |
| BR-007 | Each Group contains exactly 10 Sankalp Families. | capacity guard + activation guard | Implemented P2 |
| BR-008 | 5-6 Families are Fixed/Locked. | assignment limits + activation guard | Implemented P2 |
| BR-009 | Remaining 4-5 Families are selected/registered through permitted Karyakar flow. | linked-Karyakar selection + reported-family verification workflow | Implemented P2 |
| BR-010 | Karyakar cannot change Fixed/Locked assignments. | permission boundary + remaining-only endpoint | Implemented P2 |
| BR-011 | A Family cannot have two simultaneous active Group assignments. | partial unique index + transaction/row locks | Implemented P2 |
| BR-012 | Authorized Family transfer closes old assignment and creates new active assignment. | transfer transaction + audit reason | Implemented P2 |
| BR-013 | A Karyakar may belong to multiple active Groups. | group_karyakars many-to-many history table | Implemented P2 |
| BR-014 | Area/Society/Family/Group assignment changes must be auditable. | scoped assignment service + detailed AuditTrail entries | Implemented P2 |
| BR-015 | Target progress derives from completed assigned records. | computed service/query | Implemented P3 |
| BR-016 | Home Visit completion is field-user/mobile workflow. | scoped action endpoint | Implemented P3 |
| BR-017 | 4-day inactivity creates Reminder; 7-day inactivity creates Alert. | scheduler + idempotent alert history | Implemented P3 |
| BR-018 | Badge milestones: 3, 6, 9, 12, 15 completed families. | badge calculator | Implemented P3 |
| BR-019 | Completion popup shows own Zone, Center, completed, pending and ratio analysis. | completion response/UI | Implemented P3 |
| BR-020 | Karyakar category is read-only and calculated from Age + Gender. | domain calculator + DB value | Implemented P1 |
| BR-021 | Category ranges: >50, 26-50, 13-25, 0-12 with gender-specific categories. | tested calculator | Implemented P1 |
| BR-022 | Male/Female filter is available where relevant. | shared filter contract | Implemented P1/P4/P5 |
| BR-023 | Click-to-call uses available phone; no WhatsApp automation. | `tel:` action only | Implemented P3 |
| BR-024 | Bal Pravruti Group has 3 children + 1 Sanchalak. | separate module validator + linked approved Sanchalak Karyakar | Implemented P5 |
| BR-025 | Bal Pravruti completion contributes to overall Zone/Center/Karyalay analysis. | reporting aggregation | Implemented P5 |
| BR-026 | GPS, mandatory visit photos, WhatsApp automation, AI recommendations/predictive analytics, attendance, payment/donation, public self-registration and unrelated booking/event functions are out of scope. | scope-control / RTM | Enforced by planning |

## Phase 3 field-execution enforcement

- Home Visit completion is available only for an `active` Group Family assignment in an `active` Group.
- A Group Family assignment can have only one completion record; the database unique constraint prevents duplicate completion even if requests race.
- A normal field completion must be recorded by an approved Karyakar who is an active member of that Group.
- Only Super Admin may use the completion override path; an assigned Karyakar and an override reason are required and retained.
- Every completion stores Center, Group, Family, Karyakar, target (when applicable), Area/Society snapshot, completion time and recorder.
- Target completed quantity is recalculated from persisted Home Visits within its Group, date, Area/Society and optional Karyakar scope. Remaining and percentage are derived from that value.
- Motivation badges are persisted once at 3, 6, 9, 12 and 15 individually attributed completed Family visits.
- The same-portal completion report returns Zone, Center, Group, Karyakar, completed/pending values and target ratio analysis after a successful completion.
- The inactivity scheduler checks active Groups hourly. Four days without required activity creates a Reminder; seven days creates an Alert and escalates the open Reminder. Duplicate open Reminder/Alert rows are prevented.
- A new completion resolves open inactivity events for that Karyakar/Group. When the Group has no pending Family visits, all remaining open inactivity events for the Group are resolved.
- Click-to-call uses only `tel:` links for available Karyakar and Head-of-Family phone numbers; no WhatsApp automation is introduced.


## Monitoring & reporting rules (Phase 4)

1. Every dashboard, analysis query, report view and CSV export must start from the signed-in user's permitted organizational scope.
2. Center Admin / Computer Op. analytics cannot include another Center. Zonal Admin analytics cannot include Centers outside the assigned Zone.
3. A main-project Karyakar's analysis/report scope is locked to the Karyakar record linked through `karyakars.user_id` and its active Groups; request parameters cannot broaden that scope.
4. BN Karyalay analysis is forced to Female Karyakar scope. This filter is server-side and cannot be overridden by URL parameters.
5. Current campaign completion percentage = completed active Family assignments / active Family assignments. A completion is evidenced by the unique Home Visit attached to that Group Family assignment.
6. When a reporting date range is supplied, completion counts represent Home Visits inside that range while the active assignment denominator remains the selected operational scope.
7. Target quantity and Target completed quantity are displayed separately from Family-assignment completion because the SRS target-quantity ambiguity remains documented in DP-006.
8. CSV exports must reuse the same report service and role filters as the UI. There is no unscoped export endpoint.
9. Audit Log filtering may narrow a user's permitted log set but must never widen it. Field roles see their own relevant actions only.


## Bal Pravruti rules (Phase 5)

1. Every active Bal Pravruti Group is created with exactly three distinct active Family Members and one assigned Sanchalak.
2. The implementation treats age `0-12` Family Members as Bal/Balika child candidates, matching the SRS age/category table; this interpretation is recorded in DP-011.
3. The Sanchalak must be an Approved Sankalp Karyakar linked to a portal user with the `sanchalak` role and must belong to the same Center as the Bal Group.
4. Nirdeshak and Nirikshak scope is explicit per Bal Group through supervisor assignment records. Their Bal dashboard, analysis and relevant audit visibility cannot expand beyond assigned Groups.
5. A Sanchalak sees only Bal Groups assigned to their linked user and only the assigned Sanchalak can submit a Bal completion report for that Group.
6. A Bal completion report records Society, Families Visited, Families Completed, optional Mobile Number, optional known Family link/name, relevant Family details, completion date and submitter. `families_completed` cannot exceed `families_visited`.
7. Bal completion reporting is separate from main Sankalp Home Visit records; no fake main `home_visits` are generated from Bal activity.
8. Main Center/Zone/Karyalay analysis adds the Bal completed-family count as a separate `bal_completed` value and exposes `overall_completed = main_completed + bal_completed`. Main assignment completion percentage remains based only on main active Family assignments because the SRS does not define a Bal target denominator (DP-012).
9. BN Karyalay Bal analysis is server-side locked to Female Sanchalak scope, consistent with the female-specific analysis requirement.
10. Bal Group creation, child/supervisor assignments and completion reports are auditable; Bal roles can view audit entries for their assigned Bal Group scope.


## Support and production rules (Phases 6-7)

1. Published global support content is visible to permitted users; Center-scoped support records never broaden a user's organizational scope.
2. Shared Content administrative changes, announcement changes, Family Time schedule changes, inventory changes, support-resolution changes and permission/master-data changes are auditable.
3. Inventory outward transactions cannot reduce stock below zero; the item row is locked while computing stock before/after.
4. Sticky Notes are private to their owning user.
5. Family Time completion is idempotent for a user/schedule/date tuple.
6. Support requests are visible to their submitter; management views are constrained to the manager's permitted Centers unless the role is organization-wide.
7. The readiness endpoint is healthy only when both database and cache checks succeed.
8. Production runs behind trusted reverse-proxy headers and adds security response headers; HSTS is emitted only in production.
9. PostgreSQL, Redis and public uploads use persistent volumes; release updates must not delete them.
10. Pilot/UAT seeding is opt-in only through `PILOT_DATA=true` and must remain disabled for production data.
11. CSV/TSV imports are streamed; XLSX is supported but is comparatively memory intensive.
12. Production acceptance requires the exact deployed Git commit to pass GitHub CI and target-environment smoke/backup checks.
