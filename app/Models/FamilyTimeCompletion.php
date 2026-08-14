<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyTimeCompletion extends Model
{
    use Auditable;

    protected $fillable = ['family_time_schedule_id', 'user_id', 'center_id', 'completed_on', 'note'];
    protected function casts(): array { return ['completed_on' => 'date']; }
    public function schedule(): BelongsTo { return $this->belongsTo(FamilyTimeSchedule::class, 'family_time_schedule_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
}
