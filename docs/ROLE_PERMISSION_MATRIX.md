# Role & Permission Matrix

## Roles seeded in v1.0.4

| Role | Default organizational scope | Module |
|---|---|---|
| Karyalay Admin / Super Admin | Organization | Main |
| BN Karyalay Admin | Organization / female Karyalay | Main |
| Zonal Admin | Assigned Zone | Main |
| Center Admin | Assigned Center | Main |
| Computer Op. | Assigned Center | Main |
| Karyakar | Assigned work / Center | Main |
| Nirdeshak | Assigned Bal Pravruti scope | Bal Pravruti |
| Nirikshak | Assigned Bal Pravruti scope | Bal Pravruti |
| Sanchalak | Assigned Bal Pravruti scope | Bal Pravruti |

## Permission architecture

The seed contains the complete v1.0.4 permission set. Permissions remain editable through Settings for authorized role managers.

Permission slugs include:

- `view_all_centers`
- `view_zone`
- `view_center`
- `manage_zones`
- `manage_centers`
- `manage_users`
- `reset_user_passwords`
- `manage_roles`
- `view_audit_logs`
- `manage_master_data`
- `register_family`
- `register_karyakar`
- `approve_karyakar`
- `create_group`
- `manage_fixed_families`
- `assign_transfer_families`
- `assign_area_society`
- `assign_target`
- `view_own_assignments`
- `mark_home_visit`
- `view_reports_analysis`
- `gender_category_filters`
- `access_bal_pravruti`
- `manage_bal_groups`
- `submit_bal_completion`
- `view_bal_analysis`
- `manage_shared_content`
- `view_announcements` / `manage_announcements`
- `view_family_time` / `record_family_time` / `manage_family_time`
- `view_shared_content` / `manage_shared_content`
- `view_testimonials` / `submit_testimonial` / `manage_testimonials`
- `view_inventory` / `manage_inventory`
- `use_sticky_notes`
- `contact_support` / `manage_support`

All authorization remains server-side even when UI links are hidden.

## Password reset delegation - v1.0.4

- Only Super Admin may grant or remove the `reset_user_passwords` permission in the role matrix. A role with delegated `manage_roles` cannot self-grant or remove this security-sensitive permission.

- `reset_user_passwords` is enabled for **Karyalay Admin / Super Admin** by default.
- Other roles do **not** receive it automatically. Super Admin can grant/revoke it from **Settings -> Roles & Permission Matrix**.
- Super Admin may reset any portal user's password, including their own.
- A delegated role may reset only users whose complete role assignment is inside that administrator's organizational scope and whose authority level is equal or lower. This prevents a Center role from resetting a Zonal/Super Admin or a user who also has an out-of-scope assignment.
- Resetting a password rotates the remember token, revokes existing authenticated sessions using `session_version`, records `password_changed_at`, and writes a redacted audit event. The new password is never stored in audit fields.
- `manage_users` does not imply password reset. Password changes are prohibited on the generic user-update endpoint; the dedicated reset permission is required.


## Phase 2 operational behavior

- Users with `create_group` can create Center-scoped Groups from exactly two approved Karyakars.
- Users with `assign_transfer_families` can assign Fixed/Remaining Families, review Karyakar-reported new Families, and perform audited transfers.
- Users with `assign_area_society` can change Group/Karyakar/Family Area and Society with a required reason.
- Users with `assign_target` can create Center/Group/Karyakar/Area target assignments.
- Karyakar users use `view_own_assignments`; a portal user must be linked to an approved `karyakars.user_id` record. They see only Groups in which that Karyakar is an active member and cannot use the Fixed/Locked assignment endpoint.
- Karyakar selection of existing Remaining Families is further constrained to the Group's assigned Society, or assigned Sampark Area when no Society is selected. If the Group has neither, the Karyakar may report a new Family for Center Admin verification instead of browsing unrelated Center data.

## Phase 3 operational behavior

- `view_own_assignments` exposes scoped assignment and Reminders/Alerts routes.
- `mark_home_visit` exposes the Karyakar My Target / Home Visit routes; the UI additionally limits the My Target navigation item to Karyakar and Super Admin roles. Controllers still apply Karyakar linkage/scope server-side.
- `mark_home_visit` permits the Home Visit endpoint. A normal completion additionally requires the logged-in portal user to be linked to an approved Karyakar actively assigned to that Group.
- Super Admin may use the documented Home Visit override path only with an assigned Group Karyakar and a required reason.
- Center/Zone/Karyalay administrative users can view Reminder/Alert history only within their permitted organizational scope.
- A Karyakar user sees only their own Reminder/Alert history.
- Click-to-call is rendered only for records already visible under the user's scoped page access; phone links do not bypass record authorization.


## Phase 4 report/analysis scope enforcement

- Super Admin / Karyalay Admin: organization-wide monitoring and reports.
- BN Karyalay Admin: organization-wide administrative scope under DP-001, with server-locked Female monitoring/filter/analysis.
- Zonal Admin: assigned Zone only.
- Center Admin: assigned Center only.
- Computer Op.: permitted Center operational reporting only.
- Karyakar: linked Karyakar's own active Groups/assignments only.
- Nirdeshak / Nirikshak / Sanchalak: Bal Pravruti reporting scope is implemented in Phase 5; Phase 4 does not fabricate main-project access for these roles.


## Phase 5 Bal Pravruti scope enforcement

- Super Admin / Karyalay Admin: organization-wide Bal Pravruti access.
- BN Karyalay Admin: organization administration under DP-001; Bal analysis is server-locked to Female Sanchalak scope.
- Zonal Admin: can manage/view Bal Groups inside the assigned Zone.
- Center Admin: can manage/view Bal Groups inside the assigned Center under the explicit Bal hierarchy interpretation in DP-010.
- Nirdeshak: `access_bal_pravruti` + `view_bal_analysis`; only Groups explicitly assigned as Nirdeshak are visible.
- Nirikshak: `access_bal_pravruti` + `view_bal_analysis`; only Groups explicitly assigned as Nirikshak are visible.
- Sanchalak: `access_bal_pravruti` + `submit_bal_completion` + `view_bal_analysis`; only Groups assigned through `bal_groups.sanchalak_user_id` are visible.
- A Sanchalak portal user must be linked to an Approved Sankalp Karyakar in the same Center before the user can be selected for a Bal Group.
- Only the assigned Sanchalak may submit a Bal completion report; Super/Center administrators are not treated as substitute completion submitters.
- Nirdeshak/Nirikshak/Sanchalak do not receive the main-project `view_reports_analysis` permission. Their `/` dashboard redirects to the separate Bal dashboard.
- Bal role audit visibility is limited to assigned Bal Group records plus the user's own actions.


## Phase 6 support-module behavior

- All seeded roles can view Announcements, Family Time and Shared Content, submit Testimonials/Feedback, use own Sticky Notes and contact Support.
- Super Admin and BN Karyalay Admin receive the complete permission set, including management of Announcements, Shared Content, Testimonials, inventory and Support.
- Zonal/Center Admin receive scoped Family Time, inventory and Support management; their queries remain limited to their Zone/Center.
- Computer Op. receives permitted Center inventory operations but not organization-wide content/announcement management.
- Karyakar/Nirdeshak/Nirikshak/Sanchalak receive only support view/submit/own-note capabilities unless permissions are explicitly changed by an authorized role manager.
- Shared content and announcement publication management is intentionally not granted to Center roles by default because the SRS assigns Karyalay shared content management to Karyalay/Super Admin.

## Runtime authorization rule

Navigation visibility is only a convenience. Every write/read boundary is enforced server-side by permission middleware plus organizational-scope checks. Permission edits are audited.
