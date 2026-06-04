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

## Requirements

- PHP 8.2+
- Laravel 10–13 (`illuminate/*` components)
- Company SSO IdP at `https://auth.company.com` (or `IDP_URL` in local)

## Quick check

```bash
composer test
composer analyse
```
