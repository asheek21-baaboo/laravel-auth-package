<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly CurrentUserService $currentUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = $this->validator->validate($token);
        } catch (InvalidTokenException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        // Hydrate the CurrentUserService with the validated claims
        // so controllers can call CurrentUser::id(), CurrentUser::email(), etc.
        $this->currentUser->setFromClaims($claims);

        return $next($request);
    }

    /**
     * Extract the JWT from the request.
     *
     * Priority:
     * 1. Bearer token in Authorization header  (for API clients / testing)
     * 2. 'token' httpOnly cookie               (standard browser flow)
     */
    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            return $bearer;
        }

        return $request->cookie('token') ?: null;
    }
}
