<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        return Inertia::render('admin/zones', [
            'zones' => $scope->zones($request->user())->withCount('centers')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'alpha_dash', 'max:20', 'unique:zones,code'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $data['code'] = strtoupper($data['code']);
        Zone::query()->create($data);
        return back()->with('success', 'Zone created successfully.');
    }

    public function update(Request $request, Zone $zone): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('zones', 'code')->ignore($zone->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $data['code'] = strtoupper($data['code']);
        $zone->update($data);
        return back()->with('success', 'Zone updated successfully.');
    }
}
