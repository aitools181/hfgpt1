<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyTimeSchedule extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'title', 'description', 'audience', 'starts_at', 'ends_at', 'status', 'created_by'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function completions(): HasMany { return $this->hasMany(FamilyTimeCompletion::class); }
}
