<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\Tests\Support\TestJwt;
use Baaboo\InternalToolComposerAuthPackage\TokenValidator;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

afterEach(function () {
    JWT::$timestamp = null;
});

function makeValidator(MockHandler $mock, int $cacheTtl = 3600): TokenValidator
{
    $handler = HandlerStack::create($mock);

    return new TokenValidator(
        cache: new Repository(new ArrayStore),
        idpUrl: 'https://auth.test',
        cacheTtl: $cacheTtl,
        httpClient: new Client(['handler' => $handler]),
    );
}

test('throws InvalidTokenException::expired() when token exp is in the past', function () {
    JWT::$timestamp = 2_000_000_000;
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
    ]);
    $validator = makeValidator($mock);
    $token = TestJwt::encode(['exp' => 1_999_999_000]);

    expect(fn () => $validator->validate($token))
        ->toThrow(InvalidTokenException::class, 'Token has expired.');
});

test('throws InvalidTokenException::invalidSignature() when token is tampered', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
    ]);
    $validator = makeValidator($mock);
    $token = TestJwt::encode();
    $token = substr($token, 0, -3).'xxx';

    expect(fn () => $validator->validate($token))
        ->toThrow(InvalidTokenException::class, 'Token signature is invalid.');
});

test('throws InvalidTokenException::malformed() when token is not a valid JWT', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
    ]);
    $validator = makeValidator($mock);

    expect(fn () => $validator->validate('not-a-jwt'))
        ->toThrow(InvalidTokenException::class, 'Token is malformed.');
});

test('throws InvalidTokenException::unresolvableKey() when JWKS endpoint is unreachable', function () {
    $mock = new MockHandler([]);
    $validator = makeValidator($mock);

    expect(fn () => $validator->validate(TestJwt::encode()))
        ->toThrow(InvalidTokenException::class, 'Could not fetch or parse the IdP public key.');
});

test('returns decoded stdClass with correct claims on a valid token', function () {
    JWT::$timestamp = 2_000_000_000;
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
    ]);
    $validator = makeValidator($mock);
    $token = TestJwt::encode([
        'sub' => 'uuid-1',
        'email' => 'a@b.test',
        'global_role' => 'staff',
        'project_id' => 'p1',
        'project_role' => 'viewer',
        'iat' => 2_000_000_000,
        'exp' => 2_000_000_900,
    ]);

    $claims = $validator->validate($token);

    expect($claims->sub)->toBe('uuid-1')
        ->and($claims->email)->toBe('a@b.test')
        ->and($claims->global_role)->toBe('staff')
        ->and($claims->project_id)->toBe('p1')
        ->and($claims->project_role)->toBe('viewer');
});

test('caches the public key after first fetch (HTTP client called only once across two calls)', function () {
    JWT::$timestamp = 2_000_000_000;
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
        new Response(500, [], 'should not be reached'),
    ]);
    $validator = makeValidator($mock, cacheTtl: 3600);
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $validator->validate($token);
    $validator->validate($token);

    expect($mock->count())->toBe(1);
});

test('forgetCachedKey() causes the next validate() call to re-fetch the key', function () {
    JWT::$timestamp = 2_000_000_000;
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(TestJwt::jwks())),
    ]);
    $validator = makeValidator($mock, cacheTtl: 3600);
    $token = TestJwt::encode(['iat' => 2_000_000_000, 'exp' => 2_000_000_900]);

    $validator->validate($token);
    $validator->forgetCachedKey();
    $validator->validate($token);

    expect($mock->count())->toBe(0);
});
