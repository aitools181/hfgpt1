<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => false, 'cache' => false, 'schema' => false];
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

        $ready = ! in_array(false, $checks, true);
        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
        ], $ready ? 200 : 503);
    }
}
