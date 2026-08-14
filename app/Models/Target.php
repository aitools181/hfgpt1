<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Target extends Model
{
    use Auditable;
    protected $fillable = ['center_id', 'group_id', 'karyakar_id', 'sampark_area_id', 'society_id', 'name', 'start_date', 'end_date', 'target_quantity', 'completed_quantity', 'status', 'assigned_by'];
    protected function casts(): array { return ['start_date' => 'date', 'end_date' => 'date']; }
    protected $appends = ['remaining_quantity', 'completion_percentage'];

    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function homeVisits(): HasMany { return $this->hasMany(HomeVisit::class); }
    public function inactivityEvents(): HasMany { return $this->hasMany(InactivityEvent::class); }
    public function getRemainingQuantityAttribute(): int { return max(0, $this->target_quantity - $this->completed_quantity); }
    public function getCompletionPercentageAttribute(): float { return $this->target_quantity > 0 ? min(100.0, round(($this->completed_quantity / $this->target_quantity) * 100, 2)) : 0.0; }
}
