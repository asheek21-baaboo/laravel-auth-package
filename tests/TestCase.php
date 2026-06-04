<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Tests;

use App\Models\User;
use Baaboo\InternalToolComposerAuthPackage\AuthServiceProvider;
use Baaboo\InternalToolComposerAuthPackage\Facades\CurrentUser;
use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger;
use Baaboo\InternalToolComposerAuthPackage\Services\OAuthStateManager;
use Baaboo\InternalToolComposerAuthPackage\TokenValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

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
            cache: new Repository(new ArrayStore),
            idpUrl: 'https://auth.test',
            cacheTtl: $cacheTtl,
            httpClient: $client,
        );

        $this->app->instance(TokenValidator::class, $validator);

        return $validator;
    }

    protected function swapIdpTokenExchanger(MockHandler $mock): IdpTokenExchanger
    {
        $handler = HandlerStack::create($mock);
        $exchanger = new IdpTokenExchanger(
            httpClient: new Client(['handler' => $handler]),
        );

        $this->app->instance(IdpTokenExchanger::class, $exchanger);

        return $exchanger;
    }

    protected function putOAuthStateInSession(string $state = 'test-oauth-state-40-characters-long'): string
    {
        $this->startSession();
        session()->put(OAuthStateManager::SESSION_KEY, $state);

        return $state;
    }

    protected function seedUser(
        string $id = 'test-user-id',
        string $email = 'jane@company.test',
        ?string $name = 'Jane Doe',
    ): User {
        return User::query()->create([
            'id' => $id,
            'email' => $email,
            'name' => $name,
        ]);
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
        $app['env'] = 'local';
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://hr.test');
        $app['config']->set('company-auth.idp_url', 'https://auth.test');
        $app['config']->set('company-auth.project_id', 'hr-portal');
        $app['config']->set('company-auth.client_secret', 'test-client-secret');
        $app['config']->set('company-auth.redirect_after_login', '/');

        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'company.auth'])->group(function () use ($router): void {
            $router->get('/__auth_probe', fn () => response()->json(['ok' => true]));
            $router->get('/me', MeController::class)->name('company-auth.me');
        });
    }
}
