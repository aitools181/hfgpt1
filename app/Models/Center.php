<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Center extends Model
{
    use Auditable;

    protected $fillable = ['zone_id', 'name', 'code', 'city', 'address', 'contact_phone', 'contact_email', 'status'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
