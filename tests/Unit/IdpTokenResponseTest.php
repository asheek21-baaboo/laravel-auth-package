<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Data\IdpTokenResponse;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\CodeExchangeException;

test('fromArray builds dto when all required fields are present', function () {
    $response = IdpTokenResponse::fromArray([
        'access_token' => 'jwt-from-idp',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]);

    expect($response->accessToken)->toBe('jwt-from-idp')
        ->and($response->expiresIn)->toBe(3600)
        ->and($response->tokenType)->toBe('Bearer');
});

test('fromArray accepts numeric string expires_in', function () {
    $response = IdpTokenResponse::fromArray([
        'access_token' => 'jwt',
        'expires_in' => '7200',
        'token_type' => 'Bearer',
    ]);

    expect($response->expiresIn)->toBe(7200);
});

test('fromArray throws invalidResponse when access_token is missing', function () {
    expect(fn () => IdpTokenResponse::fromArray([
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]))->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});

test('fromArray throws invalidResponse when expires_in is missing', function () {
    expect(fn () => IdpTokenResponse::fromArray([
        'access_token' => 'jwt',
        'token_type' => 'Bearer',
    ]))->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});

test('fromArray throws invalidResponse when token_type is missing', function () {
    expect(fn () => IdpTokenResponse::fromArray([
        'access_token' => 'jwt',
        'expires_in' => 3600,
    ]))->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});

test('fromArray throws invalidResponse when expires_in is zero', function () {
    expect(fn () => IdpTokenResponse::fromArray([
        'access_token' => 'jwt',
        'expires_in' => 0,
        'token_type' => 'Bearer',
    ]))->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});
