<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->index();
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->string('status', 30)->default('processing')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sampark_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->string('city_village')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['center_id', 'name']);
            $table->unique(['center_id', 'external_code']);
        });

        Schema::create('societies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['center_id', 'name']);
            $table->unique(['center_id', 'external_code']);
        });

        Schema::create('families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sampark_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('society_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_family_id')->nullable();
            $table->string('manual_reference')->nullable()->unique();
            $table->string('source', 20)->default('manual')->index();
            $table->string('head_name');
            $table->string('head_mobile', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city_village')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['center_id', 'external_family_id']);
            $table->index(['center_id', 'source', 'status']);
        });

        Schema::create('family_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('external_member_id')->nullable();
            $table->string('name');
            $table->string('gender', 10)->nullable()->index();
            $table->unsignedTinyInteger('age')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('relationship')->nullable();
            $table->boolean('is_head')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['family_id', 'external_member_id']);
            $table->index(['family_id', 'gender']);
        });

        Schema::create('karyakars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('karyakar_reference')->unique();
            $table->string('source', 20)->default('manual')->index();
            $table->string('full_name');
            $table->string('gender', 10)->index();
            $table->unsignedTinyInteger('age');
            $table->string('category', 50)->index();
            $table->string('mobile', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('preferred_area')->nullable();
            $table->text('experience_notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->unique(['center_id', 'family_member_id']);
            $table->index(['center_id', 'gender', 'category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyakars');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('families');
        Schema::dropIfExists('societies');
        Schema::dropIfExists('sampark_areas');
        Schema::dropIfExists('import_batches');
    }
};
