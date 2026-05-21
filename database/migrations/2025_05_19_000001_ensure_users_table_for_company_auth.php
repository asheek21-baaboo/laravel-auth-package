<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('email');
                $table->string('name')->nullable();
                $table->string('password')->nullable();
                $table->timestamps();
            });

            return;
        }

        // Pre-existing application table: only add a nullable password column when absent.
        // Never rename, drop, or change() any existing column (avoids doctrine/dbal and schema drift).
        if (! Schema::hasColumn('users', 'password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('password')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentional no-op: cannot safely reverse changes on a pre-existing users table.
    }
};
