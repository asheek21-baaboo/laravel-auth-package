<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use App\Models\User;

/**
 * Fixed platform endpoints for the internal SSO IdP.
 */
final class CompanyAuth
{
    public const IDP_URL = 'https://auth.company.com';

    public const JWKS_PATH = '/.well-known/jwks.json';

    public const TOKEN_EXCHANGE_PATH = '/oauth/token';

    public const OAUTH_AUTHORIZE_PATH = '/oauth/authorize';

    public const IDP_LOGOUT_PATH = '/logout';

    public const JWKS_CACHE_TTL = 3600;

    public const TOKEN_COOKIE_NAME = 'token';

    /** Cookie lifetime in minutes — must match the 10-hour JWT (`exp` − `iat`). */
    public const TOKEN_COOKIE_MINUTES = 600;

    public const ACCESS_TOKEN_TTL_SECONDS = 36_000;

    /** Laravel guard for SSO-authenticated requests (not configurable). */
    public const SSO_GUARD = 'sso';

    /** Auth provider paired with {@see SSO_GUARD}. */
    public const USER_PROVIDER = 'users';

    /** Consuming application's Eloquent user model. */
    public const USER_MODEL = User::class;

    /**
     * IdP base URL for JWKS fetch and issuer checks.
     * Non-local environments always use {@see IDP_URL}.
     */
    public static function idpUrl(): string
    {
        if (! app()->environment('local')) {
            return self::IDP_URL;
        }

        $configured = config('company-auth.idp_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return self::IDP_URL;
    }
}
