<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

/**
 * Fixed platform endpoints for the internal SSO IdP.
 */
final class CompanyAuth
{
    public const IDP_URL = 'https://auth.company.com';

    public const JWKS_PATH = '/.well-known/jwks.json';

    public const TOKEN_EXCHANGE_PATH = '/oauth/token';

    public const JWKS_CACHE_TTL = 3600;

    public const TOKEN_COOKIE_NAME = 'token';

    /** Cookie lifetime in minutes — must match the 10-hour JWT (`exp` − `iat`). */
    public const TOKEN_COOKIE_MINUTES = 600;

    public const ACCESS_TOKEN_TTL_SECONDS = 36_000;

    /** Laravel guard for {@see \Baaboo\InternalToolComposerAuthPackage\Models\SsoUser} (not configurable). */
    public const SSO_GUARD = 'sso';

    /** Auth provider key paired with {@see SSO_GUARD} (not configurable). */
    public const SSO_USER_PROVIDER = 'sso_users';

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
