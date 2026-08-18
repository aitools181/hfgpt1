<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            throw new \RuntimeException('Authentication repair cannot continue because the users table is missing. Restore the database or run the foundational migrations.');
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->index();
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->rememberToken();
            }
            if (! Schema::hasColumn('users', 'session_version')) {
                $table->unsignedInteger('session_version')->default(1);
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }
        });

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('module', 30)->default('main');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('module', 50)->default('main');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table): void {
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table): void {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'role_id', 'zone_id', 'center_id'], 'user_role_scope_unique');
                $table->index(['user_id', 'is_primary']);
            });
        } else {
            Schema::table('user_roles', function (Blueprint $table): void {
                if (! Schema::hasColumn('user_roles', 'zone_id')) {
                    $table->unsignedBigInteger('zone_id')->nullable();
                }
                if (! Schema::hasColumn('user_roles', 'center_id')) {
                    $table->unsignedBigInteger('center_id')->nullable();
                }
                if (! Schema::hasColumn('user_roles', 'is_primary')) {
                    $table->boolean('is_primary')->default(false);
                }
                if (! Schema::hasColumn('user_roles', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('user_roles', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('user_name')->nullable();
                $table->string('user_role')->nullable();
                $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
                $table->string('module', 80);
                $table->string('action', 80);
                $table->string('record_type')->nullable();
                $table->string('record_id')->nullable();
                $table->string('record_reference')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['module', 'action', 'created_at']);
                $table->index(['center_id', 'created_at']);
                $table->index(['zone_id', 'created_at']);
            });
        } else {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $columns = [
                    'user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('user_id')->nullable(),
                    'user_name' => fn (Blueprint $t) => $t->string('user_name')->nullable(),
                    'user_role' => fn (Blueprint $t) => $t->string('user_role')->nullable(),
                    'zone_id' => fn (Blueprint $t) => $t->unsignedBigInteger('zone_id')->nullable(),
                    'center_id' => fn (Blueprint $t) => $t->unsignedBigInteger('center_id')->nullable(),
                    'module' => fn (Blueprint $t) => $t->string('module', 80)->default('unknown'),
                    'action' => fn (Blueprint $t) => $t->string('action', 80)->default('unknown'),
                    'record_type' => fn (Blueprint $t) => $t->string('record_type')->nullable(),
                    'record_id' => fn (Blueprint $t) => $t->string('record_id')->nullable(),
                    'record_reference' => fn (Blueprint $t) => $t->string('record_reference')->nullable(),
                    'old_values' => fn (Blueprint $t) => $t->json('old_values')->nullable(),
                    'new_values' => fn (Blueprint $t) => $t->json('new_values')->nullable(),
                    'reason' => fn (Blueprint $t) => $t->text('reason')->nullable(),
                    'ip_address' => fn (Blueprint $t) => $t->string('ip_address', 45)->nullable(),
                    'user_agent' => fn (Blueprint $t) => $t->string('user_agent', 500)->nullable(),
                    'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
                ];
                foreach ($columns as $name => $add) {
                    if (! Schema::hasColumn('audit_logs', $name)) {
                        $add($table);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // This is an idempotent production-repair migration. Reverting it could
        // remove identity/audit data and is intentionally a no-op.
    }
};
