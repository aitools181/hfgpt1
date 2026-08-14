<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use Auditable;

    protected $fillable = ['name', 'code', 'status'];

    public function centers(): HasMany
    {
        return $this->hasMany(Center::class);
    }
}
