# Laravel SSO integration prompt

`baaboo/internal-tool-composer-auth-package` — wire company SSO into an existing internal Laravel tool.

## Copy-paste into your agent

Open the **consuming Laravel app** in Cursor, then paste:

> Follow `docs/prompts/INTEGRATE_LARAVEL_SSO_PACKAGE.md` and `docs/INSTALLATION.md` end to end. Interview me for the install settings, replace any existing login/logout, `composer require` and publish this package, copy the SSO `.env` keys, wire protected routes with `company.auth`, and verify the login flow.

If the prompt files live only in the package repo, @-mention them from `sso-composer-auth-package` (or paste this file into the chat once).

---

You are integrating **company SSO** into an **internal Laravel tool** (HR portal, CRM, ops dashboard, etc.) using the private Composer package:

| Item | Value |
|------|--------|
| Package | `baaboo/internal-tool-composer-auth-package` |
| Namespace | `Baaboo\InternalToolComposerAuthPackage` |
| Auth middleware | `company.auth` |
| Guest middleware (JWT) | `company.guest` |
| Guard | `Auth::guard('sso')` — `App\Models\User`, provider key `users` |

The package **owns** JWT validation, JWKS, OAuth callback, login redirect to the IdP, logout, token-expired UX, and cookie handling. **Do not reimplement** token parsing, signature verification, or duplicate auth routes.

### Before changing any file

1. Read **`docs/INSTALLATION.md`** in the package repo (or the copy your team maintains) — full install flow, env vars, routes, troubleshooting.
2. Read **`docs/SECURE_DEFAULTS.md`** — httpOnly cookie, CSRF on logout, no JWT in `localStorage`.
3. **Inspect the consuming Laravel app** and report what you found:
   - `composer.json` — is the VCS repo for this package already listed?
   - `.env` / `.env.example` — existing `SSO_*` or legacy auth env vars
   - `routes/web.php`, `routes/auth.php`, `bootstrap/app.php` — custom `/login`, `/logout`, `guest` middleware, Breeze/Fortify/Jetstream routes
   - Controllers/views named `Login*`, `Logout*`, or Fortify/Breeze auth scaffolding
   - `config/auth.php` — default guard; whether the app still expects session `web` guard for “logged in”
4. Ask the human for **`SSO_PROJECT_ID`**, **`SSO_CLIENT_SECRET`**, and **`APP_URL`** if not already in `.env` or IdP registry docs. Do not invent secrets.

**Working directory:** the **consuming Laravel app root**, not the package source repo (unless you are editing the package itself).

---

### Product rules (do not violate)

| Rule | Detail |
|------|--------|
| Single `/login` | Package registers `GET /login` → IdP OAuth (`route('login')`). **Remove** app-defined `/login` that conflicts. |
| Single `/logout` | Package registers `POST /logout` (`route('logout')`). **Remove** app `GET /logout` or duplicate POST handlers. Use CSRF form in Blade. |
| No duplicate OAuth paths | Package provides `/oauth/callback`, `/oauth/token-expired`, `/oauth/error`. **Do not** register your own callback. |
| Protected routes | Use `middleware(['web', 'company.auth'])` — not Laravel `auth` / `auth:web` alone. |
| Guest on login | Package `/login` already uses `company.guest`. **Never** add Laravel `guest` middleware to `/login`. |
| JWT in browser | **Forbidden** in JS. Identity for SPAs: server `GET /me` with cookie (`credentials: 'include'`). |
| Cookie | Name `token`, httpOnly, 10 hours, `SameSite=Lax` — set by package only. |
| IdP redirect URI | Must be `{APP_URL}/oauth/callback` — verify with `php artisan route:list --name=company-auth.callback`. |

---

### Phase 1 — Composer install

1. If the package is **private**, ensure `composer.json` has the VCS repository (adjust URL to your org):

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-org/sso-composer-auth-package"
        }
    ]
}
```

2. Require the package:

```bash
composer require baaboo/internal-tool-composer-auth-package
```

3. Confirm Laravel discovery (no manual `AuthServiceProvider` in `config/app.php`):

```bash
php artisan package:discover
```

Provider: `Baaboo\InternalToolComposerAuthPackage\AuthServiceProvider`.

---

### Phase 2 — Publish config and migrate

**Config (recommended):**

```bash
php artisan vendor:publish --tag=company-auth-config
```

**Migrations (optional copy; package auto-loads migration if not published):**

```bash
php artisan vendor:publish --tag=company-auth-migrations
php artisan migrate
```

If you skip publishing migrations, run `php artisan migrate` anyway — the package loads `ensure_users_table_for_company_auth` (non-destructive if `users` already exists).

---

### Phase 3 — Environment variables (`.env`)

Append or merge into the consuming app’s **`.env`** and mirror required keys in **`.env.example`** (placeholders only — no real secrets in example).

**Required:**

```env
SSO_PROJECT_ID=your-tool-slug
SSO_CLIENT_SECRET=from-idp-registry
```

**Recommended:**

```env
APP_URL=https://your-tool.company.com
SSO_CLIENT_ID=your-tool-slug
SSO_REDIRECT_AFTER_LOGIN=/dashboard
SSO_REDIRECT_TO_IDP_LOGOUT=true
```

**Local development only:**

```env
APP_ENV=local
IDP_URL=http://baaboo-sso.test
```

After editing `.env`:

```bash
php artisan config:clear
```

| Variable | Purpose |
|----------|---------|
| `SSO_PROJECT_ID` | Tool slug; must match JWT `aud` and IdP registry |
| `SSO_CLIENT_SECRET` | Server-side OAuth code exchange |
| `SSO_CLIENT_ID` | Defaults to `SSO_PROJECT_ID` if omitted |
| `SSO_REDIRECT_AFTER_LOGIN` | Post-callback redirect; also used by `company.guest` |
| `SSO_REDIRECT_TO_IDP_LOGOUT` | `true` → server POST to IdP `/oauth/session/end` with Bearer JWT; `false` → skip IdP session end. Both redirect to package `logged_out` error page. |
| `IDP_URL` | Local only — overrides production IdP base when `APP_ENV=local` |

Tell the human to register the app on the IdP with:

- `project_id` = `SSO_PROJECT_ID`
- `client_secret` = `SSO_CLIENT_SECRET`
- Redirect URI = `{APP_URL}/oauth/callback`

---

### Phase 4 — Remove conflicting login / logout (critical)

Search the consuming app and **remove or refactor** anything that duplicates package auth:

| Find | Action |
|------|--------|
| `Route::get('/login', …)` in app routes | **Delete** — package owns `GET /login` |
| `Route::post('/login', …)` (Breeze/Fortify) | **Delete** if switching fully to SSO |
| `Route::get|post('/logout', …)` in app | **Delete** — package owns `POST /logout` |
| `Route::get('/oauth/callback', …)` | **Delete** — package owns callback |
| Laravel Breeze / Fortify / Jetstream install | Remove auth routes from `routes/auth.php` or disable provider; keep only what the app still needs |
| Custom `LoginController` / `AuthenticatedSessionController` | Remove if unused; update imports |
| Blade links to `/login` | Change to `route('login')` |
| Logout links | Use POST form: `action="{{ route('logout') }}"` + `@csrf` |
| `middleware('guest')` on login routes | **Remove** — use package login only (`company.guest` is internal) |
| `redirectGuestsTo('/login')` pointing at wrong path | Point to `route('login')` or `/login` |

**Do not** register a second route named `login` or `logout` — Laravel will fail or shadow the package.

After cleanup:

```bash
php artisan route:list --path=login
php artisan route:list --path=logout
php artisan route:list --path=oauth
```

Expected package routes (names may vary slightly):

- `GET login`
- `POST logout`
- `company-auth.callback` → `/oauth/callback`
- `company-auth.token-expired` → `/oauth/token-expired`
- `company-auth.error` → `/oauth/error`

---

### Phase 5 — Wire application routes

1. Wrap **authenticated browser routes** in `web` + `company.auth`:

```php
// routes/web.php
use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'company.auth'])->group(function () {
    Route::get('/me', MeController::class); // optional but recommended
    // Route::get('/dashboard', ...);
});
```

2. Leave **public** routes outside `company.auth` (landing, health checks).

3. **Laravel 11+** — in `bootstrap/app.php`, send unauthenticated guests to SSO login:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->redirectGuestsTo(fn () => route('login'));
})
```

4. **Do not** protect package routes (`/login`, `/logout`, `/oauth/*`) with `company.auth`.

5. For **API/JSON** clients: same `company.auth`; they may send `Authorization: Bearer <jwt>` instead of the cookie.

---

### Phase 6 — Use the authenticated user

**Eloquent (policies, Spatie, gates):**

```php
$user = Auth::guard('sso')->user();
```

**JWT claims (`project_role`, `global_role`):**

```php
use Baaboo\InternalToolComposerAuthPackage\Facades\CurrentUser;

CurrentUser::id();
CurrentUser::email();
CurrentUser::role();
CurrentUser::globalRole();
```

Only after `company.auth` on the request.

**Optional Spatie:** add `HasRoles` to your app's `App\Models\User`; point `config/permission.php` at that model. See INSTALLATION.md §12.

---

### Phase 7 — UI snippets

**Logout (Blade):**

```blade
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Log out</button>
</form>
```

**Login link:**

```blade
<a href="{{ route('login') }}">Sign in</a>
```

---

### Phase 8 — Verification checklist

Run through with the human; fix anything that fails:

- [ ] `composer require` succeeded; `php artisan package:discover` lists the provider
- [ ] `config/company-auth.php` published (or merged defaults work)
- [ ] `.env` has `SSO_PROJECT_ID` and `SSO_CLIENT_SECRET`; `.env.example` updated
- [ ] `php artisan migrate` — `users` ready (UUID `id` aligned with JWT `sub`)
- [ ] No duplicate `login` / `logout` / `oauth/callback` routes in the app
- [ ] IdP redirect URI = `{APP_URL}/oauth/callback`
- [ ] Protected routes use `['web', 'company.auth']`
- [ ] `redirectGuestsTo(route('login'))` (or equivalent) configured
- [ ] Login flow: IdP → callback → `token` cookie → redirect to `SSO_REDIRECT_AFTER_LOGIN`
- [ ] `Auth::guard('sso')->user()` non-null on protected routes after login
- [ ] `GET /me` returns 200 with `name`, `role`, `permissions` (if registered)
- [ ] Expired JWT: browser → `/oauth/token-expired`; API → `401` JSON
- [ ] Production: `APP_ENV=production`, HTTPS, `APP_DEBUG=false`

---

### What NOT to do

- Do not store JWTs in `localStorage` or expose them to frontend JavaScript.
- Do not implement your own `/oauth/callback` or JWKS fetching.
- Do not use Laravel `guest` middleware for SSO login pages.
- Do not use `auth:web` or default `auth` middleware instead of `company.auth` for SSO-protected pages.
- Do not commit real `SSO_CLIENT_SECRET` values.
- Do not add a second `GET /login` “for convenience”.
- Do not skip removing old Breeze/Fortify logout routes — they break CSRF/logout expectations.

---

### Troubleshooting (quick reference)

| Symptom | Likely fix |
|---------|------------|
| `401 Unauthenticated` | No cookie; user must hit `route('login')` → IdP → callback |
| `401 User profile not found…` | User needs successful `/oauth/callback` on **this** app once |
| `403` on callback | Wrong secret, `aud`, or redirect URI mismatch |
| `CurrentUser accessed before AuthMiddleware` | Add `company.auth` to the route |
| Route conflict / wrong login page | Remove duplicate app `/login` routes |

Full table: INSTALLATION.md §15.

---

### Output format (when you finish)

Provide the human a short report:

1. **Discovery** — what auth scaffolding existed and what you removed
2. **Files changed** — list with one-line reason each
3. **`.env` keys added** — names only (no secret values)
4. **Commands run** — composer, publish, migrate, route:list
5. **Manual steps left** — IdP registry, deploy env on server, first login test URL
6. **Verification** — checklist above with pass/fail per item

If something is blocked (missing IdP credentials, PHP version, no Composer repo access), stop and list blockers instead of guessing.

---

**Related:** [`BUILD_NPM_PACKAGE.md`](./BUILD_NPM_PACKAGE.md) — Node/React/Vue (`@baaboo/company-auth-*`), not this Composer package.
