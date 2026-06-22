<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Unauthenticated login route — directs users to the shared error page (no IdP redirect).
 */
final class AuthLoginController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('company-auth.error', ['stub' => 'unauthenticated']);
    }
}
