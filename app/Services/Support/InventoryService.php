<?php

namespace App\Services\Support;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly SupportScopeService $scope, private readonly AuditTrail $audit) {}

    public function createItem(User $user, array $data): InventoryItem
    {
        $this->scope->assertCenterAccess($user, (int) $data['center_id']);
        $item = InventoryItem::query()->create([
            ...$data,
            'sku' => strtoupper(trim($data['sku'])),
            'current_stock' => 0,
            'created_by' => $user->id,
        ]);
        return $item;
    }

    public function transact(User $user, InventoryItem $item, string $type, int $quantity, ?string $reference, ?string $note): InventoryTransaction
    {
        $this->scope->assertCenterAccess($user, (int) $item->center_id);
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
        }

        return DB::transaction(function () use ($user, $item, $type, $quantity, $reference, $note): InventoryTransaction {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $before = (int) $locked->current_stock;
            $after = $type === 'inward' ? $before + $quantity : $before - $quantity;
            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => 'Outward quantity cannot exceed current stock.']);
            }

            $locked->forceFill(['current_stock' => $after])->saveQuietly();
            $transaction = InventoryTransaction::query()->create([
                'inventory_item_id' => $locked->id,
                'center_id' => $locked->center_id,
                'transaction_type' => $type,
                'quantity' => $quantity,
                'stock_before' => $before,
                'stock_after' => $after,
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $user->id,
                'recorded_at' => now(),
            ]);
            $this->audit->record('inventory', $type, InventoryItem::class, (string) $locked->id, ['stock' => $before], ['stock' => $after, 'quantity' => $quantity], $note, centerId: $locked->center_id);
            return $transaction;
        });
    }
}
