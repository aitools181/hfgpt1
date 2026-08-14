<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRegistrationImport;
use App\Models\ImportBatch;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $centerIds = $scope->centers($request->user())->pluck('id');
        return Inertia::render('registration/imports', [
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
            'batches' => ImportBatch::query()->with('center:id,name,code')->whereIn('center_id', $centerIds)->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request, OrganizationalScope $scope): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'center_id' => ['required', Rule::in($allowedCenters)], 'type' => ['required', Rule::in(['families', 'areas'])],
            'file' => ['required', 'file', 'max:15360', 'mimes:csv,txt,tsv,xlsx'],
        ]);
        $file = $request->file('file');
        $path = $file->store('imports/'.$data['center_id'], 'local');
        if ($path === false) {
            return back()->with('error', 'The import file could not be stored. Please try again.');
        }
        $batch = ImportBatch::query()->create([
            'center_id' => $data['center_id'], 'uploaded_by' => $request->user()->id, 'type' => $data['type'],
            'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'status' => 'processing',
        ]);
        try {
            ProcessRegistrationImport::dispatch($batch->id);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'errors' => [['row' => null, 'message' => 'The import could not be queued.']], 'completed_at' => now()]);
            report($e);
            return back()->with('error', 'Import could not be queued. Review the application/queue logs.');
        }
        return back()->with('success', 'Import queued for processing. Refresh the batch list to review completion and skipped rows.');
    }
}
