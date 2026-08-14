<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FamilyMember extends Model
{
    use Auditable;
    protected $fillable = ['family_id', 'external_member_id', 'name', 'gender', 'age', 'date_of_birth', 'mobile', 'relationship', 'is_head', 'status'];
    protected function casts(): array { return ['date_of_birth' => 'date', 'is_head' => 'boolean']; }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function karyakar(): HasOne { return $this->hasOne(Karyakar::class); }
}
