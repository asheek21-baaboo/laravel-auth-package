# `@baaboo/company-auth` — NPM package specification

> **Purpose:** Authoritative spec for the JavaScript/TypeScript SSO client packages that mirror `baaboo/internal-tool-composer-auth-package` (PHP).  
> **Auth backend:** Laravel IdP at `https://auth.company.com` — **not** this package.  
> **Audience:** Engineers building the npm monorepo; AI agents must follow this document exactly.

**Companion:** Use [`prompts/BUILD_NPM_PACKAGE.md`](./prompts/BUILD_NPM_PACKAGE.md) as the copy-paste build prompt for an AI agent.

---

## 1. Product split (read first)

| Stack | Install | Responsibility |
|-------|---------|----------------|
| **Laravel internal tool** | `composer require baaboo/internal-tool-composer-auth-package` only | Server auth (callback, JWT verify, cookie, `/me`). Optional thin npm **client-only** later. |
| **JS/TS app (React, Vue, Next, Express, etc.)** | npm packages below | **Server** parity with composer + **browser** hooks. No Composer. |

**Inertia is out of scope** for the npm design.

---

## 2. Monorepo layout (required)

Publish a **pnpm/npm workspace** monorepo (separate git repo recommended: `company-auth-npm`).

```
company-auth-npm/
├── package.json                 # private workspace root
├── packages/
│   ├── core/                    # @baaboo/company-auth-core
│   ├── server/                  # @baaboo/company-auth-server
│   ├── react/                   # @baaboo/company-auth-react
│   ├── vue/                     # @baaboo/company-auth-vue
│   └── cli/                     # @baaboo/company-auth-cli
├── templates/                   # generated files for init
└── README.md
```

### 2.1 Package names and dependencies

| Package | npm name | Depends on | Peer deps |
|---------|----------|------------|-----------|
| Core | `@baaboo/company-auth-core` | — | — |
| Server | `@baaboo/company-auth-server` | `core` | — |
| React | `@baaboo/company-auth-react` | `core` | `react` ^18 \|\| ^19 |
| Vue | `@baaboo/company-auth-vue` | `core` | `vue` ^3.4 |
| CLI | `@baaboo/company-auth-cli` | — | — |

**Meta package (optional):** `@baaboo/company-auth` re-exports `core` and documents install flow; it must **not** bundle React and Vue together.

### 2.2 `package.json` exports (each package)

**`@baaboo/company-auth-core`**

```json
{
  "name": "@baaboo/company-auth-core",
  "type": "module",
  "exports": {
    ".": "./dist/index.js",
    "./constants": "./dist/constants.js",
    "./types": "./dist/types.js",
    "./errors": "./dist/errors.js"
  }
}
```

**`@baaboo/company-auth-server`**

```json
{
  "exports": {
    ".": "./dist/index.js",
    "./express": "./dist/adapters/express.js",
    "./hono": "./dist/adapters/hono.js",
    "./next": "./dist/adapters/next.js",
    "./handlers": "./dist/handlers/index.js"
  }
}
```

**`@baaboo/company-auth-react`**

```json
{
  "exports": {
    ".": "./dist/index.js"
  },
  "peerDependencies": {
    "react": ">=18"
  }
}
```

**`@baaboo/company-auth-vue`**

```json
{
  "exports": {
    ".": "./dist/index.js"
  },
  "peerDependencies": {
    "vue": ">=3.4"
  }
}
```

Build with **tsup** or **unbuild**; ship **ESM** + **`.d.ts`**; target Node **20+** for server, browserslist defaults for client.

---

## 3. Platform constants (must match PHP `CompanyAuth`)

Implement in `packages/core/src/constants.ts`:

| Symbol | Value | Notes |
|--------|-------|-------|
| `IDP_URL` | `https://auth.company.com` | Production IdP |
| `JWKS_PATH` | `/.well-known/jwks.json` | |
| `TOKEN_EXCHANGE_PATH` | `/oauth/token` | POST JSON |
| `JWKS_CACHE_TTL_SECONDS` | `3600` | |
| `TOKEN_COOKIE_NAME` | `token` | httpOnly |
| `TOKEN_COOKIE_MAX_AGE_SECONDS` | `36000` | 10 hours (= 600 min) |
| `ACCESS_TOKEN_TTL_SECONDS` | `36000` | |

**`idpUrl(config)`** — mirror PHP exactly:

- If `config.nodeEnv !== 'local'` (and not `development` if you align with `NODE_ENV`) → return `IDP_URL`.
- If local → return `config.idpUrl` from env `IDP_URL` (trim trailing `/`), else `IDP_URL`.

PHP uses `APP_ENV=local`; Node uses `NODE_ENV=local` or `development` — **document** which the consuming app must set.

---

## 4. Configuration (environment variables)

Must match `config/company-auth.php` in the composer repo:

| Env var | Required | Maps to | Purpose |
|---------|----------|---------|---------|
| `SSO_PROJECT_ID` | Yes | `projectId` | Tool slug; JWT `aud` must match at callback |
| `SSO_CLIENT_SECRET` | Yes | `clientSecret` | Code exchange |
| `SSO_CLIENT_ID` | No | `clientId` | Defaults to `SSO_PROJECT_ID` |
| `SSO_REDIRECT_AFTER_LOGIN` | No | `redirectAfterLogin` | Default `/` |
| `IDP_URL` | Local only | `idpUrl` | Override IdP base URL |
| `NODE_ENV` | — | — | `local` / `development` enables `IDP_URL` override |

**Do not** introduce `APP_PROJECT_ID` or `COMPANY_AUTH_*` in npm — use `SSO_*` only (composer error messages may still mention legacy names; npm must not).

Load via `loadConfig()` in core that throws clear errors if `SSO_PROJECT_ID` or `SSO_CLIENT_SECRET` missing when server handlers run.

---

## 5. JWT contract (IdP → tool)

### 5.1 Claims

| Claim | Type | Used by |
|-------|------|---------|
| `sub` | string (UUID) | `user.id` |
| `email` | string | `user.email`, `/me` `name` |
| `global_role` | string | `user.globalRole` |
| `project_role` | string | `user.role`, `/me` `role` |
| `aud` | string | Callback validation; `user.projectId` (PHP `CurrentUser::projectId()` reads **`aud`**, not `project_id`) |
| `iss` | string | Callback: must equal `idpUrl()` |
| `jti` | string | Callback: required non-empty |
| `iat` | number | |
| `exp` | number | Validated on every request |
| `project_id` | string | IdP may issue; **optional** extra assert at callback if present |

**Algorithm:** RS256 only. Tools never hold the private key.

### 5.2 `/me` response contract (immutable)

```json
{
  "name": "jane@company.com",
  "role": "manager",
  "permissions": []
}
```

| Field | Source | Rule |
|-------|--------|------|
| `name` | JWT `email` | Until IdP adds display name |
| `role` | JWT `project_role` | |
| `permissions` | Derived | `["*"]` if `project_role === "admin"`, else `[]` |

**Not** application RBAC. No `can()` helper in v1.

---

## 6. Security rules (non-negotiable)

1. **JWT only in httpOnly cookie** for browsers — name `token`.
2. **Browser bundle must NOT export** `decodeJwt`, `verifyJwt`, or access to raw JWT. Validation runs **only** in `@baaboo/company-auth-server` (Node).
3. **SPA reads identity via `GET /me`** with `credentials: 'include'` — never `localStorage` / `sessionStorage` / JS-readable cookies.
4. **Bearer token** supported for API/testing (same as PHP middleware); priority: Bearer **then** cookie.
5. Cookie flags (production): `httpOnly: true`, `secure: true`, `sameSite: 'lax'`, `path: '/'`, **no** `domain` (host-only).
6. Local: `secure: false` only on localhost.
7. **No refresh tokens** — 10-hour access JWT only; re-login via IdP.
8. **Do not** bundle `react` or `vue` inside any package.

---

## 7. `@baaboo/company-auth-core`

### 7.1 Types (`types.ts`)

```ts
export interface CompanyAuthConfig {
  projectId: string;
  clientId: string;
  clientSecret: string;
  redirectAfterLogin: string;
  idpUrl: string;
  nodeEnv: string;
}

export interface JwtClaims {
  sub: string;
  email: string;
  global_role: string;
  project_role: string;
  aud: string;
  iss?: string;
  jti?: string;
  iat: number;
  exp: number;
  project_id?: string;
  [key: string]: unknown;
}

export interface AuthUser {
  id: string;
  email: string;
  globalRole: string;
  projectId: string;
  role: string;
  claims: JwtClaims;
}

export interface MeResponse {
  name: string;
  role: string;
  permissions: string[];
}
```

### 7.2 `claimsToUser(claims: JwtClaims): AuthUser`

- `id` ← `sub`
- `email` ← `email`
- `globalRole` ← `global_role`
- `projectId` ← `aud`
- `role` ← `project_role`
- `claims` ← full object

### 7.3 `claimsToMe(claims: JwtClaims): MeResponse`

Implement same logic as PHP `MeController`.

### 7.4 Errors (`errors.ts`)

Mirror PHP exception messages **exactly** for API responses:

| Code | HTTP | Message |
|------|------|---------|
| `UNAUTHENTICATED` | 401 | `Unauthenticated.` |
| `TOKEN_EXPIRED` | 401 | `Token has expired.` |
| `INVALID_SIGNATURE` | 401 | `Token signature is invalid.` |
| `MALFORMED_TOKEN` | 401 | `Token is malformed.` + reason |
| `UNRESOLVABLE_KEY` | 401 | `Could not fetch or parse the IdP public key.` |
| `INVALID_CALLBACK` | 403 | `Token claim [x] is invalid for this application.` / `Token is missing required claim [jti].` |
| `CODE_EXCHANGE_FAILED` | 403 | `Authorization code could not be exchanged.` |
| `MISSING_CODE` | 400 | `Missing authorization code.` |
| `INVALID_CODE` | 400 | `Invalid authorization code.` |

Export `AuthError` class with `code`, `status`, `isExpired`.

### 7.5 Browser-safe client (`client.ts`)

```ts
export async function fetchMe(baseUrl: string): Promise<MeResponse>
```

- `GET ${baseUrl}/me` (or configurable path default `/me`)
- `credentials: 'include'`
- `Accept: application/json`
- 401 → throw `AuthError` with body message
- No JWT handling

Optional:

```ts
export async function logout(baseUrl: string, csrfToken: string): Promise<void>
```

`POST ${baseUrl}/oauth/logout` with `X-XSRF-TOKEN` — **(planned)** until server implements logout; stub and document.

---

## 8. `@baaboo/company-auth-server`

Use **`jose`** for JWKS + `jwtVerify`. Use native `fetch` (Node 20+).

### 8.1 `TokenValidator`

Parity with PHP `TokenValidator`:

| Method | Behaviour |
|--------|-----------|
| `validate(token: string): Promise<JwtClaims>` | Fetch JWKS (cached 3600s), verify RS256 + exp |
| `forgetCachedKeys(): void` | Clear cache (tests, key rotation) |

JWKS URL: `` `${idpUrl()}${JWKS_PATH}` ``  
Cache key: `baaboo_auth_jwks_public_key` (same string as PHP).

### 8.2 `CallbackJwtValidator`

Parity with PHP `CallbackJwtValidator`:

After `TokenValidator.validate`:

1. `iss === idpUrl(config)`
2. `aud === config.projectId`
3. `jti` non-empty string

Return claims.

### 8.3 `IdpTokenExchanger`

Parity with PHP `IdpTokenExchanger`:

`POST ${idpUrl}${TOKEN_EXCHANGE_PATH}` JSON body:

```json
{
  "grant_type": "authorization_code",
  "code": "<code>",
  "redirect_uri": "<absolute callback url>",
  "client_id": "<clientId>",
  "client_secret": "<clientSecret>",
  "project_id": "<projectId>"
}
```

Headers: `Accept: application/json`, `Content-Type: application/json`.

Expect 200 body: `{ "access_token": string, "expires_in": number, "token_type": string }`.

Throw on missing config with messages matching PHP (`SSO_PROJECT_ID is not configured.`, etc.).

### 8.4 `extractToken(request)`

Priority:

1. `Authorization: Bearer <token>`
2. Cookie `token`

Framework-agnostic helper from headers + cookie header string.

### 8.5 `createAuthMiddleware(options)`

Returns Connect/Express-style `(req, res, next)` or generic handler:

1. `extractToken` → if null: `401` JSON `{ message: "Unauthenticated." }`
2. `TokenValidator.validate` → on error:
   - If expired **and** `Accept` does not prefer JSON → redirect `302` to `/oauth/token-expired` + `Set-Cookie` clear `token`
   - Else `401` JSON `{ message: <error message> }`
3. Attach `req.auth = { user: claimsToUser(claims), claims }`
4. `next()`

**(planned v1.1)** After validate: check revocation blacklist for `sub` / `jti`.

### 8.6 HTTP route handlers (register on consuming app)

| Method | Path | Name | Behaviour |
|--------|------|------|-----------|
| `GET` | `/oauth/callback` | `company-auth.callback` | See §8.7 |
| `GET` | `/oauth/token-expired` | `company-auth.token-expired` | HTML page §8.8 |
| `GET` | `/me` | — | Protected; returns `claimsToMe` JSON |

Throttle: document 20/min callback, 60/min token-expired (implement if adapter supports).

### 8.7 Callback handler (`handleOAuthCallback`)

Parity with `AuthCallbackController`:

1. Read `code` query param; missing → `400` `Missing authorization code.`
2. Validate code format: regex `^[A-Za-z0-9\-._~\/+]+=*$` (same as PHP)
3. `redirectUri` = absolute URL of **this app's** `/oauth/callback` (from config `appUrl` + path)
4. `jwt = await IdpTokenExchanger.exchange(code, redirectUri)`
5. `await CallbackJwtValidator.validate(jwt)`
6. `302` redirect to `redirectAfterLogin` with `Set-Cookie` httpOnly `token`

### 8.8 Token expired handler

Return `text/html` minimal page (port `resources/views/token-expired.blade.php`):

- Title: `Session expired`
- Copy: `Token expired, please log in via SSO.`
- Link: `idpUrl(config)` with `rel="noopener noreferrer"`

### 8.9 Cookie helpers (`tokenCookie.ts`)

`setTokenCookie(jwt: string, isProduction: boolean): string` → `Set-Cookie` header value  
`clearTokenCookie(isProduction: boolean): string` → expire cookie

Match PHP `TokenCookie` semantics.

### 8.10 Framework adapters

| Adapter | File | Notes |
|---------|------|-------|
| Express | `adapters/express.ts` | `companyAuthRouter(config)`, `requireAuth` middleware |
| Hono | `adapters/hono.ts` | Same routes |
| Next.js App Router | `adapters/next.ts` | Route handlers export `GET` for callback, token-expired, me; middleware helper |

Each adapter wires §8.6 handlers and documents `appUrl` (origin) requirement.

### 8.11 Planned (stub interfaces + TODO tests)

| Feature | Route | Notes |
|---------|-------|-------|
| Revoke | `POST /oauth/revoke` | Service JWT, `aud` = projectId, body `sub` / optional `jti` |
| Logout | `POST /oauth/logout` | Clear cookie, CSRF |
| Per-request `iss`/`aud` in middleware | — | Optional strict mode flag `enforceAudienceOnEveryRequest: true` |

Mark exported but throw `new Error('Not implemented — see SECURE_DEFAULTS §8')` or omit until implemented.

---

## 9. `@baaboo/company-auth-react`

**Peer:** `react >= 18`. **Must not** import `@baaboo/company-auth-server`.

### 9.1 `AuthProvider`

Props:

```ts
interface AuthProviderProps {
  children: React.ReactNode;
  meUrl?: string;           // default '/me'
  baseUrl?: string;         // default '' (same origin)
  onUnauthenticated?: () => void;
}
```

- On mount: `fetchMe(baseUrl + meUrl)` → store `user: MeResponse | null`, `loading`, `error`
- Expose context

### 9.2 `useAuth()`

Returns:

```ts
{
  user: MeResponse | null;
  loading: boolean;
  error: AuthError | null;
  isAuthenticated: boolean;
  refetch: () => Promise<void>;
  logout: () => Promise<void>;  // when server route exists
}
```

### 9.3 `RequireAuth`

- While loading → render `fallback` prop or null
- If !user → `Navigate` to `/oauth/token-expired` or `onUnauthenticated()`
- Else children

**Do not** read JWT in components.

### 9.4 Optional `useAuthUser()` (typed helpers)

Map `MeResponse` + if backend later exposes headers, stay on `/me` only for v1.

---

## 10. `@baaboo/company-auth-vue`

**Peer:** `vue >= 3.4`. **Must not** import server package.

### 10.1 `createAuthPlugin(options)`

Same options as React `AuthProvider`. Install:

- `provide(AUTH_INJECTION_KEY, authState)`
- `fetchMe` on app mount

### 10.2 `useAuth()`

Composable mirroring React `useAuth()` return shape.

### 10.3 `createAuthRouterGuard(options)`

For `vue-router`:

```ts
export function createAuthRouterGuard(options?: { meUrl?: string; publicPaths?: string[] })
```

`beforeEach`: if route requires auth, ensure `useAuth().user` loaded; else redirect to token-expired or login.

### 10.4 `AuthPlugin` component (optional)

`<AuthProvider>`-style wrapper component for setup convenience.

---

## 11. `@baaboo/company-auth-cli` — install / init flow

### 11.1 Goal

When a developer installs the npm packages, **only the chosen framework package** is added (React **or** Vue, not both).

### 11.2 Commands

```bash
# Recommended — interactive
npx @baaboo/company-auth-cli init

# Non-interactive (CI)
npx @baaboo/company-auth-cli init --framework react
npx @baaboo/company-auth-cli init --framework vue
COMPANY_AUTH_FRAMEWORK=react npx @baaboo/company-auth-cli init
```

### 11.3 Interactive prompts (exact)

1. **"Which framework does this project use?"**  
   - `( ) React`  
   - `( ) Vue`  
   - Validate: required selection.

2. **"App URL (origin, e.g. https://hr.company.com):"** — required for server callback `redirect_uri`.

3. **"SSO project ID (SSO_PROJECT_ID):"** — required.

4. Write `.env.example` entries and `company-auth.config.ts` (or JSON) from templates.

5. Run package install:

```bash
npm install @baaboo/company-auth-core @baaboo/company-auth-server @baaboo/company-auth-react
# OR
npm install @baaboo/company-auth-core @baaboo/company-auth-server @baaboo/company-auth-vue
```

**Never** install both `@baaboo/company-auth-react` and `@baaboo/company-auth-vue` in the same project.

### 11.4 Optional `postinstall` (discouraged in CI)

If root meta-package exists, `postinstall` runs init **only when** `.company-auth.json` missing **and** `CI` is not set. Prefer explicit `npx … init`.

### 11.5 Generated files (templates)

| File | Purpose |
|------|---------|
| `company-auth.config.ts` | Typed config loader |
| `.env.example` | `SSO_*`, `IDP_URL`, `NODE_ENV` |
| `src/auth/setupExpress.ts` or `setupNext.ts` | Sample server wiring |
| `src/auth/AuthProvider.tsx` or `auth.plugin.ts` | Sample client wiring |

---

## 12. Parity matrix: PHP composer ↔ npm

| PHP (composer) | npm | Notes |
|--------------|-----|-------|
| `CompanyAuth` | `core/constants` + `idpUrl()` | |
| `TokenValidator` | `server/TokenValidator` | jose |
| `CallbackJwtValidator` | `server/CallbackJwtValidator` | |
| `IdpTokenExchanger` | `server/IdpTokenExchanger` | |
| `AuthMiddleware` | `server/createAuthMiddleware` | |
| `AuthCallbackController` | `server/handleOAuthCallback` | |
| `TokenExpiredController` | `server/handleTokenExpired` | |
| `MeController` | `server/handleMe` | |
| `CurrentUserService` | `req.auth.user` / `claimsToUser` | |
| `TokenCookie` | `server/tokenCookie` | |
| `CurrentUser` facade | N/A (server); `useAuth` (client) | |
| `routes/company-auth.php` | Adapter mounts `/oauth/*` | Same paths |
| Config `SSO_*` | `loadConfig()` | |

---

## 13. Testing requirements

| Area | Tool | Minimum cases |
|------|------|----------------|
| core | vitest | `claimsToMe`, `claimsToUser`, config loader errors |
| server | vitest | TokenValidator (expired, bad sig, JWKS cache), CallbackJwtValidator (iss, aud, jti), IdpTokenExchanger mock fetch, middleware 401/redirect, callback sets cookie |
| react | vitest + @testing-library/react | Provider loads /me, RequireAuth redirects |
| vue | vitest + @vue/test-utils | plugin + composable |

Use a local RSA key pair + mock JWKS (mirror `tests/Support/TestJwt.php` in composer repo).

---

## 14. Documentation to ship in npm repo

- README: Laravel apps → use composer, not server package.
- README: SPA apps → server + react|vue.
- Integration checklist (copy from `docs/SECURE_DEFAULTS.md` §11).
- Link to IdP registration: callback URL `https://<tool>/oauth/callback`.

---

## 15. Versioning and release

- Semver independent per package; keep versions aligned on breaking changes.
- GitHub Packages or private npm registry.
- Tag monorepo `v1.0.0` — all packages same major on first release.

---

## 16. Explicit non-goals (v1)

- No password storage or IdP implementation
- No Google OAuth / MFA in package
- No fine-grained permission resolution
- No JWT in browser bundle
- No Inertia helpers
- No automatic Laravel integration (composer handles Laravel)

---

*Source of truth for PHP behaviour: repository `sso-composer-auth-package` — `src/`, `config/company-auth.php`, `routes/company-auth.php`, `docs/SECURE_DEFAULTS.md`.*
