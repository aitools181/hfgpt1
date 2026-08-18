<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Field\InactivityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('happy-family:status', function (): void {
    $this->info('SMVS Happy Family Portal foundation is installed.');
})->purpose('Show project foundation status');

Artisan::command('happy-family:auth-preflight', function (): int {
    $failures = [];
    $requiredColumns = [
        'users' => ['id', 'name', 'email', 'password', 'status', 'last_login_at', 'remember_token', 'session_version', 'password_changed_at'],
        'roles' => ['id', 'name', 'slug', 'module'],
        'permissions' => ['id', 'name', 'slug', 'module'],
        'role_permissions' => ['role_id', 'permission_id'],
        'user_roles' => ['user_id', 'role_id', 'zone_id', 'center_id', 'is_primary'],
        'audit_logs' => ['id', 'user_id', 'user_name', 'user_role', 'module', 'action', 'record_type', 'record_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'],
        'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
    ];

    try {
        DB::select('select 1');
    } catch (\Throwable $e) {
        $failures[] = 'database connection failed: '.$e->getMessage();
    }

    foreach ($requiredColumns as $table => $columns) {
        if (! Schema::hasTable($table)) {
            $failures[] = "missing authentication table: {$table}";
            continue;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $failures[] = "missing authentication column: {$table}.{$column}";
            }
        }
    }

    if ($failures === []) {
        try {
            DB::beginTransaction();
            AuditLog::query()->create([
                'user_id' => null,
                'user_name' => 'preflight',
                'user_role' => 'system',
                'module' => 'authentication',
                'action' => 'preflight_probe',
                'record_type' => 'system',
                'record_id' => 'startup',
                'old_values' => [],
                'new_values' => [],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'startup-preflight',
                'created_at' => now(),
            ]);
            DB::rollBack();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $failures[] = 'audit-log write probe failed: '.$e->getMessage();
        }
    }

    if ($failures === []) {
        try {
            $sessionId = 'preflight-'.bin2hex(random_bytes(20));
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'startup-preflight',
                'payload' => base64_encode('happy-family-session-probe'),
                'last_activity' => time(),
            ]);
            $sessionOk = DB::table('sessions')->where('id', $sessionId)->exists();
            DB::table('sessions')->where('id', $sessionId)->delete();
            if (! $sessionOk) {
                $failures[] = 'database session round-trip probe failed';
            }
        } catch (\Throwable $e) {
            $failures[] = 'database session write/read/delete probe failed: '.$e->getMessage();
        }
    }

    if ($failures === []) {
        try {
            $cacheKey = 'preflight:'.bin2hex(random_bytes(16));
            cache()->put($cacheKey, 'ok', 10);
            if (cache()->get($cacheKey) !== 'ok') {
                $failures[] = 'application cache round-trip probe failed';
            }
            cache()->forget($cacheKey);
        } catch (\Throwable $e) {
            $failures[] = 'application cache write/read/delete probe failed: '.$e->getMessage();
        }
    }

    $superAdminEmail = strtolower(trim((string) env('SUPER_ADMIN_EMAIL', '')));
    if ($superAdminEmail !== '' && Schema::hasTable('users') && Schema::hasTable('roles') && Schema::hasTable('user_roles')) {
        try {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$superAdminEmail])->first();
            if (! $user) {
                $failures[] = 'configured Super Admin user was not found after seeding';
            } elseif ($user->status !== 'active') {
                $failures[] = 'configured Super Admin user is not active';
            } elseif (! $user->roles()->where('roles.slug', 'super_admin')->exists()) {
                $failures[] = 'configured Super Admin user is not linked to the super_admin role';
            }
        } catch (\Throwable $e) {
            $failures[] = 'Super Admin role linkage check failed: '.$e->getMessage();
        }
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            $this->error('[auth-preflight] '.$failure);
        }
        return 1;
    }

    $this->info('[auth-preflight] Authentication schema, database session/cache backends, audit write path and Super Admin linkage are ready.');
    return 0;
})->purpose('Fail deployment before traffic if authentication/storage prerequisites are broken');

Artisan::command('happy-family:inactivity-check', function (InactivityService $service): void {
    $result = $service->checkAll();
    $this->info(sprintf('Inactivity check complete. Reminders created: %d; alerts created: %d.', $result['reminders'], $result['alerts']));
})->purpose('Create 4-day inactivity reminders and 7-day inactivity alerts');

Schedule::command('happy-family:inactivity-check')->hourly()->withoutOverlapping();

// Keep the failed-job table bounded so a long-running production instance does not grow indefinitely.
Schedule::command('queue:prune-failed --hours=720')->dailyAt('02:20')->withoutOverlapping();
