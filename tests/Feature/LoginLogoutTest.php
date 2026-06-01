<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

afterEach(function () {
    JWT::$timestamp = null;
});

test('GET /login redirects to IdP authorize URL with project and callback params', function () {
    $response = $this->get('/login');

    $response->assertRedirect();
    $target = $response->headers->get('Location');
    expect($target)->toStartWith('https://auth.test/oauth/authorize?')
        ->and($target)->toContain('client_id=hr-portal')
        ->and($target)->toContain('response_type=code')
        ->and($target)->toContain('project_id=hr-portal')
        ->and($target)->toContain(urlencode(route('company-auth.callback')));
});

test('GET /login redirects authenticated users to redirect_after_login', function () {
    JWT::$timestamp = 2_000_000_000;
    $this->swapTokenValidatorWithJwks(TestJwt::jwks());
    $this->seedSsoUser();
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $this->withToken($token)
        ->get('/login')
        ->assertRedirect('/');
});

test('POST /logout clears token cookie and redirects to IdP logout by default', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $response = $this->post('/logout');

    $response->assertRedirect('https://auth.test/logout');
    $response->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);
});

test('POST /logout redirects to error page when IdP logout is disabled', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['company-auth.redirect_to_idp_logout' => false]);

    $this->post('/logout')
        ->assertRedirect(route('company-auth.error', ['stub' => 'logged_out']))
        ->assertCookieExpired(CompanyAuth::TOKEN_COOKIE_NAME);
});
