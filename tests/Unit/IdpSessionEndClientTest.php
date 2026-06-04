<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpSessionEndClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function makeIdpSessionEndClient(MockHandler $mock): IdpSessionEndClient
{
    $handler = HandlerStack::create($mock);

    return new IdpSessionEndClient(httpClient: new Client(['handler' => $handler]));
}

test('endSession posts to IdP session end with Bearer access token', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(204),
    ]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $client = new IdpSessionEndClient(httpClient: new Client(['handler' => $handler]));

    $client->endSession('user-jwt-token');

    expect($history)->toHaveCount(1);
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe(CompanyAuth::idpUrl().CompanyAuth::OAUTH_SESSION_END_PATH)
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer user-jwt-token');
});

test('endSession ignores empty token', function () {
    $history = [];
    $mock = new MockHandler([]);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));
    $client = new IdpSessionEndClient(httpClient: new Client(['handler' => $handler]));

    $client->endSession('   ');

    expect($history)->toHaveCount(0);
});

test('endSession does not throw when IdP returns an error status', function () {
    $client = makeIdpSessionEndClient(new MockHandler([
        new Response(401),
    ]));

    $client->endSession('expired-jwt');
})->throwsNoExceptions();

test('endSession does not throw when IdP is unreachable', function () {
    $mock = new MockHandler([
        function () {
            throw new ConnectException(
                'Connection refused',
                new Request('POST', 'https://auth.test/oauth/session/end'),
            );
        },
    ]);

    makeIdpSessionEndClient($mock)->endSession('user-jwt');
})->throwsNoExceptions();
