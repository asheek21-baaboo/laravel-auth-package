<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;

afterEach(function () {
    JWT::$timestamp = null;
});

test('Auth::guard(sso)->user() is set after company.auth with existing sso user', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedSsoUser(
        id: 'probe-subject',
        email: 'probe@company.test',
        name: 'Probe User',
    );

    $token = TestJwt::encode([
        'sub' => 'probe-subject',
        'email' => 'probe@company.test',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)->getJson('/__auth_probe')->assertOk();

    $user = Auth::guard('sso')->user();

    expect($user)->toBeInstanceOf(SsoUser::class)
        ->and($user->id)->toBe('probe-subject')
        ->and($user->email)->toBe('probe@company.test')
        ->and($user->name)->toBe('Probe User');
});

test('returns 401 when jwt is valid but sso user row is missing', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $token = TestJwt::encode([
        'sub' => 'never-logged-in',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)
        ->getJson('/__auth_probe')
        ->assertStatus(401)
        ->assertJsonFragment(['message' => 'User profile not found. Please sign in again via SSO.']);
});
