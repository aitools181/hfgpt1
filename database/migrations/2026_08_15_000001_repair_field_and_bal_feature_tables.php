<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Repair guard for deployments upgraded from early release candidates where
        // migration history could exist while one of the Phase 3/5 feature tables was absent.
        $createdInactivityEvents = false;
        if (! Schema::hasTable('inactivity_events')) {
            Schema::create('inactivity_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('center_id')->constrained()->cascadeOnDelete();
                $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
                $table->foreignId('karyakar_id')->constrained()->cascadeOnDelete();
                $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 20)->index();
                $table->unsignedSmallInteger('inactivity_days');
                $table->string('status', 20)->default('open')->index();
                $table->timestamp('activity_anchor_at');
                $table->timestamp('triggered_at');
                $table->timestamp('resolved_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['center_id', 'event_type', 'status']);
                $table->index(['group_id', 'karyakar_id', 'status']);
            });
            $createdInactivityEvents = true;
        }

        if ($createdInactivityEvents) {
            DB::statement("CREATE UNIQUE INDEX inactivity_one_open_type_idx ON inactivity_events (group_id, karyakar_id, event_type) WHERE status IN ('open','escalated')");
        }

        if (! Schema::hasTable('bal_group_sequences')) {
            Schema::create('bal_group_sequences', function (Blueprint $table): void {
                $table->foreignId('center_id')->primary()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bal_groups')) {
            Schema::create('bal_groups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('center_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
                $table->string('group_code')->unique();
                $table->foreignId('sanchalak_karyakar_id')->constrained('karyakars')->restrictOnDelete();
                $table->foreignId('sanchalak_user_id')->constrained('users')->restrictOnDelete();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->index(['center_id', 'status']);
                $table->index(['sanchalak_user_id', 'status']);
            });
        }

        if (! Schema::hasTable('bal_group_children')) {
            Schema::create('bal_group_children', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bal_group_id')->constrained('bal_groups')->cascadeOnDelete();
                $table->foreignId('family_member_id')->constrained('family_members')->restrictOnDelete();
                $table->unsignedTinyInteger('position');
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->unique(['bal_group_id', 'position']);
                $table->unique(['bal_group_id', 'family_member_id']);
                $table->index(['family_member_id', 'status']);
            });
        }

        if (! Schema::hasTable('bal_group_supervisors')) {
            Schema::create('bal_group_supervisors', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bal_group_id')->constrained('bal_groups')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->string('role_slug', 30)->index();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->unique(['bal_group_id', 'user_id', 'role_slug'], 'bal_supervisor_unique');
            });
        }

        if (! Schema::hasTable('bal_completion_reports')) {
            Schema::create('bal_completion_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('center_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bal_group_id')->constrained('bal_groups')->cascadeOnDelete();
                $table->foreignId('sanchalak_karyakar_id')->constrained('karyakars')->restrictOnDelete();
                $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('families_visited')->default(1);
                $table->unsignedInteger('families_completed')->default(1);
                $table->string('mobile', 30)->nullable();
                $table->string('family_name')->nullable();
                $table->text('family_details')->nullable();
                $table->date('completion_date');
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['center_id', 'completion_date']);
                $table->index(['bal_group_id', 'completion_date']);
                $table->index(['sanchalak_karyakar_id', 'completion_date']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op. This migration repairs potentially pre-existing production
        // schema and must never drop valid operational tables during a rollback.
    }
};
