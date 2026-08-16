<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class LiveHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'application' => config('app.name'),
            'framework' => app()->version(),
            'time' => now()->toIso8601String(),
        ]);
    }
}
