<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => false,
            'cache' => false,
            'redis' => false,
            'schema' => false,
            'auth_schema' => false,
            'storage' => false,
            'session_storage' => false,
            'disk' => false,
        ];
        $diskFreeMb = null;
        $minimumDiskFreeMb = max(128, (int) env('HEALTH_MIN_DISK_FREE_MB', 512));
        $missingTables = [];
        $missingColumns = [];
        $authMissingTables = [];
        $authMissingColumns = [];

        try {
            DB::select('select 1');
            $checks['database'] = true;

            $requiredTables = [
                'users', 'centers', 'families', 'karyakars', 'groups', 'targets',
                'home_visits', 'inactivity_events',
                'bal_groups', 'bal_group_children', 'bal_group_supervisors', 'bal_completion_reports',
            ];
            foreach ($requiredTables as $table) {
                if (! Schema::hasTable($table)) {
                    $missingTables[] = $table;
                }
            }

            if (Schema::hasTable('users')) {
                foreach (['session_version', 'password_changed_at'] as $column) {
                    if (! Schema::hasColumn('users', $column)) {
                        $missingColumns[] = "users.{$column}";
                    }
                }
            }
            $checks['schema'] = $missingTables === [] && $missingColumns === [];

            $authTables = ['users', 'roles', 'permissions', 'role_permissions', 'user_roles', 'audit_logs'];
            foreach ($authTables as $table) {
                if (! Schema::hasTable($table)) {
                    $authMissingTables[] = $table;
                }
            }
            $authColumnMap = [
                'users' => ['id', 'name', 'email', 'password', 'status', 'last_login_at', 'remember_token', 'session_version', 'password_changed_at'],
                'roles' => ['id', 'name', 'slug', 'module'],
                'permissions' => ['id', 'name', 'slug', 'module'],
                'role_permissions' => ['role_id', 'permission_id'],
                'user_roles' => ['user_id', 'role_id', 'zone_id', 'center_id', 'is_primary'],
                'audit_logs' => ['id', 'user_id', 'user_name', 'user_role', 'module', 'action', 'record_type', 'record_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'],
            ];
            foreach ($authColumnMap as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $authMissingColumns[] = "{$table}.{$column}";
                    }
                }
            }
            $checks['auth_schema'] = $authMissingTables === [] && $authMissingColumns === [];
        } catch (Throwable) {
        }

        try {
            $key = 'health:ready:'.getmypid();
            Cache::put($key, 'ok', 5);
            $checks['cache'] = Cache::get($key) === 'ok';
            Cache::forget($key);
        } catch (Throwable) {
        }

        try {
            $redis = Redis::connection('default');
            $ping = $redis->command('ping');
            $pingOk = $ping === true || strtoupper((string) $ping) === 'PONG' || (string) $ping === '1';

            // PING alone is not enough when Redis uses maxmemory-policy
            // noeviction: the server can answer PONG while refusing the queue
            // writes that imports depend on. Verify a tiny expiring write too.
            $redisKey = 'health:ready:redis:'.getmypid().':'.bin2hex(random_bytes(4));
            $write = $redis->command('setex', [$redisKey, 5, 'ok']);
            $read = $redis->command('get', [$redisKey]);
            $redis->command('del', [$redisKey]);
            $writeOk = $write === true || strtoupper((string) $write) === 'OK' || (string) $write === '1';
            $checks['redis'] = $pingOk && $writeOk && (string) $read === 'ok';
        } catch (Throwable) {
        }

        try {
            $writablePaths = [
                storage_path(),
                storage_path('framework/cache'),
                storage_path('framework/views'),
                storage_path('logs'),
                base_path('bootstrap/cache'),
            ];
            $checks['storage'] = collect($writablePaths)->every(fn (string $path): bool => is_dir($path) && is_writable($path));

            $sessionDir = storage_path('framework/sessions');
            if (is_dir($sessionDir) && is_writable($sessionDir)) {
                $probe = $sessionDir.'/.health-session-'.getmypid().'-'.bin2hex(random_bytes(4));
                $written = @file_put_contents($probe, 'ok', LOCK_EX);
                $checks['session_storage'] = $written === 2 && @file_get_contents($probe) === 'ok';
                @unlink($probe);
            }

            $freeBytes = disk_free_space(storage_path());
            if ($freeBytes !== false) {
                $diskFreeMb = round($freeBytes / 1024 / 1024, 1);
                $checks['disk'] = $diskFreeMb >= $minimumDiskFreeMb;
            }
        } catch (Throwable) {
        }

        $ready = ! in_array(false, $checks, true);
        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'auth_missing_tables' => $authMissingTables,
            'auth_missing_columns' => $authMissingColumns,
            'disk_free_mb' => $diskFreeMb,
            'minimum_disk_free_mb' => $minimumDiskFreeMb,
        ], $ready ? 200 : 503);
    }
}
