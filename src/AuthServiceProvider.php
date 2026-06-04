<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Auth\SsoJwtGuard;
use Baaboo\InternalToolComposerAuthPackage\Services\CallbackJwtValidator;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpSessionEndClient;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger;
use Baaboo\InternalToolComposerAuthPackage\Services\OAuthStateManager;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoAuthorizationUrlBuilder;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoRequestAuthenticator;
use Baaboo\InternalToolComposerAuthPackage\Services\UserSynchronizer;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenExtractor;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/company-auth.php', 'company-auth');

        $this->app->singleton(TokenValidator::class, function ($app) {
            return new TokenValidator(
                cache: $app['cache']->store(),
                idpUrl: CompanyAuth::idpUrl(),
            );
        });

        $this->app->singleton(CurrentUserService::class);
        $this->app->singleton(IdpTokenExchanger::class);
        $this->app->singleton(IdpSessionEndClient::class);
        $this->app->singleton(OAuthStateManager::class);
        $this->app->singleton(CallbackJwtValidator::class);
        $this->app->singleton(UserSynchronizer::class);
        $this->app->singleton(SsoRequestAuthenticator::class);
        $this->app->singleton(SsoAuthorizationUrlBuilder::class);
        $this->app->singleton(TokenExtractor::class);

        $this->registerSsoAuthGuard();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/company-auth.php' => config_path('company-auth.php'),
        ], 'company-auth-config');

        $migration = '2025_05_19_000001_ensure_users_table_for_company_auth.php';
        $this->publishes([
            __DIR__.'/../database/migrations/'.$migration => database_path('migrations/'.$migration),
        ], 'company-auth-migrations');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('company.auth', AuthMiddleware::class);
        $router->aliasMiddleware('company.guest', GuestMiddleware::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/company-auth.php');
    }

    private function registerSsoAuthGuard(): void
    {
        $this->app->booted(function (): void {
            $auth = $this->app->make(AuthFactory::class);

            $auth->extend('sso-jwt', function ($app, string $name, array $config) use ($auth) {
                $provider = $auth->createUserProvider($config['provider'] ?? null);

                return new SsoJwtGuard($provider);
            });

            $this->app['config']->set('auth.guards.'.CompanyAuth::SSO_GUARD, [
                'driver' => 'sso-jwt',
                'provider' => CompanyAuth::USER_PROVIDER,
            ]);
        });
    }
}
