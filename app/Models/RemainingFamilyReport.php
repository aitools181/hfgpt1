<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemainingFamilyReport extends Model
{
    use Auditable;
    protected $fillable = ['group_id', 'family_id', 'karyakar_id', 'status', 'note', 'reported_at', 'reviewed_by', 'reviewed_at', 'review_note'];
    protected function casts(): array { return ['reported_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
