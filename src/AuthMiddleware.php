<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoRequestAuthenticator;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenCookie;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenExtractor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly SsoRequestAuthenticator $authenticator,
        private readonly TokenExtractor $tokenExtractor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenExtractor->fromRequest($request);

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = $this->validator->validate($token);
        } catch (InvalidTokenException $e) {
            if ($e->isExpired() && ! $request->expectsJson()) {
                return redirect()
                    ->route('company-auth.token-expired')
                    ->withCookie(TokenCookie::forget());
            }

            return response()->json(['message' => $e->getMessage()], 401);
        }

        $authenticated = $this->authenticator->resolveFromClaims($claims);

        if ($authenticated === null) {
            return redirect()->route('company-auth.error', ['stub' => 'sign_in_failed']);
        }

        $this->authenticator->applyToSession($authenticated);

        return $next($request);
    }
}
