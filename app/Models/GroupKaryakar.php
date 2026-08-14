<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupKaryakar extends Model
{
    use Auditable;
    protected $fillable = ['group_id', 'karyakar_id', 'position', 'status', 'assigned_by', 'assigned_at', 'ended_at', 'change_note'];
    protected function casts(): array { return ['assigned_at' => 'datetime', 'ended_at' => 'datetime']; }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
}
