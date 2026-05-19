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
    'redirect_after_logout' => env('SSO_REDIRECT_AFTER_LOGOUT', '/login'),

    /** When true, logout redirects to the IdP global logout URL (clears portal session). */
    'redirect_to_idp_logout' => env('SSO_REDIRECT_TO_IDP_LOGOUT', true),

];
