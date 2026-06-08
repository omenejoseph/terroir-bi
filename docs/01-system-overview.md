# 01 — System Overview

## What the product does

A winery operations platform covering the full path from grape to cash:

```
 Grapes/fruit  ──▶  Wine Lots  ──▶  Bottling  ──▶  Finished Inventory  ──▶  Orders  ──▶  Customers
   (cost in)       (cellar)       (COGS set)       (stock)               (revenue)     (B2B)

 Suppliers  ──▶  Costs / Invoices  ──▶  Bank reconciliation        (cost out / accounting)
                 (incl. e-invoice)
```

It is used internally by winery staff and externally by the winery's B2B
customers (restaurants, shops, distributors) who place orders through a
tokenized self-service catalog.

## Target: multi-tenant B2B SaaS

A **tenant** = one winery business (an "organization"/"account"). Each tenant
has its own users, customers, inventory, cellar, suppliers, costs, e-invoice
credentials, and translation overrides. No tenant may ever read or write
another tenant's data. See [`04-multi-tenancy-and-security.md`](04-multi-tenancy-and-security.md).

## Current stack → target stack

| Concern | Current (as-is) | Target (to-be) |
|---|---|---|
| Framework | Next.js 14 App Router (server actions + a few route handlers) | Laravel 11 (API-only, `routes/api.php`) |
| Language | TypeScript | PHP 8.3 |
| ORM | Prisma | Eloquent |
| DB | PostgreSQL | PostgreSQL (keep) |
| Auth | NextAuth (JWT, credentials provider) | Laravel Sanctum (SPA/token) or Passport; JWT claims carry `tenant_id` + roles |
| Authorization | `requireRole()` guard, comma-separated role string | Policies + Gates; `spatie/laravel-permission` (roles scoped per tenant) |
| Validation | Zod schemas in `src/lib/validators.ts` | FormRequest classes |
| File storage | Vercel Blob | S3-compatible (`Storage` disk), tenant-prefixed keys |
| Background work | Inline / fire-and-forget on page load | Queued jobs + scheduler (`php artisan schedule`) |
| AI parsing | Anthropic SDK called from route handlers | Anthropic via a queued service class |
| e-Invoice | `src/lib/e-racun-client.ts` (Moj-eRačun v2) | Dedicated integration service, per-tenant credentials |
| Frontend | Coupled (React) | Decoupled SPA/mobile consuming the Laravel API |

## Role model (authorization)

Roles are stored as a **comma-separated string** on the user (e.g. `"ADMIN"` or
`"TEAM,CELLAR"`). A user may hold multiple roles. Source of truth:
`src/types/index.ts`.

| Role | Capability (observed in code) |
|---|---|
| `ADMIN` | Everything: delete customers/inventory/suppliers/costs, manage users, generate/revoke order tokens, inventory checks, reorder, translation overrides, e-invoice sync. |
| `TEAM` | Create/read/update customers, orders, inventory. No deletes, no user management. |
| `CELLAR` | All cellar module actions (vessels, lots, transfers, additions, analyses, tasting notes, bottling). Cellar guard is `requireRole("ADMIN","CELLAR")`. |
| `ORDERS` | Reserved/declared role for order-focused staff (defined in the `Role` type). |

> **Migration note:** keep the *semantics* but model roles properly in Laravel
> with `spatie/laravel-permission`, scoping role assignments to a tenant via a
> team/tenant column. A `tenant_owner` role should map to today's `ADMIN`.

## Glossary

| Term | Meaning |
|---|---|
| **Lot / Wine Lot** | A batch of wine tracked through fermentation/aging with its own volume and cost. |
| **Vessel** | A physical container (barrel/tank/vat/amphora). Holds portions of one or more lots via `VesselLot`. |
| **VesselLot** | Join row: how many liters of a given lot sit in a given vessel. |
| **Racking / Transfer** | Moving wine between vessels/lots (`RACK`, `BLEND`, `SPLIT`). |
| **Bottling** | Converting bulk lot volume into counted bottles → finished inventory, setting COGS. |
| **Recipe / BOM** | `RecipeItem` rows defining inputs consumed to produce one output item. |
| **COGS snapshot** | Cost per unit copied onto an order line at creation time so margins are immune to later cost changes. |
| **Pricing tier** | A named price book (e.g. Wholesale/Retail) with its own per-item prices and a default rebate. |
| **Order token** | A per-customer secret enabling unauthenticated self-service ordering. |
| **OIB** | Croatian tax ID (used to match/auto-create suppliers). |
| **Moj-eRačun** | Croatian e-invoice exchange network; the app polls its inbox and turns invoices into costs. |
| **Tenant** | One winery business / SaaS account (introduced for the rebuild). |

## Non-functional requirements carried forward

- **Croatian-first i18n** with DB-backed translation overrides (locale `hr` default, `en` available).
- **Decimal money & volume precision** — never use floats for money; current code rounds aggressively (`Math.round(x*100)/100`).
- **Auditability** — order status history, stock movements, cellar operations, and cost provenance are all retained; preserve this.
- **Soft-delete-when-referenced** pattern is used throughout (deactivate instead of delete if child records exist). Keep it.