<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'sampark_area_id', 'society_id', 'external_family_id', 'manual_reference', 'source', 'head_name', 'head_mobile', 'address', 'city_village', 'status', 'registered_at', 'registered_by'];
    protected function casts(): array { return ['registered_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function members(): HasMany { return $this->hasMany(FamilyMember::class); }
    public function karyakars(): HasMany { return $this->hasMany(Karyakar::class); }
    public function groupAssignments(): HasMany { return $this->hasMany(GroupFamilyAssignment::class); }
    public function remainingFamilyReports(): HasMany { return $this->hasMany(RemainingFamilyReport::class); }
    public function homeVisits(): HasMany { return $this->hasMany(HomeVisit::class); }
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SankalpGroup::class, 'group_family_assignments', 'family_id', 'group_id')
            ->withPivot(['slot_number', 'assignment_type', 'assignment_source', 'status', 'assigned_at', 'ended_at', 'change_note'])
            ->withTimestamps();
    }
    public function getReferenceAttribute(): string { return $this->external_family_id ?: (string) $this->manual_reference; }
}
