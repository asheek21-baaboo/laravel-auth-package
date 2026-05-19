<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Stateless guard: user is set per request after JWT validation ({@see AuthMiddleware}).
 */
final class SsoJwtGuard implements Guard
{
    private ?Authenticatable $user = null;

    public function __construct(
        private readonly UserProvider $provider,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function id(): ?string
    {
        $id = $this->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function logout(): void
    {
        $this->user = null;
    }

    public function getProvider(): UserProvider
    {
        return $this->provider;
    }
}
