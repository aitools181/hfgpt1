<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_sequences', function (Blueprint $table): void {
            $table->foreignId('center_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_code')->unique();
            $table->string('group_type', 30)->index();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'status']);
        });

        Schema::create('group_karyakars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('karyakar_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'position']);
            $table->unique(['group_id', 'karyakar_id']);
            $table->index(['karyakar_id', 'status']);
        });

        Schema::create('group_family_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('assignment_type', 20)->index();
            $table->string('assignment_source', 30)->default('admin')->index();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('transferred_to_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->text('change_note')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status', 'assignment_type']);
            $table->index(['family_id', 'status']);
        });

        // Prevent one Sankalp Family from being assigned to more than one active Group.
        DB::statement("CREATE UNIQUE INDEX group_family_one_active_idx ON group_family_assignments (family_id) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX group_family_active_slot_idx ON group_family_assignments (group_id, slot_number) WHERE status = 'active'");

        Schema::create('remaining_family_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karyakar_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->text('note')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status']);
        });

        Schema::table('karyakars', function (Blueprint $table): void {
            $table->foreignId('sampark_area_id')->nullable()->after('center_id')->constrained()->nullOnDelete();
            $table->foreignId('society_id')->nullable()->after('sampark_area_id')->constrained()->nullOnDelete();
            $table->unique('user_id', 'karyakars_user_id_unique');
        });

        Schema::create('targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('karyakar_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sampark_area_id')->constrained()->restrictOnDelete();
            $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('target_quantity');
            $table->unsignedInteger('completed_quantity')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['center_id', 'group_id', 'status']);
            $table->index(['karyakar_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
        Schema::dropIfExists('remaining_family_reports');
        Schema::table('karyakars', function (Blueprint $table): void {
            $table->dropUnique('karyakars_user_id_unique');
            $table->dropConstrainedForeignId('society_id');
            $table->dropConstrainedForeignId('sampark_area_id');
        });
        DB::statement('DROP INDEX IF EXISTS group_family_active_slot_idx');
        DB::statement('DROP INDEX IF EXISTS group_family_one_active_idx');
        Schema::dropIfExists('group_family_assignments');
        Schema::dropIfExists('group_karyakars');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('group_sequences');
    }
};
