<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

afterEach(function () {
    JWT::$timestamp = null;
});

function validCallbackJwt(array $overrides = []): string
{
    JWT::$timestamp = 2_000_000_000;

    return TestJwt::encode(array_merge([
        'iss' => 'https://auth.test',
        'aud' => 'hr-portal',
        'jti' => 'test-jti-1',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ], $overrides));
}

test('GET /auth/callback returns 400 when code is missing', function () {
    $this->get('/auth/callback')
        ->assertStatus(400);
});

test('GET /auth/callback exchanges code, sets token cookie, and redirects', function () {
    $jwt = validCallbackJwt();
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $response = $this->get('/auth/callback?code=one-time-code');

    $response->assertRedirect('/');
    $response->assertCookie(CompanyAuth::TOKEN_COOKIE_NAME);
});

test('GET /auth/callback returns 403 when token aud does not match project', function () {
    $jwt = validCallbackJwt(['aud' => 'other-portal']);
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $this->get('/auth/callback?code=bad-aud')
        ->assertStatus(403);
});

test('GET /auth/callback returns 403 when IdP rejects the code', function () {
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(400, [], json_encode(['error' => 'invalid_grant'])),
    ]));

    $this->get('/auth/callback?code=expired-code')
        ->assertStatus(403);
});
