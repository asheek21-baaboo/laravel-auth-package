<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only rename the legacy package table when the app does not already have users.
        if (! Schema::hasTable('sso_users') || Schema::hasTable('users')) {
            return;
        }

        Schema::rename('sso_users', 'users');

        if (! Schema::hasColumn('users', 'password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('password')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentional no-op: rollback must not rename the application's users table.
    }
};
