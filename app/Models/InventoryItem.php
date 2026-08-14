<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'sku', 'name', 'unit', 'current_stock', 'minimum_stock', 'status', 'created_by'];
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function transactions(): HasMany { return $this->hasMany(InventoryTransaction::class); }
}
