<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Tests;

use Baaboo\InternalToolComposerAuthPackage\AuthServiceProvider;
use Baaboo\InternalToolComposerAuthPackage\Facades\CurrentUser;
use Baaboo\InternalToolComposerAuthPackage\TokenValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  array<int, Response>  $extraResponses
     */
    protected function swapTokenValidatorWithJwks(array $jwks, int $cacheTtl = 3600, array $extraResponses = []): TokenValidator
    {
        $queue = [
            new Response(200, ['Content-Type' => 'application/json'], json_encode($jwks)),
            ...$extraResponses,
        ];

        $handler = HandlerStack::create(new MockHandler($queue));
        $client = new Client(['handler' => $handler]);

        $validator = new TokenValidator(
            idpUrl: 'https://auth.test',
            cacheTtl: $cacheTtl,
            cache: new Repository(new ArrayStore),
            jwksPath: '/.well-known/jwks.json',
            httpClient: $client,
        );

        $this->app->instance(TokenValidator::class, $validator);

        return $validator;
    }

    protected function getPackageProviders($app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'CurrentUser' => CurrentUser::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('company-auth.idp_url', 'https://auth.test');
        $app['config']->set('company-auth.cache_ttl', 3600);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('company.auth')
            ->get('/__auth_probe', fn () => response()->json([
                'ok' => true,
            ]));
    }
}
