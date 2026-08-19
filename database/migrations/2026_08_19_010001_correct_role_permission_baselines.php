<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'assign_target')->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('slug', ['zonal_admin', 'center_admin'])->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }

    public function down(): void
    {
        // Permission assignments are administrator-controlled after deployment.
        // Do not silently re-grant a removed operational permission on rollback.
    }
};
