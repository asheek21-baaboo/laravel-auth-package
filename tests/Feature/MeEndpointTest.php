<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;

afterEach(function () {
    JWT::$timestamp = null;
});

test('GET /me redirects to unauthenticated error page when no token', function () {
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());

    $this->getJson('/me')
        ->assertRedirect(route('company-auth.error', ['stub' => 'unauthenticated']));
});

test('GET /me returns 200 with correct shape when authenticated', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode([
        'email' => 'jane@company.test',
        'project_role' => 'manager',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)
        ->getJson('/me')
        ->assertOk()
        ->assertJsonPath('name', 'jane@company.test')
        ->assertJsonPath('role', 'manager')
        ->assertJsonPath('permissions', []);
});

test('GET /me response contains name, role, and permissions keys', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $json = $this->withToken($token)->getJson('/me')->json();

    expect($json)->toHaveKeys(['name', 'role', 'permissions']);
});

test('GET /me returns permissions as ["*"] when project_role is admin', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode([
        'project_role' => 'admin',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $this->withToken($token)
        ->getJson('/me')
        ->assertOk()
        ->assertJsonPath('role', 'admin')
        ->assertJsonPath('permissions', ['*']);
});
