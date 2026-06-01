<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Exceptions;

use RuntimeException;

final class UserNotProvisionedException extends RuntimeException
{
    public static function forSub(string $sub): self
    {
        return new self("No local user exists for sub \"{$sub}\" and createUser is false.");
    }
}
