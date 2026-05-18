<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Data;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\CodeExchangeException;
use Throwable;

final readonly class IdpTokenResponse
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $tokenType,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws CodeExchangeException
     */
    public static function fromArray(array $body): self
    {
        try {
            $accessToken = $body['access_token'] ?? null;
            if (! is_string($accessToken) || $accessToken === '') {
                throw CodeExchangeException::invalidResponse();
            }

            $expiresIn = $body['expires_in'] ?? null;
            if (! is_int($expiresIn) && ! (is_numeric($expiresIn) && (int) $expiresIn == $expiresIn)) {
                throw CodeExchangeException::invalidResponse();
            }
            $expiresIn = (int) $expiresIn;
            if ($expiresIn < 1) {
                throw CodeExchangeException::invalidResponse();
            }

            $tokenType = $body['token_type'] ?? null;
            if (! is_string($tokenType) || $tokenType === '') {
                throw CodeExchangeException::invalidResponse();
            }

            return new self($accessToken, $expiresIn, $tokenType);
        } catch (CodeExchangeException $e) {
            throw $e;
        } catch (Throwable) {
            throw CodeExchangeException::invalidResponse();
        }
    }
}
