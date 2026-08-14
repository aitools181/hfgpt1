<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SharedContent extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'content_type', 'title', 'body', 'url', 'file_path', 'audience', 'status', 'sort_order', 'published_at', 'expires_at', 'created_by'];
    protected function casts(): array { return ['published_at' => 'datetime', 'expires_at' => 'datetime']; }
    protected $appends = ['file_url'];
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function getFileUrlAttribute(): ?string { return $this->file_path ? Storage::disk('public')->url($this->file_path) : null; }
}
