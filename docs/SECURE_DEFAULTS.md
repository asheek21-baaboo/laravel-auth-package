# Secure defaults for internal Laravel tools

This document defines the **recommended security baseline** for browser-based internal tools that use `baaboo/internal-tool-composer-auth-package`. It covers cookies, JWT claims, middleware, CSRF, token expiry / SSO re-login, **cross-app revocation**, logout, and how this compares to classic Laravel sessions.

**Internal tools policy:** one **10-hour** access JWT per login (no refresh tokens). When it expires, the user sees a dedicated page and must sign in again via the IdP. **Revocation** uses a per-app `sub` blacklist (and optional `jti` blacklist) fed by the IdP via authenticated `POST /auth/revoke` calls secured with a **short-lived service JWT** (`aud` = that app only).

**Audience:** developers integrating a new tool, and IdP engineers issuing tokens.

**Package version:** Phase 1 (v1). Items marked **(planned)** are not implemented in the package yet but are part of the platform design.

---

## 1. Architecture at a glance

Use a **hybrid** model: a real server session at the IdP, and a **10-hour JWT per tool** in an `httpOnly` cookie (no refresh token).

```
User ──► IdP portal (server session at auth.company.com)
         │
         └──► Redirect to tool with one-time code
                │
                └──► Tool callback exchanges code ──► IdP returns JWT
                       │
                       └──► Tool sets `token` cookie (httpOnly)
                              │
                              └──► Every request: company.auth validates JWT (this package)
                                     │
                                     └──► If expired: token-expired page → user logs in via IdP again

IdP deactivation / offboarding (server-to-server, per app):
         SSO ──POST /auth/revoke + service JWT (aud = this app)──► each child app
              └──► package stores revoked `sub` in cache ──► next user request → 401 → SSO
```

| Layer | Technology | Purpose |
|-------|------------|---------|
| IdP login & portal | Laravel **server session** | Single sign-on, launchpad, MFA (later) |
| Tool authentication | **JWT** in `httpOnly` cookie | Stateless verification in each app via this package |
| Tool authorization | Per-project DB / policies | Fine-grained permissions (not in this package) |

**Do not** share one JWT cookie across all `*.company.com` apps. Issue a **project-scoped** token (`project_id` claim) and set the cookie **without** a parent `Domain=` unless you explicitly accept cross-app cookie scope.

---

## 2. Cookie secure defaults

The package reads the access token from a cookie named **`token`** (see `AuthMiddleware`). The consuming project is responsible for **setting** that cookie after a successful code exchange.

### 2.1 Recommended flags (production)

| Flag | Value | Why |
|------|-------|-----|
| `HttpOnly` | `true` | JavaScript cannot read the token (mitigates XSS token theft) |
| `Secure` | `true` | Cookie only sent over HTTPS |
| `SameSite` | `Lax` | Default for top-level redirects from the IdP portal; blocks most cross-site cookie sends |
| `Path` | `/` | Sent on all app routes |
| `Domain` | **omit** (host-only) | Cookie bound to `hr.company.com`, not all subdomains |

Use `SameSite=None` only if the tool must receive the cookie on **embedded** cross-site contexts (iframes, cross-site XHR). That requires `Secure=true` and stricter CSRF controls.

### 2.2 Laravel example (after code exchange)

```php
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

// $jwt = string from IdP token endpoint after validating one-time code

$minutes = 600; // 10 hours — must match JWT `exp` − `iat` (see §3.3)

return redirect('/dashboard')->withCookie(
    cookie(
        name: 'token',
        value: $jwt,
        minutes: $minutes,
        path: '/',
        domain: null,           // host-only — do not use .company.com
        secure: true,
        httpOnly: true,
        raw: false,
        sameSite: SymfonyCookie::SAMESITE_LAX,
    )
);
```

### 2.3 Local development

| Environment | `Secure` | Notes |
|-------------|----------|-------|
| Production / staging | `true` | HTTPS only |
| Local (`http://localhost`) | `false` only on localhost | Browsers allow non-Secure cookies on localhost; never disable `Secure` on shared staging URLs |

Keep `HttpOnly` and `SameSite=Lax` in all environments.

### 2.4 What not to do

- Do **not** store the JWT in `localStorage`, `sessionStorage`, or non-`httpOnly` cookies.
- Do **not** expose the JWT to the frontend (no `window.__TOKEN__`, no SPA bearer from JS).
- Do **not** issue JWTs longer than the platform TTL (see §3.3 — **10 hours** for internal tools).
- Do **not** add refresh tokens for internal browser tools; re-login via the IdP when the JWT expires (see §7).

### 2.5 No refresh token cookie

Internal tools use **only** the `token` cookie (access JWT). There is no `refresh_token` cookie and no silent renewal endpoint.

When the JWT expires, the user must authenticate again through the IdP (see §7).

---

## 3. JWT claims contract

Tokens are **RS256**-signed by the IdP. This package verifies signature and **`exp`** via `firebase/php-jwt` and the IdP JWKS (`CompanyAuth::idpUrl()` + `CompanyAuth::JWKS_PATH`).

### 3.1 Required claims (IdP must issue)

| Claim | Type | Example | Enforced by package today |
|-------|------|---------|---------------------------|
| `sub` | string (UUID) | `550e8400-e29b-41d4-a716-446655440000` | Used by `CurrentUser::id()` |
| `email` | string | `jane@company.com` | Used by `CurrentUser::email()` |
| `global_role` | string | `staff` \| `super_admin` | Used by `CurrentUser::globalRole()` |
| `project_id` | string | `hr-portal` | Used by `CurrentUser::projectId()` |
| `project_role` | string | `manager` | Used by `CurrentUser::role()` |
| `exp` | int (Unix) | `1715778900` | **Yes** — expired tokens rejected |
| `iat` | int (Unix) | `1715778000` | Issued-at; keep skew small |

### 3.2 Strongly recommended claims (IdP should issue; tool may assert)

| Claim | Purpose | Package v1 |
|-------|---------|------------|
| `iss` | Issuer URL (`https://auth.company.com`) | Not validated yet — **add in IdP + assert in tool callback** |
| `aud` | Audience = this tool’s `project_id` or client id | Not validated yet — **assert in tool** to prevent cross-app token replay |
| `nbf` | Not-before | Optional; `firebase/php-jwt` respects it if present |
| `jti` | Unique token id per access JWT | **Required** — enables single-token revoke; see §8 |

**Example assertion in tool callback (before setting cookie):**

```php
use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;

$claims = /* decode or trust IdP JSON response */;

if (($claims['iss'] ?? '') !== CompanyAuth::idpUrl()) {
    abort(403, 'Invalid issuer');
}

if (($claims['aud'] ?? '') !== config('app.project_id')) {
    abort(403, 'Invalid audience');
}

if (($claims['project_id'] ?? '') !== config('app.project_id')) {
    abort(403, 'Token not scoped to this project');
}
```

### 3.3 Access token lifetime (internal tools)

| Setting | Default |
|---------|---------|
| Access token (`token` cookie) | **10 hours** from `iat` (`exp` = `iat` + 36 000 seconds) |
| IdP issuance | IdP must set `exp` accordingly when issuing the JWT after code exchange |
| Cookie `minutes` | **600** when setting the cookie (must match JWT lifetime) |
| Clock skew | ≤ 30 seconds between IdP and tools (NTP) |
| JWKS cache (`CompanyAuth::JWKS_CACHE_TTL`) | **3600** (1 hour) — fixed in package; key rotation still works via `kid` |

After 10 hours the JWT is invalid. The tool shows the token-expired page (§7); the user starts a new SSO login to obtain a fresh JWT. No refresh token is issued or stored.

### 3.4 Algorithm & keys

- **Algorithm:** RS256 only.
- **Keys:** IdP holds private key; tools fetch public keys from `CompanyAuth::idpUrl()` + `CompanyAuth::JWKS_PATH`.
- **Rotation:** IdP publishes multiple keys in JWKS; package caches keys for `cache_ttl` — call `TokenValidator::forgetCachedKey()` in tests or after rotation drills.

---

## 4. What this package validates (middleware)

`company.auth` (`AuthMiddleware`) on each protected request:

1. Extract token: `Authorization: Bearer` **or** cookie `token` (Bearer wins).
2. Verify RS256 signature using JWKS from IdP.
3. Reject if missing, malformed, bad signature, or **expired**.
4. Hydrate `CurrentUser` from claims.

**(planned)** Reject if `sub` or `jti` is on the local revocation blacklist (§8).

It does **not** (v1):

- ~~Run the OAuth/code exchange or set cookies~~ — **exchange + cookie** are handled by `GET /auth/callback` in the package
- Validate `iss` / `aud` / `project_id` on **every request** (enforced at callback; optional app middleware)
- Enforce CSRF (app responsibility for cookie-based POST/PUT/DELETE)
- Call logout on the IdP (optional; consuming project)

### 4.1 Recommended route layout (consuming project)

```php
// routes/web.php

// Public — no company.auth (package registers /auth/callback and /auth/token-expired)
Route::get('/auth/login', AuthLoginController::class);           // optional — redirect to IdP
Route::get('/auth/token-expired', TokenExpiredController::class); // optional if you override the package page
Route::post('/auth/logout', AuthLogoutController::class)->middleware('web');
// POST /auth/revoke — SSO server only, service JWT (planned in package; see §8)

// Protected — web (cookies + CSRF) + package auth
Route::middleware(['web', 'company.auth'])->group(function () {
    Route::get('/me', \Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController::class);
    Route::get('/dashboard', DashboardController::class);
});
```

Use the `web` middleware group on all browser routes that use the `token` cookie.

### 4.2 Optional app-level middleware (recommended)

Add after `company.auth` or inside your base controller:

| Check | When |
|-------|------|
| `project_id === config('app.project_id')` | Every authenticated request |
| HTTPS redirect | `AppServiceProvider` / load balancer |
| Rate limit on `/auth/callback` | Brute force on one-time codes |

---

## 5. CSRF (required for cookie auth)

Browsers automatically send cookies. Any **state-changing** request (`POST`, `PUT`, `PATCH`, `DELETE`) from a browser must be CSRF-protected.

**Defaults for Laravel Blade / Inertia tools:**

```php
// bootstrap/app.php or RouteServiceProvider — typical web stack
Route::middleware(['web', 'company.auth'])->group(function () {
    // routes
});
```

- Enable Laravel’s `VerifyCsrfToken` on the `web` group.
- For SPA (Inertia/Livewire): use `@csrf` / `X-XSRF-TOKEN` from the encrypted `XSRF-TOKEN` cookie.
- Do **not** disable CSRF globally because “we use JWT.”

**Safe without CSRF:** `GET`, `HEAD`, `OPTIONS` (if you only use cookies on mutating routes behind CSRF, you are aligned with `SameSite=Lax`).

---

## 6. Login flow: one-time code exchange

**Implemented:** `GET /auth/callback` (`AuthCallbackController`, route name `company-auth.callback`).

1. User lands with `?code=...` (HTTPS only).
2. Package POSTs to `{CompanyAuth::idpUrl()}/api/auth/token` with `grant_type`, `code`, `redirect_uri`, `client_id`, `client_secret`, `project_id`.
3. IdP returns `{ "access_token": "<jwt>" }` (or `"token"`).
4. Package verifies RS256 + `exp`, then asserts `iss`, `aud`, `project_id`, and `jti` against `APP_PROJECT_ID`.
5. Sets `token` httpOnly cookie (§2) and redirects to `COMPANY_AUTH_REDIRECT` (default `/`).

PKCE remains an IdP/login concern when the authorization step uses OAuth2; the callback only receives the one-time `code`.

**One-time code (IdP):**

| Property | Recommendation |
|----------|----------------|
| TTL | 60–120 seconds |
| Use count | 1 |
| Transport | Query param on redirect, over HTTPS only |

---

## 7. Token expiry and SSO re-login (no refresh tokens)

Internal tools **do not** use refresh tokens. Session continuity for a work day is covered by a single **10-hour** access JWT. When it expires, the user must log in again via SSO.

### 7.1 When the JWT expires

`company.auth` rejects expired tokens (`InvalidTokenException::expired()`). For **browser** routes, do not leave users on a blank 401 JSON response — show a dedicated page:

> **Token expired, please log in via SSO.**

| Concern | Recommendation |
|---------|----------------|
| Copy | Exact message above (or equivalent); include a clear call-to-action button/link |
| CTA | Link to IdP login or tool `GET /auth/login` (redirects to IdP with `redirect_uri` + `project_id`) |
| Cookie | Clear or ignore the expired `token` cookie when rendering the page |
| API / JSON clients | May continue to return `401` JSON without the HTML page |

For **browser** requests that are not expecting JSON, an **expired** JWT triggers a **redirect** to `GET /auth/token-expired` (message + link to IdP from `CompanyAuth::idpUrl()`), and clears the `token` cookie. **JSON / API** clients still receive `401` with `Token has expired.`

### 7.2 Re-login flow after expiry

```
User (expired JWT) ──► GET /auth/token-expired  (or middleware redirect)
                    ──► Page: "Token expired, please log in via SSO."
                    ──► User clicks "Log in via SSO"
                    ──► GET /auth/login ──► redirect to IdP
                    ──► IdP login (if needed) ──► one-time code ──► /auth/callback
                    ──► New 10-hour JWT in `token` cookie ──► app
```

If the IdP still has a valid server session, the user may not need to re-enter credentials; that is IdP behaviour. The tool always requires a **new** JWT from the code exchange.

### 7.3 Why no refresh token

| Refresh tokens | This platform (internal tools) |
|----------------|------------------------------|
| Silent renewal without redirect | Not required — 10-hour JWT is enough for a work session |
| Extra cookies / refresh rotation | Avoided — revocation via §8 service JWT + cache, not refresh tokens |
| Long-lived secondary secret | Replaced by explicit SSO re-login after expiry |

---

## 8. Revocation (`POST /auth/revoke`) — service JWT, per app

A **10-hour** user JWT cannot be recalled by expiry alone when an employee is terminated or a laptop is stolen. The platform uses **immediate revocation**: the IdP calls each registered child app with a **direct HTTPS POST** (not a browser request). Each app’s package validates a **short-lived IdP service JWT** and writes to a local cache blacklist.

This reuses the same RS256 + JWKS stack as user tokens — no per-app shared secrets on the wire beyond what the IdP already signs.

### 8.1 End-to-end flow (employee deactivation)

```
Admin deactivates user (e.g. HR system → SSO)
         │
         ▼
SSO marks user deactivated (DB)          ← blocks new logins first
         │
         ▼
SSO fans out direct POST to every registered child app
    ├── POST https://hr.company.com/auth/revoke       (service JWT aud = hr-portal)
    ├── POST https://finance.company.com/auth/revoke (service JWT aud = finance)
    └── POST https://inventory.company.com/auth/revoke
         │
         ▼
Each package: verify service JWT → blacklist `sub` in cache (TTL ≥ 10h)
         │
         ▼
User’s next request on any app → company.auth → 401
         │
         ▼
Frontend → SSO login → SSO rejects (user deactivated)
         │
         ▼
Locked out across apps ✅
```

**Order:** deactivate on SSO **before** (or atomically with) fan-out so the user cannot obtain a new 10-hour JWT during the revoke window.

**Retries:** SSO must retry failed POSTs (queue/job). A missed app = user still has access there until retry succeeds.

### 8.2 Why service JWT (Option 3) — per application

| Approach | This platform |
|----------|----------------|
| Per-app static Bearer secret | Works, but another secret to rotate per app |
| **Short-lived service JWT** | Same JWKS as user JWTs; **`aud` scopes token to one app**; IdP already signs JWTs |

For each child app in the SSO registry, the IdP mints a **new** service JWT whose `aud` is **only** that app’s `project_id`. HR’s revoke token must not be accepted by finance — enforced by `aud === config('app.project_id')` on the child.

### 8.3 Service JWT claims (IdP issues)

Separate token type from the user access JWT. Short TTL (no long-lived machine tokens).

| Claim | Value | Notes |
|-------|-------|-------|
| `iss` | `https://auth.company.com` | Must match `CompanyAuth::idpUrl()` |
| `aud` | `hr-portal` | **This app only** — must equal `APP_PROJECT_ID` on the child |
| `sub` | `sso-revoke-service` | Fixed service subject (not the user being revoked) |
| `exp` | `iat` + 60–120s | Short-lived |
| `iat` | Unix timestamp | |
| `jti` | Unique id | Optional replay logging |
| `action` | `revoke` | Constant — reject other values |
| `revoke_sub` | User UUID | User to lock out (may duplicate POST body) |

**Algorithm:** RS256, same JWKS endpoint as user tokens.

### 8.4 `POST /auth/revoke` contract

**(planned)** Registered by this package. Server-to-server only — exclude from browser CSRF/session; not protected by `company.auth`.

```http
POST /auth/revoke HTTP/1.1
Host: hr.company.com
Authorization: Bearer <service-jwt>
Content-Type: application/json

{
  "sub": "550e8400-e29b-41d4-a716-446655440000"
}
```

| Field | Rule |
|-------|------|
| `Authorization` | `Bearer` + service JWT |
| Body `sub` | UUID of user to revoke |
| Body `jti` | Optional — revoke single access token instead of whole user |

**Package validation (in order):**

1. Parse Bearer service JWT.
2. Verify RS256 signature via JWKS (`TokenValidator` / same keys).
3. Validate `exp`, `iss`, `action === 'revoke'`.
4. Validate `aud` (and/or `project_id` if present) **equals** `config('app.project_id')` — **per-app gate**.
5. Validate `sub` (service subject) is `sso-revoke-service` (or allowlisted service subjects).
6. If body includes `sub`, it must match `revoke_sub` in the JWT when both are present.
7. Write cache (see §8.5).
8. Return `204 No Content` or `200 { "revoked": true }`.

Reject with `401` / `403` on any failure. Rate-limit by SSO egress IP if exposed beyond internal network.

### 8.5 Cache blacklist (each app, local)

Use the Laravel cache store (Redis recommended).

| Key | When | TTL |
|-----|------|-----|
| `revoked:sub:{uuid}` | Offboarding / deactivate user | **≥ 10 hours** (match max access JWT life) |
| `revoked:jti:{id}` | Stolen laptop / single session | Until that token’s `exp` (or 10h max) |

**Enforcement (planned in `company.auth`):** after signature + `exp` check on the **user** access JWT:

- If `revoked:sub:{claims.sub}` exists → `401`.
- If `claims.jti` present and `revoked:jti:{jti}` exists → `401`.

User JWTs must include `jti` (§3.2) for single-token revoke.

### 8.6 SSO registry (IdP responsibility)

| Field | Example |
|-------|---------|
| `project_id` | `hr-portal` |
| `base_url` | `https://hr.company.com` |
| `revoke_url` | `{base_url}/auth/revoke` |

On deactivate: loop registry → mint service JWT with `aud` = that row’s `project_id` → `POST` with body `{ "sub": "<deactivated-user-uuid>" }`.

### 8.7 IdP example (mint + call)

```php
// SSO — once per registered app (pseudocode)
$serviceJwt = $idpSigner->issue([
    'iss' => config('app.url'),
    'aud' => $app->project_id,      // hr-portal — unique per POST target
    'sub' => 'sso-revoke-service',
    'action' => 'revoke',
    'revoke_sub' => $deactivatedUserId,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => Str::uuid()->toString(),
]);

Http::withToken($serviceJwt)
    ->timeout(5)
    ->post($app->revoke_url, ['sub' => $deactivatedUserId]);
```

### 8.8 Security notes

- **Not a user cookie** — service JWT travels only in the `Authorization` header from SSO servers.
- **Per-app `aud`** — finance cannot accept HR’s revoke JWT even if the request is intercepted.
- **Deactivate first** — SSO user record disabled before fan-out.
- **HTTPS only** — same as all auth traffic.
- **Do not** reuse the user’s 10-hour JWT for revoke calls.

---

## 9. Logout

| Step | Action |
|------|--------|
| 1 | Clear `token` cookie — `expire` in the past, same `Path`/`Domain` as when set |
| 2 | Optionally redirect to IdP global logout to clear portal session |

```php
return redirect('https://auth.company.com/logout')
    ->withoutCookie('token');
```

Voluntary logout does not replace §8 — offboarding must still run the IdP fan-out.

---

## 10. Session-based auth vs JWT cookie (for your stack)

| Topic | Laravel session (per app) | JWT in `httpOnly` cookie + this package |
|-------|----------------------------|----------------------------------------|
| Shared across many Composer apps | Needs shared session store or SSO bridge | Natural — same validation code everywhere |
| Instant revoke one user | Easy (delete session row) | **§8** — IdP `POST /auth/revoke` + `sub` blacklist per app (seconds) |
| XSS | Session ID in `httpOnly` cookie — similar risk | Same — never expose token to JS |
| CSRF | Required | Required |
| Server state | Session DB/Redis per app | Stateless verify via JWKS |
| Ops complexity | Per-app session config | IdP + JWKS cache |

For **many internal Laravel tools**, a **10-hour** JWT in an `httpOnly` cookie plus **§8 revocation** and SSO re-login on expiry is the intended fit — no refresh tokens.

---

## 11. Environment & Laravel config checklist

### 11.1 `.env` (every tool)

```env
APP_URL=https://hr.company.com

# Local only — override IdP host (ignored when APP_ENV is not local)
IDP_URL=http://sso.test

# Per-tool identity (for aud / project_id checks in callback)
APP_PROJECT_ID=hr-portal
```

JWKS path and cache TTL are fixed on `CompanyAuth` (`JWKS_PATH`, `JWKS_CACHE_TTL`). IdP base URL: production uses `CompanyAuth::IDP_URL`; local may set `IDP_URL` in `.env` (merged via `config/company-auth.php`).

### 11.2 Laravel hardening (app repo)

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

- Force HTTPS (middleware or reverse proxy).
- Set HSTS at the load balancer.
- Log auth events: login, logout, revoke, token expiry, failed validation (actor, IP, UA, timestamp).

### 11.3 New project checklist

- [ ] `composer require baaboo/internal-tool-composer-auth-package`
- [ ] `APP_PROJECT_ID` + `COMPANY_AUTH_CLIENT_SECRET` set
- [ ] IdP registry `redirect_uri` matches `route('company-auth.callback')`
- [ ] Protected routes use `company.auth`
- [ ] `web` + CSRF on browser mutating routes
- [ ] HTTPS + HSTS in production
- [ ] IdP issues JWT with **10-hour** `exp`; cookie `minutes` = 600
- [ ] Token-expired UX: package provides `GET /auth/token-expired`; customize view if needed
- [ ] `/auth/login` redirects to IdP for re-authentication
- [ ] No JWT in `localStorage`; no refresh token cookie
- [ ] Logout clears `token` cookie (+ optional IdP logout)
- [ ] Registered in SSO app registry with `revoke_url` and `project_id`
- [ ] Access JWTs include `jti`; IdP fan-out on deactivate (§8)
- [ ] `POST /auth/revoke` accepts service JWT with `aud` = this app’s `APP_PROJECT_ID` **(planned in package)**

---

## 12. Bearer header (non-browser)

`AuthMiddleware` accepts `Authorization: Bearer` for **API clients and tests**. For production browser apps, prefer the `token` cookie only.

Machine-to-machine clients should use separate client credentials or scoped API tokens — not the user’s browser cookie.

---

## 13. Package roadmap vs this doc

| Capability | Status |
|------------|--------|
| JWKS fetch + cache | **Implemented** (`TokenValidator`) |
| Signature + `exp` validation | **Implemented** |
| `company.auth` + `CurrentUser` + `MeController` | **Implemented** — register `GET /me` on `web` routes in the consuming app |
| `iss` / `aud` validation in package | **Not in v1** — assert in tool callback |
| Auth callback + code exchange | **Implemented** (`GET /auth/callback`) |
| Cookie helper / Set-Cookie abstraction | **Not in v1** |
| Token-expired page + `/auth/login` redirect | **Token-expired** — `GET /auth/token-expired` in package (IdP link). `/auth/login` optional (IdP-first portals). |
| `POST /auth/revoke` + service JWT validation (`aud` per app) | **Planned** — §8 |
| `sub` / `jti` blacklist check in `company.auth` | **Planned** — §8 |
| Refresh tokens | **Not used** for internal tools |

When auth routes ship in the package, update this document and `CURSOR_CONTEXT.md` together.

---

## Related docs

- [`CURSOR_CONTEXT.md`](../CURSOR_CONTEXT.md) — package API, JWT shape, security rules
- [`PACKAGE_BUILD_GUIDE.md`](../PACKAGE_BUILD_GUIDE.md) — maintainer build steps
