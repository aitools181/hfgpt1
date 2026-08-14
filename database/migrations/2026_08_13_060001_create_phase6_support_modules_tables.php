<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('audience', 30)->default('all')->index();
            $table->string('status', 20)->default('published')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['center_id', 'status', 'published_at']);
        });

        Schema::create('family_time_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('audience', 30)->default('all')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['center_id', 'starts_at', 'status']);
        });

        Schema::create('family_time_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_time_schedule_id')->nullable()->constrained('family_time_schedules')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->date('completed_on');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['family_time_schedule_id', 'user_id', 'completed_on'], 'family_time_completion_unique');
            $table->index(['center_id', 'completed_on']);
        });

        Schema::create('shared_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('content_type', 30)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('file_path')->nullable();
            $table->string('audience', 30)->default('all')->index();
            $table->string('status', 20)->default('published')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['content_type', 'status', 'published_at']);
            $table->index(['center_id', 'status']);
        });

        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('display_name');
            $table->string('designation')->nullable();
            $table->text('message');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'status']);
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('unit', 40)->default('pcs');
            $table->integer('current_stock')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['center_id', 'sku']);
            $table->index(['center_id', 'status']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 20)->index();
            $table->unsignedInteger('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['center_id', 'recorded_at']);
            $table->index(['inventory_item_id', 'recorded_at']);
        });

        Schema::create('sticky_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('category', 40)->default('general')->index();
            $table->text('message');
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 20)->default('open')->index();
            $table->text('response_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('sticky_notes');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('shared_contents');
        Schema::dropIfExists('family_time_completions');
        Schema::dropIfExists('family_time_schedules');
        Schema::dropIfExists('announcements');
    }
};
