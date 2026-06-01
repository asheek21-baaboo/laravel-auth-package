# AI prompt — implement `NPM_PACKAGE_SPEC.md` changes only

> **Use this prompt when** an npm monorepo (`company-auth-npm`) already exists **or** you are patching an in-progress build.  
> **Do not** rebuild from scratch — implement **only** the deltas below.  
> **Full contract:** [`../NPM_PACKAGE_SPEC.md`](../NPM_PACKAGE_SPEC.md) (read §5, §4.1, §9.7–9.12, §12.3–12.4 in detail).  
> **Greenfield scaffold:** use [`BUILD_NPM_PACKAGE.md`](./BUILD_NPM_PACKAGE.md) instead.

---

## PROMPT START

You are updating `@baaboo/company-auth-*` to match the **current** `docs/NPM_PACKAGE_SPEC.md`. Assume core JWT auth (routes, `TokenValidator`, cookie, `/me`, React/Vue hooks) may already exist. **Implement only what is listed below.**

### Context (spec doc cleanup — no code unless docs are wrong)

The spec was refocused as a **standalone npm build contract**:

- Removed PHP↔npm parity tables, Laravel/`users` guard sections, and “use Composer for Laravel” guidance.
- Renumbered sections: local DB = **§5**, JWT = **§6**, security = **§7**, core = **§8**, server = **§9**, react = **§10**, vue = **§11**, cli = **§12**, testing = **§13**.

**Do not** re-add comparison docs or PHP references in code comments unless required for a one-line “reference repo” link in README.

---

## Delta 1 — Local `users` table + `UserStore` (main feature)

### 1.1 Types (`@baaboo/company-auth-core`)

Add or export:

```ts
export interface LocalUser {
  id: string;
  email: string;
  name: string | null;
}

export interface AuthContext {
  user: AuthUser;
  claims: JwtClaims;
  localUser?: LocalUser;
}
```

`req.auth` (and handler context) should use `AuthContext`, not JWT-only `{ user, claims }` when a store is wired.

### 1.2 Error (`core/errors`)

Add:

| Code | HTTP | Message |
|------|------|---------|
| `USER_PROFILE_NOT_FOUND` | 401 | `User profile not found. Please sign in again via SSO.` |

### 1.3 Config (`core` + server options)

Support optional env (document in `.env.example`; wire in `loadConfig` or server options only):

| Env | Default | Purpose |
|-----|---------|---------|
| `SSO_USERS_TABLE` | `users` | Table name hint for docs / consumer adapters |
| `SSO_REQUIRE_LOCAL_USER` | `true` when `userStore` set | If true, missing DB row → 401 after valid JWT |

```ts
interface CompanyAuthServerOptions {
  userStore?: UserStore;
  requireLocalUser?: boolean; // default true when userStore is set
  usersTable?: string;        // default 'users'
}
```

`DATABASE_URL` is **consumer-owned** — package does not connect unless the app passes a `UserStore` implementation.

### 1.4 `UserStore` + `syncUserFromClaims` (`@baaboo/company-auth-server`)

New module e.g. `packages/server/src/db/userStore.ts`:

```ts
export interface UserStore {
  findById(id: string): Promise<LocalUser | null>;
  upsertFromClaims(claims: JwtClaims): Promise<LocalUser>;
}

export async function syncUserFromClaims(
  claims: JwtClaims,
  store: UserStore,
  options?: { syncName?: boolean },
): Promise<LocalUser>
```

**`syncUserFromClaims` rules:**

- Require non-empty `sub` and `email` on claims.
- `name` = JWT `name` if non-empty string, else `email`.
- Upsert where `id` = `sub`.
- Call **only** from OAuth callback — **not** on every request.

### 1.5 Reference migrations (ship files, never auto-run)

Add under `packages/server/migrations/`:

| File | Content |
|------|---------|
| `001_create_users_table.postgresql.sql` | `CREATE TABLE IF NOT EXISTS users` — `id` UUID PK, `email`, `name` nullable, `password` nullable, timestamps |
| `001_create_users_table.mysql.sql` | MySQL-compatible same shape |
| `002_add_password_column_if_missing.sql` | Additive `password` nullable only (pre-existing `users` table) |
| `README.md` | Consumer must run migrations with **their** tool (Prisma, Knex, Drizzle, raw SQL); package never runs migrate on install/start |

**`package.json` exports** on `@baaboo/company-auth-server`:

```json
"./db": "./dist/db/index.js",
"./migrations": "./migrations"
```

Re-export from `./db`: `UserStore`, `syncUserFromClaims`, `LocalUser` (re-export type from core).

### 1.6 Middleware behaviour changes

**`createAuthMiddleware`**

After successful `TokenValidator.validate`:

1. If `userStore` **and** `requireLocalUser !== false`:
   - `localUser = await userStore.findById(claims.sub)`
   - If `null` → `401` JSON `{ message: "User profile not found. Please sign in again via SSO." }`
2. Set `req.auth = { user: claimsToUser(claims), claims, localUser? }`
3. If no `userStore` → unchanged JWT-only behaviour.

**`createGuestMiddleware`**

- Valid JWT **and** (`!userStore` OR `findById(sub)` returns row) → `302` to `redirectAfterLogin`
- Valid JWT but **no row** when `userStore` set → `next()` (show login; do not 401)
- Invalid/expired/missing token → `next()`

### 1.7 Callback handler

After `CallbackJwtValidator.validate(jwt)` returns `claims`:

```ts
if (options.userStore) {
  await syncUserFromClaims(claims, options.userStore);
}
```

Then set httpOnly cookie + redirect (existing flow).

### 1.8 Framework adapters

`companyAuthRouter(config, options?)` / Hono / Next handlers: accept optional `CompanyAuthServerOptions` and pass `userStore` into middleware + callback.

### 1.9 `/me` handler

**No change to response body** — still `claimsToMe(claims)` from JWT. DB row is not exposed on `/me` in v1.

---

## Delta 2 — CLI `init` prompts + templates

Add interactive step (skip when `CI=true`):

**“Store SSO users in a local database?”** (yes / no)

| Answer | Action |
|--------|--------|
| **Yes** | Copy `templates/migrations/*` → `database/migrations/company-auth/` (or user-chosen path); generate `src/auth/userStore.ts` stub implementing `UserStore`; add `DATABASE_URL` to `.env.example`; print: *Apply migrations with your migration tool before starting the server.* |
| **No** | Do not copy SQL; `setupExpress.ts` / Next sample wires auth **without** `userStore` |

**New templates:**

- `templates/migrations/` — copies of reference SQL + short README
- `templates/src/auth/userStore.ts` — stub with `findById` / `upsertFromClaims` throwing “implement me” or using a placeholder

**Must not:** run migrations in `postinstall`, `init`, or server startup.

---

## Delta 3 — Tests (add cases only)

| Package | New / updated tests |
|---------|---------------------|
| `server` | In-memory `UserStore`; callback calls `upsertFromClaims`; auth middleware `USER_PROFILE_NOT_FOUND` when row missing; guest redirects only when JWT + row exist |
| `core` | Export/types for `LocalUser`, `AuthContext`; error code `USER_PROFILE_NOT_FOUND` |

---

## Delta 4 — README / docs (short additions)

In npm repo README, add subsection **Local database (optional)**:

- Link to `@baaboo/company-auth-server/migrations`
- State: copy SQL → run with Prisma/Knex/Drizzle/psql yourself
- Wire `userStore` in server bootstrap

---

## Out of scope for this delta (do not implement now)

- `POST /oauth/revoke` + revocation blacklist (§9.17 planned)
- Bundled Prisma/Knex adapters (`createPrismaUserStore`) — v1 = interface only
- Auto-running migrations
- Changing `/me` JSON shape
- Re-introducing PHP/Laravel comparison docs

---

## Verification checklist

- [ ] Without `userStore`: behaviour identical to previous JWT-only build
- [ ] With `userStore`: callback upserts; auth loads row; missing row → 401 with exact message above
- [ ] Guest: no redirect to home when JWT valid but row missing
- [ ] `npm pack` / exports include `./migrations` as static files
- [ ] Browser bundles still contain no `jose` / JWT verify
- [ ] CLI copies migrations only when user answers yes to DB prompt

When finished, list **files created/changed** and any **intentional** gaps left for v1.1 (ORM adapters).

## PROMPT END

---

### Usage

1. Open the npm monorepo in the agent.
2. Paste **PROMPT START → PROMPT END**.
3. Attach or point to `sso-composer-auth-package/docs/NPM_PACKAGE_SPEC.md` §4.1, §5, §9.7–9.12, §12.3–12.4 for line-level detail.
