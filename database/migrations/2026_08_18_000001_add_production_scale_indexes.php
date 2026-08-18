<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Query-path indexes for the 100k-family production target. PostgreSQL
        // CREATE INDEX IF NOT EXISTS keeps this safe on upgraded databases.
        DB::statement('CREATE INDEX IF NOT EXISTS families_center_registered_idx ON families (center_id, registered_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS families_center_society_status_idx ON families (center_id, society_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS family_members_family_status_age_idx ON family_members (family_id, status, age)');
        DB::statement('CREATE INDEX IF NOT EXISTS karyakars_center_status_name_idx ON karyakars (center_id, status, full_name)');
        DB::statement('CREATE INDEX IF NOT EXISTS groups_center_area_status_idx ON groups (center_id, sampark_area_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS targets_center_dates_status_idx ON targets (center_id, start_date, end_date, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS import_batches_center_status_created_idx ON import_batches (center_id, status, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_record_lookup_idx ON audit_logs (record_type, record_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS inactivity_recipient_status_idx ON inactivity_events (recipient_user_id, status, triggered_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS group_karyakars_group_status_idx ON group_karyakars (group_id, status, karyakar_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS targets_group_status_dates_karyakar_idx ON targets (group_id, status, start_date, end_date, karyakar_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS home_visits_group_karyakar_completed_idx ON home_visits (group_id, karyakar_id, completed_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS families_center_registered_idx');
        DB::statement('DROP INDEX IF EXISTS families_center_society_status_idx');
        DB::statement('DROP INDEX IF EXISTS family_members_family_status_age_idx');
        DB::statement('DROP INDEX IF EXISTS karyakars_center_status_name_idx');
        DB::statement('DROP INDEX IF EXISTS groups_center_area_status_idx');
        DB::statement('DROP INDEX IF EXISTS targets_center_dates_status_idx');
        DB::statement('DROP INDEX IF EXISTS import_batches_center_status_created_idx');
        DB::statement('DROP INDEX IF EXISTS audit_logs_record_lookup_idx');
        DB::statement('DROP INDEX IF EXISTS inactivity_recipient_status_idx');
        DB::statement('DROP INDEX IF EXISTS group_karyakars_group_status_idx');
        DB::statement('DROP INDEX IF EXISTS targets_group_status_dates_karyakar_idx');
        DB::statement('DROP INDEX IF EXISTS home_visits_group_karyakar_completed_idx');
    }
};
