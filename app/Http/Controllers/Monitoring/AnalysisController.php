<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MonitoringAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalysisController extends Controller
{
    public function __invoke(Request $request, MonitoringAnalyticsService $analytics): Response
    {
        $input = $request->only(['center_id', 'group_id', 'karyakar_id', 'area_id', 'gender', 'category', 'status', 'date_from', 'date_to']);
        $data = $analytics->dashboard($request->user(), $input);

        return Inertia::render('monitoring/analysis', [
            'analysis' => $data,
            'options' => $analytics->filterOptions($request->user(), $input),
        ]);
    }
}
