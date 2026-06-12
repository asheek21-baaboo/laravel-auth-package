<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpSessionEndClient;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenCookie;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenExtractor;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuthLogoutController extends Controller
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly TokenExtractor $tokenExtractor,
        private readonly IdpSessionEndClient $sessionEndClient,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $accessToken = $this->tokenExtractor->fromRequest($request);

        if (config('company-auth.redirect_to_idp_logout', true) && $accessToken !== null) {
            $this->sessionEndClient->endSession($accessToken);
        }

        $guard = $this->auth->guard(CompanyAuth::SSO_GUARD);
        if (method_exists($guard, 'logout')) {
            $guard->logout();
        }

        $forgetCookie = TokenCookie::forget();

        return redirect()
            ->route('company-auth.error', ['stub' => 'logged_out'])
            ->withCookie($forgetCookie);
    }
}
