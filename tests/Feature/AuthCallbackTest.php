<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

test('GET /oauth/callback returns 403 when state is missing', function () {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'should-not-be-requested',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])),
    ]));
    $handler->push(Middleware::history($history));
    $this->app->instance(
        \Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger::class,
        new \Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger(
            httpClient: new Client(['handler' => $handler]),
        ),
    );

    $this->putOAuthStateInSession('expected-state');
    $this->get('/oauth/callback?code=one-time-code')
        ->assertStatus(403);

    expect($history)->toHaveCount(0);
});

test('GET /oauth/callback returns 403 when state does not match session', function () {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'should-not-be-requested',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])),
    ]));
    $handler->push(Middleware::history($history));
    $this->app->instance(
        \Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger::class,
        new \Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger(
            httpClient: new Client(['handler' => $handler]),
        ),
    );

    $this->putOAuthStateInSession('expected-state');
    $this->get('/oauth/callback?code=one-time-code&state=wrong-state')
        ->assertStatus(403);

    expect($history)->toHaveCount(0);
});

test('GET /oauth/callback returns 400 when code is missing', function () {
    $state = $this->putOAuthStateInSession();

    $this->get('/oauth/callback?state='.$state)
        ->assertStatus(400);
});

test('GET /oauth/callback exchanges code with valid state, sets token cookie, and redirects', function () {
    $jwt = validCallbackJwt();
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $loginResponse = $this->get('/login');
    parse_str((string) parse_url((string) $loginResponse->headers->get('Location'), PHP_URL_QUERY), $query);
    $state = $query['state'];

    $response = $this->get('/oauth/callback?code=one-time-code&state='.$state);

    $response->assertRedirect('/');
    $response->assertCookie(CompanyAuth::TOKEN_COOKIE_NAME);

    $this->assertDatabaseHas('users', [
        'id' => 'test-user-id',
        'email' => 'jane@company.test',
    ]);
});

test('GET /oauth/callback cannot reuse the same state twice', function () {
    $jwt = validCallbackJwt();
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $state = $this->putOAuthStateInSession();

    $this->get('/oauth/callback?code=first-code&state='.$state)
        ->assertRedirect('/');

    $this->get('/oauth/callback?code=second-code&state='.$state)
        ->assertStatus(403);
});

test('GET /oauth/callback returns 403 when token aud does not match project', function () {
    $jwt = validCallbackJwt(['aud' => 'other-portal']);
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $state = $this->putOAuthStateInSession();

    $this->get('/oauth/callback?code=bad-aud&state='.$state)
        ->assertStatus(403);
});

test('GET /oauth/callback redirects to error page when user is not provisioned', function () {
    $jwt = validCallbackJwt(['createUser' => false]);
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => CompanyAuth::ACCESS_TOKEN_TTL_SECONDS,
        ])),
    ]));

    $state = $this->putOAuthStateInSession();

    $this->get('/oauth/callback?code=one-time-code&state='.$state)
        ->assertRedirect(route('company-auth.error', ['stub' => 'user_not_provisioned']))
        ->assertCookieMissing(CompanyAuth::TOKEN_COOKIE_NAME);

    $this->assertDatabaseMissing('users', ['id' => 'test-user-id']);
});

test('GET /oauth/callback returns 403 when IdP rejects the code', function () {
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->swapIdpTokenExchanger(new MockHandler([
        new Response(400, [], json_encode(['error' => 'invalid_grant'])),
    ]));

    $state = $this->putOAuthStateInSession();

    $this->get('/oauth/callback?code=expired-code&state='.$state)
        ->assertStatus(403);
});
