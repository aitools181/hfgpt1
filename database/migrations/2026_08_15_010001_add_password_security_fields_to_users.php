<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'session_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedInteger('session_version')->default(1)->after('last_login_at');
            });
        }

        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('password_changed_at')->nullable()->after('session_version');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('password_changed_at');
            });
        }

        if (Schema::hasColumn('users', 'session_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('session_version');
            });
        }
    }
};
