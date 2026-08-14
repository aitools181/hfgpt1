<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InactivityEvent extends Model
{
    protected $fillable = [
        'center_id', 'group_id', 'karyakar_id', 'target_id', 'recipient_user_id', 'event_type',
        'inactivity_days', 'status', 'activity_anchor_at', 'triggered_at', 'resolved_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'activity_anchor_at' => 'datetime',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function group(): BelongsTo { return $this->belongsTo(SankalpGroup::class, 'group_id'); }
    public function karyakar(): BelongsTo { return $this->belongsTo(Karyakar::class); }
    public function target(): BelongsTo { return $this->belongsTo(Target::class); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }
}
