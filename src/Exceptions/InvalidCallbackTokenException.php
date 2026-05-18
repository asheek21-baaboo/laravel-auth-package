<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Exceptions;

use RuntimeException;

final class InvalidCallbackTokenException extends RuntimeException
{
    public static function claimMismatch(string $claim): self
    {
        return new self("Token claim [{$claim}] is invalid for this application.");
    }

    public static function missingClaim(string $claim): self
    {
        return new self("Token is missing required claim [{$claim}].");
    }
}
