<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\CurrentUserService;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenExtractor;
use Baaboo\InternalToolComposerAuthPackage\TokenValidator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use stdClass;

/**
 * Validates the request JWT and resolves {@see SsoUser} (shared by auth + guest middleware).
 */
final class SsoRequestAuthenticator
{
    public function __construct(
        private readonly TokenExtractor $tokenExtractor,
        private readonly TokenValidator $tokenValidator,
        private readonly AuthFactory $auth,
        private readonly CurrentUserService $currentUser,
    ) {}

    /**
     * @return array{claims: stdClass, user: SsoUser}|null
     */
    public function authenticate(Request $request): ?array
    {
        $token = $this->tokenExtractor->fromRequest($request);

        if ($token === null) {
            return null;
        }

        try {
            $claims = $this->tokenValidator->validate($token);
        } catch (InvalidTokenException) {
            return null;
        }

        return $this->resolveFromClaims($claims);
    }

    /**
     * @return array{claims: stdClass, user: SsoUser}|null
     */
    public function resolveFromClaims(stdClass $claims): ?array
    {
        $sub = $claims->sub ?? null;
        if (! is_string($sub) || $sub === '') {
            return null;
        }

        $user = $this->auth->createUserProvider(CompanyAuth::SSO_USER_PROVIDER)->retrieveById($sub);

        if (! $user instanceof SsoUser) {
            return null;
        }

        return ['claims' => $claims, 'user' => $user];
    }

    /**
     * @param  array{claims: stdClass, user: SsoUser}  $authenticated
     */
    public function applyToSession(array $authenticated): void
    {
        $this->auth->guard(CompanyAuth::SSO_GUARD)->setUser($authenticated['user']);
        $this->currentUser->setFromClaims($authenticated['claims']);
    }
}
