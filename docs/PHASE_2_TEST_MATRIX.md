# Phase 2 Acceptance Test Matrix

Primary automated suite: `tests/Feature/Phase2GroupAssignmentTest.php`.

| Requirement | Test coverage |
|---|---|
| Exactly 2 approved Karyakars | `test_group_requires_exactly_two_approved_karyakars_and_center_code_numbering` |
| Valid Couple / 2 Male / 2 Female combinations | `test_group_combination_is_validated_and_karyakar_can_belong_to_multiple_groups` |
| Center Code Group numbering | `test_group_requires_exactly_two_approved_karyakars_and_center_code_numbering` |
| Karyakar in multiple Groups | `test_group_combination_is_validated_and_karyakar_can_belong_to_multiple_groups` |
| Exactly 10 Families, 5-6 Fixed, 4-5 Remaining | `test_group_activation_enforces_exactly_ten_families_with_fixed_remaining_ratio` |
| Duplicate active Family prevention | `test_duplicate_active_family_assignment_is_prevented_and_transfer_closes_old_assignment` |
| Authorized transfer closes old / opens new + reason/audit | `test_duplicate_active_family_assignment_is_prevented_and_transfer_closes_old_assignment` |
| Karyakar Remaining Family selection | `test_linked_karyakar_can_select_existing_remaining_family_and_report_new_family` |
| Karyakar new Family report -> Center Admin verification | `test_linked_karyakar_can_select_existing_remaining_family_and_report_new_family` |
| Karyakar cannot assign Fixed/Locked Family | `test_karyakar_cannot_assign_fixed_family` |
| Group Area/Society assignment + audit | `test_area_society_assignment_and_target_creation_are_center_scoped` |
| Target assignment fields and Group/Karyakar consistency | `test_area_society_assignment_and_target_creation_are_center_scoped` |

Runtime execution of the Laravel test suite requires Composer dependencies. The current build environment does not contain Composer/vendor packages, so the suite is delivered but was not executed here. PHP syntax lint was executed across the test and application sources.
