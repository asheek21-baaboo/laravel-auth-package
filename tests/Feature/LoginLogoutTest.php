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

test('GET /login redirects to IdP authorize URL with project and callback params', function () {
    $response = $this->get('/login');

    $response->assertRedirect();
    $target = $response->headers->get('Location');
    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    expect($target)->toStartWith('https://auth.test/oauth/authorize?')
        ->and($query['client_id'])->toBe('hr-portal')
        ->and($query['response_type'])->toBe('code')
        ->and($query['project_id'])->toBe('hr-portal')
        ->and($query['redirect_uri'])->toBe(route('company-auth.callback'))
        ->and($query['state'])->toHaveLength(40);
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

test('POST /logout calls IdP session end with Bearer token and redirects to login', function () {
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

    $response->assertRedirect('/login');
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
        ->assertRedirect('/login')
        ->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);

    expect($history)->toHaveCount(0);
});

test('POST /logout redirects to error page when IdP logout is disabled', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['company-auth.redirect_to_idp_logout' => false]);

    $this->post('/logout')
        ->assertRedirect(route('company-auth.error', ['stub' => 'logged_out']))
        ->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);
});
