<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'title', 'body', 'audience', 'status', 'published_at', 'expires_at', 'created_by'];
    protected function casts(): array { return ['published_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
