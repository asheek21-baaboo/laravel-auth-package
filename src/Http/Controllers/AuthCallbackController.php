<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\CodeExchangeException;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidCallbackTokenException;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\UserNotProvisionedException;
use Baaboo\InternalToolComposerAuthPackage\Services\CallbackJwtValidator;
use Baaboo\InternalToolComposerAuthPackage\Services\IdpTokenExchanger;
use Baaboo\InternalToolComposerAuthPackage\Services\OAuthStateManager;
use Baaboo\InternalToolComposerAuthPackage\Services\UserSynchronizer;
use Baaboo\InternalToolComposerAuthPackage\Support\TokenCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuthCallbackController extends Controller
{
    public function __construct(
        private readonly OAuthStateManager $oauthState,
        private readonly IdpTokenExchanger $tokenExchanger,
        private readonly CallbackJwtValidator $callbackJwtValidator,
        private readonly UserSynchronizer $userSynchronizer,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $receivedState = (string) $request->query('state', '');
        if (! $this->oauthState->validateAndConsume($receivedState)) {
            abort(403, 'Invalid OAuth state.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            abort(400, 'Missing authorization code.');
        }

        if (! preg_match('/^[A-Za-z0-9\-._~\/+]+=*$/', $code)) {
            abort(400, 'Invalid authorization code.');
        }

        $redirectUri = route('company-auth.callback');

        try {
            $jwt = $this->tokenExchanger->exchange($code, $redirectUri);
            $claims = $this->callbackJwtValidator->validate($jwt);
        } catch (CodeExchangeException|InvalidCallbackTokenException|InvalidTokenException $e) {
            abort(403, $e->getMessage());
        }

        try {
            $this->userSynchronizer->syncFromClaims($claims);
        } catch (UserNotProvisionedException) {
            return redirect()->route('company-auth.error', ['stub' => 'user_not_provisioned']);
        }

        $destination = config('company-auth.redirect_after_login', '/');
        if (! is_string($destination) || $destination === '') {
            $destination = '/';
        }

        return redirect($destination)
            ->withCookie(TokenCookie::make($jwt));
    }
}
