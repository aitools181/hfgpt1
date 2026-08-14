<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SankalpGroup extends Model
{
    use Auditable;

    protected $table = 'groups';
    protected $fillable = ['center_id', 'sampark_area_id', 'society_id', 'group_code', 'group_type', 'status', 'created_by', 'activated_at', 'closed_at'];
    protected function casts(): array { return ['activated_at' => 'datetime', 'closed_at' => 'datetime']; }

    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function karyakarAssignments(): HasMany { return $this->hasMany(GroupKaryakar::class, 'group_id'); }
    public function familyAssignments(): HasMany { return $this->hasMany(GroupFamilyAssignment::class, 'group_id'); }
    public function targets(): HasMany { return $this->hasMany(Target::class, 'group_id'); }
    public function homeVisits(): HasMany { return $this->hasMany(HomeVisit::class, 'group_id'); }
    public function inactivityEvents(): HasMany { return $this->hasMany(InactivityEvent::class, 'group_id'); }
    public function remainingFamilyReports(): HasMany { return $this->hasMany(RemainingFamilyReport::class, 'group_id'); }

    public function karyakars(): BelongsToMany
    {
        return $this->belongsToMany(Karyakar::class, 'group_karyakars', 'group_id', 'karyakar_id')
            ->withPivot(['position', 'status', 'assigned_at', 'ended_at', 'change_note'])
            ->withTimestamps();
    }

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'group_family_assignments', 'group_id', 'family_id')
            ->withPivot(['slot_number', 'assignment_type', 'assignment_source', 'status', 'assigned_at', 'ended_at', 'change_note'])
            ->withTimestamps();
    }
}
