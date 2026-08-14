<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SamparkArea extends Model
{
    use Auditable;
    protected $fillable = ['center_id', 'external_code', 'name', 'city_village', 'status'];
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function societies(): HasMany { return $this->hasMany(Society::class); }
    public function groups(): HasMany { return $this->hasMany(SankalpGroup::class, 'sampark_area_id'); }
    public function karyakars(): HasMany { return $this->hasMany(Karyakar::class, 'sampark_area_id'); }
    public function families(): HasMany { return $this->hasMany(Family::class, 'sampark_area_id'); }
}
