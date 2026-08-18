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
        $checks = ['database' => false, 'cache' => false, 'redis' => false, 'schema' => false, 'storage' => false, 'disk' => false];
        $diskFreeMb = null;
        $minimumDiskFreeMb = max(128, (int) env('HEALTH_MIN_DISK_FREE_MB', 512));
        $missingTables = [];
        $missingColumns = [];

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
            $checks['storage'] = is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
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
            'disk_free_mb' => $diskFreeMb,
            'minimum_disk_free_mb' => $minimumDiskFreeMb,
        ], $ready ? 200 : 503);
    }
}
