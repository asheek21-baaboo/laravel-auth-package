# baaboo/internal-tool-composer-auth-package

Shared SSO authentication for internal Laravel tools. Validates IdP-issued JWTs (RS256), protects routes, syncs identity into the app `users` table, and exposes helpers such as `CurrentUser` and `/me`.

## Install

```bash
composer require baaboo/internal-tool-composer-auth-package
```

**Full setup (env, migrations, routes, guard, Spatie):** [docs/INSTALLATION.md](docs/INSTALLATION.md)

## Documentation

| Document | Description |
|----------|-------------|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Step-by-step integration guide |
| [docs/SECURE_DEFAULTS.md](docs/SECURE_DEFAULTS.md) | Cookies, CSRF, JWT, revocation, logout |
| [CURSOR_CONTEXT.md](CURSOR_CONTEXT.md) | Package contract and architecture |
| [docs/NPM_PACKAGE_SPEC.md](docs/NPM_PACKAGE_SPEC.md) | Parity spec for the Node/npm client (separate repo) |

## OAuth `state` (CSRF on callback)

Login and callback routes use the **`web` middleware group** (session required). When a user hits `GET /login`, the package generates a random `state`, stores it in the session (`company_auth.oauth.state`), and sends it to the IdP authorize URL. On `GET /oauth/callback`, the package compares the returned `state` to the session value with `hash_equals()` **before** exchanging the authorization code. Invalid or missing `state` returns **403** and does not call the IdP token endpoint. The session value is removed after one successful use.

No extra environment variables are required — host apps get this protection automatically.

## Requirements

- PHP 8.2+
- Laravel 10–13 (`illuminate/*` components)
- Company SSO IdP at `https://auth.company.com` (or `IDP_URL` in local)
- Session driver configured for browser routes (`web` middleware on package routes)

## Quick check

```bash
composer test
composer analyse
```
