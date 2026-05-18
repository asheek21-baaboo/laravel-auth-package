<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Exceptions;

use RuntimeException;

final class CodeExchangeException extends RuntimeException
{
    public static function idpRejected(string $message = 'Authorization code could not be exchanged.'): self
    {
        return new self($message);
    }

    public static function invalidResponse(): self
    {
        return new self('IdP token response was invalid.');
    }

    public static function transportFailed(): self
    {
        return new self('Could not reach the IdP token endpoint.');
    }

    public static function httpError(int $status, string $body = ''): self
    {
        $detail = $body !== '' ? " Response: {$body}" : '';

        return new self("Token exchange failed with HTTP {$status}.{$detail}", $status);
    }
}
