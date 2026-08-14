<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BalGroup extends Model
{
    use Auditable;

    protected $fillable = [
        'center_id', 'sampark_area_id', 'society_id', 'group_code', 'sanchalak_karyakar_id',
        'sanchalak_user_id', 'status', 'created_by', 'activated_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function sanchalak(): BelongsTo { return $this->belongsTo(Karyakar::class, 'sanchalak_karyakar_id'); }
    public function sanchalakUser(): BelongsTo { return $this->belongsTo(User::class, 'sanchalak_user_id'); }
    public function children(): HasMany { return $this->hasMany(BalGroupChild::class); }
    public function supervisors(): HasMany { return $this->hasMany(BalGroupSupervisor::class); }
    public function completionReports(): HasMany { return $this->hasMany(BalCompletionReport::class); }
}
