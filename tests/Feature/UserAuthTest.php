<?php

declare(strict_types=1);

use App\Models\User;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;

afterEach(function () {
    JWT::$timestamp = null;
});

test('Auth::guard(sso)->user() is set after company.auth with existing user', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser(
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

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->id)->toBe('probe-subject')
        ->and($user->email)->toBe('probe@company.test')
        ->and($user->name)->toBe('Probe User');
});

test('redirects to error page when jwt is valid but user row is missing', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $token = TestJwt::encode([
        'sub' => 'never-logged-in',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)
        ->getJson('/__auth_probe')
        ->assertRedirect(route('company-auth.error', ['stub' => 'sign_in_failed']));
});
