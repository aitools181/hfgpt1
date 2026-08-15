<?php

namespace App\Http\Controllers\Bal;

use App\Http\Controllers\Controller;
use App\Services\Bal\BalFeatureReadiness;
use App\Services\Bal\BalPravrutiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BalPravrutiService $service, BalFeatureReadiness $readiness): Response
    {
        $missing = $readiness->missingTables();
        $data = $missing === []
            ? $service->dashboard($request->user(), $request->only(['center_id', 'gender', 'category', 'date_from', 'date_to']))
            : $readiness->fallbackDashboard($request->user());

        return Inertia::render('bal/dashboard', [
            'bal' => $data,
            'canManage' => $request->user()->hasPermission('manage_bal_groups'),
            'canSubmit' => $request->user()->hasRole('sanchalak') && $request->user()->hasPermission('submit_bal_completion'),
            'systemWarning' => $missing === [] ? null : 'Bal Pravruti database tables were missing in this deployment. v1.0.3 includes an automatic repair migration. Redeploy and refresh. Missing: '.implode(', ', $missing),
        ]);
    }
}
