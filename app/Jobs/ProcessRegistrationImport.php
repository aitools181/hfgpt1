<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\RegistrationImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessRegistrationImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function __construct(public int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(RegistrationImportService $service): void
    {
        $batch = ImportBatch::query()->findOrFail($this->batchId);
        if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return;
        }

        $absolutePath = Storage::disk('local')->path($batch->stored_path);
        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Stored import source file is missing.');
        }

        $extension = strtolower(pathinfo($batch->stored_path, PATHINFO_EXTENSION));
        if ($batch->type === 'families') {
            $service->importFamilies($batch, $absolutePath, $extension);
            $this->deleteSourceFile($batch);
            return;
        }
        if ($batch->type === 'areas') {
            $service->importAreas($batch, $absolutePath, $extension);
            $this->deleteSourceFile($batch);
            return;
        }

        throw new \RuntimeException('Unsupported import batch type.');
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?: 'Import processing failed.';
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch) {
            $batch->update([
                'status' => 'failed',
                'errors' => [['row' => null, 'message' => mb_substr($message, 0, 1000)]],
                'completed_at' => now(),
            ]);
            $this->deleteSourceFile($batch);
        }
    }

    private function deleteSourceFile(ImportBatch $batch): void
    {
        if (! $batch->stored_path) {
            return;
        }
        try {
            Storage::disk('local')->delete($batch->stored_path);
        } catch (Throwable $exception) {
            report($exception);
            return;
        }
        $batch->forceFill(['stored_path' => null])->saveQuietly();
    }
}
