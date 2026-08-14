<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GroupFamilyAssignment extends Model
{
    use Auditable;
    protected $fillable = ['group_id', 'family_id', 'slot_number', 'assignment_type', 'assignment_source', 'status', 'assigned_by', 'assigned_at', 'ended_at', 'transferred_to_group_id', 'change_note'];
    protected function casts(): array { return ['assigned_at' => 'datetime', 'ended_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function transferredToGroup(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'transferred_to_group_id'); }
    public function homeVisit(): HasOne { return $this->hasOne(HomeVisit::class, 'group_family_assignment_id'); }
}
