<?php

namespace App\Http\Controllers\Bal;

use App\Http\Controllers\Controller;
use App\Services\Bal\BalPravrutiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalysisController extends Controller
{
    public function __invoke(Request $request, BalPravrutiService $service): Response
    {
        return Inertia::render('bal/analysis', [
            'analysis' => $service->dashboard($request->user(), $request->only(['center_id', 'gender', 'category', 'date_from', 'date_to'])),
            'options' => $service->filterOptions($request->user()),
        ]);
    }
}
