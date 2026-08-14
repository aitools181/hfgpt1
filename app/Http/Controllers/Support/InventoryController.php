<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\InventoryItem;
use App\Services\Support\InventoryService;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $centerIds = $scope->visibleCenterIds($user);
        $query = InventoryItem::query()->with('center:id,name,code')->with(['transactions' => fn ($q) => $q->with('recorder:id,name')->latest('recorded_at')->limit(10)])->orderBy('name');
        if (! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'))) {
            $query->whereIn('center_id', $centerIds);
        }
        if ($request->filled('center_id')) {
            $centerId = (int) $request->integer('center_id');
            $scope->assertCenterAccess($user, $centerId);
            $query->where('center_id', $centerId);
        }
        return Inertia::render('support/inventory', [
            'items' => $query->get(),
            'centers' => $this->centers($user, $scope),
            'canManage' => $user->hasPermission('manage_inventory'),
            'filters' => ['center_id' => $request->input('center_id')],
        ]);
    }

    public function store(Request $request, InventoryService $service): RedirectResponse
    {
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $centerId = (int) $request->input('center_id');
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('inventory_items', 'sku')->where(fn ($query) => $query->where('center_id', $centerId))],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:40'],
            'minimum_stock' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $service->createItem($request->user(), [...$data, 'minimum_stock' => $data['minimum_stock'] ?? 0]);
        return back()->with('success', 'Inventory item created.');
    }

    public function transact(Request $request, InventoryItem $item, InventoryService $service): RedirectResponse
    {
        $data = $request->validate([
            'transaction_type' => ['required', 'in:inward,outward'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->transact($request->user(), $item, $data['transaction_type'], (int) $data['quantity'], $data['reference'] ?? null, $data['note'] ?? null);
        return back()->with('success', ucfirst($data['transaction_type']).' stock transaction recorded.');
    }

    private function centers($user, SupportScopeService $scope): array
    {
        $ids = $scope->visibleCenterIds($user);
        return Center::query()->when(! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'name', 'code'])->all();
    }
}
