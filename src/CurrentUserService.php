<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use stdClass;

class CurrentUserService
{
    private ?stdClass $claims = null;

    /**
     * Hydrate the service from validated JWT claims.
     * Called by AuthMiddleware after successful token validation.
     */
    public function setFromClaims(stdClass $claims): void
    {
        $this->claims = $claims;
    }

    public function id(): string
    {
        return $this->claim('sub');
    }

    public function email(): string
    {
        return $this->claim('email');
    }

    public function globalRole(): string
    {
        return $this->claim('global_role');
    }

    public function projectId(): string
    {
        return $this->claim('aud');
    }

    public function role(): string
    {
        return $this->claim('project_role');
    }

    /**
     * Return all raw claims. Useful for debugging or logging.
     */
    public function all(): ?stdClass
    {
        return $this->claims;
    }

    /**
     * @throws \RuntimeException if the middleware was not applied
     */
    private function claim(string $key): mixed
    {
        if ($this->claims === null) {
            throw new \RuntimeException(
                "CurrentUser accessed before AuthMiddleware ran. Did you apply the 'company.auth' middleware?"
            );
        }

        return $this->claims->{$key} ?? null;
    }
}
