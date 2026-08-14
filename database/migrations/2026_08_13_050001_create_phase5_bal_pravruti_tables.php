<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bal_group_sequences', function (Blueprint $table): void {
            $table->foreignId('center_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

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

    public function down(): void
    {
        Schema::dropIfExists('bal_completion_reports');
        Schema::dropIfExists('bal_group_supervisors');
        Schema::dropIfExists('bal_group_children');
        Schema::dropIfExists('bal_groups');
        Schema::dropIfExists('bal_group_sequences');
    }
};
