<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalGroupChild extends Model
{
    use Auditable;

    protected $fillable = ['bal_group_id', 'family_member_id', 'position', 'status', 'assigned_by', 'assigned_at', 'ended_at'];
    protected function casts(): array { return ['assigned_at' => 'datetime', 'ended_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(BalGroup::class, 'bal_group_id'); }
    public function member(): BelongsTo { return $this->belongsTo(FamilyMember::class, 'family_member_id'); }
}
