<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MonitoringAnalyticsService;
use App\Services\Monitoring\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports, MonitoringAnalyticsService $analytics): Response
    {
        $type = (string) $request->query('report', 'center_performance');
        $input = $request->only(['center_id', 'group_id', 'karyakar_id', 'area_id', 'gender', 'category', 'status', 'date_from', 'date_to']);
        $report = $reports->build($request->user(), $type, $input);

        return Inertia::render('monitoring/reports', [
            'report' => $report,
            'reportTypes' => ReportService::TYPES,
            'options' => $analytics->filterOptions($request->user(), $input),
        ]);
    }

    public function export(Request $request, ReportService $reports): StreamedResponse
    {
        $type = (string) $request->query('report', 'center_performance');
        $input = $request->only(['center_id', 'group_id', 'karyakar_id', 'area_id', 'gender', 'category', 'status', 'date_from', 'date_to']);
        $report = $reports->stream($request->user(), $type, $input);
        $filename = 'happy-family-'.str_replace('_', '-', $report['type']).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values($report['columns']), ',', '"', '');
            foreach ($report['rows'] as $row) {
                if (connection_aborted()) {
                    break;
                }
                $ordered = [];
                foreach (array_keys($report['columns']) as $key) {
                    $value = $row[$key] ?? '';
                    $value = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $ordered[] = $this->csvSafe($value);
                }
                fputcsv($handle, $ordered, ',', '"', '');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvSafe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }
        return $value;
    }
}
