<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Services\SsoRequestAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For login/register routes: redirect authenticated users away (JWT-aware replacement for Laravel "guest").
 */
final class GuestMiddleware
{
    public function __construct(
        private readonly SsoRequestAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $this->authenticator->authenticate($request);

        if ($authenticated !== null) {
            $this->authenticator->applyToSession($authenticated);

            $destination = config('company-auth.redirect_after_login', '/');
            if (! is_string($destination) || $destination === '') {
                $destination = '/';
            }

            return redirect($destination);
        }

        return $next($request);
    }
}
