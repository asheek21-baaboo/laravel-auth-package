<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Exceptions;

use RuntimeException;

class InvalidTokenException extends RuntimeException
{
    public static function missingToken(): self
    {
        return new self('No token found in request.');
    }

    public static function expired(): self
    {
        return new self('Token has expired.');
    }

    public static function invalidSignature(): self
    {
        return new self('Token signature is invalid.');
    }

    public static function malformed(string $reason = ''): self
    {
        return new self('Token is malformed.'.($reason ? " {$reason}" : ''));
    }

    public static function unresolvableKey(): self
    {
        return new self('Could not fetch or parse the IdP public key.');
    }
}
