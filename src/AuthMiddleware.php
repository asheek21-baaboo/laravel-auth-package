<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenCookie;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenExtractor;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly CurrentUserService $currentUser,
        private readonly TokenExtractor $tokenExtractor,
        private readonly AuthFactory $auth,
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

        $sub = $claims->sub ?? null;
        if (! is_string($sub) || $sub === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $provider = $this->auth->createUserProvider(CompanyAuth::SSO_USER_PROVIDER);

        $ssoUser = $provider->retrieveById($sub);

        if (! $ssoUser instanceof SsoUser) {
            return response()->json([
                'message' => 'User profile not found. Please sign in again via SSO.',
            ], 401);
        }

        $this->auth->guard(CompanyAuth::SSO_GUARD)->setUser($ssoUser);
        $this->currentUser->setFromClaims($claims);

        return $next($request);
    }
}
