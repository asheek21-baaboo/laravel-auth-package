<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoUserSynchronizer;

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

    $this->assertDatabaseHas('sso_users', [
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
