<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\CodeExchangeException;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger;

function validIdpTokenJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'access_token' => 'jwt-from-idp',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ], $overrides));
}
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function makeIdpTokenExchanger(MockHandler $mock): IdpTokenExchanger
{
    $handler = HandlerStack::create($mock);

    return new IdpTokenExchanger(httpClient: new Client(['handler' => $handler]));
}

test('exchange returns access_token from IdP response', function () {
    $exchanger = makeIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], validIdpTokenJson()),
    ]));

    $token = $exchanger->exchange('auth-code-123', 'https://hr.test/auth/callback');

    expect($token)->toBe('jwt-from-idp');
});

test('exchange posts authorization_code payload to the IdP token endpoint', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], validIdpTokenJson(['access_token' => 'ok'])),
    ]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $exchanger = new IdpTokenExchanger(httpClient: new Client(['handler' => $handler]));

    config(['company-auth.client_id' => 'explicit-client']);

    $exchanger->exchange('my-code', 'https://hr.test/auth/callback');

    expect($history)->toHaveCount(1);
    $request = $history[0]['request'];
    expect((string) $request->getUri())->toBe(CompanyAuth::idpUrl().CompanyAuth::TOKEN_EXCHANGE_PATH);
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'grant_type' => 'authorization_code',
        'code' => 'my-code',
        'redirect_uri' => 'https://hr.test/auth/callback',
        'client_id' => 'explicit-client',
        'client_secret' => 'test-client-secret',
        'project_id' => 'hr-portal',
    ]);
});

test('exchange uses project_id as client_id when client_id is not configured', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], validIdpTokenJson(['access_token' => 'ok'])),
    ]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $exchanger = new IdpTokenExchanger(httpClient: new Client(['handler' => $handler]));

    config(['company-auth.client_id' => null]);

    $exchanger->exchange('code', 'https://hr.test/callback');

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['client_id'])->toBe('hr-portal');
});

test('exchange throws idpRejected when project_id is not configured', function () {
    config(['company-auth.project_id' => null]);
    $exchanger = makeIdpTokenExchanger(new MockHandler);

    expect(fn () => $exchanger->exchange('code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'APP_PROJECT_ID is not configured.');
});

test('exchange throws idpRejected when client_secret is not configured', function () {
    config(['company-auth.client_secret' => null]);
    $exchanger = makeIdpTokenExchanger(new MockHandler);

    expect(fn () => $exchanger->exchange('code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'COMPANY_AUTH_CLIENT_SECRET is not configured.');
});

test('exchange throws idpRejected when IdP returns an error status', function () {
    $exchanger = makeIdpTokenExchanger(new MockHandler([
        new Response(400, [], json_encode(['error' => 'invalid_grant'])),
    ]));

    expect(fn () => $exchanger->exchange('expired-code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'Authorization code could not be exchanged.');
});

test('exchange throws invalidResponse when IdP body is not JSON', function () {
    $exchanger = makeIdpTokenExchanger(new MockHandler([
        new Response(200, [], 'not-json'),
    ]));

    expect(fn () => $exchanger->exchange('code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});

test('exchange throws invalidResponse when IdP body has no access_token', function () {
    $exchanger = makeIdpTokenExchanger(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])),
    ]));

    expect(fn () => $exchanger->exchange('code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'IdP token response was invalid.');
});

test('exchange throws transportFailed when IdP is unreachable', function () {
    $mock = new MockHandler([
        function () {
            throw new ConnectException(
                'Connection refused',
                new Request('POST', 'https://auth.test/oauth/token'),
            );
        },
    ]);
    $exchanger = makeIdpTokenExchanger($mock);

    expect(fn () => $exchanger->exchange('code', 'https://hr.test/callback'))
        ->toThrow(CodeExchangeException::class, 'Could not reach the IdP token endpoint.');
});
