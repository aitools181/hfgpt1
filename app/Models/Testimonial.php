<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Testimonial extends Model
{
    use Auditable;

    protected $fillable = ['center_id', 'submitted_by', 'display_name', 'designation', 'message', 'rating', 'status', 'reviewed_by', 'reviewed_at', 'review_note'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
