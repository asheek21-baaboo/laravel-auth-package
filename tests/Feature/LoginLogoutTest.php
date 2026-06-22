<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpSessionEndClient;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

afterEach(function () {
    JWT::$timestamp = null;
});

test('GET /login redirects unauthenticated users to the unauthenticated error page', function () {
    $this->get('/login')
        ->assertRedirect(route('company-auth.error', ['stub' => 'unauthenticated']));
});

test('GET /login redirects authenticated users to redirect_after_login', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $this->withToken($token)
        ->get('/login')
        ->assertRedirect('/');
});

test('POST /logout calls IdP session end with Bearer token and redirects to logged_out page', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $history = [];
    $mock = new MockHandler([
        new Response(204),
    ]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $this->app->instance(IdpSessionEndClient::class, new IdpSessionEndClient(
        httpClient: new Client(['handler' => $handler]),
    ));

    JWT::$timestamp = 2_000_000_000;
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $response = $this->withToken($token)->post('/logout');

    $response->assertRedirect(route('company-auth.error', ['stub' => 'logged_out']));
    $response->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);
    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer '.$token);
});

test('POST /logout skips IdP session end when no token is present', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $history = [];
    $mock = new MockHandler([]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $this->app->instance(IdpSessionEndClient::class, new IdpSessionEndClient(
        httpClient: new Client(['handler' => $handler]),
    ));

    $this->post('/logout')
        ->assertRedirect(route('company-auth.error', ['stub' => 'logged_out']))
        ->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);

    expect($history)->toHaveCount(0);
});

test('POST /logout skips IdP session end when IdP logout is disabled', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['company-auth.redirect_to_idp_logout' => false]);

    $this->post('/logout')
        ->assertRedirect(route('company-auth.error', ['stub' => 'logged_out']))
        ->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);
});
