<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidCallbackTokenException;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\TokenValidator;
use stdClass;

final class CallbackJwtValidator
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
    ) {}

    /**
     * Verify JWT signature/expiry and enforce iss, aud, project_id, jti for this tool.
     *
     * @throws InvalidTokenException
     * @throws InvalidCallbackTokenException
     */
    public function validate(string $jwt): stdClass
    {
        $claims = $this->tokenValidator->validate($jwt);

        $projectId = config('company-auth.project_id');

        if (! is_string($projectId) || $projectId === '') {
            throw InvalidCallbackTokenException::claimMismatch('project_id');
        }

        $issuer = $claims->iss ?? null;
        if ($issuer !== CompanyAuth::idpUrl()) {
            throw InvalidCallbackTokenException::claimMismatch('iss');
        }

        $audience = $claims->aud ?? null;
        if ($audience !== $projectId) {
            throw InvalidCallbackTokenException::claimMismatch('aud');
        }

        $jti = $claims->jti ?? null;
        if (! is_string($jti) || $jti === '') {
            throw InvalidCallbackTokenException::missingClaim('jti');
        }

        return $claims;
    }
}
