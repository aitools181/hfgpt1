<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Zone;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CenterController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        return Inertia::render('admin/centers', [
            'centers' => $scope->centers($request->user())->orderBy('name')->get(),
            'zones' => $scope->zones($request->user())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'canManageCenters' => $request->user()->hasPermission('manage_centers'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate($this->rules());
        $data['code'] = strtoupper($data['code']);
        Center::query()->create($data);
        return back()->with('success', 'Center created successfully.');
    }

    public function update(Request $request, Center $center): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate($this->rules($center));
        $data['code'] = strtoupper($data['code']);
        $center->update($data);
        return back()->with('success', 'Center updated successfully.');
    }

    private function rules(?Center $center = null): array
    {
        return [
            'zone_id' => ['nullable', 'exists:zones,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('centers', 'code')->ignore($center?->id)],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
