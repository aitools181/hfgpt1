<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    use Auditable;
    protected $fillable = ['center_id', 'uploaded_by', 'type', 'original_filename', 'stored_path', 'status', 'total_rows', 'created_rows', 'updated_rows', 'skipped_rows', 'errors', 'completed_at'];
    protected function casts(): array { return ['errors' => 'array', 'completed_at' => 'datetime']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
}
