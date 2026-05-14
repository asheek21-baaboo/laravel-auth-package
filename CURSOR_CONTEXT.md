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
                     │  JWT (RS256, 15-min expiry)
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
        │  - Own their domain permissions│
        └────────────────────────────────┘
```

---

## Package Responsibilities

| Responsibility | Class / Feature |
|---|---|
| Fetch & cache IdP public key (JWKS) | `TokenValidator` |
| Verify JWT signature + expiry | `TokenValidator` |
| Protect routes, return 401/403 | `AuthMiddleware` (alias: `company.auth`) |
| Expose current user to controllers | `CurrentUser` facade |
| Auto-register `GET /me` route | `AuthServiceProvider` |
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
    'exp'          => 1234567890,            // 15-minute expiry timestamp
]
```

**Algorithm:** RS256 (asymmetric). Tools only need the public key — they never hold the signing secret.

**Token storage:** `httpOnly` cookies only. Tokens must never be accessible to JavaScript.

---

## The `/me` Response Contract

Every internal tool's `/me` endpoint (auto-registered by this package) must return this exact shape. This contract must **never** be changed per project.

```json
{
    "name": "Jane Smith",
    "role": "manager",
    "permissions": ["reports.view", "reports.export", "users.view"]
}
```

For admin roles, `permissions` is always `["*"]`.

---

## Permission Model (Two Layers)

### Layer 1 — Portal Access (IdP-controlled)
- Stored in `project_user` pivot on the IdP
- The `project_role` claim in the JWT carries the coarse role string: `admin`, `manager`, `editor`, `viewer`
- This package reads `project_role` from the token

### Layer 2 — Project Permissions (per tool, not this package's concern)
- Each project owns its own `roles`, `permissions`, `permission_role`, `role_user` tables
- `admin` role is seeded with `["*"]` wildcard — cannot be deleted or modified
- All other roles are runtime-created by project admins
- The `/me` endpoint this package provides resolves the final permissions array from the local project DB

---

## Package File Structure

```
sso-composer-auth-package/
├── composer.json
├── phpstan.neon
├── src/
│   ├── AuthServiceProvider.php        # Registers everything via Laravel auto-discovery
│   ├── TokenValidator.php             # JWKS fetch + cache + JWT verify
│   ├── AuthMiddleware.php             # Registered as 'company.auth'
│   ├── CurrentUserService.php         # Backed by resolved JWT claims
│   ├── Facades/
│   │   └── CurrentUser.php            # Facade over CurrentUserService
│   └── Http/
│       └── Controllers/
│           └── MeController.php       # Auto-registered GET /me
├── tests/
│   ├── TestCase.php                   # Base test case extending Orchestra\Testbench
│   ├── Unit/
│   │   └── TokenValidatorTest.php
│   └── Feature/
│       └── MeEndpointTest.php
└── config/
    └── company-auth.php               # Publishable config (IDP_URL, cache TTL, etc.)
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

```bash
composer require baaboo/internal-tool-composer-auth-package
```

`.env`:
```
IDP_URL=https://auth.company.com
```

Routes:
```php
// Wrap protected routes with the middleware
Route::middleware('company.auth')->group(function () {
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
        $userId   = CurrentUser::id();
        $email    = CurrentUser::email();
        $role     = CurrentUser::role();
        $canExport = CurrentUser::can('reports.export');
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
| Token lifetime | 15 minutes + refresh token | Revocation possible immediately via refresh token blacklist |
| Public key caching | Laravel cache store | Avoid hitting IdP JWKS endpoint on every request |
| IdP technology | Laravel Passport | Native Laravel DX, no external dependency (no Keycloak/Auth0) |
| Framework coupling | `illuminate/*` components only, not `laravel/framework` | Supports Laravel 10–13 without pinning the full framework |
| Static analysis | `larastan/larastan ^3.0` | PHPStan wrapper with Laravel-aware type inference |
| Code style | `laravel/pint` | Opinionated, zero-config Laravel formatter |

---

## Security Rules (Non-Negotiable)

- Tokens are **always** stored in `httpOnly` cookies — never in `localStorage` or accessible to JS
- The `admin` role always returns `["*"]` and can **never** be deleted or modified via API
- HTTPS is enforced across all services; HSTS headers must be set
- Every token event must be logged with actor, IP, user agent, and timestamp
- JWT signing keys are rotatable without downtime via the JWKS endpoint
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
| Phase 1 — Foundation | **In progress** | IdP + portal + this Composer package v1 (TokenValidator, AuthMiddleware, CurrentUser, `/me`) |
| Phase 2 — Dynamic Roles & Frontend | Planned | Per-project role management UI, npm package (`@company/auth`) for React/Vue |
| Phase 3 — Hardening | Planned | Google Workspace OAuth, MFA, refresh token rotation, audit log UI |

---

## Coding Conventions

- **PHP 8.2+** — use named arguments, readonly properties, enums, and fibers where appropriate
- **Strict types** — every file must start with `declare(strict_types=1);`
- **No magic** — prefer explicit method calls over `__get`, `__call` magic where possible
- **PSR-4** autoloading under `Baaboo\InternalToolComposerAuthPackage\` namespace
- **Tests** live in `tests/` under `Baaboo\InternalToolComposerAuthPackage\Tests\` namespace, using Pest
- **Formatting** via `composer format` (Pint) before every commit
- **Static analysis** via `composer analyse` must pass at level 5 minimum before merging
- Config values are read from environment via `config('company-auth.*')` — never `env()` directly inside package classes

---

## Environment Variables

| Variable | Required | Description |
|---|---|---|
| `IDP_URL` | Yes | Base URL of the Laravel IdP, e.g. `https://auth.company.com` |
| `COMPANY_AUTH_CACHE_TTL` | No | Seconds to cache the JWKS public key (default: 3600) |

---

## Useful Commands

```bash
composer test          # Run Pest test suite
composer analyse       # Run Larastan static analysis
composer format        # Run Pint code formatter
```
