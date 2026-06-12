<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | IdP Base URL (local only)
    |--------------------------------------------------------------------------
    */
    'idp_url' => env('IDP_URL', 'http://baaboo-sso.test'),

    /*
    |--------------------------------------------------------------------------
    | Tool identity (required for callback + aud / project_id checks)
    |--------------------------------------------------------------------------
    */
    'project_id' => env('SSO_PROJECT_ID'),

    'client_id' => env('SSO_CLIENT_ID'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Post-login redirect (after successful /auth/callback)
    |--------------------------------------------------------------------------
    */
    'redirect_after_login' => env('SSO_REDIRECT_AFTER_LOGIN', '/'),

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    /** When true, logout POSTs the user's JWT to IdP `/oauth/session/end` (Bearer token). */
    'redirect_to_idp_logout' => env('SSO_REDIRECT_TO_IDP_LOGOUT', true),

    /*
    |--------------------------------------------------------------------------
    | OAuth error messages (`GET /oauth/error?stub=…`)
    |--------------------------------------------------------------------------
    |
    | Each stub maps to `message` and `description` shown on the shared error view.
    | `fallback` is used when `stub` is missing or unknown.
    |
    */
    'errors' => [
        'fallback' => [
            'message' => 'Token Expired',
            'description' => 'The token has expired. Please log in again.',
        ],
        'access_denied' => [
            'message' => 'Access denied',
            'description' => 'You do not have permission to use this application.',
        ],
        'sign_in_failed' => [
            'message' => 'Sign-in failed',
            'description' => 'We could not complete sign-in. Please try again.',
        ],
        'user_not_provisioned' => [
            'message' => 'Account not available',
            'description' => 'Your account is not set up for this application. Contact your administrator.',
        ],
        'logged_out' => [
            'message' => 'Logged out',
            'description' => 'You have been logged out. Please log in again.',
        ],
        'unauthenticated' => [
            'message' => 'Unauthenticated',
            'description' => 'Please log in to continue.',
        ],
    ],

];
