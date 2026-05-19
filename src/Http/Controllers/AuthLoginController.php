<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\Services\SsoAuthorizationUrlBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Redirects the browser to the IdP OAuth2 authorize endpoint (no local login form).
 */
final class AuthLoginController extends Controller
{
    public function __construct(
        private readonly SsoAuthorizationUrlBuilder $authorizationUrlBuilder,
    ) {}

    public function __invoke(): RedirectResponse
    {
        return redirect()->away($this->authorizationUrlBuilder->authorizeUrl());
    }
}
