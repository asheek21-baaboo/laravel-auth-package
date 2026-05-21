<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoUserSynchronizer;
use Illuminate\Support\Facades\Schema;

test('syncFromClaims creates sso user from jwt claims', function () {
    $claims = (object) [
        'sub' => 'uuid-1',
        'email' => 'jane@company.test',
        'name' => 'Jane Doe',
    ];

    $user = app(SsoUserSynchronizer::class)->syncFromClaims($claims);

    expect($user->id)->toBe('uuid-1')
        ->and($user->email)->toBe('jane@company.test')
        ->and($user->name)->toBe('Jane Doe');

    $this->assertDatabaseHas('users', [
        'id' => 'uuid-1',
        'email' => 'jane@company.test',
        'name' => 'Jane Doe',
    ]);
});

test('syncFromClaims uses email as name when name claim is missing', function () {
    $claims = (object) [
        'sub' => 'uuid-2',
        'email' => 'bob@company.test',
    ];

    $user = app(SsoUserSynchronizer::class)->syncFromClaims($claims);

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
    ];

    $user = app(SsoUserSynchronizer::class)->syncFromClaims($claims);

    expect($user->email)->toBe('no-name@company.test')
        ->and(array_key_exists('name', $user->getAttributes()))->toBeFalse();
});

test('syncFromClaims updates existing user on login', function () {
    SsoUser::query()->create([
        'id' => 'uuid-3',
        'email' => 'old@company.test',
        'name' => 'Old Name',
    ]);

    $claims = (object) [
        'sub' => 'uuid-3',
        'email' => 'new@company.test',
        'name' => 'New Name',
    ];

    $user = app(SsoUserSynchronizer::class)->syncFromClaims($claims);

    expect($user->email)->toBe('new@company.test')
        ->and($user->name)->toBe('New Name')
        ->and(SsoUser::query()->count())->toBe(1);
});
