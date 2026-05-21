<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ENSURE_USERS_MIGRATION = 'database/migrations/2025_05_19_000001_ensure_users_table_for_company_auth.php';

const RENAME_SSO_USERS_MIGRATION = 'database/migrations/2025_05_20_000001_rename_sso_users_table_to_users.php';

function resetUsersSchemaForMigrationTest(): void
{
    Schema::dropIfExists('sso_users');
    Schema::dropIfExists('users');

    DB::table('migrations')
        ->whereIn('migration', [
            '2025_05_19_000001_ensure_users_table_for_company_auth',
            '2025_05_20_000001_rename_sso_users_table_to_users',
        ])
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

test('rename migration does not run when application already has users table', function () {
    resetUsersSchemaForMigrationTest();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });

    Schema::create('sso_users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->timestamps();
    });

    runPackageMigration(RENAME_SSO_USERS_MIGRATION);

    expect(Schema::hasTable('sso_users'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();
});
