# Phase 2 Handoff - Group & Assignment

Version: `0.3.0`  
Date: 2026-08-13  
Package type: cumulative (Phase 0 + Phase 1 + Phase 2)

## Scope delivered

Phase 2 implements the SRS Group & Assignment workflow while preserving the existing Phase 0/1 foundation.

### Group creation

- Group creation requires exactly 2 different **Approved** Sankalp Karyakars.
- Server validates only the permitted combinations:
  - `couple` = one Male + one Female
  - `two_male` = two Male Karyakars
  - `two_female` = two Female Karyakars
- Group code is generated from Center Code through a Center-scoped locked sequence, e.g. `GND-001`, `GND-002`.
- One Karyakar may be assigned to multiple Groups; Group membership remains exactly 2 per Group.
- Groups start as `draft` and become `active` only after passing the full Family-composition gate.

### Sankalp Family assignment

- Maximum 10 active Families per Group.
- Fixed/Locked assignment maximum is 6.
- Remaining assignment maximum is 5.
- Group activation requires exactly 10 total with exactly either:
  - 5 Fixed + 5 Remaining, or
  - 6 Fixed + 4 Remaining.
- Karyakar users cannot call the Fixed/Locked administrative assignment endpoint.
- The Family table displays current active Group assignment.
- The Karyakar table displays all active Group assignments.

### Duplicate prevention and transfers

- A partial unique database index allows only one `active` Group assignment for each Family.
- Assignment and transfer services also use transactions and row locks.
- Authorized transfer:
  1. locks the current assignment,
  2. closes it as `transferred`,
  3. stores `ended_at`, destination and reason,
  4. creates the new active assignment,
  5. records an explicit audit event with old/new Group and reason.
- If an active source Group loses a Family through transfer, it is returned to `draft` because it no longer satisfies the exact-10 activation rule.

### Remaining Family Karyakar flow

Two source-supported paths are provided:

1. **Select existing Family** - an assigned Karyakar may choose an eligible existing Family as `remaining`; the assignment is marked `assignment_source=karyakar`.
2. **Report new Family** - the Karyakar can report a new Head of Family/contact/address record. It is saved as `source=karyakar_reported`, `status=pending_verification`, and appears in the Group's Remaining Family Reports. Center-level authorized users can Accept + Assign or Reject it.

The SRS does not explicitly define acceptance semantics for a newly reported Family. This implementation decision is documented as `DP-004` in `DECISIONS_PENDING.md`.

### Area and Society assignment

- Dedicated Area/Society assignment page supports Group, Karyakar and Family records.
- Area must belong to the same Center.
- Society must belong to the selected Area and same Center.
- Every assignment/change requires a reason and creates a detailed audit entry.
- Manual Family registration was also tightened so Area/Society cannot cross Center boundaries.

### Target assignment

- Target records support Center, Group, optional individual Karyakar, Sampark Area, optional Society, start date, end date and target quantity.
- If an individual Karyakar is selected, they must be an active Karyakar of the selected Group.
- `completed_quantity`, remaining and completion percentage fields are present, but Home Visit-driven updates intentionally remain Phase 3.

### Karyakar portal identity linkage

User management can optionally link a portal user with role `karyakar` to one approved Sankalp Karyakar record in the same Center. This link is what enforces own-Group visibility and Remaining Family selection/reporting.

## New primary files

- `database/migrations/2026_08_13_020001_create_phase2_group_assignment_tables.php`
- `app/Models/SankalpGroup.php`
- `app/Models/GroupKaryakar.php`
- `app/Models/GroupFamilyAssignment.php`
- `app/Models/RemainingFamilyReport.php`
- `app/Models/Target.php`
- `app/Services/Assignments/GroupCodeGenerator.php`
- `app/Services/Assignments/GroupRules.php`
- `app/Services/Assignments/GroupAssignmentService.php`
- `app/Services/Assignments/AreaAssignmentService.php`
- `app/Services/Assignments/TargetService.php`
- `app/Http/Controllers/Assignments/GroupController.php`
- `app/Http/Controllers/Assignments/AreaAssignmentController.php`
- `app/Http/Controllers/Assignments/TargetController.php`
- `resources/js/pages/assignments/groups.tsx`
- `resources/js/pages/assignments/group-detail.tsx`
- `resources/js/pages/assignments/areas.tsx`
- `resources/js/pages/assignments/targets.tsx`
- `tests/Feature/Phase2GroupAssignmentTest.php`

## Validation performed in this environment

Passed:

- PHP syntax lint across all `app/`, `database/`, `routes/` and `tests/` PHP files.
- `composer.json` JSON parse.
- `package.json` JSON parse.
- `docker-compose.yml` YAML parse.

Not executable in this environment:

- Composer dependencies are not installed and the `composer` binary is unavailable, so Laravel/PHPUnit runtime tests could not be executed here.
- `npm install --no-audit --no-fund` was attempted once and timed out before creating `node_modules`, so the React/Vite production build and full TypeScript dependency-aware check could not be run here.
- Docker CLI is unavailable in this environment, so the final Compose stack could not be booted here.

The Phase 2 feature test suite is included and is expected to run in CI/your Docker build once dependencies are available.

## Deployment/migration note

This is a cumulative package. For an existing Phase 1 database, deploy the new code and run normal Laravel migrations; the Phase 2 migration adds Group/assignment/target tables and Area/Society foreign keys to Karyakars. Back up the production database before applying migrations.

## Next phase

Phase 3 - Field Execution:

- mobile-first My Target
- assigned Family checklist
- Family detail + click-to-call
- Home Visit completion transaction
- target completed/remaining/% calculation
- completion popup report
- 3/6/9/12/15 motivation badges
- 4-day Reminder / 7-day Alert with history
