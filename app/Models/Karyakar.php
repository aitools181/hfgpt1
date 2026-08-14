<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Karyakar extends Model
{
    use Auditable;
    protected $fillable = ['center_id', 'sampark_area_id', 'society_id', 'family_id', 'family_member_id', 'user_id', 'karyakar_reference', 'source', 'full_name', 'gender', 'age', 'category', 'mobile', 'email', 'address', 'preferred_area', 'experience_notes', 'status', 'nominated_by', 'approved_by', 'approved_at', 'decision_note'];
    protected function casts(): array { return ['approved_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function member(): BelongsTo { return $this->belongsTo(FamilyMember::class, 'family_member_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function groupAssignments(): HasMany { return $this->hasMany(GroupKaryakar::class); }
    public function targets(): HasMany { return $this->hasMany(Target::class); }
    public function remainingFamilyReports(): HasMany { return $this->hasMany(RemainingFamilyReport::class); }
    public function homeVisits(): HasMany { return $this->hasMany(HomeVisit::class); }
    public function badges(): HasMany { return $this->hasMany(KaryakarBadge::class); }
    public function inactivityEvents(): HasMany { return $this->hasMany(InactivityEvent::class); }
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SankalpGroup::class, 'group_karyakars', 'karyakar_id', 'group_id')
            ->withPivot(['position', 'status', 'assigned_at', 'ended_at', 'change_note'])
            ->withTimestamps();
    }
}
