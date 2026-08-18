<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditTrail
{
    private const REDACTED_KEYS = ['password', 'remember_token', 'api_token', 'secret'];

    public function recordModelChange(Model $model, string $action, array $oldValues, array $newValues, ?string $reason = null): void
    {
        if ($model instanceof AuditLog) {
            return;
        }

        $user = Auth::user();
        $primaryRole = $user?->primaryRole();
        $centerId = array_key_exists('center_id', $model->getAttributes()) ? data_get($model, 'center_id') : null;
        if ($model instanceof \App\Models\Center) {
            $centerId = $model->id;
        }
        $zoneId = array_key_exists('zone_id', $model->getAttributes()) ? data_get($model, 'zone_id') : null;
        if ($model instanceof \App\Models\Zone) {
            $zoneId = $model->id;
        }

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'system',
            'user_role' => $primaryRole?->slug ?? 'system',
            'zone_id' => $zoneId,
            'center_id' => $centerId,
            'module' => strtolower(class_basename($model)),
            'action' => $action,
            'record_type' => $model::class,
            'record_id' => (string) $model->getKey(),
            'record_reference' => data_get($model, 'code') ?? data_get($model, 'name'),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    public function record(string $module, string $action, ?string $recordType = null, ?string $recordId = null, array $oldValues = [], array $newValues = [], ?string $reason = null, ?int $zoneId = null, ?int $centerId = null): void
    {
        $user = Auth::user();
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'system',
            'user_role' => $user?->primaryRole()?->slug ?? 'system',
            'zone_id' => $zoneId,
            'center_id' => $centerId,
            'module' => $module,
            'action' => $action,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    public function recordSafely(string $module, string $action, ?string $recordType = null, ?string $recordId = null, array $oldValues = [], array $newValues = [], ?string $reason = null, ?int $zoneId = null, ?int $centerId = null): bool
    {
        try {
            $this->record($module, $action, $recordType, $recordId, $oldValues, $newValues, $reason, $zoneId, $centerId);
            return true;
        } catch (Throwable $e) {
            Log::error('Non-blocking audit write failed.', [
                'module' => $module,
                'action' => $action,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function sanitize(array $values): array
    {
        return Arr::except($values, self::REDACTED_KEYS);
    }
}
