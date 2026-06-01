# AI build prompt — `@baaboo/company-auth` npm monorepo

> **Copy everything below the line into your AI agent** (Cursor, Claude, etc.) to scaffold the npm packages.  
> **Mandatory reading:** [`../NPM_PACKAGE_SPEC.md`](../NPM_PACKAGE_SPEC.md) — treat it as the contract; if this prompt and the spec disagree, **the spec wins**.

---

## PROMPT START

You are building the **internal SSO npm monorepo** `@baaboo/company-auth-*` for JavaScript/TypeScript applications (React, Vue, Next.js, Express, Hono).

### Before writing any code

1. Read **`NPM_PACKAGE_SPEC.md`** in full — it is the contract.
2. Read `docs/SECURE_DEFAULTS.md` for security rules (cookies, CSRF, revocation design).
3. Optionally read `sso-composer-auth-package` reference sources if attached: `src/CompanyAuth.php`, `src/TokenValidator.php`, `src/AuthMiddleware.php`, `src/GuestMiddleware.php`, controllers under `src/Http/Controllers/`, `config/company-auth.php`, `routes/company-auth.php`.

**Auth backend is the Laravel IdP** (`https://auth.company.com`). This npm repo is a **client library only** — it does not replace the IdP.

---

### Product rules (do not violate)

| Rule | Detail |
|------|--------|
| Install set | **`@baaboo/company-auth-core` + `@baaboo/company-auth-server` + exactly one of react OR vue**. |
| JWT in browser | **FORBIDDEN.** Never export verify/decode JWT from packages imported by the browser. Validation only in `company-auth-server`. |
| Identity in SPA | **`GET /me`** with `credentials: 'include'` only. |
| Cookie name | `token`, httpOnly, 10 hours, SameSite=Lax. |
| Routes (canonical) | `GET /login`, `POST /logout`, `GET /oauth/callback`, `GET /oauth/token-expired`, `GET /me` (protected). |
| Env vars | `SSO_*` per spec §4; `appUrl` for callback origin. |
| `/me` JSON | `{ name, role, permissions }` — `permissions` is `["*"]` only when `project_role === "admin"`. |
| `projectId` on user | Map from JWT claim **`aud`**. |

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

- [ ] Platform constants per spec §3
- [ ] `idpUrl(config)` — production URL unless `NODE_ENV` is local/development and `IDP_URL` set
- [ ] Types: `CompanyAuthConfig`, `JwtClaims`, `AuthUser`, `MeResponse`
- [ ] `loadConfig()` from `process.env` with clear errors
- [ ] `claimsToUser()`, `claimsToMe()`
- [ ] `AuthError` with codes/messages per spec §7.4
- [ ] `fetchMe`, `logout`, `loginUrl` — browser-safe, no JWT

#### `@baaboo/company-auth-server`

- [ ] `TokenValidator` — JWKS fetch, cache key `baaboo_auth_jwks_public_key`, TTL 3600s
- [ ] `CallbackJwtValidator` — iss, aud, jti after signature verify
- [ ] `IdpTokenExchanger` — POST `/oauth/token` per spec §9.5
- [ ] `extractToken` — Bearer then cookie `token`
- [ ] `UserStore` + `syncUserFromClaims` per spec §5
- [ ] Reference SQL migrations under `migrations/` + README (consumer runs own migrate tool)
- [ ] `createAuthMiddleware` + `createGuestMiddleware` per spec §9.7–9.8 (local user when store set)
- [ ] `handleLogin`, `handleLogout`, `handleOAuthCallback`, `handleTokenExpired`, `handleMe`
- [ ] `tokenCookie` helpers per spec §9.15
- [ ] Adapters: **Express**, **Hono**, **Next.js App Router**
- [ ] Unit tests with mock JWKS (local RSA fixture) + in-memory `UserStore`

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

- [ ] `init` command — **interactive prompts:**
  - **"Which framework does this project use?"** → React | Vue (required)
  - App URL (origin)
  - SSO_PROJECT_ID
  - **"Store SSO users in a local database?"** → copy reference migrations + `userStore.ts` stub, or JWT-only
- [ ] Install only: `core` + `server` + **chosen framework package** (never both react and vue)
- [ ] Support `--framework react|vue` and `COMPANY_AUTH_FRAMEWORK` env for CI
- [ ] Generate from `templates/`: `.env.example`, `company-auth.config.ts`, optional `database/migrations/company-auth/*.sql`, sample server + client setup
- [ ] Do not run interactive prompt when `CI=true`
- [ ] Never auto-run migrations on install

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

### Error messages (must match spec §8.4 literally)

- `Unauthenticated.`
- `User profile not found. Please sign in again via SSO.`
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
2. **Security:** no JWT in JS; CSRF on `POST /logout`.
3. **Quick start** for Express + React and Express + Vue.
4. **Env var table** from spec §4.
5. **IdP registration:** redirect URI = `{appUrl}/oauth/callback`.

---

### Quality gates before you finish

- [ ] `pnpm test` passes all packages
- [ ] `pnpm build` emits ESM + types
- [ ] React package does not depend on Vue and vice versa
- [ ] Browser bundles contain zero `jose` / JWT verify code (verify with bundle analysis or export map discipline)
- [ ] Route paths match spec §9.9 (`/login`, `/logout`, `/oauth/callback`, `/oauth/token-expired`, `/me`)
- [ ] Reference migrations ship but are not executed by the package

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
3. Commands: `pnpm install`, `pnpm test`, `pnpm build`

## PROMPT END

---

### Usage notes for the human

1. Create a new repo (e.g. `company-auth-npm`).
2. Copy `docs/NPM_PACKAGE_SPEC.md` into that repo root (or submodule).
3. Paste **PROMPT START → PROMPT END** into the agent.
4. After generation, validate behaviour against spec §8–12 and reference tests in `sso-composer-auth-package` if available.
