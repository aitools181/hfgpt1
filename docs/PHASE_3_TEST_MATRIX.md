# Phase 3 Acceptance Test Matrix

| ID | Requirement / scenario | Enforcement / expected result | Automated coverage |
|---|---|---|---|
| P3-001 | Karyakar sees only active Groups assigned to their linked Karyakar record | My Target query is server-scoped by `karyakars.user_id` and active Group membership | Phase3FieldExecutionTest + controller scope |
| P3-002 | Assigned Family checklist shows Fixed/Remaining and Completed/Pending | My Target and Group Detail render assignment type + Home Visit state | UI/static coverage |
| P3-003 | Click-to-call Family Head | `tel:` rendered only when `head_mobile` exists | UI/static coverage |
| P3-004 | Click-to-call Karyakar | `tel:` rendered only when Karyakar mobile exists | UI/static coverage |
| P3-005 | Karyakar marks Home Visit complete | Active membership + active Group/assignment required; transactional completion created | `test_assigned_karyakar_can_complete_home_visit_and_target_progress_updates_once` |
| P3-006 | Duplicate completion prevented | unique `group_family_assignment_id` + service guard | same test |
| P3-007 | Unassigned Karyakar cannot complete another Group's Family | server validation rejects with authorization error | `test_unassigned_karyakar_cannot_complete_another_groups_family` |
| P3-008 | Target completed/remaining/% updates from visits | relevant Group and individual targets are recalculated after visit | first test |
| P3-009 | Completion popup shows own report | session payload contains Zone/Center/Group/completed/pending/ratio analysis | first test + UI modal |
| P3-010 | Badge milestones 3/6/9/12/15 | idempotent persisted awards based on individually attributed Home Visits | `test_badges_are_awarded_at_3_6_9_12_and_15_completed_families` |
| P3-011 | 4-day inactivity Reminder | scheduler service opens one Reminder after threshold | `test_four_day_reminder_and_seven_day_alert_are_created_without_duplicates` |
| P3-012 | 7-day inactivity Alert | one Alert opens and Reminder becomes escalated | same test |
| P3-013 | Reminder/Alert history retained and resolved after activity | new Home Visit resolves open/escalated events | `test_new_home_visit_resolves_open_reminder_and_alert_history` |
| P3-014 | Super Admin completion override | assigned Karyakar + required reason; record marked override | `test_super_admin_override_requires_assigned_karyakar_and_reason` |
| P3-015 | Mobile-first field UI | responsive cards/checklist/modal, no desktop-only table dependency for field workflow | static UI review |
| P3-016 | No GPS/mandatory photo/WhatsApp automation | no such fields/actions added to completion flow | code/RTM scope check |

Full runtime execution of PHPUnit requires Composer dependencies. The release package contains the tests even when the artifact-building environment cannot install `vendor/`.
