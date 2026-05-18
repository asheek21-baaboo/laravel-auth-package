<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Services\CallbackJwtValidator;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger;
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
        $this->app->singleton(CallbackJwtValidator::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/company-auth.php' => config_path('company-auth.php'),
        ], 'company-auth-config');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('company.auth', AuthMiddleware::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/company-auth.php');
    }
}
