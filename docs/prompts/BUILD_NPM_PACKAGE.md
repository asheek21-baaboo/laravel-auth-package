# AI build prompt — `@baaboo/company-auth` npm monorepo

> **Copy everything below the line into your AI agent** (Cursor, Claude, etc.) to scaffold the npm packages.  
> **Mandatory reading:** [`../NPM_PACKAGE_SPEC.md`](../NPM_PACKAGE_SPEC.md) — treat it as the contract; if this prompt and the spec disagree, **the spec wins**.

---

## PROMPT START

You are building the **internal SSO npm monorepo** `@baaboo/company-auth-*` for JavaScript/TypeScript applications. This is the **parity implementation** of the existing PHP package `baaboo/internal-tool-composer-auth-package` in the sibling repository `sso-composer-auth-package`.

### Before writing any code

1. Read **`NPM_PACKAGE_SPEC.md`** in full (same repo or provided by the user).
2. Read these PHP source files for exact behaviour (user will attach repo or paths):
   - `src/CompanyAuth.php`
   - `src/TokenValidator.php`
   - `src/AuthMiddleware.php`
   - `src/Services/IdpTokenExchanger.php`
   - `src/Services/CallbackJwtValidator.php`
   - `src/Http/Controllers/AuthCallbackController.php`
   - `src/Http/Controllers/MeController.php`
   - `src/Http/Controllers/TokenExpiredController.php`
   - `src/Support/TokenCookie.php`
   - `src/CurrentUserService.php`
   - `config/company-auth.php`
   - `routes/company-auth.php`
   - `resources/views/token-expired.blade.php`
3. Read `docs/SECURE_DEFAULTS.md` for security rules.

**Auth backend is the Laravel IdP** (`https://auth.company.com`). This npm repo is a **client library only** — it does not replace the IdP.

**Inertia is not supported.** Do not add Inertia helpers.

---

### Product rules (do not violate)

| Rule | Detail |
|------|--------|
| Laravel apps | Use **Composer package only** for server auth. npm **client** (`fetchMe` + hooks) is optional. Do not require Node server for Laravel. |
| JS/TS apps (React, Vue, Next, Express) | Install **`@baaboo/company-auth-core` + `@baaboo/company-auth-server` + exactly one of react OR vue**. |
| JWT in browser | **FORBIDDEN.** Never export verify/decode JWT from packages imported by the browser. Validation only in `company-auth-server`. |
| Identity in SPA | **`GET /me`** with `credentials: 'include'` only. |
| Cookie name | `token`, httpOnly, 10 hours, SameSite=Lax. |
| Routes (canonical) | `GET /login`, `POST /logout`, `GET /oauth/callback`, `GET /oauth/token-expired`, `GET /me` (protected). |
| Env vars | `SSO_PROJECT_ID`, `SSO_CLIENT_SECRET`, `SSO_CLIENT_ID` (optional), `SSO_REDIRECT_AFTER_LOGIN`, `IDP_URL` (local). |
| `/me` JSON | `{ name, role, permissions }` — `permissions` is `["*"]` only when `project_role === "admin"`. |
| `projectId` on user | Map from JWT claim **`aud`** (same as PHP `CurrentUserService::projectId()`). |

---

### Deliverable: monorepo structure

Create a new repository `company-auth-npm` with pnpm workspaces:

```
packages/core/
packages/server/
packages/react/
packages/vue/
packages/cli/
templates/
```

Implement exactly as specified in `NPM_PACKAGE_SPEC.md` sections 2–11.

**Tech stack:**

- TypeScript 5.x, strict mode
- `jose` for JWKS + JWT verify (RS256)
- Native `fetch` (Node 20+)
- Build: `tsup` (ESM + d.ts)
- Tests: `vitest`
- Lint: `eslint` + `typescript-eslint`
- Format: `prettier`

---

### Package implementation checklist

#### `@baaboo/company-auth-core`

- [ ] Constants mirroring PHP `CompanyAuth`
- [ ] `idpUrl(config)` — production URL unless `NODE_ENV` is local/development and `IDP_URL` set
- [ ] Types: `CompanyAuthConfig`, `JwtClaims`, `AuthUser`, `MeResponse`
- [ ] `loadConfig()` from `process.env` with clear errors
- [ ] `claimsToUser()`, `claimsToMe()`
- [ ] `AuthError` with codes/messages matching PHP
- [ ] `fetchMe(baseUrl)` — browser-safe, credentials include, no JWT

#### `@baaboo/company-auth-server`

- [ ] `TokenValidator` — JWKS fetch, cache key `baaboo_auth_jwks_public_key`, TTL 3600s
- [ ] `CallbackJwtValidator` — iss, aud, jti after signature verify
- [ ] `IdpTokenExchanger` — POST `/oauth/token` with same JSON body as PHP
- [ ] `extractToken` — Bearer then cookie `token`
- [ ] `createAuthMiddleware` — 401 JSON; expired + HTML Accept → redirect `/oauth/token-expired` + clear cookie
- [ ] `handleOAuthCallback` — code validation regex, exchange, validate, redirect + Set-Cookie
- [ ] `handleTokenExpired` — HTML port of blade template
- [ ] `handleMe` — returns `claimsToMe`
- [ ] `tokenCookie` helpers — match PHP flags
- [ ] Adapters: **Express**, **Hono**, **Next.js App Router** (route handler exports)
- [ ] Unit tests with mock JWKS (generate RSA fixture like PHP `tests/Support/TestJwt.php`)

#### `@baaboo/company-auth-react`

- [ ] `AuthProvider`, `useAuth`, `RequireAuth`
- [ ] Peer dependency `react >= 18`
- [ ] No import from `company-auth-server`
- [ ] Tests with MSW or mock `fetch` for `/me`

#### `@baaboo/company-auth-vue`

- [ ] `createAuthPlugin`, `useAuth`, `createAuthRouterGuard`
- [ ] Peer dependency `vue >= 3.4`
- [ ] No import from `company-auth-server`
- [ ] Tests with @vue/test-utils

#### `@baaboo/company-auth-cli`

- [ ] `init` command — **interactive prompt:**
  - **"Which framework does this project use?"** → React | Vue (required)
  - App URL (origin)
  - SSO_PROJECT_ID
- [ ] Install only: `core` + `server` + **chosen framework package** (never both react and vue)
- [ ] Support `--framework react|vue` and `COMPANY_AUTH_FRAMEWORK` env for CI
- [ ] Generate from `templates/`: `.env.example`, `company-auth.config.ts`, sample server + client setup
- [ ] Do not run interactive prompt when `CI=true`

---

### Install UX (must implement)

Document and implement:

```bash
npx @baaboo/company-auth-cli init
```

After init, `package.json` must contain **only one** of:

- `@baaboo/company-auth-react`
- `@baaboo/company-auth-vue`

Plus always:

- `@baaboo/company-auth-core`
- `@baaboo/company-auth-server`

---

### Error messages (must match PHP literally)

- `Unauthenticated.`
- `Token has expired.`
- `Token signature is invalid.`
- `Token is malformed.`
- `Could not fetch or parse the IdP public key.`
- `Missing authorization code.`
- `Invalid authorization code.`
- `Token claim [iss] is invalid for this application.` (and aud, etc.)
- `Token is missing required claim [jti].`
- `Authorization code could not be exchanged.`
- `SSO_PROJECT_ID is not configured.`
- `SSO_CLIENT_SECRET is not configured.`

---

### Planned features (stubs only in v1)

Do **not** fully implement yet; export TODO or omit:

- `POST /oauth/revoke` + revocation blacklist
- `iss`/`aud` check on every request (optional config flag stub)

Add `// PLANNED` comments and failing tests skipped with `describe.skip`.

---

### README content (required)

1. **Architecture diagram** (IdP → tool server → SPA).
2. **When to use Composer vs npm** (Laravel → Composer; Node SPA → npm).
3. **Security:** no JWT in JS.
4. **Quick start** for Express + React and Express + Vue.
5. **Env var table** from spec.
6. **IdP registration:** redirect URI = `{appUrl}/oauth/callback`.

---

### Quality gates before you finish

- [ ] `pnpm test` passes all packages
- [ ] `pnpm build` emits ESM + types
- [ ] React package does not depend on Vue and vice versa
- [ ] Browser bundles contain zero `jose` / JWT verify code (verify with bundle analysis or export map discipline)
- [ ] Route paths are `/oauth/callback` and `/oauth/token-expired` (not `/auth/*` unless spec updated)
- [ ] Parity matrix in spec section 12 is satisfied

---

### What NOT to build

- Do not implement the IdP login UI or Passport.
- Do not use `localStorage` for tokens.
- Do not publish to public npm (private registry config in README only).
- Do not add optional `project_id` claim to `CurrentUser` instead of `aud` for `projectId`.
- Do not bundle React + Vue in one install.

---

### Output format

When done, provide:

1. File tree of the monorepo
2. Install instructions for React and Vue apps
3. List of any intentional deviations from PHP (should be empty or justified)
4. Commands: `pnpm install`, `pnpm test`, `pnpm build`

## PROMPT END

---

### Usage notes for the human

1. Create a new repo (e.g. `company-auth-npm`) — keep it separate from the Composer package repo.
2. Copy `docs/NPM_PACKAGE_SPEC.md` into that repo root (or submodule).
3. Paste **PROMPT START → PROMPT END** into the agent with access to **both** repos.
4. After generation, run parity review against PHP tests in `sso-composer-auth-package/tests/`.
