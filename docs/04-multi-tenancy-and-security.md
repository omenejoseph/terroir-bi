# 04 — Multi-Tenancy & Security Isolation

The current app is **single-tenant**: one winery, one database, no concept of
an organization. Globally-unique constraints (`User.email`, `Customer.email`,
`InventoryItem.sku`, `Order.orderNumber`, `WineLot.lotNumber`, `Supplier.taxId`,
`EInvoice.electronicId`, `Customer.orderToken`) and global env-var secrets
(`ERACUN_*`, `ANTHROPIC_API_KEY`) all assume that.

To become a B2B SaaS, the system must isolate every winery from every other
winery, with **no possible cross-tenant read or write**.

## 4.1 Recommended isolation model

**Primary recommendation: shared database + shared schema, row-level isolation
via a mandatory `tenant_id` column, enforced by a global Eloquent scope.**

Rationale for this product:
- Tenants are SMB wineries with modest data volumes → per-tenant databases are
  operationally heavy and not justified.
- Cross-tenant analytics, billing, and support are far simpler on one schema.
- Migrations run once, not N times.

This is the model assumed throughout these docs (every business table has
`tenant_id`). Two escape hatches are documented for scale:

| Model | When to use | Trade-off |
|---|---|---|
| **Shared DB, shared schema, `tenant_id`** *(recommended)* | Default for all tenants | Cheapest; isolation is application-enforced (must be airtight) |
| **Shared DB, schema-per-tenant** (Postgres schemas) | A few large/regulated tenants | Stronger isolation; more migration overhead |
| **Database-per-tenant** | Enterprise / data-residency demands | Strongest isolation; highest ops cost; use `stancl/tenancy` |

> Suggested package: `stancl/tenancy` (supports both single-DB and multi-DB
> modes) **or** a hand-rolled `BelongsToTenant` trait + global scope if you want
> full control. Either is fine; the rest of this doc assumes the global-scope
> approach.

## 4.2 Tenant resolution

A request's tenant is established by middleware **before** any query runs, in
this precedence:

1. **Authenticated API token / JWT** → `tenant_id` claim is authoritative.
2. **Subdomain / custom domain** → `acme.app.com` → tenant by `slug` (must match the token's tenant; mismatch = 403).
3. **Public order token** (unauthenticated catalog) → resolve tenant from the
   `customers.order_token` lookup (see §4.6).
4. **Webhooks / scheduled jobs** → tenant passed explicitly in the job payload;
   never inferred.

The resolved tenant id is stored in a request-scoped container binding
(`app()->instance('tenant', $tenant)`), which the global scope reads.

## 4.3 Enforcing isolation in code

```php
// app/Models/Concerns/BelongsToTenant.php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        // 1. Read filter: every query is auto-scoped.
        static::addGlobalScope(new TenantScope);

        // 2. Write guard: new rows always get the current tenant_id.
        static::creating(function ($model) {
            if (! $model->tenant_id) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }
}
```

```php
// app/Models/Scopes/TenantScope.php
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Fail closed: if no tenant is bound, return nothing.
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        abort_if(! $tenant, 500, 'No tenant context');
        $builder->where($model->getTable().'.tenant_id', $tenant->id);
    }
}
```

Rules of thumb:
- **Every** business model uses `BelongsToTenant`. The only un-scoped models are
  `Tenant`, `Plan`, and the admin/back-office models.
- Cross-table joins are safe because both sides are scoped, but for raw
  queries / `whereHas` always include `tenant_id` explicitly.
- A model retrieved by ID via `find()` is already tenant-scoped, so
  `Order::find($id)` for another tenant's order returns `null` → 404, not a leak.
- **Defense in depth:** add a composite DB index `(tenant_id, id)` and, where
  feasible, Postgres **Row-Level Security** policies as a backstop in case the
  application scope is ever bypassed.

## 4.4 Tenant-scoping the previously-global uniqueness

These must change from global unique to composite unique `(tenant_id, …)`:

| Field | New constraint |
|---|---|
| `customers.email` | unique `(tenant_id, email)` |
| `inventory_items.sku` | unique `(tenant_id, sku)` |
| `orders.order_number` | unique `(tenant_id, order_number)` — also makes per-tenant numbering clean |
| `wine_lots.lot_number` | unique `(tenant_id, lot_number)` |
| `pricing_tiers.name` | unique `(tenant_id, name)` |
| `suppliers.tax_id` | unique `(tenant_id, tax_id)` |
| `e_invoices.electronic_id` | unique `(tenant_id, electronic_id)` |
| `translation_overrides` | unique `(tenant_id, locale, key)` |
| `supplier_price_items` | unique `(tenant_id, supplier_id, description)` |

**`users.email`** is a policy decision:
- If a person can belong to only one winery → unique `(tenant_id, email)`.
- If the same email could log into multiple wineries → keep global-unique email
  with a separate `tenant_user` pivot, and pick the active tenant at login.
- **Recommended for B2B simplicity:** one account = one tenant → `(tenant_id, email)`.

## 4.5 Per-tenant secrets (critical)

The current app reads `ERACUN_USERNAME/PASSWORD/COMPANY_ID/SOFTWARE_ID` and the
Anthropic key from **process env** — a single global account. In SaaS this is a
hard isolation break: every tenant has their *own* Moj-eRačun account.

- Move all integration credentials into `tenant_secrets`, **encrypted at rest**
  (`Crypt::encryptString`), decrypted only inside the integration service.
- Never serialize secrets into API responses, logs, or queue payloads (pass the
  `tenant_id`, let the worker load secrets).
- The Anthropic key may stay platform-global (cost borne by platform) **or** be
  per-tenant if you want tenants to bring their own key.

## 4.6 The public order token (special case)

Self-service ordering is unauthenticated — the `order_token` *is* the
credential. Hardening for multi-tenant:

- Store a **high-entropy** token (≥ 32 bytes, `Str::random(64)` or signed). The
  current 32-hex-char token is acceptable but prefer longer.
- The token lookup resolves both the **customer** and the **tenant** in one
  query; bind that tenant for the rest of the request.
- Endpoints under the public token are **read catalog** + **create order only**,
  never anything else. Enforce with a dedicated middleware group + route prefix
  (`/public/{token}/...`).
- **Server-side price re-resolution is mandatory** (already done in the source):
  prices submitted by the client are ignored/verified against
  `resolvePricesForCustomer()`. Never trust client prices. See flow 02.
- Rate-limit and allow token rotation (`generateOrderToken` / `revokeOrderToken`).
- Respect `customers.hide_prices` and only expose `is_for_sale` FINISHED items.

## 4.7 File storage isolation

Uploads (invoices, receipts, product images) must be namespaced per tenant:

```
s3://bucket/{tenant_id}/invoices/{ulid}-{filename}
s3://bucket/{tenant_id}/inventory/{item_id}-{ts}.webp
```

Serve via signed, expiring URLs scoped to the tenant; never a public bucket
root. Validate MIME type and size on upload (current limits: images 10 MB,
documents/PDF 20 MB).

## 4.8 Authorization (roles within a tenant)

- Re-implement `requireRole()` as Laravel **Policies/Gates**, with role checks
  reading the comma-separated `role` string (or migrate to
  `spatie/laravel-permission` with tenant-team scoping).
- Map today's roles: `ADMIN` → tenant owner/admin; `TEAM`, `CELLAR`, `ORDERS`
  → scoped operator roles.
- Destructive actions (`delete*`, user management, token issue, e-invoice sync,
  inventory check, translation overrides) require `ADMIN`.
- A **platform super-admin** (cross-tenant support) is a separate guard, audited,
  and must explicitly "impersonate" a tenant to act inside it.

## 4.9 Security checklist (threats specific to multi-tenancy)

- [ ] Global tenant scope on every business model; tests assert cross-tenant `find()` returns null.
- [ ] Fail-closed scope (no tenant bound → error, never "all rows").
- [ ] All previously-global unique keys re-scoped (§4.4).
- [ ] FK integrity tests: a child's `tenant_id` must equal its parent's `tenant_id` (add a DB check/observer).
- [ ] Integration secrets per-tenant, encrypted, never logged.
- [ ] Public order token: high entropy, read+create only, server-side price verification, rotatable, rate-limited.
- [ ] File keys tenant-prefixed; signed URLs; MIME/size validation.
- [ ] Authorization policies cover every mutating endpoint; default deny.
- [ ] Queued jobs carry explicit `tenant_id`; workers re-bind tenant context.
- [ ] IDs are UL/UUID (non-enumerable) — no integer PKs exposed.
- [ ] Rate limiting per tenant + per IP; audit log of admin/destructive actions.
- [ ] Tenant deletion is a background purge/anonymize job, with export first.
- [ ] (Optional, recommended) Postgres RLS as a backstop to the app scope.