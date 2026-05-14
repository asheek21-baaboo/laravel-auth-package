<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/company-auth.php', 'company-auth');

        // Bind TokenValidator as a singleton — we want one instance
        // managing the cached public key for the entire request lifecycle
        $this->app->singleton(TokenValidator::class, function ($app) {
            return new TokenValidator(
                idpUrl: config('company-auth.idp_url'),
                cacheTtl: config('company-auth.cache_ttl', 3600),
                cache: $app['cache']->store(),
                jwksPath: config('company-auth.jwks_path'),
            );
        });

        // Bind CurrentUserService as a singleton scoped to the request
        $this->app->singleton(CurrentUserService::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/company-auth.php' => config_path('company-auth.php')], 'company-auth-config');

        // Register the middleware alias so projects can use:
        // Route::middleware('company.auth')
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('company.auth', AuthMiddleware::class);

        // Auto-register the /me route
        // Projects never write this controller themselves
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
