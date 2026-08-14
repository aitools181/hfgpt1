<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('home_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('group_family_assignment_id')->constrained('group_family_assignments')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karyakar_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('message_delivered')->default(true);
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_admin_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->unique('group_family_assignment_id');
            $table->index(['center_id', 'completed_at']);
            $table->index(['group_id', 'completed_at']);
            $table->index(['karyakar_id', 'completed_at']);
            $table->index(['target_id', 'completed_at']);
        });

        Schema::create('karyakar_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('karyakar_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('milestone');
            $table->string('badge_key', 40);
            $table->timestamp('awarded_at');
            $table->foreignId('trigger_home_visit_id')->nullable()->constrained('home_visits')->nullOnDelete();
            $table->timestamps();
            $table->unique(['karyakar_id', 'milestone']);
            $table->index(['karyakar_id', 'awarded_at']);
        });

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

        DB::statement("CREATE UNIQUE INDEX inactivity_one_open_type_idx ON inactivity_events (group_id, karyakar_id, event_type) WHERE status IN ('open','escalated')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inactivity_one_open_type_idx');
        Schema::dropIfExists('inactivity_events');
        Schema::dropIfExists('karyakar_badges');
        Schema::dropIfExists('home_visits');
    }
};
