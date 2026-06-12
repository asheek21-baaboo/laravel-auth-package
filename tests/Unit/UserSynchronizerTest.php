<?php

declare(strict_types=1);

use App\Models\User;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\UserNotProvisionedException;
use Baaboo\InternalToolComposerAuthPackage\Services\UserSynchronizer;
use Illuminate\Support\Facades\Schema;

test('syncFromClaims creates user from jwt claims', function () {
    $claims = (object) [
        'sub' => 'uuid-1',
        'email' => 'jane@company.test',
        'name' => 'Jane Doe',
        'createUser' => true,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->email)->toBe('jane@company.test')
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->id)->not->toBeEmpty();

    $this->assertDatabaseHas('users', [
        'email' => 'jane@company.test',
        'name' => 'Jane Doe',
    ]);
});

test('syncFromClaims uses email as name when name claim is missing', function () {
    $claims = (object) [
        'sub' => 'uuid-2',
        'email' => 'bob@company.test',
        'createUser' => true,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->name)->toBe('bob@company.test');
});

test('syncFromClaims does not set name when users table has no name column', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table): void {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->timestamps();
    });

    $claims = (object) [
        'sub' => 'uuid-4',
        'email' => 'no-name@company.test',
        'name' => 'Ignored',
        'createUser' => true,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->email)->toBe('no-name@company.test')
        ->and(array_key_exists('name', $user->getAttributes()))->toBeFalse();
});

test('syncFromClaims updates existing user matched by email without changing id', function () {
    User::query()->create([
        'id' => 'old-uuid',
        'email' => 'jonas.meyer@seed.local',
        'name' => 'Old Name',
    ]);

    $claims = (object) [
        'sub' => 'new-uuid-from-idp',
        'email' => 'jonas.meyer@seed.local',
        'name' => 'Jonas Meyer',
        'createUser' => true,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->id)->toBe('old-uuid')
        ->and($user->email)->toBe('jonas.meyer@seed.local')
        ->and($user->name)->toBe('Jonas Meyer')
        ->and(User::query()->count())->toBe(1);
});

test('syncFromClaims updates name for existing user on login', function () {
    User::query()->create([
        'id' => 'uuid-3',
        'email' => 'jane@company.test',
        'name' => 'Old Name',
    ]);

    $claims = (object) [
        'sub' => 'any-sub',
        'email' => 'jane@company.test',
        'name' => 'New Name',
        'createUser' => true,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->id)->toBe('uuid-3')
        ->and($user->email)->toBe('jane@company.test')
        ->and($user->name)->toBe('New Name')
        ->and(User::query()->count())->toBe(1);
});

test('syncFromClaims does not update existing user when createUser is false', function () {
    User::query()->create([
        'id' => 'uuid-5',
        'email' => 'unchanged@company.test',
        'name' => 'Unchanged',
    ]);

    $claims = (object) [
        'sub' => 'different-sub',
        'email' => 'unchanged@company.test',
        'name' => 'New Name',
        'createUser' => false,
    ];

    $user = app(UserSynchronizer::class)->syncFromClaims($claims);

    expect($user->email)->toBe('unchanged@company.test')
        ->and($user->name)->toBe('Unchanged');
});

test('syncFromClaims throws when createUser is false and user does not exist', function () {
    $claims = (object) [
        'sub' => 'uuid-missing',
        'email' => 'ghost@company.test',
        'createUser' => false,
    ];

    app(UserSynchronizer::class)->syncFromClaims($claims);
})->throws(UserNotProvisionedException::class);
