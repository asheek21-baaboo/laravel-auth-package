# Package to-do list (general)

> High-level checklist for `baaboo/internal-tool-composer-auth-package`.  
> Detailed guard work: **[AUTH_GUARD_TODO.md](./AUTH_GUARD_TODO.md)** (keep that doc as the deep plan).  
> Security baseline: **[SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md)**.

---

## Laravel Auth guard (policies & Spatie)

Bridge JWT SSO to `Auth::user()` so consuming apps do not maintain two parallel “who is logged in?” paths.

- [x] **Implement auth guard (`sso`)** — `users` table sync on callback, `SsoJwtGuard`, middleware sets `Auth::guard('sso')` — see [INSTALLATION.md](./INSTALLATION.md) §7–§10
- [ ] **Remaining guard polish** — optional items in [AUTH_GUARD_TODO.md](./AUTH_GUARD_TODO.md) (`CurrentUser` delegates to guard, `auth:sso` alias docs)
- [ ] **Document consuming-app setup** — `config/auth.php` `company` guard, optional Spatie resolver (`sub` → local `User`)
- [ ] **Keep `CurrentUser` working** — delegate to guard user for backward compatibility
- [ ] **Tests** — guard unit tests + feature test that `Auth::user()` is set after `company.auth`

---

## Token expired — what to do

When the 10-hour JWT expires, browser users need a clear path back to SSO (no refresh tokens). See **SECURE_DEFAULTS.md §7**.

### Already in place

- [x] `company.auth` redirects expired browser requests to the token-expired route and clears the `token` cookie
- [x] `TokenExpiredController` + blade view (“Token expired, please log in via SSO” + link to IdP)
- [x] JSON/API clients get `401` with `Token has expired.` (no HTML redirect)

### Still to do / align

- [ ] **Route naming & paths** — package registers `GET /oauth/token-expired` (`company-auth.token-expired`); docs often say `/auth/token-expired`. Decide canonical path (`/auth/*` vs `/oauth/*`) and align routes, `CURSOR_CONTEXT.md`, and `SECURE_DEFAULTS.md`
- [x] **Login route** — `GET /login` → IdP authorize (`company.guest`)
- [x] **Token-expired CTA** — links to `route('login')`
- [ ] **Publish / override view** — allow consuming apps to publish `token-expired` blade or override controller for branding
- [ ] **Direct visit to token-expired** — document behaviour when user opens the page without an expired cookie (informational only vs redirect home)
- [ ] **Logging** — log token-expiry events (actor if known from stale cookie, IP, UA, timestamp) per §11.2
- [ ] **Feature tests** — cover login redirect URL building when `/auth/login` exists; regression on middleware redirect + cookie forget
- [ ] **Update integration checklist** — `CURSOR_CONTEXT.md` + §11.3 in `SECURE_DEFAULTS.md` with final route names

### Expected user flow (target)

```
Expired JWT on protected page
  → middleware redirect → token-expired page (cookie cleared)
  → user clicks “Log in via SSO”
  → GET /auth/login (optional) → IdP authorize
  → GET /auth/callback → new JWT cookie → app home / COMPANY_AUTH_REDIRECT
```

---

## Logout

Implemented — see **SECURE_DEFAULTS.md §9** and **INSTALLATION.md**.

- [x] `AuthLogoutController` — `POST /logout` (`logout`)
- [x] Clear `token` cookie + `SsoJwtGuard::logout()`
- [x] Optional IdP logout (`SSO_REDIRECT_TO_IDP_LOGOUT`, default `true`)
- [x] Logout always redirects to `logged_out` error page (removed `SSO_REDIRECT_AFTER_LOGOUT`)

---

## Revocation (IdP → app) — related, planned

Not logout, but required for “instant lockout” alongside expiry UX.

- [ ] **`POST /auth/revoke`** — service JWT validation (`aud` = `APP_PROJECT_ID`), body `sub` / optional `jti`
- [ ] **Blacklist in cache** — `revoked:sub:{uuid}` TTL ≥ 10h; optional `revoked:jti:{id}`
- [ ] **`company.auth` checks blacklist** after JWT verify
- [ ] **Expired/revoked browser UX** — decide: revoked users → same token-expired page vs `401` vs redirect to IdP with error

*(Detail in SECURE_DEFAULTS.md §8.)*

---

## Other package items (backlog)

- [ ] **`iss` / `aud` / `project_id` on every request** — optional strict check in middleware (today enforced at callback)
- [ ] **Revocation + auth guard** — blacklisted user should not populate `Auth::user()`
- [ ] **Align callback route** — package uses `GET /oauth/callback`; docs mention `/auth/callback`
- [ ] **CHANGELOG + tag** when logout / token-expired / guard ship

---

## Consuming app checklist (copy when integrating)

- [ ] `composer require baaboo/internal-tool-composer-auth-package`
- [ ] `APP_PROJECT_ID`, `COMPANY_AUTH_CLIENT_SECRET`, HTTPS in production
- [ ] Protected routes: `web` + `company.auth` (later: `auth:company`)
- [ ] **Token expired:** ensure users can reach token-expired page and SSO login after 10h
- [ ] **Logout:** wire UI to package logout route; optional IdP global logout
- [ ] **Auth guard (when available):** register Spatie resolver if using roles/permissions
- [ ] **Revoke (when available):** register `revoke_url` in IdP app registry

---

*See also: [AUTH_GUARD_TODO.md](./AUTH_GUARD_TODO.md) · [SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) · [CURSOR_CONTEXT.md](../CURSOR_CONTEXT.md)*
