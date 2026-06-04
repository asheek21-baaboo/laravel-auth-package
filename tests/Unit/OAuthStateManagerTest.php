<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Services\OAuthStateManager;

test('issue stores a random state in the session', function () {
    $this->startSession();

    $state = app(OAuthStateManager::class)->issue();

    expect($state)->toHaveLength(40)
        ->and(session(OAuthStateManager::SESSION_KEY))->toBe($state);
});

test('validateAndConsume accepts matching state once', function () {
    $this->startSession();
    $manager = app(OAuthStateManager::class);
    $state = $manager->issue();

    expect($manager->validateAndConsume($state))->toBeTrue()
        ->and(session()->has(OAuthStateManager::SESSION_KEY))->toBeFalse();
});

test('validateAndConsume rejects missing or mismatched state', function () {
    $this->startSession();
    $manager = app(OAuthStateManager::class);
    $manager->issue();

    expect($manager->validateAndConsume('wrong-state'))->toBeFalse()
        ->and(session()->has(OAuthStateManager::SESSION_KEY))->toBeFalse();
});

test('validateAndConsume rejects replay after successful validation', function () {
    $this->startSession();
    $manager = app(OAuthStateManager::class);
    $state = $manager->issue();

    $manager->validateAndConsume($state);

    expect($manager->validateAndConsume($state))->toBeFalse();
});
