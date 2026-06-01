<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ENSURE_USERS_MIGRATION = 'database/migrations/2025_05_19_000001_ensure_users_table_for_company_auth.php';

function resetUsersSchemaForMigrationTest(): void
{
    Schema::dropIfExists('users');

    DB::table('migrations')
        ->where('migration', '2025_05_19_000001_ensure_users_table_for_company_auth')
        ->delete();
}

function runPackageMigration(string $relativePath): void
{
    test()->artisan('migrate', [
        '--path' => $relativePath,
        '--realpath' => true,
    ])->assertSuccessful();
}

test('users migration leaves pre-existing users table unchanged except optional password column', function () {
    resetUsersSchemaForMigrationTest();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });

    runPackageMigration(ENSURE_USERS_MIGRATION);

    expect(Schema::hasColumn('users', 'password'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'name'))->toBeFalse();
});

test('users migration adds nullable password when column is missing on existing table', function () {
    resetUsersSchemaForMigrationTest();

    Schema::create('users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->timestamps();
    });

    runPackageMigration(ENSURE_USERS_MIGRATION);

    expect(Schema::hasColumn('users', 'password'))->toBeTrue();
});
