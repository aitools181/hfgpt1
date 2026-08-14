# Phase 1 Handoff - Registration & Data

Version: 0.2.0

## Implemented

- Center-scoped SMVS Global Family/Member import using CSV, TSV or XLSX.
- Family ID as the imported primary family reference and Member ID as member reference.
- Center-scoped Sampark Area / Society import foundation.
- Unified Family model supporting `global` and `manual` sources.
- Manual Sankalp Family registration with generated `HF-{CENTER}-{NNNNNN}` reference.
- Family members with Male/Female, age, contact, relationship and head flag.
- Family register with Center/source/status/search filters and Male/Female counts.
- Family detail view.
- Manual Sankalp Karyakar registration.
- Family-ID/member based Karyakar nomination.
- Pending -> Approved/Rejected workflow for authorized roles.
- System-calculated, read-only 8-category mapping from Age + Gender.
- Karyakar search plus Center/Gender/Category/Status/Source filters.
- Center scoping on all Phase 1 write/read paths.
- Audit trail coverage through Auditable models.
- Phase 1 dashboard counts and navigation.

## Required import columns

### Family / Member

Required: `family_id`, `head_name`.

Optional: `head_mobile`, `address`, `city_village`, `member_id`, `member_name`, `gender`, `age`, `member_mobile`, `relationship`, `is_head`.

One family may appear on multiple rows, one per member. The importer upserts the Family by `(center_id, family_id)` and Member by `(family_id, member_id)`.

### Sampark Area / Society

Required: `area_name`.

Optional: `area_code`, `city_village`, `society_code`, `society_name`.

## Category business rule

| Age | Male | Female |
|---|---|---|
| > 50 | Vadil Yuvak Karyakar | Vadil Yuvti Karyakar |
| 26-50 | Yuvak Karyakar | Yuvti Karyakar |
| 13-25 | Kishor Karyakar | Kishori Karyakar |
| 0-12 | Bal Karyakar | Balika Karyakar |

Category is calculated server-side. Client display is informational only.

## Phase 2 boundary

Area/Society masters are available now, but assignment of Area/Society to Groups/Karyakars/Families as an operational workflow is Phase 2. Group creation, exactly-2 Karyakar validation, exactly-10 Family assignment, 5-6 Fixed/Locked, 4-5 Remaining, duplicate active Family prevention and transfer are also Phase 2.
