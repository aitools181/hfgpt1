<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Society extends Model
{
    use Auditable;
    protected $fillable = ['center_id', 'sampark_area_id', 'external_code', 'name', 'status'];
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function area(): BelongsTo { return $this->belongsTo(SamparkArea::class, 'sampark_area_id'); }
    public function groups(): HasMany { return $this->hasMany(SankalpGroup::class); }
    public function karyakars(): HasMany { return $this->hasMany(Karyakar::class); }
    public function families(): HasMany { return $this->hasMany(Family::class); }
}
