# `@baaboo/company-auth` — NPM package specification

> **Purpose:** Authoritative spec for the JavaScript/TypeScript SSO client monorepo used by internal tools (React, Vue, Next.js, Express, Hono, etc.).  
> **Auth backend:** Laravel IdP at `https://auth.company.com` — **not** implemented by this package.  
> **Audience:** Engineers building the npm monorepo; AI agents must follow this document exactly.

**Companion prompts:**

- [`prompts/BUILD_NPM_PACKAGE.md`](./prompts/BUILD_NPM_PACKAGE.md) — greenfield scaffold (full monorepo).
- [`prompts/IMPLEMENT_NPM_SPEC_CHANGES.md`](./prompts/IMPLEMENT_NPM_SPEC_CHANGES.md) — **delta only** (local DB + related changes; use when code already exists).

---

## 1. System overview

```
┌─────────────────────────────────────────────────────────┐
│              Laravel IdP  (auth.company.com)             │
│  Issues RS256 JWTs scoped per project (10-hour TTL)     │
└────────────────────┬────────────────────────────────────┘
                     │  OAuth2 authorize + code exchange
         ┌───────────▼───────────┐
         │  @baaboo/company-auth│  ← this monorepo
         │  core + server +     │
         │  react | vue + cli   │
         └───────────┬───────────┘
                     │  installs into
        ┌────────────▼──────────────────┐
        │  Internal JS/TS tools        │
        │  (SPA + Node server)         │
        └──────────────────────────────┘
```

**Package responsibilities**

| Responsibility | Module |
|----------------|--------|
| Platform constants (IdP URLs, paths, cookie name, TTLs) | `@baaboo/company-auth-core` |
| Config loader (`SSO_*` env vars) | `core` |
| JWKS fetch + cache + JWT verify (RS256, `exp`) | `server` — `TokenValidator` |
| Callback claim checks (`iss`, `aud`, `jti`) | `server` — `CallbackJwtValidator` |
| OAuth authorize + logout URL builders | `server` |
| Authorization code → JWT exchange | `server` — `IdpTokenExchanger` |
| Local `users` profile (optional store + reference migration) | `server` — `UserStore`, `syncUserFromClaims` |
| Auth + guest middleware | `server` |
| HTTP routes: login, logout, callback, token-expired, `/me` | `server` + framework adapters |
| httpOnly `token` cookie set/clear | `server` — `tokenCookie` |
| Browser-safe `/me` + logout client | `core` |
| React / Vue auth context + route guards | `react` / `vue` |
| Interactive project scaffold | `cli` |

**Planned (document, stub or omit until implemented):** `POST /oauth/revoke` (service JWT), per-request `sub` / `jti` revocation blacklist — see [SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) §8.

---

## 2. Monorepo layout

Publish a **pnpm/npm workspace** monorepo (recommended repo name: `company-auth-npm`).

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
│   └── migrations/              # reference SQL (consumer chooses runner)
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

### 2.2 `package.json` exports

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
    "./handlers": "./dist/handlers/index.js",
    "./db": "./dist/db/index.js",
    "./migrations": "./migrations"
  }
}
```

Ship reference migrations under `packages/server/migrations/` (plain SQL, not executed by the package). Consumers copy or translate them into Prisma, Knex, Drizzle, TypeORM, etc.

**`@baaboo/company-auth-react`** / **`@baaboo/company-auth-vue`**

```json
{
  "exports": { ".": "./dist/index.js" },
  "peerDependencies": { "react": ">=18" }
}
```

(Vue package: `"vue": ">=3.4"`.)

Build with **tsup** or **unbuild**; ship **ESM** + **`.d.ts`**; target Node **20+** for server, browserslist defaults for client.

---

## 3. Platform constants

Implement in `packages/core/src/constants.ts`:

| Symbol | Value | Notes |
|--------|-------|-------|
| `IDP_URL` | `https://auth.company.com` | Production IdP |
| `JWKS_PATH` | `/.well-known/jwks.json` | |
| `TOKEN_EXCHANGE_PATH` | `/oauth/token` | POST JSON |
| `OAUTH_AUTHORIZE_PATH` | `/oauth/authorize` | Browser login redirect |
| `OAUTH_SESSION_END_PATH` | `/oauth/session/end` | IdP session end (`Authorization: Bearer` JWT) |
| `JWKS_CACHE_TTL_SECONDS` | `3600` | |
| `TOKEN_COOKIE_NAME` | `token` | httpOnly |
| `TOKEN_COOKIE_MAX_AGE_SECONDS` | `36000` | 10 hours (= 600 min) |
| `ACCESS_TOKEN_TTL_SECONDS` | `36000` | |

**`idpUrl(config)`**

- If `config.nodeEnv` is not `local` or `development` → return `IDP_URL`.
- If local → return `config.idpUrl` from env `IDP_URL` (trim trailing `/`), else `IDP_URL`.

Consuming apps must set `NODE_ENV=local` or `development` for local IdP override.

---

## 4. Configuration

| Env var | Required | Maps to | Purpose |
|---------|----------|---------|---------|
| `SSO_PROJECT_ID` | Yes | `projectId` | Tool slug; JWT `aud` must match at callback |
| `SSO_CLIENT_SECRET` | Yes | `clientSecret` | Code exchange |
| `SSO_CLIENT_ID` | No | `clientId` | Defaults to `SSO_PROJECT_ID` |
| `SSO_REDIRECT_AFTER_LOGIN` | No | `redirectAfterLogin` | Default `/`; guest redirect when already logged in |
| `SSO_REDIRECT_AFTER_LOGOUT` | No | `redirectAfterLogout` | Default `/login` when IdP logout disabled |
| `SSO_REDIRECT_TO_IDP_LOGOUT` | No | `redirectToIdpLogout` | Default `true` — POST logout JWT to IdP `/oauth/session/end` |
| `IDP_URL` | Local only | `idpUrl` | Override IdP base URL |
| `NODE_ENV` | — | `nodeEnv` | `local` / `development` enables `IDP_URL` override |

Use **`SSO_*` only** — do not introduce `APP_PROJECT_ID` or `COMPANY_AUTH_*`.

`loadConfig()` in core throws clear errors if `SSO_PROJECT_ID` or `SSO_CLIENT_SECRET` is missing when server handlers run.

Consuming apps must also provide **`appUrl`** (origin, e.g. `https://hr.company.com`) for absolute callback `redirect_uri` — via config file or env, not necessarily a platform env name.

### 4.1 Local database (optional)

Tools may keep a **local shadow profile** in a `users` table (`id` = JWT `sub`). The package does **not** run migrations for you — it ships a **reference migration** and a **`UserStore` interface**; the consuming app chooses how to apply schema changes (Prisma migrate, Knex, Drizzle Kit, raw SQL, etc.).

| Env var | Required | Purpose |
|---------|----------|---------|
| `DATABASE_URL` | When using DB | Connection string for the app’s chosen client (not read by the package unless you wire a built-in adapter) |
| `SSO_USERS_TABLE` | No | Table name; default `users` |
| `SSO_REQUIRE_LOCAL_USER` | No | Default `true` when `userStore` is configured — auth middleware returns 401 if JWT is valid but no row exists |

**Config / middleware options**

```ts
interface CompanyAuthServerOptions {
  userStore?: UserStore;
  requireLocalUser?: boolean; // default true when userStore set
  usersTable?: string;        // default 'users'
}
```

---

## 5. Local `users` table

### 5.1 Purpose

| When | Behaviour |
|------|-----------|
| **Login** (`GET /oauth/callback`) | After JWT validation → **upsert** row from `sub`, `email`, `name` (fallback `email`) |
| **Each request** (auth middleware) | JWT verified → **load** row by `sub` → attach to `req.auth` |
| **Guest middleware** | Valid JWT + row exists → redirect to `redirectAfterLogin` |

Identity for `/me` still comes from **JWT claims** (`claimsToMe`). The DB row is for server-side lookups, foreign keys, and optional ORM integration — not a second source of `project_role`.

### 5.2 Canonical schema (reference migration)

Table name: **`users`** (override with `usersTable` / `SSO_USERS_TABLE`).

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID, PK | JWT `sub` — not auto-increment |
| `email` | string | Required on sync |
| `name` | string, nullable | From JWT `name` or `email` |
| `password` | string, nullable | Always null for SSO users; column exists for apps that share `users` with password auth |
| `created_at` | timestamp | Optional but recommended |
| `updated_at` | timestamp | Optional but recommended |

**Non-destructive rule (match reference behaviour):** If the app already has a `users` table, the consumer must **not** let this package drop or rename columns. Only add missing columns (at minimum nullable `password` if absent). The reference migration uses `CREATE TABLE IF NOT EXISTS` and a separate optional patch file for `ADD COLUMN password`.

### 5.3 Reference migration files (ship in repo)

Place under `packages/server/migrations/` and copy via CLI to the consuming app:

| File | Purpose |
|------|---------|
| `001_create_users_table.postgresql.sql` | `CREATE TABLE IF NOT EXISTS users (...)` |
| `001_create_users_table.mysql.sql` | MySQL-compatible variant |
| `002_add_password_column_if_missing.sql` | `ALTER TABLE ... ADD COLUMN password` only when table pre-exists |
| `README.md` | **Consumer chooses how to migrate** — see §5.4 |

Export path: `@baaboo/company-auth-server/migrations` (files only, no runtime migrate command in the package).

### 5.4 Consumer migration responsibility (required documentation)

The package **must not** auto-run migrations on `npm install` or server start.

Document clearly:

1. Copy or translate the reference SQL into the app’s migration system.
2. Run migrations **before** enabling `userStore` in production.
3. Examples (consumer picks one):
   - **Prisma:** paste columns into `schema.prisma`, run `npx prisma migrate dev`
   - **Knex:** `knex migrate:make` + copy SQL into `up`
   - **Drizzle:** add table to schema, `drizzle-kit push` / generate migration
   - **Raw:** `psql $DATABASE_URL -f migrations/001_create_users_table.postgresql.sql`
4. If `users` already exists, run only the additive patch (password column) or merge manually.

CLI `init` must ask: **“Store SSO users in a local database?”**  
- **Yes** → copy `templates/migrations/*` into the project (e.g. `database/migrations/company-auth/`) and print: *“Apply these with your migration tool before starting the server.”*  
- **No** → skip files; wire auth without `userStore` (JWT-only `req.auth`).

### 5.5 `UserStore` interface (`server`)

```ts
export interface LocalUser {
  id: string;       // JWT sub
  email: string;
  name: string | null;
}

export interface UserStore {
  findById(id: string): Promise<LocalUser | null>;
  upsertFromClaims(claims: JwtClaims): Promise<LocalUser>;
}
```

Implement in the consuming app (Prisma, Knex, etc.) or use optional adapters later (`createPrismaUserStore`, etc.) as **peer-dependent** extras — not required in v1.

### 5.6 `syncUserFromClaims(claims, store)`

Called from **callback handler only** (not every request):

- Require `sub` and `email` strings
- `name` ← JWT `name` or `email`
- `upsert` on `id` = `sub`
- If `name` column does not exist in consumer schema, store may omit it (adapter checks metadata or config flag `syncName: false`)

### 5.7 Auth middleware with local user

When `userStore` is configured (and `requireLocalUser !== false`):

1. Validate JWT (as today)
2. `localUser = await userStore.findById(claims.sub)`
3. If `localUser === null` → `401` JSON `{ message: "User profile not found. Please sign in again via SSO." }` (`USER_PROFILE_NOT_FOUND`)
4. `req.auth = { user: claimsToUser(claims), claims, localUser }`

When `userStore` is **omitted**: keep JWT-only `req.auth` (no DB).

Guest middleware: treat as authenticated only when JWT validates **and** (`userStore` absent OR `findById` returns a row).

### 5.8 Callback handler addition

After `CallbackJwtValidator.validate(jwt)`:

```ts
if (options.userStore) {
  await syncUserFromClaims(claims, options.userStore);
}
```

Then set cookie and redirect.

---

## 6. JWT contract

### 6.1 Claims

| Claim | Type | Used by |
|-------|------|---------|
| `sub` | string (UUID) | `user.id` |
| `email` | string | `user.email`, `/me` `name` |
| `name` | string | Optional display name; not required for `/me` |
| `global_role` | string | `user.globalRole` |
| `project_role` | string | `user.role`, `/me` `role` |
| `aud` | string | Callback validation; `user.projectId` |
| `iss` | string | Callback: must equal `idpUrl()` |
| `jti` | string | Callback: required non-empty |
| `iat` | number | |
| `exp` | number | Validated on every request |
| `project_id` | string | IdP may issue; optional extra assert at callback if present |

**Algorithm:** RS256 only. Tools never hold the private key.

### 6.2 `/me` response contract (immutable)

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

Not application RBAC. No `can()` helper in v1.

---

## 7. Security rules (non-negotiable)

1. **JWT only in httpOnly cookie** for browsers — name `token`.
2. **Browser bundle must NOT export** `decodeJwt`, `verifyJwt`, or access to raw JWT. Validation runs **only** in `@baaboo/company-auth-server` (Node).
3. **SPA reads identity via `GET /me`** with `credentials: 'include'` — never `localStorage` / `sessionStorage` / JS-readable cookies.
4. **Bearer token** supported for API/testing; priority: Bearer **then** cookie.
5. Cookie flags (production): `httpOnly: true`, `secure: true`, `sameSite: 'lax'`, `path: '/'`, **no** `domain` (host-only).
6. Local: `secure: false` only on localhost (or when request is not HTTPS in local).
7. **No refresh tokens** — 10-hour access JWT only; re-login via IdP.
8. **Do not** bundle `react` or `vue` inside any package.
9. Protect **`POST /logout`** with CSRF in cookie-based apps (document for consumers).
10. PKCE is required on the IdP authorization step (IdP concern; callback receives one-time `code`).

See [SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) for revocation design, integration checklist, and cookie details.

---

## 8. `@baaboo/company-auth-core`

### 8.1 Types (`types.ts`)

```ts
export interface CompanyAuthConfig {
  projectId: string;
  clientId: string;
  clientSecret: string;
  redirectAfterLogin: string;
  redirectAfterLogout: string;
  redirectToIdpLogout: boolean;
  idpUrl: string;
  nodeEnv: string;
  appUrl: string;
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

export interface LocalUser {
  id: string;
  email: string;
  name: string | null;
}

export interface AuthUser {
  id: string;
  email: string;
  globalRole: string;
  projectId: string;
  role: string;
  claims: JwtClaims;
}

export interface AuthContext {
  user: AuthUser;
  claims: JwtClaims;
  localUser?: LocalUser;
}

export interface MeResponse {
  name: string;
  role: string;
  permissions: string[];
}
```

### 8.2 `claimsToUser(claims: JwtClaims): AuthUser`

- `id` ← `sub`
- `email` ← `email`
- `globalRole` ← `global_role`
- `projectId` ← `aud`
- `role` ← `project_role`
- `claims` ← full object

### 8.3 `claimsToMe(claims: JwtClaims): MeResponse`

- `name` ← `email`
- `role` ← `project_role`
- `permissions` ← `['*']` if `project_role === 'admin'`, else `[]`

### 8.4 Errors (`errors.ts`)

| Code | HTTP | Message |
|------|------|---------|
| `UNAUTHENTICATED` | 401 | `Unauthenticated.` |
| `USER_PROFILE_NOT_FOUND` | 401 | `User profile not found. Please sign in again via SSO.` |
| `TOKEN_EXPIRED` | 401 | `Token has expired.` |
| `INVALID_SIGNATURE` | 401 | `Token signature is invalid.` |
| `MALFORMED_TOKEN` | 401 | `Token is malformed.` + reason |
| `UNRESOLVABLE_KEY` | 401 | `Could not fetch or parse the IdP public key.` |
| `INVALID_CALLBACK` | 403 | `Token claim [x] is invalid for this application.` / `Token is missing required claim [jti].` |
| `CODE_EXCHANGE_FAILED` | 403 | `Authorization code could not be exchanged.` |
| `MISSING_CODE` | 400 | `Missing authorization code.` |
| `INVALID_CODE` | 400 | `Invalid authorization code.` |

Export `AuthError` class with `code`, `status`, `isExpired`.

Config errors when exchanging:

- `SSO_PROJECT_ID is not configured.`
- `SSO_CLIENT_SECRET is not configured.`

(IdP transport/response failures use `CodeExchangeException` messages above.)

### 8.5 Browser-safe client (`client.ts`)

```ts
export async function fetchMe(baseUrl: string, mePath?: string): Promise<MeResponse>
```

- `GET ${baseUrl}${mePath ?? '/me'}`
- `credentials: 'include'`, `Accept: application/json`
- 401 → throw `AuthError` with body message
- No JWT handling

```ts
export async function logout(
  baseUrl: string,
  options?: { csrfToken?: string; path?: string },
): Promise<void>
```

- Default `POST ${baseUrl}/logout`
- CSRF header when host app uses cookie-based CSRF (e.g. `X-XSRF-TOKEN`)
- `credentials: 'include'`; follow redirect
- Identity cleared by server `Set-Cookie`, not in JS

```ts
export function loginUrl(baseUrl: string, path = '/login'): string
```

Absolute URL to app login route (redirects to IdP).

---

## 9. `@baaboo/company-auth-server`

Use **`jose`** for JWKS + `jwtVerify`. Use native `fetch` (Node 20+).

### 9.1 `TokenValidator`

| Method | Behaviour |
|--------|-----------|
| `validate(token: string): Promise<JwtClaims>` | Fetch JWKS (cached 3600s), verify RS256 + `exp` |
| `forgetCachedKeys(): void` | Clear cache (tests, key rotation) |

- JWKS URL: `` `${idpUrl(config)}${JWKS_PATH}` ``
- Cache key: `baaboo_auth_jwks_public_key`

### 9.2 `CallbackJwtValidator`

After `TokenValidator.validate`:

1. `iss === idpUrl(config)`
2. `aud === config.projectId`
3. `jti` non-empty string

Return claims.

### 9.3 `buildAuthorizeUrl(config, callbackAbsoluteUrl): string`

Query: `client_id`, `redirect_uri`, `response_type=code`, `project_id`.

Return `` `${idpUrl(config)}${OAUTH_AUTHORIZE_PATH}?${query}` ``.

`client_id` defaults to `projectId` when `SSO_CLIENT_ID` unset.

### 9.4 `buildLogoutUrl(config): string`

`` POST `${idpUrl(config)}${OAUTH_SESSION_END_PATH}` `` with header `Authorization: Bearer <accessToken>` (same as PHP `IdpSessionEndClient`)

### 9.5 `IdpTokenExchanger`

`POST ${idpUrl}${TOKEN_EXCHANGE_PATH}` JSON:

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

Expect 200: `{ "access_token": string, "expires_in": number, "token_type": string }` (all required, `expires_in` ≥ 1).

### 9.6 `extractToken(request)`

1. `Authorization: Bearer <token>`
2. Cookie `token`

Framework-agnostic (headers + `Cookie` header parse).

### 9.7 `createAuthMiddleware(options)`

1. `extractToken` → if null: `401` JSON `{ message: "Unauthenticated." }`
2. `TokenValidator.validate` → on error:
   - If expired **and** client does not prefer JSON (`Accept` / `X-Requested-With`) → `302` to `/oauth/token-expired` + clear `token` cookie
   - Else `401` JSON `{ message: <error message> }`
3. If `userStore` + `requireLocalUser`: load row by `claims.sub`; missing → `401` `USER_PROFILE_NOT_FOUND` (§5.7)
4. Attach `req.auth = { user: claimsToUser(claims), claims, localUser? }`
5. `next()`

Options (reserved): `enforceAudienceOnEveryRequest`, revocation blacklist — see §9.17.

### 9.8 `createGuestMiddleware(options)`

JWT-aware guest (replaces framework `guest` — does not read session):

1. `extractToken` → if null: `next()`
2. If token validates **and** local user exists when `userStore` is set → `302` to `redirectAfterLogin` (default `/`)
3. On invalid/expired token or missing local row: `next()` (allow login flow; do not 401)

### 9.9 HTTP routes

| Method | Path | Route name | Middleware | Behaviour |
|--------|------|------------|------------|-----------|
| `GET` | `/login` | `login` | guest | §9.10 |
| `POST` | `/logout` | `logout` | — | §9.11 |
| `GET` | `/oauth/callback` | `company-auth.callback` | — | §9.12 |
| `GET` | `/oauth/token-expired` | `company-auth.token-expired` | — | §9.13 |
| `GET` | `/me` | — | auth | §9.14 |

**Rate limits:** 20/min callback; 60/min token-expired, login, logout.

### 9.10 Login handler (`handleLogin`)

`302` to `buildAuthorizeUrl(config, `${appUrl}/oauth/callback`)`. Register with **guest** middleware only.

### 9.11 Logout handler (`handleLogout`)

1. Clear `req.auth` if present
2. `Set-Cookie` clear `token` (`clearTokenCookie`)
3. If `redirectToIdpLogout` (default `true`): `302` to `buildLogoutUrl(config)`
4. Else: `302` to `redirectAfterLogout` (default `/login`)

### 9.12 Callback handler (`handleOAuthCallback`)

1. Read `code`; missing → `400` `Missing authorization code.`
2. Validate code: regex `^[A-Za-z0-9\-._~\/+]+=*$`
3. `redirectUri` = `${appUrl}/oauth/callback`
4. `jwt = await IdpTokenExchanger.exchange(code, redirectUri)`
5. `claims = await CallbackJwtValidator.validate(jwt)`
6. If `userStore`: `await syncUserFromClaims(claims, userStore)` (§5.6)
7. `302` to `redirectAfterLogin` with httpOnly `token` cookie

### 9.13 Token expired handler (`handleTokenExpired`)

`Content-Type: text/html; charset=UTF-8`

- Title: `Session expired`
- Copy: `Token expired, please log in via SSO.`
- Link: **`/login`** (same-origin app login → IdP), not raw IdP URL only

### 9.14 Me handler (`handleMe`)

Requires auth middleware. Return JSON `claimsToMe(claims)`.

### 9.15 Cookie helpers (`tokenCookie.ts`)

`setTokenCookie(jwt, isProduction): string` — max-age 36000s, `httpOnly`, `sameSite=lax`, `path=/`, no `domain`, `secure` per §7.

`clearTokenCookie(isProduction): string` — expire cookie (same flags).

### 9.16 Framework adapters

| Adapter | Export | Notes |
|---------|--------|-------|
| Express | `companyAuthRouter(config, options?)` | Mounts §9.9 routes; pass `userStore` in options |
| Hono | Same pattern | |
| Next.js App Router | Route handlers for login, logout, callback, token-expired, me | Requires `appUrl` |

### 9.17 Planned (stub or omit)

| Feature | Route / behaviour |
|---------|-------------------|
| Revoke | `POST /oauth/revoke` — service JWT, `aud` = projectId, body `sub` / optional `jti` |
| Revocation blacklist | After JWT verify in auth middleware |
| Per-request `iss`/`aud` | `enforceAudienceOnEveryRequest: true` |

Mark as `Not implemented — see SECURE_DEFAULTS §8` or omit from exports until done.

---

## 10. `@baaboo/company-auth-react`

**Peer:** `react >= 18`. **Must not** import `@baaboo/company-auth-server`.

### 10.1 `AuthProvider`

```ts
interface AuthProviderProps {
  children: React.ReactNode;
  meUrl?: string;           // default '/me'
  baseUrl?: string;         // default '' (same origin)
  onUnauthenticated?: () => void;
}
```

On mount: `fetchMe(baseUrl + meUrl)` → `user`, `loading`, `error`.

### 10.2 `useAuth()`

```ts
{
  user: MeResponse | null;
  loading: boolean;
  error: AuthError | null;
  isAuthenticated: boolean;
  refetch: () => Promise<void>;
  logout: () => Promise<void>;
}
```

`logout` calls core `logout()` → `POST /logout`.

### 10.3 `RequireAuth`

- Loading → `fallback` or null
- !user → navigate to `loginPath` (default `/login`) or `onUnauthenticated()`
- Else children

Never read JWT in components.

---

## 11. `@baaboo/company-auth-vue`

**Peer:** `vue >= 3.4`. **Must not** import server package.

### 11.1 `createAuthPlugin(options)`

Same options as React `AuthProvider`. `provide` auth state; `fetchMe` on mount.

### 11.2 `useAuth()`

Same return shape as React.

### 11.3 `createAuthRouterGuard(options)`

```ts
createAuthRouterGuard(options?: { meUrl?: string; publicPaths?: string[] })
```

`beforeEach`: protected routes need loaded user; else redirect `/login`.

Default public paths: `/login`, `/oauth/callback`, `/oauth/token-expired`.

### 11.4 `AuthProvider` component (optional)

Wrapper for setup convenience.

---

## 12. `@baaboo/company-auth-cli`

### 12.1 Goal

On install/init, add **only** the chosen framework package (React **or** Vue, never both).

### 12.2 Commands

```bash
npx @baaboo/company-auth-cli init
npx @baaboo/company-auth-cli init --framework react
npx @baaboo/company-auth-cli init --framework vue
COMPANY_AUTH_FRAMEWORK=react npx @baaboo/company-auth-cli init
```

### 12.3 Interactive prompts

1. Framework: React or Vue (required).
2. App URL (origin) — required for callback `redirect_uri`.
3. `SSO_PROJECT_ID` — required.
4. **“Store SSO users in a local database?”** (yes / no)
   - **Yes** → copy `templates/migrations/*` to `database/migrations/company-auth/` (or path the user chooses); add `DATABASE_URL` to `.env.example`; generate stub `src/auth/userStore.ts` implementing `UserStore`; print reminder: **you must run migrations with your own tool before deploy** (§5.4).
   - **No** → JWT-only mode; do not copy migration files.
5. Write `.env.example` + `company-auth.config.ts`.
6. Install:

```bash
npm install @baaboo/company-auth-core @baaboo/company-auth-server @baaboo/company-auth-react
# OR
npm install @baaboo/company-auth-core @baaboo/company-auth-server @baaboo/company-auth-vue
```

Never install both React and Vue packages in one project.

### 12.4 Generated templates

| File | Purpose |
|------|---------|
| `company-auth.config.ts` | Typed config loader |
| `.env.example` | `SSO_*`, `IDP_URL`, `NODE_ENV`, optional `DATABASE_URL` |
| `database/migrations/company-auth/*.sql` | Reference migrations (only when user chose DB) |
| `src/auth/userStore.ts` | Stub `UserStore` (only when user chose DB) |
| `src/auth/setupExpress.ts` or `setupNext.ts` | Sample server wiring (`userStore` optional) |
| `src/auth/AuthProvider.tsx` or `auth.plugin.ts` | Sample client wiring |

Prefer explicit `npx … init` over `postinstall` in CI.

---

## 13. Testing requirements

| Area | Tool | Minimum cases |
|------|------|----------------|
| core | vitest | `claimsToMe`, `claimsToUser`, `loadConfig` errors |
| server | vitest | TokenValidator, CallbackJwtValidator, IdpTokenExchanger, `buildAuthorizeUrl`, auth middleware 401/redirect, **USER_PROFILE_NOT_FOUND** when store returns null, `syncUserFromClaims` on callback, guest redirect when JWT + row valid, login/logout/callback cookie |
| react | vitest + RTL | Provider `/me`, RequireAuth redirect |
| vue | vitest + test-utils | plugin + composable |

Mock JWKS with local RSA key pair (same approach as reference tests in `sso-composer-auth-package`).

---

## 14. Documentation to ship

- README: install `core` + `server` + one UI package; register IdP callback `https://<tool>/oauth/callback`.
- **Local DB:** §5 + `packages/server/migrations/README.md` — reference SQL only; consumer runs their own migration tool.
- Integration checklist from [SECURE_DEFAULTS.md](./SECURE_DEFAULTS.md) §11 (adapt env names to `SSO_*`).
- Security summary: cookies, CSRF on `POST /logout`, no JWT in browser bundle.

---

## 15. Versioning and release

- Semver per package; align majors on breaking changes.
- Private npm registry or GitHub Packages.
- First release: tag monorepo `v1.0.0` with aligned package versions.

---

## 16. Non-goals (v1)

- IdP implementation or password-based login in this package
- Auto-running database migrations (consumer-owned migrate step)
- Bundled ORM (Prisma/Knex adapters optional later; v1 = `UserStore` interface only)
- Google OAuth / MFA in package
- Fine-grained permission resolution (no `can()`, no app RBAC tables in package)
- JWT verify/decode in browser bundles
- Refresh tokens
- Bundling React and Vue in one dependency

---

*Behavioural reference implementation: `sso-composer-auth-package` (`src/`, `config/company-auth.php`, `routes/company-auth.php`, `docs/SECURE_DEFAULTS.md`).*
