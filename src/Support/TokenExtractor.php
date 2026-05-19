<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Support;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Illuminate\Http\Request;

final class TokenExtractor
{
    /**
     * Extract the JWT from the request.
     *
     * Priority:
     * 1. Bearer token in Authorization header (API clients / tests)
     * 2. httpOnly cookie named {@see CompanyAuth::TOKEN_COOKIE_NAME}
     */
    public function fromRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            return $bearer;
        }

        $cookie = $request->cookie(CompanyAuth::TOKEN_COOKIE_NAME);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
