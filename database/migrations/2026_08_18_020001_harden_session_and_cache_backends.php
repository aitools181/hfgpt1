<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        } else {
            Schema::table('sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('sessions', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->index();
                }
                if (! Schema::hasColumn('sessions', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable();
                }
                if (! Schema::hasColumn('sessions', 'user_agent')) {
                    $table->text('user_agent')->nullable();
                }
                if (! Schema::hasColumn('sessions', 'payload')) {
                    $table->longText('payload')->nullable();
                }
                if (! Schema::hasColumn('sessions', 'last_activity')) {
                    $table->integer('last_activity')->default(0)->index();
                }
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        } else {
            Schema::table('cache', function (Blueprint $table): void {
                if (! Schema::hasColumn('cache', 'value')) {
                    $table->mediumText('value')->nullable();
                }
                if (! Schema::hasColumn('cache', 'expiration')) {
                    $table->integer('expiration')->default(0);
                }
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        } else {
            Schema::table('cache_locks', function (Blueprint $table): void {
                if (! Schema::hasColumn('cache_locks', 'owner')) {
                    $table->string('owner')->nullable();
                }
                if (! Schema::hasColumn('cache_locks', 'expiration')) {
                    $table->integer('expiration')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        // Production hardening migration: intentionally non-destructive.
    }
};
