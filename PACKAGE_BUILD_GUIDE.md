# company/auth-package — Step-by-Step Build Guide

> This document is the sequenced build plan for the `company/auth-package` Composer package only.
> It does not cover the Laravel IdP, the App Portal, or the consuming internal tools.
> Follow each step in order — later steps depend on what earlier steps produce.

---

## Overview

```
Step 1  →  Repo & Composer scaffold
Step 2  →  Service Provider
Step 3  →  Config file
Step 4  →  TokenValidator
Step 5  →  AuthMiddleware
Step 6  →  CurrentUserService + Facade
Step 7  →  MeController + route registration
Step 8  →  Testing infrastructure
Step 9  →  Write the tests
Step 10 →  Static analysis setup
Step 11 →  Code style setup
Step 12 →  Final wiring & smoke test
```

---

## Step 1 — Repo & Composer Scaffold

**Goal:** A valid, installable Composer package with the correct structure and all dependencies declared.

### 1.1 — Finalise `composer.json`

Your `composer.json` should look exactly like this. Replace anything left from `composer init`:

```json
{
    "name": "company/auth-package",
    "description": "Shared SSO authentication package for internal Laravel tools. Validates IdP-issued JWTs, protects routes, and exposes the /me endpoint.",
    "type": "library",
    "license": "proprietary",
    "authors": [
        {
            "name": "Engineering",
            "email": "engineering@company.com"
        }
    ],
    "require": {
        "php": "^8.2",
        "illuminate/support": "^10.0|^11.0|^12.0",
        "illuminate/http": "^10.0|^11.0|^12.0",
        "illuminate/routing": "^10.0|^11.0|^12.0",
        "illuminate/contracts": "^10.0|^11.0|^12.0",
        "firebase/php-jwt": "^6.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "require-dev": {
        "orchestra/testbench": "^8.0|^9.0|^10.0",
        "pestphp/pest": "^4.0",
        "pestphp/pest-plugin-laravel": "^4.0",
        "mockery/mockery": "^1.6",
        "laravel/pint": "^1.27",
        "larastan/larastan": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Company\\Auth\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Company\\Auth\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Company\\Auth\\AuthServiceProvider"
            ],
            "aliases": {
                "CurrentUser": "Company\\Auth\\Facades\\CurrentUser"
            }
        }
    },
    "scripts": {
        "test": "vendor/bin/pest",
        "analyse": "vendor/bin/phpstan analyse",
        "format": "vendor/bin/pint"
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### 1.2 — Create the directory structure

Create these directories and files manually (empty for now):

```
mkdir -p src/Facades
mkdir -p src/Http/Controllers
mkdir -p src/Exceptions
mkdir -p config
mkdir -p tests/Unit
mkdir -p tests/Feature
touch src/AuthServiceProvider.php
touch src/TokenValidator.php
touch src/AuthMiddleware.php
touch src/CurrentUserService.php
touch src/Facades/CurrentUser.php
touch src/Http/Controllers/MeController.php
touch src/Exceptions/InvalidTokenException.php
touch config/company-auth.php
touch tests/TestCase.php
touch tests/Unit/TokenValidatorTest.php
touch tests/Feature/MeEndpointTest.php
touch tests/Feature/AuthMiddlewareTest.php
```

### 1.3 — Install dependencies

```bash
composer install
```

### 1.4 — Checkpoint

Your repo root should look like this:

```
company/auth-package/
├── composer.json
├── composer.lock
├── vendor/
├── config/
│   └── company-auth.php
├── src/
│   ├── AuthServiceProvider.php
│   ├── TokenValidator.php
│   ├── AuthMiddleware.php
│   ├── CurrentUserService.php
│   ├── Exceptions/
│   │   └── InvalidTokenException.php
│   ├── Facades/
│   │   └── CurrentUser.php
│   └── Http/
│       └── Controllers/
│           └── MeController.php
└── tests/
    ├── TestCase.php
    ├── Unit/
    │   └── TokenValidatorTest.php
    └── Feature/
        ├── MeEndpointTest.php
        └── AuthMiddlewareTest.php
```

---

## Step 2 — Service Provider

**File:** `src/AuthServiceProvider.php`

**Goal:** The entry point for Laravel's package auto-discovery. Registers the config, middleware, route, and bindings. When a consuming project installs this package, Laravel calls this automatically — no manual registration needed.

```php
<?php

declare(strict_types=1);

namespace Company\Auth;

use Company\Auth\Http\Controllers\MeController;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config with the app's published config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/company-auth.php',
            'company-auth'
        );

        // Bind TokenValidator as a singleton — we want one instance
        // managing the cached public key for the entire request lifecycle
        $this->app->singleton(TokenValidator::class, function ($app) {
            return new TokenValidator(
                idpUrl: config('company-auth.idp_url'),
                cacheTtl: config('company-auth.cache_ttl', 3600),
                cache: $app['cache']->store(),
            );
        });

        // Bind CurrentUserService as a singleton scoped to the request
        $this->app->singleton(CurrentUserService::class);
    }

    public function boot(): void
    {
        // Allow consuming projects to publish the config
        $this->publishes([
            __DIR__ . '/../config/company-auth.php' => config_path('company-auth.php'),
        ], 'company-auth-config');

        // Register the middleware alias so projects can use:
        // Route::middleware('company.auth')
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('company.auth', AuthMiddleware::class);

        // Auto-register the /me route
        // Projects never write this controller themselves
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
```

After writing the service provider, create the routes file:

```bash
mkdir -p routes
touch routes/api.php
```

**File:** `routes/api.php`

```php
<?php

declare(strict_types=1);

use Company\Auth\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware('company.auth')
    ->get('/me', MeController::class)
    ->name('company-auth.me');
```

---

## Step 3 — Config File

**File:** `config/company-auth.php`

**Goal:** All package configuration in one publishable file. Consuming projects set values via `.env` — they never hard-code them.

```php
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
```

---

## Step 4 — TokenValidator

**File:** `src/TokenValidator.php`

**Goal:** The most critical class in the package. Responsible for:
1. Fetching the IdP's public key from the JWKS endpoint
2. Caching the public key (avoid a network call on every request)
3. Verifying the JWT signature using RS256
4. Validating the `exp` claim (expiry)
5. Returning the decoded claims as a plain object

**Also create the exception first:**

**File:** `src/Exceptions/InvalidTokenException.php`

```php
<?php

declare(strict_types=1);

namespace Company\Auth\Exceptions;

use RuntimeException;

class InvalidTokenException extends RuntimeException
{
    public static function missingToken(): self
    {
        return new self('No token found in request.');
    }

    public static function expired(): self
    {
        return new self('Token has expired.');
    }

    public static function invalidSignature(): self
    {
        return new self('Token signature is invalid.');
    }

    public static function malformed(string $reason = ''): self
    {
        return new self('Token is malformed.' . ($reason ? " {$reason}" : ''));
    }

    public static function unresolvableKey(): self
    {
        return new self('Could not fetch or parse the IdP public key.');
    }
}
```

**File:** `src/TokenValidator.php`

```php
<?php

declare(strict_types=1);

namespace Company\Auth;

use Company\Auth\Exceptions\InvalidTokenException;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use stdClass;
use Throwable;

class TokenValidator
{
    private const CACHE_KEY = 'company_auth_jwks_public_key';

    public function __construct(
        private readonly string $idpUrl,
        private readonly int $cacheTtl,
        private readonly CacheRepository $cache,
        private readonly ?Client $httpClient = null,
    ) {}

    /**
     * Validate a raw JWT string and return its decoded claims.
     *
     * @throws InvalidTokenException
     */
    public function validate(string $token): stdClass
    {
        $keys = $this->getPublicKeys();

        try {
            return JWT::decode($token, $keys);
        } catch (ExpiredException) {
            throw InvalidTokenException::expired();
        } catch (SignatureInvalidException) {
            throw InvalidTokenException::invalidSignature();
        } catch (Throwable $e) {
            throw InvalidTokenException::malformed($e->getMessage());
        }
    }

    /**
     * Fetch the JWKS public keys from the IdP, with caching.
     *
     * @throws InvalidTokenException
     */
    private function getPublicKeys(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $jwks = $this->fetchJwks();
        $keys = JWK::parseKeySet($jwks);

        $this->cache->put(self::CACHE_KEY, $keys, $this->cacheTtl);

        return $keys;
    }

    /**
     * Make the HTTP request to the JWKS endpoint.
     *
     * @throws InvalidTokenException
     */
    private function fetchJwks(): array
    {
        $client = $this->httpClient ?? new Client();
        $url    = rtrim($this->idpUrl, '/') . config('company-auth.jwks_path');

        try {
            $response = $client->get($url, ['timeout' => 5]);
            $body     = (string) $response->getBody();

            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw InvalidTokenException::unresolvableKey();
        }
    }

    /**
     * Bust the cached public key.
     * Useful during key rotation or in tests.
     */
    public function forgetCachedKey(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
```

---

## Step 5 — AuthMiddleware

**File:** `src/AuthMiddleware.php`

**Goal:** The HTTP middleware registered as `company.auth`. Extracts the JWT from the request (cookie or `Authorization` header), passes it to `TokenValidator`, stores the decoded claims on the `CurrentUserService`, and either proceeds or returns a 401/403 response.

```php
<?php

declare(strict_types=1);

namespace Company\Auth;

use Company\Auth\Exceptions\InvalidTokenException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly CurrentUserService $currentUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = $this->validator->validate($token);
        } catch (InvalidTokenException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        // Hydrate the CurrentUserService with the validated claims
        // so controllers can call CurrentUser::id(), CurrentUser::email(), etc.
        $this->currentUser->setFromClaims($claims);

        return $next($request);
    }

    /**
     * Extract the JWT from the request.
     *
     * Priority:
     * 1. Bearer token in Authorization header  (for API clients / testing)
     * 2. 'token' httpOnly cookie               (standard browser flow)
     */
    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            return $bearer;
        }

        return $request->cookie('token') ?: null;
    }
}
```

---

## Step 6 — CurrentUserService and Facade

**Goal:** Give controllers a clean, readable API to access the authenticated user's data without passing the request object around.

**File:** `src/CurrentUserService.php`

```php
<?php

declare(strict_types=1);

namespace Company\Auth;

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
        return $this->claim('project_id');
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
```

**File:** `src/Facades/CurrentUser.php`

```php
<?php

declare(strict_types=1);

namespace Company\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string   id()
 * @method static string   email()
 * @method static string   globalRole()
 * @method static string   projectId()
 * @method static string   role()
 *
 * @see \Company\Auth\CurrentUserService
 */
class CurrentUser extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Company\Auth\CurrentUserService::class;
    }
}
```

---

## Step 7 — MeController

**File:** `src/Http/Controllers/MeController.php`

**Goal:** Auto-registered `GET /me` endpoint. Returns `name`, `role`, and a minimal `permissions` array derived **only** from JWT `project_role` (`["*"]` for `admin`, otherwise `[]`). Application-level permission matrices, gates, and policies are **not** part of this package — each consuming project implements those on its own (separate routes, services, or an overridden controller binding if you replace `/me`).

```php
<?php

declare(strict_types=1);

namespace Company\Auth\Http\Controllers;

use Company\Auth\CurrentUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class MeController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUser,
    ) {}

    /**
     * Return the authenticated user context.
     *
     * Contract (must never change):
     * {
     *   "name":        string,
     *   "role":        string,
     *   "permissions": string[]   — ["*"] only when JWT project_role is admin; otherwise []
     * }
     */
    public function __invoke(): JsonResponse
    {
        $role = $this->currentUser->role();
        $permissions = $role === 'admin' ? ['*'] : [];

        return response()->json([
            'name'        => $this->currentUser->email(), // Replace with name claim when IdP provides it
            'role'        => $role,
            'permissions' => $permissions,
        ]);
    }
}
```

> **Note:** The `name` field will come from a `name` JWT claim once the IdP includes it. For now it falls back to `email`. Update `TokenValidator`, `CurrentUserService::name()`, and this controller together when the IdP adds the claim.

---

## Step 8 — Testing Infrastructure

**Goal:** Set up Pest and Testbench so tests can boot a real Laravel app with this package loaded, without needing a consuming project.

### 8.1 — Base TestCase

**File:** `tests/TestCase.php`

```php
<?php

declare(strict_types=1);

namespace Company\Auth\Tests;

use Company\Auth\AuthServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'CurrentUser' => \Company\Auth\Facades\CurrentUser::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Point to a fake IdP URL for all tests
        $app['config']->set('company-auth.idp_url', 'https://auth.test');
        $app['config']->set('company-auth.cache_ttl', 0); // Disable caching in tests
    }
}
```

### 8.2 — Pest configuration

**File:** `tests/Pest.php`

```php
<?php

declare(strict_types=1);

uses(Company\Auth\Tests\TestCase::class)->in('Feature');
```

Unit tests do not extend TestCase (no Laravel app needed for pure unit tests).

### 8.3 — Create `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

### 8.4 — Verify Pest runs

```bash
composer test
```

You should see 0 tests, 0 assertions — no errors. If it errors, the autoloading or TestCase setup needs fixing before proceeding.

---

## Step 9 — Write the Tests

Write tests after each class is built. These are the tests that matter for this package.

### Unit: TokenValidator

**File:** `tests/Unit/TokenValidatorTest.php`

Test these behaviours:

```
✓ throws InvalidTokenException::expired() when token exp is in the past
✓ throws InvalidTokenException::invalidSignature() when token is tampered
✓ throws InvalidTokenException::malformed() when token is not a valid JWT
✓ throws InvalidTokenException::unresolvableKey() when JWKS endpoint is unreachable
✓ returns decoded stdClass with correct claims on a valid token
✓ caches the public key after first fetch (HTTP client called only once across two calls)
✓ forgetCachedKey() causes the next validate() call to re-fetch the key
```

For tests involving real JWTs, generate a test RS256 key pair:

```bash
# Generate a test private key (never commit a real one)
openssl genrsa -out tests/fixtures/test_private.pem 2048
openssl rsa -in tests/fixtures/test_private.pem -pubout -out tests/fixtures/test_public.pem
```

Add `tests/fixtures/` to the repo. Sign tokens in tests using `firebase/php-jwt` directly with the private key, and configure `TokenValidator` to use the public key — mock the JWKS response to return it.

### Feature: AuthMiddleware

**File:** `tests/Feature/AuthMiddlewareTest.php`

```
✓ returns 401 when no token is present (no cookie, no header)
✓ returns 401 with message when token is expired
✓ returns 401 with message when token signature is invalid
✓ proceeds to next middleware when token is valid
✓ extracts token from Authorization: Bearer header
✓ extracts token from cookie named 'token'
✓ populates CurrentUserService with claims after valid token
```

### Feature: MeController

**File:** `tests/Feature/MeEndpointTest.php`

```
✓ GET /me returns 401 when unauthenticated
✓ GET /me returns 200 with correct shape when authenticated
✓ GET /me response contains 'name', 'role', 'permissions' keys (permissions are only [] or ["*"] from project_role)
✓ GET /me returns permissions as ["*"] when project_role is admin
```

---

## Step 10 — Static Analysis Setup

**File:** `phpstan.neon`

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - src

    level: 5

    # Testbench bootstraps a full app, so we need to tell PHPStan about it
    bootstrapFiles:
        - vendor/autoload.php
```

Run analysis:

```bash
composer analyse
```

Fix all reported errors before moving to Step 11. Target: zero errors at level 5. You can raise to level 8 or 9 later once the codebase is stable.

---

## Step 11 — Code Style Setup

**File:** `pint.json`

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

Run formatter:

```bash
composer format
```

Pint will rewrite any files that don't conform. Run it once now, commit the result, then run it as a pre-commit habit going forward.

---

## Step 12 — Final Wiring & Smoke Test

**Goal:** Verify the package installs and works correctly inside a real consuming Laravel app before tagging v1.0.0.

### 12.1 — Tag the package

```bash
git tag v1.0.0
git push origin v1.0.0
```

### 12.2 — Test in a local Laravel app

In a local Laravel project (your first integration target), add the private repo and require the package:

```json
// In the consuming project's composer.json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/your-org/auth-package"
    }
]
```

```bash
composer require company/auth-package
```

Then set `.env`:

```
IDP_URL=https://auth.company.com
```

Wrap a test route:

```php
Route::middleware('company.auth')->get('/test-auth', function () {
    return response()->json([
        'user' => CurrentUser::email(),
        'role' => CurrentUser::role(),
    ]);
});
```

Hit the route with a valid JWT from the IdP. Confirm:

- ✅ Valid token → 200 with user data
- ✅ No token → 401
- ✅ Expired token → 401
- ✅ `GET /me` returns the correct shape

### 12.3 — Final checklist before v1.0.0 is declared done

```
[ ] All tests pass:          composer test
[ ] No analysis errors:      composer analyse
[ ] Code is formatted:       composer format
[ ] composer.json is clean   (no leftover test entries, correct version constraints)
[ ] config/company-auth.php  is publishable and documented
[ ] routes/api.php           registers /me correctly behind company.auth middleware
[ ] At least one real tool   has been integrated and verified end-to-end
[ ] README.md exists         with install instructions for consuming project developers
```

---

## What Comes After This Package (Out of Scope Here)

Once this package is stable and integrated into the first tool, Phase 2 work begins — but that is a separate build plan:

- Per-project authorization (roles, permission tables, policies) in consuming projects only
- Role management UI (in consuming projects)
- Richer `/me` payloads or dedicated permission APIs, if a tool needs them (consuming project code)
- `@company/auth` npm package for React and Vue frontends
- Token revocation on user deactivation (IdP work)

---

*Last updated: May 2026 — Phase 1*
