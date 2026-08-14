<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaryakarBadge extends Model
{
    protected $fillable = ['karyakar_id', 'milestone', 'badge_key', 'awarded_at', 'trigger_home_visit_id'];
    protected function casts(): array { return ['awarded_at' => 'datetime']; }

    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
    public function triggerHomeVisit(): BelongsTo { return $this->belongsTo(HomeVisit::class, 'trigger_home_visit_id'); }
}
