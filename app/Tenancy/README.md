# Tenancy abstraction

This directory is the application's tenancy layer. It wraps a tenancy **driver**
(currently [`stancl/tenancy`](https://tenancyforlaravel.com)) behind our own
contracts so the rest of the codebase never depends on the driver directly.

## Why

We want airtight, fail-closed tenant isolation **now**, but the freedom to:

- swap the driver later (e.g. to `spatie/laravel-multitenancy` or a hand-rolled
  scope) with minimal blast radius, and
- support **mixed isolation modes** — most tenants share one database with
  row-level `tenant_id` scoping (`shared_row`), while a few large/regulated
  tenants could later get their own database (`dedicated_db`).

## The pieces

| File | Role |
|------|------|
| `Contracts/TenantContext.php` | The current-tenant API the whole app uses (`current()`, `id()`, `makeCurrent()`, `runFor()`, …). Fail-closed: `id()` throws if no tenant is bound. |
| `Contracts/TenantResolver.php` | Resolves a tenant from a subdomain / id. Never touches tenant-scoped tables. |
| `TenantManager.php` | The single `TenantContext` implementation. Binds the tenant into the container; for `dedicated_db` tenants it also asks the adapter to switch DB (future). |
| `TenantScope.php` | Global Eloquent scope. Adds `where tenant_id = ?` and throws `NoTenantContextException` when unbound. Adds the `withoutTenant()` builder macro (audited escape hatch). |
| `BelongsToTenant.php` | Trait for every tenant-owned model: applies the scope, auto-fills `tenant_id` on create, blocks cross-tenant writes. |
| `Adapters/Stancl/` | **The only place coupled to the driver.** `StanclTenantAdapter` resolves tenants and (eventually) initialises database tenancy; `StanclTenantModelTrait` makes our `Tenant` model satisfy stancl's contract. |

`config/tenant.php` is the only tenancy config application code should read.
`config/tenancy.php` is the vendor (stancl) config and is considered private to
the adapter.

## Rules

1. **Application code depends on `App\Tenancy\Contracts\*` and `App\Tenancy\BelongsToTenant`** — never on `Stancl\*`.
2. Every tenant-owned model `use`s `BelongsToTenant`. The central models
   (`Tenant`, `Plan`, `Domain`, `TenantSetting`) do **not**.
3. The fail-closed guarantee is sacred: no tenant bound ⇒ throw, never "all rows".
   `tests/Feature/Tenancy/TenantScopeTest.php` enforces this.
4. `tests/Unit/Tenancy/AdapterIsolationTest.php` fails the build if any file
   outside `Adapters/Stancl/` (or the `Tenant` model, which must declare the
   driver contract) references `Stancl\Tenancy`.

## Swapping the driver

Because the coupling is contained, migrating to another library means:

1. Add a new adapter under `Adapters/<Driver>/` implementing `TenantResolver`
   (and the model trait satisfying that driver's tenant contract, if any).
2. Point the bindings in `App\Providers\TenancyServiceProvider` at the new adapter.
3. Adjust `TenantManager::makeCurrent()` if the new driver bootstraps differently.

Nothing in `app/Models`, `app/Services`, `app/Actions`, controllers, or
migrations needs to change — they only know about our contracts.

## Enabling dedicated-DB ("mixed mode") later

`Tenant::isolation_mode` already distinguishes `shared_row` from `dedicated_db`.
To turn on dedicated databases:

1. Re-enable `DatabaseTenancyBootstrapper` in `config/tenancy.php`.
2. Implement `StanclTenantAdapter::initializeDatabase()` / `endDatabase()`
   (currently throwing stubs) using stancl's `tenancy()->initialize()`.
3. Implement the `BelongsToTenant::getConnectionName()` seam to return the
   tenant connection for dedicated-DB tenants.

`shared_row` tenants keep working unchanged throughout.
