<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Exceptions;

use RuntimeException;

final class UserNotProvisionedException extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self("No local user exists for email \"{$email}\" and createUser is false.");
    }
}
