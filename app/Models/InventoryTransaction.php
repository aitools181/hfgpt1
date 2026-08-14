<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $fillable = ['inventory_item_id', 'center_id', 'transaction_type', 'quantity', 'stock_before', 'stock_after', 'reference', 'note', 'recorded_by', 'recorded_at'];
    protected function casts(): array { return ['recorded_at' => 'datetime']; }
    public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class, 'inventory_item_id'); }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
