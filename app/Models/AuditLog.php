<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'user_role', 'zone_id', 'center_id', 'module', 'action',
        'record_type', 'record_id', 'record_reference', 'old_values', 'new_values',
        'reason', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }
}
