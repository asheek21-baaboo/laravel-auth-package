# baaboo/internal-tool-composer-auth-package — Cursor Context

> **Internal SSO & Auth Platform — Composer Package**
> This file gives Cursor full context on what we are building, the decisions already made, and the rules to follow when writing code in this repository.

**How Cursor uses this file:** A project rule in `.cursor/rules/` (`alwaysApply: true`) instructs the agent to follow this document. Keep it accurate when dependencies, namespaces, or public API change.

---

## What This Package Is

A private PHP/Laravel Composer package (`baaboo/internal-tool-composer-auth-package`) that every internal Laravel tool installs to integrate with the company's centralised Single Sign-On (SSO) Identity Provider (IdP).

The package eliminates per-project auth boilerplate. A developer integrating a new internal tool should **never** write token parsing, signature verification, a `/me` controller, or 401/403 handling. The package owns all of that.

---

## The Bigger System (What This Package Plugs Into)

```
┌─────────────────────────────────────────────────────────┐
│              Laravel IdP  (auth.company.com)             │
│  - Single source of truth for all user identity         │
│  - Issues signed JWTs scoped per project                │
│  - Hosts the App Portal (launchpad after login)         │
│  - Laravel Passport for OAuth2 / token management       │
└────────────────────┬────────────────────────────────────┘
                     │  JWT (RS256, 10-hour expiry)
         ┌───────────▼───────────┐
         │   THIS PACKAGE        │  ← you are here
         │ baaboo/internal-tool- │
         │ composer-auth-package │
         └───────────┬───────────┘
                     │  installs into
        ┌────────────▼──────────────────┐
        │  Internal Laravel Tools        │
        │  (HR Portal, CRM, Ops, etc.)   │
        │  - Never store passwords       │
        │  - Trust IdP for identity      │
        │  - Own their authorization model  │
        └────────────────────────────────┘
```

---

## Package Responsibilities

| Responsibility | Class / Feature |
|---|---|
| Fetch & cache IdP public key (JWKS) | `TokenValidator` |
| Verify JWT signature + expiry | `TokenValidator` |
| Protect routes, return 401/403 | `AuthMiddleware` (alias: `company.auth`) |
| Accept IdP revoke calls (`POST /auth/revoke`, service JWT, per-app `aud`) | **Planned** — see `docs/SECURE_DEFAULTS.md` §8 |
| Enforce `sub` / `jti` revocation blacklist on user requests | **Planned** — `AuthMiddleware` after JWT verify |
| Expose current user to controllers | `CurrentUser` facade + `Auth::guard('sso')->user()` (`users` table) |
| Sync local user profile on login | Profile upsert on `GET /oauth/callback` (`users` table); validates OAuth `state` before code exchange |
| `users` migration (non-destructive when table already exists) | `database/migrations/*_ensure_users_table_for_company_auth.php` |
| `GET /login` | `AuthLoginController` — redirect to IdP OAuth authorize with session `state` (`company.guest`) |
| `POST /logout` | `AuthLogoutController` — clear cookie, POST JWT to IdP `/oauth/session/end` (Bearer), redirect locally |
| `company.guest` middleware | JWT-aware “guest” — redirect authenticated users away from login |
| `GET /oauth/token-expired` | `TokenExpiredController` — HTML page with link to `login` |
| `GET /oauth/error` | `ErrorController` — shared error view; `stub` → `config('company-auth.errors')` (`message`, `description`, `fallback`) |
| `GET /me` controller (`MeController`) | Consuming app registers on `web` + `company.auth` |
| Bootstrap everything via auto-discovery | `AuthServiceProvider` (Laravel package auto-discovery) |

---

## JWT Token Structure

Tokens are RS256-signed JWTs issued by the IdP. The package validates these tokens using the IdP's public key fetched from the JWKS endpoint.

```php
// Claims present in every token
[
    'sub'          => 'uuid-of-user',        // unique user ID
    'email'        => 'user@company.com',
    'global_role'  => 'staff',               // platform-level role: super_admin | staff
    'project_id'   => 'hr-portal',           // slug of the project this token is scoped to
    'project_role' => 'manager',             // role within this project: admin | manager | editor | viewer
    'exp'          => 1234567890,            // 10-hour expiry timestamp (iat + 36000)
    'jti'          => 'unique-token-id',     // required — single-token revoke (see docs/SECURE_DEFAULTS.md §8)
    'createUser'   => true,                  // when true, upsert local `users` row on callback; when false, use existing row only
]
```

**Algorithm:** RS256 (asymmetric). Tools only need the public key — they never hold the signing secret.

**Token storage:** `httpOnly` cookies only. Tokens must never be accessible to JavaScript.

---

## The `/me` Response Contract

Register `GET /me` on the consuming app’s **web** routes (see integration below). `MeController` returns JSON with **identity and coarse `project_role` from the JWT**. Keys and types are stable:

```json
{
    "name": "jane@company.com",
    "role": "manager",
    "permissions": []
}
```

- **`name`** — Currently the user's email (until the IdP adds a dedicated display-name claim).
- **`role`** — The JWT `project_role` claim (`admin`, `manager`, `editor`, `viewer`, etc.).
- **`permissions`** — **Not** your application's permission list. The package only sets `["*"]` when `project_role` is `admin`, and `[]` otherwise. Named capabilities (for example `reports.export`), policies, gates, and database-backed roles are **defined and enforced only in each consuming project**; this package does not resolve them, store them, or expose helpers like `CurrentUser::can()`.

**IdP vs tool:** The IdP issues the JWT with `project_role` for portal/tool access at a coarse level. Each internal tool owns fine-grained authorization (tables, UI, checks) independently of this package.

---

## Package File Structure

```
sso-composer-auth-package/
├── composer.json
├── phpstan.neon
├── src/
│   ├── AuthServiceProvider.php        # Registers everything via Laravel auto-discovery
│   ├── CompanyAuth.php                # Fixed IdP URL, JWKS path, cache TTL (platform constants)
│   ├── TokenValidator.php             # JWKS fetch + cache + JWT verify
│   ├── AuthMiddleware.php             # Registered as 'company.auth'
│   ├── CurrentUserService.php         # Backed by resolved JWT claims
│   ├── Auth/
│   │   └── SsoJwtGuard.php            # Guard driver `sso-jwt`
│   ├── Services/
│   │   └── UserSynchronizer.php       # Upsert `users` row on callback only
│   ├── Facades/
│   │   └── CurrentUser.php            # Facade over CurrentUserService
│   └── Http/
│       └── Controllers/
│           ├── AuthCallbackController.php
│           ├── TokenExpiredController.php
│           ├── ErrorController.php
│           └── MeController.php       # Wire GET /me on web routes in the consuming app
├── routes/
│   └── company-auth.php               # GET /oauth/callback, token-expired, error (auto-loaded)
├── tests/
│   ├── TestCase.php                   # Base test case extending Orchestra\Testbench
│   ├── Unit/
│   │   └── TokenValidatorTest.php
│   └── Feature/
│       ├── AuthMiddlewareTest.php
│       └── MeEndpointTest.php
├── config/
│   └── company-auth.php               # `idp_url`, callback secrets, post-login redirect
├── resources/
│   └── views/
│       ├── token-expired.blade.php   # Shown when JWT is expired (browser) or direct GET
│       └── error.blade.php           # Shared error view (dynamic message + description)
```

---

## `composer.json`

Canonical shape for this repository (align the committed file when you change dependencies or discovery). `extra.laravel` and `autoload-dev` are required for a complete Laravel package; keep them in sync with real class namespaces under `src/` and `tests/`.

This package is **not** published on Packagist; consuming apps install it from **GitHub** (private repo + deploy keys or GitHub OAuth for Composer). Do **not** set a root `"version"` in `composer.json` — Composer resolves the installed version from **git tags** (and branches/commits when you pin that way), which avoids `composer validate` warnings and matches how VCS packages are meant to work. Tag releases on this repo (for example `v1.0.0`) so Composer can detect the root package version in CI and local clones; until the first tag exists, `composer install` may log that it defaulted the root version.

```json
{
    "name": "baaboo/internal-tool-composer-auth-package",
    "description": "Shared SSO authentication package for internal Laravel tools. Validates IdP-issued JWTs, protects routes, and exposes the /me endpoint.",
    "type": "library",
    "license": "proprietary",
    "require": {
        "php": "^8.2",
        "illuminate/support": "^10.0|^11.0|^12.0|^13.0",
        "illuminate/http": "^10.0|^11.0|^12.0|^13.0",
        "illuminate/routing": "^10.0|^11.0|^12.0|^13.0",
        "illuminate/contracts": "^10.0|^11.0|^12.0|^13.0",
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
            "Baaboo\\InternalToolComposerAuthPackage\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Baaboo\\InternalToolComposerAuthPackage\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Baaboo\\InternalToolComposerAuthPackage\\AuthServiceProvider"
            ],
            "aliases": {
                "CurrentUser": "Baaboo\\InternalToolComposerAuthPackage\\Facades\\CurrentUser"
            }
        }
    },
    "scripts": {
        "test": "vendor/bin/pest",
        "analyse": "vendor/bin/phpstan analyse",
        "format": "vendor/bin/pint"
    },
    "authors": [
        {
            "name": "Mohammed Asheek",
            "email": "mohammed.asheek@baaboo.com"
        }
    ],
    "minimum-stability": "stable"
}
```

When adding or renaming providers or facades, update `extra.laravel` in `composer.json` and this section together.

---

## How a Developer Integrates a New Laravel Tool

**Full step-by-step install:** [docs/INSTALLATION.md](docs/INSTALLATION.md)

```bash
composer require baaboo/internal-tool-composer-auth-package
```

`.env` (each tool):
```
APP_PROJECT_ID=hr-portal
COMPANY_AUTH_CLIENT_SECRET=...   # from SSO app registry
COMPANY_AUTH_REDIRECT=/dashboard # optional, default /
```

`GET /auth/callback` is registered by the package. Wire protected routes on `web` + `company.auth`:
```php
use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;

Route::middleware(['web', 'company.auth'])->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/dashboard', DashboardController::class);
});
```

Controllers:
```php
use Baaboo\InternalToolComposerAuthPackage\Facades\CurrentUser;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $userId = CurrentUser::id();
        $email  = CurrentUser::email();
        $role   = CurrentUser::role();
    }
}
```

That is all. No token parsing, no signature verification, no `/me` controller to write.

---

## Key Technical Decisions

| Decision | Choice | Why |
|---|---|---|
| JWT algorithm | RS256 (asymmetric) | Consuming tools only need public key, never the signing secret |
| Token storage | `httpOnly` cookies | Prevents XSS — tokens never accessible to JavaScript |
| Token lifetime | 10 hours, no refresh token | Re-login via IdP after expiry; immediate lockout via `POST /auth/revoke` + blacklist (§8 in SECURE_DEFAULTS) |
| Public key caching | Laravel cache store | Avoid hitting IdP JWKS endpoint on every request |
| IdP technology | Laravel Passport | Native Laravel DX, no external dependency (no Keycloak/Auth0) |
| Framework coupling | `illuminate/*` components only, not `laravel/framework` | Supports Laravel 10–13 without pinning the full framework |
| Static analysis | `larastan/larastan ^3.0` | PHPStan wrapper with Laravel-aware type inference |
| Code style | `laravel/pint` | Opinionated, zero-config Laravel formatter |

---

## Security Rules (Non-Negotiable)

See **[docs/SECURE_DEFAULTS.md](docs/SECURE_DEFAULTS.md)** for cookie flags, CSRF, JWT claims, 10-hour token TTL, token-expired / SSO re-login, **revocation (service JWT per app)**, logout, and per-project integration checklists.

- Tokens are **always** stored in `httpOnly` cookies — never in `localStorage` or accessible to JS
- Consuming projects must enforce in-app authorization; the JWT only supplies coarse `project_role` from the IdP
- HTTPS is enforced across all services; HSTS headers must be set
- Every token event must be logged with actor, IP, user agent, and timestamp
- JWT signing keys are rotatable without downtime via the JWKS endpoint
- Offboarding: IdP deactivates user, then `POST /auth/revoke` to each child app with a short-lived service JWT (`aud` = that app’s `project_id`); see `docs/SECURE_DEFAULTS.md` §8
- User access JWTs must include `jti`; revocation blacklist TTL ≥ 10 hours
- PKCE is required for all Authorization Code flows
- The `exp` claim must always be validated — never skip expiry checks

---

## What This Package Does NOT Do

- Does not store passwords or user credentials (ever)
- Does not define what a `project_role` string means inside a project — that's each project's concern
- Does not manage the `roles`, `permissions`, `permission_role`, or `role_user` tables — those live in each project
- Does not handle Google OAuth / Socialite — that is an IdP concern added in Phase 3
- Does not handle MFA — deferred to Phase 3
- Does not manage infrastructure or CI/CD

---

## Phased Delivery Context

| Phase | Status | Scope |
|---|---|---|
| Phase 1 — Foundation | **In progress** | IdP + portal + this package v1 (TokenValidator, AuthMiddleware, CurrentUser, `/me`); revoke route + blacklist **planned** (§8 SECURE_DEFAULTS) |
| Phase 2 — Dynamic Roles & Frontend | Planned | Per-project role management UI, npm package (`@company/auth`) for React/Vue |
| Phase 3 — Hardening | Planned | Google Workspace OAuth, MFA, audit log UI, optional package token-expired route |

---

## Coding Conventions

- **PHP 8.2+** — use named arguments, readonly properties, enums, and fibers where appropriate
- **Strict types** — every file must start with `declare(strict_types=1);`
- **No magic** — prefer explicit method calls over `__get`, `__call` magic where possible
- **PSR-4** autoloading under `Baaboo\InternalToolComposerAuthPackage\` namespace
- **Tests** live in `tests/` under `Baaboo\InternalToolComposerAuthPackage\Tests\` namespace, using Pest
- **Formatting** via `composer format` (Pint) before every commit
- **Static analysis** via `composer analyse` must pass at level 5 minimum before merging
- IdP URL: `CompanyAuth::idpUrl()` (production constant; local override via `IDP_URL` in `.env`). JWKS path and cache TTL are fixed on `CompanyAuth`

---

## Platform constants (`CompanyAuth`)

| Symbol | Value / behaviour | Purpose |
|---|---|---|
| `CompanyAuth::idpUrl()` | `https://auth.company.com` in non-local; `config('company-auth.idp_url')` when `APP_ENV=local` | JWKS fetch + issuer checks — use this in app code |
| `CompanyAuth::IDP_URL` | `https://auth.company.com` | Production IdP base URL (constant) |
| `CompanyAuth::JWKS_PATH` | `/.well-known/jwks.json` | OIDC JWKS discovery path (fixed) |
| `CompanyAuth::OAUTH_SESSION_END_PATH` | `/oauth/session/end` | IdP session end (`Authorization: Bearer` JWT) |
| `CompanyAuth::JWKS_CACHE_TTL` | `3600` | Seconds to cache JWKS keys in the app cache store |

### Environment variables

| Variable | Required | Description |
|---|---|---|
| `IDP_URL` | Local only | Override IdP base URL when `APP_ENV=local` (e.g. `http://sso.test`) |
| `APP_PROJECT_ID` | Yes | Tool slug — must match JWT `aud` and `project_id` |
| `COMPANY_AUTH_CLIENT_SECRET` | Yes | Server-side code exchange secret from SSO registry |
| `COMPANY_AUTH_CLIENT_ID` | No | Defaults to `APP_PROJECT_ID` |
| `COMPANY_AUTH_REDIRECT` | No | Path after successful callback (default `/`) |

---

## Useful Commands

```bash
composer test          # Run Pest test suite
composer analyse       # Run Larastan static analysis
composer format        # Run Pint code formatter
```
