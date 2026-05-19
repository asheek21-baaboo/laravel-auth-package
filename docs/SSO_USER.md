# SsoUser model & `sso` auth guard

> **Installing the package?** Start with **[INSTALLATION.md](./INSTALLATION.md)**.

Internal tools get a local `sso_users` table and an Eloquent `SsoUser` model so Laravel **policies**, **gates**, and **Spatie Permission** can use `Auth::guard('sso')->user()` without syncing identity on every request.

## Flow

| When | What happens |
|------|----------------|
| **Login** (`GET /oauth/callback`) | JWT validated → `SsoUser` upserted from claims (`sub`, `email`, `name`) → cookie set |
| **Each request** (`company.auth`) | JWT validated → `SsoUser::find(sub)` → `Auth::guard('sso')->setUser($user)` (read only) |

If the JWT is valid but no `sso_users` row exists (user never completed callback on this app), the middleware returns **401** with *"User profile not found. Please sign in again via SSO."*

## Install migration

The package auto-loads migrations. To copy into your app:

```bash
php artisan vendor:publish --tag=company-auth-migrations
php artisan migrate
```

Table `sso_users`:

| Column | Description |
|--------|-------------|
| `id` | UUID, primary key — same as JWT `sub` |
| `email` | From JWT |
| `name` | From JWT `name`, or falls back to `email` |
| `timestamps` | |

## Usage in consuming apps

```php
use Illuminate\Support\Facades\Auth;
use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;

Route::middleware(['web', 'company.auth'])->group(function () {
  Route::get('/dashboard', function () {
    /** @var SsoUser $user */
    $user = Auth::guard('sso')->user();
    // $user->id, $user->email, $user->name
  });
});
```

### Spatie Permission

Use `SsoUser` as the model with `HasRoles`:

```php
use Spatie\Permission\Traits\HasRoles;
use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;

class SsoUser extends \Baaboo\InternalToolComposerAuthPackage\Models\SsoUser
{
    use HasRoles;
}
```

Configure Spatie to use your subclass (e.g. in `config/permission.php`):

```php
'models' => [
    'user' => \App\Models\SsoUser::class,
],
```

Run Spatie migrations in the **app** database (`roles`, `permissions`, pivots). Only identity lives in `sso_users`.

### Fixed platform constants

These are defined on `CompanyAuth` and **cannot** be changed via config:

| Constant | Value |
|----------|--------|
| `CompanyAuth::SSO_GUARD` | `sso` |
| `CompanyAuth::SSO_USER_PROVIDER` | `sso_users` |
| Model | `Baaboo\InternalToolComposerAuthPackage\Models\SsoUser` |

Use `Auth::guard('sso')->user()` (or `Auth::guard(CompanyAuth::SSO_GUARD)->user()`).

## Custom model (Spatie / app extension)

Extend the package `SsoUser` in your app namespace for traits such as `HasRoles`. The table must remain `sso_users` with UUID primary key `id` = JWT `sub`. Wire Spatie to your subclass in **app** config — not in `company-auth.php`.
