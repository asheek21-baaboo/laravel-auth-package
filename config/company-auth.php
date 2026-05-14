<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | IdP Base URL
    |--------------------------------------------------------------------------
    | The base URL of the Laravel Identity Provider.
    | Example: https://auth.company.com
    */
    'idp_url' => env('IDP_URL'),

    /*
    |--------------------------------------------------------------------------
    | JWKS Cache TTL
    |--------------------------------------------------------------------------
    | How long (in seconds) to cache the IdP's public key fetched from the
    | JWKS endpoint. Avoids hitting the IdP on every request.
    | Default: 3600 (1 hour)
    */
    'cache_ttl' => (int) env('COMPANY_AUTH_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | JWKS Endpoint Path
    |--------------------------------------------------------------------------
    | Path appended to idp_url to reach the public key endpoint.
    | Only change this if the IdP exposes keys at a non-standard path.
    */
    'jwks_path' => '/.well-known/jwks.json',

];
