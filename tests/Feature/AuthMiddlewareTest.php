<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CurrentUserService;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;

afterEach(function () {
    JWT::$timestamp = null;
});

test('redirects to unauthenticated error page when no token is present', function () {
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());

    $this->getJson('/__auth_probe')
        ->assertRedirect(route('company-auth.error', ['stub' => 'unauthenticated']));
});

test('returns 401 with message when token is expired (JSON request)', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $token = TestJwt::encode(['iat' => 1_999_999_000, 'exp' => 1_999_999_100]);

    $this->withToken($token)
        ->getJson('/__auth_probe')
        ->assertStatus(401)
        ->assertJsonFragment(['message' => 'Token has expired.']);
});

test('redirects to token-expired page when token is expired (browser request)', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $token = TestJwt::encode(['iat' => 1_999_999_000, 'exp' => 1_999_999_100]);

    $this->withHeaders(['Accept' => 'text/html'])
        ->withCookie('token', $token)
        ->withCredentials()
        ->get('/__auth_probe')
        ->assertRedirect(route('company-auth.token-expired'));
});

test('returns 401 with message when token signature is invalid', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);
    $token = substr($token, 0, -4).'xxxx';

    $this->withToken($token)
        ->getJson('/__auth_probe')
        ->assertStatus(401)
        ->assertJsonFragment(['message' => 'Token signature is invalid.']);
});

test('proceeds to next middleware when token is valid', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $this->withToken($token)
        ->getJson('/__auth_probe')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('extracts token from Authorization: Bearer header', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/__auth_probe')
        ->assertOk();
});

test('extracts token from cookie named token', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $this->withCookie('token', $token)
        ->withCredentials()
        ->getJson('/__auth_probe')
        ->assertOk();
});

test('populates CurrentUserService with claims after valid token', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser(
        id: 'probe-subject',
        email: 'probe@company.test',
        name: 'Probe',
    );
    $token = TestJwt::encode([
        'sub' => 'probe-subject',
        'email' => 'probe@company.test',
        'global_role' => 'staff',
        'aud' => 'tool-x',
        'project_role' => 'editor',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)->getJson('/__auth_probe')->assertOk();

    $user = app(CurrentUserService::class);

    expect($user->id())->toBe('probe-subject')
        ->and($user->email())->toBe('probe@company.test')
        ->and($user->globalRole())->toBe('staff')
        ->and($user->projectId())->toBe('tool-x')
        ->and($user->role())->toBe('editor');
});
