<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeVisit extends Model
{
    use Auditable;

    protected $fillable = [
        'center_id', 'group_id', 'group_family_assignment_id', 'family_id', 'karyakar_id', 'target_id', 'sampark_area_id', 'society_id',
        'message_delivered', 'completion_note', 'completed_at', 'recorded_by', 'is_admin_override', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'message_delivered' => 'boolean',
            'completed_at' => 'datetime',
            'is_admin_override' => 'boolean',
        ];
    }

    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function assignment(): BelongsTo { return $this->belongsTo(GroupFamilyAssignment::class, 'group_family_assignment_id'); }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
    public function target(): BelongsTo { return $this->belongsTo(Target::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
