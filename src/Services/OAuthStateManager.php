<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Illuminate\Support\Str;

/**
 * OAuth 2.0 {@code state} parameter for CSRF protection on the authorization callback.
 */
final class OAuthStateManager
{
    public const SESSION_KEY = 'company_auth.oauth.state';

    private const STATE_LENGTH = 40;

    public function issue(): string
    {
        $state = Str::random(self::STATE_LENGTH);
        session()->put(self::SESSION_KEY, $state);

        return $state;
    }

    /**
     * Timing-safe compare; consumes the session value (one-time use).
     */
    public function validateAndConsume(string $received): bool
    {
        $expected = session()->pull(self::SESSION_KEY);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $received);
    }
}
