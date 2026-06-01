<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoAuthorizationUrlBuilder;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenCookie;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class AuthLogoutController extends Controller
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SsoAuthorizationUrlBuilder $authorizationUrlBuilder,
    ) {}

    public function __invoke(): RedirectResponse
    {
        $guard = $this->auth->guard(CompanyAuth::SSO_GUARD);
        if (method_exists($guard, 'logout')) {
            $guard->logout();
        }

        $forgetCookie = TokenCookie::forget();

        if (config('company-auth.redirect_to_idp_logout', true)) {
            return redirect()->away($this->authorizationUrlBuilder->logoutUrl())
                ->withCookie($forgetCookie);
        }

        return redirect()
            ->route('company-auth.error', ['stub' => 'logged_out'])
            ->withCookie($forgetCookie);
    }
}
