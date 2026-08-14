<?php

namespace App\Http\Controllers\Bal;

use App\Http\Controllers\Controller;
use App\Services\Bal\BalPravrutiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BalPravrutiService $service): Response
    {
        $data = $service->dashboard($request->user(), $request->only(['center_id', 'gender', 'category', 'date_from', 'date_to']));

        return Inertia::render('bal/dashboard', [
            'bal' => $data,
            'canManage' => $request->user()->hasPermission('manage_bal_groups'),
            'canSubmit' => $request->user()->hasRole('sanchalak') && $request->user()->hasPermission('submit_bal_completion'),
        ]);
    }
}
