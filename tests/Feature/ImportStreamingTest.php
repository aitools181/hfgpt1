<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\Zone;
use App\Services\RegistrationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_processes_a_large_batch_with_bounded_reader_errors(): void
    {
        $zone = Zone::query()->create(['name' => 'Zone', 'code' => 'IZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Import Center', 'code' => 'IMP', 'status' => 'active']);
        $user = User::query()->create(['name' => 'Importer', 'email' => 'import@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $batch = ImportBatch::query()->create(['center_id' => $center->id, 'uploaded_by' => $user->id, 'type' => 'families', 'original_filename' => 'stress.csv', 'status' => 'processing']);

        $path = tempnam(sys_get_temp_dir(), 'hf-import-');
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['family_id', 'head_name', 'member_id', 'member_name', 'gender', 'age'], ',', '"', '');
        for ($i = 1; $i <= 250; $i++) {
            fputcsv($handle, ["F{$i}", "Family {$i}", "M{$i}", "Member {$i}", $i % 2 ? 'male' : 'female', 35], ',', '"', '');
        }
        fclose($handle);

        app(RegistrationImportService::class)->importFamilies($batch, $path, 'csv');
        @unlink($path);
        $batch->refresh();
        $this->assertSame(250, $batch->total_rows);
        $this->assertSame(250, $batch->created_rows);
        $this->assertSame(0, $batch->skipped_rows);
        $this->assertDatabaseCount('families', 250);
        $this->assertDatabaseCount('family_members', 250);
    }

    public function test_reimport_updates_family_details_without_rewriting_original_registration_provenance(): void
    {
        $zone = Zone::query()->create(['name' => 'Zone Reimport', 'code' => 'IRZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Reimport Center', 'code' => 'RIM', 'status' => 'active']);
        $originalUser = User::query()->create(['name' => 'Original Importer', 'email' => 'original-importer@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $refreshUser = User::query()->create(['name' => 'Refresh Importer', 'email' => 'refresh-importer@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);

        $firstBatch = ImportBatch::query()->create(['center_id' => $center->id, 'uploaded_by' => $originalUser->id, 'type' => 'families', 'original_filename' => 'first.csv', 'status' => 'processing']);
        $firstPath = tempnam(sys_get_temp_dir(), 'hf-first-');
        file_put_contents($firstPath, "family_id,head_name,mobile\nF-REIMPORT,Original Head,9000000001\n");
        app(RegistrationImportService::class)->importFamilies($firstBatch, $firstPath, 'csv');
        @unlink($firstPath);

        $family = \App\Models\Family::query()->where('external_family_id', 'F-REIMPORT')->firstOrFail();
        $originalRegisteredAt = $family->registered_at->copy();
        $this->travel(1)->day();

        $refreshBatch = ImportBatch::query()->create(['center_id' => $center->id, 'uploaded_by' => $refreshUser->id, 'type' => 'families', 'original_filename' => 'refresh.csv', 'status' => 'processing']);
        $refreshPath = tempnam(sys_get_temp_dir(), 'hf-refresh-');
        file_put_contents($refreshPath, "family_id,head_name,mobile\nF-REIMPORT,Updated Head,9000000002\n");
        app(RegistrationImportService::class)->importFamilies($refreshBatch, $refreshPath, 'csv');
        @unlink($refreshPath);

        $family->refresh();
        $this->assertSame('Updated Head', $family->head_name);
        $this->assertSame('9000000002', $family->head_mobile);
        $this->assertSame($originalUser->id, $family->registered_by);
        $this->assertTrue($family->registered_at->equalTo($originalRegisteredAt));
    }

}
