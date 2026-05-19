# Installation guide — `baaboo/internal-tool-composer-auth-package`

This document walks through installing and configuring the package in an **internal Laravel tool** (HR portal, CRM, ops dashboard, etc.) that authenticates users via the company SSO IdP.

For security defaults (cookies, JWT, revocation, logout), see **[SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md)**.  
For `SsoUser` / `Auth::guard('sso')` / Spatie, see **[SSO_USER.md](./SSO_USER.md)**.  
For package behaviour and JWT contract, see **[CURSOR_CONTEXT.md](../CURSOR_CONTEXT.md)**.

---

## Table of contents

1. [What you get](#1-what-you-get)
2. [Requirements](#2-requirements)
3. [Install via Composer](#3-install-via-composer)
4. [Publish configuration and migrations](#4-publish-configuration-and-migrations)
5. [Environment variables](#5-environment-variables)
6. [Register the app on the IdP](#6-register-the-app-on-the-idp)
7. [Database: `sso_users`](#7-database-sso_users)
8. [Routes provided by the package](#8-routes-provided-by-the-package)
9. [Wire your application routes](#9-wire-your-application-routes)
10. [Accessing the current user](#10-accessing-the-current-user)
11. [Optional: `/me` endpoint](#11-optional-me-endpoint)
12. [Optional: Spatie Laravel Permission](#12-optional-spatie-laravel-permission)
13. [Login and session lifecycle](#13-login-and-session-lifecycle)
14. [Verification checklist](#14-verification-checklist)
15. [Troubleshooting](#15-troubleshooting)
16. [Related commands](#16-related-commands)

---

## 1. What you get

After installation, the package **auto-registers** (Laravel package discovery):

| Feature | Description |
|---------|-------------|
| **JWT validation** | RS256 via IdP JWKS; 10-hour access tokens |
| **Middleware** | `company.auth` — protects routes, 401/redirect on failure |
| **`SsoUser` model** | Local profile (`id` = JWT `sub`, `email`, `name`) for Laravel Auth / Spatie |
| **`sso` guard** | `Auth::guard('sso')->user()` after authentication |
| **OAuth callback** | `GET /oauth/callback` — code exchange, sync user, set cookie |
| **Token expired page** | `GET /oauth/token-expired` — browser UX when JWT expires |
| **`CurrentUser` facade** | JWT claims: `id()`, `email()`, `role()`, etc. |

You do **not** implement token parsing, signature verification, or JWKS fetching in each app.

---

## 2. Requirements

| Requirement | Version / notes |
|-------------|-----------------|
| PHP | `^8.2` |
| Laravel | 10, 11, or 12 (package uses `illuminate/*` components) |
| Database | MySQL, PostgreSQL, SQLite, etc. — for `sso_users` table |
| Cache | Laravel cache store — JWKS public keys cached 1 hour |
| HTTPS | Required in production |
| IdP | Company SSO at `https://auth.company.com` (override locally) |

Each tool must be registered in the **SSO app registry** with a unique `project_id` (e.g. `hr-portal`).

---

## 3. Install via Composer

This package is **private** (not on Packagist). Add the VCS repository to the consuming app’s `composer.json`, then require it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-org/sso-composer-auth-package"
        }
    ],
    "require": {
        "baaboo/internal-tool-composer-auth-package": "^1.0"
    }
}
```

```bash
composer require baaboo/internal-tool-composer-auth-package
```

Laravel discovers `Baaboo\InternalToolComposerAuthPackage\AuthServiceProvider` automatically via `extra.laravel` in the package `composer.json`.

**Confirm discovery:**

```bash
php artisan package:discover
```

You should see the provider listed without manual registration in `config/app.php`.

---

## 4. Publish configuration and migrations

### Configuration (recommended)

Publish `config/company-auth.php` so you can customize values in the app repo:

```bash
php artisan vendor:publish --tag=company-auth-config
```

If you skip publishing, the package merges defaults from vendor.

### Migrations (optional)

The package **auto-loads** the `sso_users` migration on `php artisan migrate`. To copy the migration into your app (e.g. for customization):

```bash
php artisan vendor:publish --tag=company-auth-migrations
```

Then run:

```bash
php artisan migrate
```

---

## 5. Environment variables

Add these to the consuming app’s `.env`. Use the **`SSO_*`** names (they map to `config/company-auth.php`).

### Required

| Variable | Example | Purpose |
|----------|---------|---------|
| `SSO_PROJECT_ID` | `hr-portal` | Tool slug; must match JWT `aud` at callback and IdP registry |
| `SSO_CLIENT_SECRET` | *(secret)* | Server-side secret for authorization code exchange |

### Optional

| Variable | Default | Purpose |
|----------|---------|---------|
| `SSO_CLIENT_ID` | Same as `SSO_PROJECT_ID` | OAuth `client_id` sent to IdP token endpoint |
| `SSO_REDIRECT_AFTER_LOGIN` | `/` | Path after callback **and** when `company.guest` sees an existing session |
| `SSO_REDIRECT_AFTER_LOGOUT` | `/login` | Path when `SSO_REDIRECT_TO_IDP_LOGOUT=false` |
| `SSO_REDIRECT_TO_IDP_LOGOUT` | `true` | Redirect to IdP `/logout` to clear portal session |
| `IDP_URL` | `http://baaboo-sso.test` in config | **Local only** — IdP base URL when `APP_ENV=local` |

### Example `.env` block

```env
APP_URL=https://hr.company.com
APP_ENV=production

# SSO (this package)
SSO_PROJECT_ID=hr-portal
SSO_CLIENT_SECRET=your-secret-from-idp-registry
SSO_CLIENT_ID=hr-portal
SSO_REDIRECT_AFTER_LOGIN=/dashboard

# Local development only
# IDP_URL=http://sso.test
```

### Production vs local IdP URL

- **Production / staging:** JWKS and issuer checks use `https://auth.company.com` (`CompanyAuth::IDP_URL`).
- **Local:** When `APP_ENV=local`, `CompanyAuth::idpUrl()` uses `IDP_URL` from config / `.env`.

Do **not** put JWTs in `localStorage` or expose them to JavaScript. The package sets an **httpOnly** cookie named `token` (10-hour lifetime, `SameSite=Lax`).

---

## 6. Register the app on the IdP

In the SSO admin / app registry, create (or update) an entry for this tool:

| Registry field | Value |
|----------------|--------|
| `project_id` | Same as `SSO_PROJECT_ID` (e.g. `hr-portal`) |
| `client_secret` | Same as `SSO_CLIENT_SECRET` |
| Redirect URI | Must match Laravel route `company-auth.callback` |

**Redirect URI (callback URL):**

```
{APP_URL}/oauth/callback
```

Example: `https://hr.company.com/oauth/callback`

Verify in tinker or route list:

```bash
php artisan route:list --name=company-auth.callback
```

The IdP authorization flow must redirect the browser to this URL with a one-time `?code=...` query parameter.

**JWT claims the IdP must issue** (access token after code exchange):

| Claim | Required | Notes |
|-------|----------|--------|
| `sub` | Yes | UUID; becomes `sso_users.id` |
| `email` | Yes | Used for `SsoUser.email` |
| `name` | No | Display name; falls back to `email` |
| `aud` | Yes | Must equal `SSO_PROJECT_ID` |
| `iss` | Yes | Must equal IdP URL (`CompanyAuth::idpUrl()`) |
| `jti` | Yes | Unique per token (revocation) |
| `exp` / `iat` | Yes | 10-hour access token lifetime |
| `project_role` | Yes* | Coarse role for `/me` and tooling (*per your IdP contract) |
| `global_role` | Yes* | Platform role (`staff`, `super_admin`, etc.) |

---

## 7. Database: `sso_users`

The migration creates:

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID, PK | Same as JWT `sub` |
| `email` | string | From JWT |
| `name` | string, nullable | From JWT `name` or email |
| `created_at` / `updated_at` | timestamps | |

**When rows are written**

| Event | DB action |
|-------|-----------|
| User completes `GET /oauth/callback` | **Upsert** `SsoUser` from JWT claims (login) |
| Each protected request | **Read only** — `find(sub)`; no sync |

If someone has a valid cookie but never hit callback on this app, `company.auth` returns **401** with *"User profile not found. Please sign in again via SSO."*

---

## 8. Routes provided by the package

These are loaded from `routes/company-auth.php` (no manual import needed):

| Method | Path | Route name(s) | Middleware |
|--------|------|---------------|------------|
| `GET` | `/login` | `login`, `company-auth.login` | `web`, `company.guest`, throttle |
| `GET` | `/oauth/login` | `company-auth.login` (alias) | same as `/login` |
| `POST` | `/logout` | `logout`, `company-auth.logout` | `web`, throttle |
| `POST` | `/oauth/logout` | `company-auth.logout` (alias) | `web`, throttle |
| `GET` | `/oauth/callback` | `company-auth.callback` | `web`, throttle |
| `GET` | `/oauth/token-expired` | `company-auth.token-expired` | `web`, throttle |

**Do not register your own `/login`** — the package redirects to the IdP OAuth authorize URL. Use Laravel’s `route('login')` in `redirectGuestsTo()` etc.

**Logout in Blade** (CSRF required):

```blade
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Log out</button>
</form>
```

**Not registered by the package** (you add in the app):

- `GET /me` — see [§11](#11-optional-me-endpoint)

### Middleware aliases

| Alias | Purpose |
|-------|---------|
| `company.auth` | Protected routes — validate JWT, load `SsoUser`, set `Auth::guard('sso')` |
| `company.guest` | Login routes — if valid JWT + `sso_users` row exists, redirect to `SSO_REDIRECT_AFTER_LOGIN` (replaces Laravel `guest` for JWT) |

---

## 9. Wire your application routes

Protected browser routes need **`web`** (session + CSRF for forms) and **`company.auth`** (JWT + `SsoUser`).

```php
// routes/web.php

use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'company.auth'])->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    // … other authenticated routes
});
```

**Public routes** (no `company.auth`):

- Landing/marketing pages
- Health checks
- Package login, logout, callback, and token-expired routes (already registered)

**Do not use Laravel’s `guest` middleware** on `/login` — it checks the `web` guard, not JWT. The package route already uses `company.guest`.

**API / JSON clients** can send `Authorization: Bearer <jwt>` instead of the cookie; the same middleware applies.

---

## 10. Accessing the current user

### JWT claims — `CurrentUser` facade

Use when you need IdP claims not stored on `SsoUser` (e.g. `project_role`, `global_role`):

```php
use Baaboo\InternalToolComposerAuthPackage\Facades\CurrentUser;

$sub = CurrentUser::id();           // JWT sub
$email = CurrentUser::email();
$role = CurrentUser::role();        // project_role
$global = CurrentUser::globalRole();
```

Only available **after** `company.auth` runs on the request.

### Eloquent user — `Auth::guard('sso')`

Use for policies, gates, Spatie, and anything that expects `Authenticatable`:

```php
use Illuminate\Support\Facades\Auth;
use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;

/** @var SsoUser|null $user */
$user = Auth::guard('sso')->user();

if ($user !== null) {
    $user->id;      // UUID
    $user->email;
    $user->name;
}
```

The package registers the `sso` guard and `sso_users` provider automatically. See **[SSO_USER.md](./SSO_USER.md)** for extending the model.

---

## 11. Optional: `/me` endpoint

The package ships `MeController` but does **not** auto-register `/me` (each app chooses the path and middleware group).

```php
use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;

Route::middleware(['web', 'company.auth'])->get('/me', MeController::class);
```

**Response shape** (from JWT, not Spatie):

```json
{
    "name": "jane@company.com",
    "role": "manager",
    "permissions": []
}
```

- `permissions` is only `["*"]` when JWT `project_role` is `admin`; otherwise `[]`.
- Application permissions live in **your** DB / Spatie — not in this response.

---

## 12. Optional: Spatie Laravel Permission

1. Install Spatie in the consuming app and run its migrations (`roles`, `permissions`, pivots).
2. Extend the package model:

```php
// app/Models/SsoUser.php
namespace App\Models;

use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser as BaseSsoUser;
use Spatie\Permission\Traits\HasRoles;

class SsoUser extends BaseSsoUser
{
    use HasRoles;
}
```

3. Point Spatie’s permission config at your subclass (see [SSO_USER.md](./SSO_USER.md)).

4. Use `Auth::guard('sso')->user()->can('reports.export')` on protected routes.

The guard name (`sso`), provider (`sso_users`), and package `SsoUser` model are **fixed** in code — not overridable via `company-auth.php`.

Fine-grained permissions remain **per tool**; the IdP JWT only supplies coarse `project_role`.

---

## 13. Login and session lifecycle

```text
1. User opens tool → no cookie → your app redirects to IdP login (your route or portal)

2. IdP authenticates → redirects to:
   GET https://your-tool.com/oauth/callback?code=ONE_TIME_CODE

3. Package:
   - POSTs code to IdP /oauth/token
   - Validates JWT (signature, exp, iss, aud, jti)
   - Upserts sso_users row
   - Sets httpOnly `token` cookie (10 hours)
   - Redirects to SSO_REDIRECT_AFTER_LOGIN

4. Subsequent requests:
   - company.auth validates JWT
   - Loads SsoUser by sub
   - Sets Auth::guard('sso')->user()

5. JWT expires (browser):
   - Redirect to /oauth/token-expired, cookie cleared
   - User clicks link → IdP login again

6. JWT expires (JSON API):
   - 401 { "message": "Token has expired." }
```

There are **no refresh tokens** for internal tools. See **[SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) §7**.

---

## 14. Verification checklist

Use this after installation:

- [ ] `composer require` succeeded; provider discovered
- [ ] `SSO_PROJECT_ID` and `SSO_CLIENT_SECRET` set in `.env`
- [ ] `php artisan migrate` — `sso_users` table exists
- [ ] IdP registry redirect URI = `{APP_URL}/oauth/callback`
- [ ] `php artisan route:list` shows `company-auth.callback` and `company-auth.token-expired`
- [ ] Protected routes use `middleware(['web', 'company.auth'])`
- [ ] Login flow: callback sets `token` cookie and creates `sso_users` row
- [ ] `Auth::guard('sso')->user()` non-null on protected routes after login
- [ ] `GET /me` returns 200 with `name`, `role`, `permissions` (if registered)
- [ ] Expired token: browser → token-expired page; API → 401 JSON
- [ ] Production: `APP_ENV=production`, HTTPS, `APP_DEBUG=false`

---

## 15. Troubleshooting

### `401 Unauthenticated.` on every request

- No `token` cookie and no `Authorization: Bearer` header.
- User must log in via IdP → callback.

### `401 User profile not found. Please sign in again via SSO.`

- JWT is valid but no `sso_users` row for `sub`.
- User must complete `/oauth/callback` at least once on **this** app (not only another tool).

### `403` on `/oauth/callback`

- Invalid or expired `code`.
- JWT `aud` ≠ `SSO_PROJECT_ID`, or missing `iss` / `jti`.
- Wrong `SSO_CLIENT_SECRET` or IdP rejected the exchange.
- Check Laravel log and IdP token endpoint response.

### `APP_PROJECT_ID is not configured` / secret errors

- Set `SSO_PROJECT_ID` and `SSO_CLIENT_SECRET` in `.env` (config keys `company-auth.project_id` / `client_secret`).

### JWKS / signature errors

- App must reach `{IdP_URL}/.well-known/jwks.json`.
- Ensure cache is configured (`CACHE_STORE` / Redis, etc.).
- Clock skew: server time must be accurate for `exp` validation.

### `CurrentUser accessed before AuthMiddleware ran`

- Controller/route missing `company.auth` middleware.

### Spatie `can()` always false

- Using wrong guard: pass `sso` to Spatie config or use `Auth::guard('sso')->user()`.
- Permissions are app-local; JWT `project_role` ≠ Spatie permission names.

---

## 16. Related commands

```bash
# Publish config
php artisan vendor:publish --tag=company-auth-config

# Publish migration copy (optional)
php artisan vendor:publish --tag=company-auth-migrations

# Run migrations (includes package sso_users if not published)
php artisan migrate

# List package auth routes
php artisan route:list --path=oauth
```

**Further reading**

| Document | Topic |
|----------|--------|
| [SSO_USER.md](./SSO_USER.md) | `SsoUser`, `sso` guard, Spatie |
| [SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) | Cookies, CSRF, revoke, logout |
| [CURSOR_CONTEXT.md](../CURSOR_CONTEXT.md) | JWT contract, package API |
| [TODO.md](./TODO.md) | Logout, revoke, roadmap |

---

*Package: `baaboo/internal-tool-composer-auth-package` · Namespace: `Baaboo\InternalToolComposerAuthPackage`*
