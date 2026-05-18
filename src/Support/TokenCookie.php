<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Support;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

final class TokenCookie
{
    public static function make(string $jwt): SymfonyCookie
    {
        $secure = ! app()->environment('local') || request()->isSecure();

        return cookie(
            name: CompanyAuth::TOKEN_COOKIE_NAME,
            value: $jwt,
            minutes: CompanyAuth::TOKEN_COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }

    /**
     * Expire the access-token cookie (e.g. after redirect for expired JWT).
     */
    public static function forget(): SymfonyCookie
    {
        $secure = ! app()->environment('local') || request()->isSecure();

        return cookie(
            name: CompanyAuth::TOKEN_COOKIE_NAME,
            value: '',
            minutes: -2628000,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }
}
