<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = [
                ['view_all_centers', 'View all Centers', 'organization'],
                ['view_zone', 'View assigned Zone', 'organization'],
                ['view_center', 'View own Center', 'organization'],
                ['manage_zones', 'Manage Zones', 'master'],
                ['manage_centers', 'Manage Centers', 'master'],
                ['manage_users', 'Manage Users', 'security'],
                ['manage_roles', 'Manage Roles and Permissions', 'security'],
                ['view_audit_logs', 'View Activity / Audit Logs', 'audit'],
                ['manage_master_data', 'Manage Settings / Master Data', 'master'],
                ['register_family', 'Register / Edit Sankalp Family', 'families'],
                ['register_karyakar', 'Register / Nominate Sankalp Karyakar', 'karyakars'],
                ['approve_karyakar', 'Approve Sankalp Karyakar', 'karyakars'],
                ['create_group', 'Create Group', 'groups'],
                ['manage_fixed_families', 'Manage Fixed / Locked Sankalp Families', 'groups'],
                ['assign_transfer_families', 'Assign / Transfer Sankalp Families', 'groups'],
                ['assign_area_society', 'Assign Sampark Area / Society', 'assignments'],
                ['assign_target', 'Assign Area / Target', 'targets'],
                ['view_own_assignments', 'View own assignments', 'field'],
                ['mark_home_visit', 'Mark Home Visit / Completion', 'field'],
                ['view_reports_analysis', 'Reports and Analysis', 'reports'],
                ['gender_category_filters', 'Gender / Category Filters', 'reports'],
                ['access_bal_pravruti', 'Access Bal Pravruti Module', 'bal_pravruti'],
                ['manage_bal_groups', 'Manage Bal Pravruti Groups', 'bal_pravruti'],
                ['submit_bal_completion', 'Submit Bal Pravruti Completion', 'bal_pravruti'],
                ['view_bal_analysis', 'View Bal Pravruti Dashboard / Analysis', 'bal_pravruti'],
                ['view_announcements', 'View Announcements', 'support'],
                ['manage_announcements', 'Manage Announcements', 'support'],
                ['view_family_time', 'View Family Time Schedule', 'support'],
                ['record_family_time', 'Record Family Time Completion', 'support'],
                ['manage_family_time', 'Manage Family Time Schedule', 'support'],
                ['view_shared_content', 'View Karyalay Shared Content', 'content'],
                ['manage_shared_content', 'Manage Karyalay Shared Content', 'content'],
                ['view_testimonials', 'View Testimonials / Feedback', 'support'],
                ['submit_testimonial', 'Submit Testimonial / Feedback', 'support'],
                ['manage_testimonials', 'Review Testimonials / Feedback', 'support'],
                ['view_inventory', 'View Inventory / Stock Register', 'inventory'],
                ['manage_inventory', 'Manage Inventory / Stock Register', 'inventory'],
                ['use_sticky_notes', 'Use Sticky Notes', 'support'],
                ['contact_support', 'Contact Support', 'support'],
                ['manage_support', 'Manage Support Requests', 'support'],
                ['submit_correction_request', 'Submit Correction / Change Request', 'corrections'],
                ['manage_correction_requests', 'Review Correction / Change Requests', 'corrections'],
            ];

            $newPermissionSlugs = [];
            $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) use (&$newPermissionSlugs) {
                $model = Permission::query()->updateOrCreate(
                    ['slug' => $permission[0]],
                    ['name' => $permission[1], 'module' => $permission[2]]
                );
                if ($model->wasRecentlyCreated) {
                    $newPermissionSlugs[] = $permission[0];
                }
                return [$permission[0] => $model];
            });

            $roles = [
                'super_admin' => ['Karyalay Admin / Super Admin', 'main'],
                'bn_karyalay_admin' => ['BN Karyalay Admin', 'main'],
                'zonal_admin' => ['Zonal Admin', 'main'],
                'center_admin' => ['Center Admin', 'main'],
                'computer_op' => ['Computer Op.', 'main'],
                'karyakar' => ['Karyakar', 'main'],
                'nirdeshak' => ['Nirdeshak', 'bal_pravruti'],
                'nirikshak' => ['Nirikshak', 'bal_pravruti'],
                'sanchalak' => ['Sanchalak', 'bal_pravruti'],
            ];

            $newRoleSlugs = [];
            $roleModels = collect($roles)->mapWithKeys(function (array $data, string $slug) use (&$newRoleSlugs) {
                $model = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $data[0], 'module' => $data[1]]);
                if ($model->wasRecentlyCreated) {
                    $newRoleSlugs[] = $slug;
                }
                return [$slug => $model];
            });

            $commonSupport = ['view_announcements', 'view_family_time', 'record_family_time', 'view_shared_content', 'view_testimonials', 'submit_testimonial', 'use_sticky_notes', 'contact_support', 'submit_correction_request'];

            $matrix = [
                'super_admin' => $permissionModels->keys()->all(),
                'bn_karyalay_admin' => $permissionModels->keys()->all(),
                'zonal_admin' => array_merge(['view_zone', 'view_center', 'register_family', 'register_karyakar', 'approve_karyakar', 'create_group', 'manage_fixed_families', 'assign_transfer_families', 'assign_area_society', 'assign_target', 'view_own_assignments', 'view_reports_analysis', 'view_audit_logs', 'gender_category_filters', 'access_bal_pravruti', 'manage_bal_groups', 'view_bal_analysis', 'manage_family_time', 'view_inventory', 'manage_inventory', 'manage_support', 'manage_correction_requests'], $commonSupport),
                'center_admin' => array_merge(['view_center', 'register_family', 'register_karyakar', 'approve_karyakar', 'create_group', 'manage_fixed_families', 'assign_transfer_families', 'assign_area_society', 'assign_target', 'view_own_assignments', 'view_reports_analysis', 'view_audit_logs', 'gender_category_filters', 'access_bal_pravruti', 'manage_bal_groups', 'view_bal_analysis', 'manage_family_time', 'view_inventory', 'manage_inventory', 'manage_support', 'manage_correction_requests'], $commonSupport),
                'computer_op' => array_merge(['view_center', 'register_family', 'register_karyakar', 'create_group', 'manage_fixed_families', 'assign_transfer_families', 'assign_area_society', 'assign_target', 'view_own_assignments', 'view_reports_analysis', 'view_audit_logs', 'gender_category_filters', 'view_inventory', 'manage_inventory'], $commonSupport),
                'karyakar' => array_merge(['view_center', 'view_own_assignments', 'mark_home_visit', 'view_reports_analysis', 'view_audit_logs', 'gender_category_filters'], $commonSupport),
                'nirdeshak' => array_merge(['view_own_assignments', 'view_audit_logs', 'gender_category_filters', 'access_bal_pravruti', 'view_bal_analysis'], $commonSupport),
                'nirikshak' => array_merge(['view_own_assignments', 'view_audit_logs', 'gender_category_filters', 'access_bal_pravruti', 'view_bal_analysis'], $commonSupport),
                'sanchalak' => array_merge(['view_own_assignments', 'view_audit_logs', 'gender_category_filters', 'access_bal_pravruti', 'submit_bal_completion', 'view_bal_analysis'], $commonSupport),
            ];

            foreach ($matrix as $roleSlug => $permissionSlugs) {
                $baselineSlugs = array_values(array_unique($permissionSlugs));
                $role = $roleModels[$roleSlug];

                if (in_array($roleSlug, $newRoleSlugs, true)) {
                    $role->permissions()->sync($permissionModels->only($baselineSlugs)->pluck('id')->all());
                    continue;
                }

                // Preserve administrator-edited permission assignments on existing roles.
                // Only newly introduced permissions are added automatically when the baseline includes them.
                $newBaselineSlugs = array_values(array_intersect($baselineSlugs, $newPermissionSlugs));
                if ($newBaselineSlugs !== []) {
                    $role->permissions()->syncWithoutDetaching($permissionModels->only($newBaselineSlugs)->pluck('id')->all());
                }
            }
        });
    }
}
