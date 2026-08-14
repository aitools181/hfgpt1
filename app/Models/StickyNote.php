<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StickyNote extends Model
{
    protected $fillable = ['user_id', 'title', 'body', 'status', 'pinned_at'];
    protected function casts(): array { return ['pinned_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
