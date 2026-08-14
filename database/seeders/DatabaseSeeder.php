<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(PilotDataSeeder::class);

        $email = strtolower(trim((string) env('SUPER_ADMIN_EMAIL')));
        if (! $email) {
            return;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $password = (string) env('SUPER_ADMIN_PASSWORD', '');
            if (mb_strlen($password) < 16 || in_array(strtolower(trim($password)), ['change-me', 'change-me-now', 'password', 'password123'], true)) {
                throw new \RuntimeException('SUPER_ADMIN_PASSWORD must be a non-default value of at least 16 characters for first bootstrap.');
            }
            $user = User::query()->create([
                'email' => $email,
                'name' => env('SUPER_ADMIN_NAME', 'SMVS Super Admin'),
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        }

        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        DB::table('user_roles')->where('user_id', $user->id)->update(['is_primary' => false]);
        $user->roles()->syncWithoutDetaching([
            $role->id => ['zone_id' => null, 'center_id' => null, 'is_primary' => true],
        ]);
        $user->roles()->updateExistingPivot($role->id, ['zone_id' => null, 'center_id' => null, 'is_primary' => true]);
    }
}
