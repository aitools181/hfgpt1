<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => false, 'cache' => false];
        try {
            DB::select('select 1');
            $checks['database'] = true;
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
        return response()->json(['status' => $ready ? 'ready' : 'degraded', 'checks' => $checks], $ready ? 200 : 503);
    }
}
