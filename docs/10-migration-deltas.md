# 10 — Migration Deltas (source `main` vs. this blueprint) 🆕

This blueprint's first snapshot was taken from a **stale branch** of the source
app. The source `main` has since moved on. This file is the **change log** of
everything in the already-migrated domains (**Customers & Pricing**, **Team/IAM**,
**Inventory**) — plus **Orders** — that was **not** reflected in the original docs
and is now folded into them.

> Legend: ✅ already in Laravel · 🟡 partial · ❌ not built yet.
> The build order for closing these is in
> [`11-backend-implementation-plan.md`](11-backend-implementation-plan.md).

## A. Customers & Pricing

| Delta | Where | Status |
|---|---|---|
| `customer_type` (free-form class) | `customers` col | ❌ |
| `oib` (OIB/EU-VAT) + **VIES auto-fill** endpoint | `customers` col + `GET /customers/lookup-vat` | ❌ |
| `is_agency` (hospitality price book flag) | `customers` col | ❌ |
| `allow_single_bottle` (per-bottle portal ordering) | `customers` col | ❌ |
| `reorder_contacted_at` + **reorder radar** | `customers` col + `GET /customers/reorder-radar`, `POST /customers/{id}/contacted` | ❌ |
| **Customer merge** (reassign children, drop collisions) | `POST /customers/merge[/preview]` | ❌ |
| **Per-customer catalog overrides** | `customer_product_overrides` table + endpoints | ❌ |
| **Customer-level consignment** (FIFO across placements) | `/customers/{id}/consignment*` | ❌ |
| Customer **insights** (revenue trend, product performance, YoY) | `GET /customers/{id}/insights` | ❌ |
| Price resolver returns **per-bottle**; case = ×`bottles_per_case` | `PricingService` | 🟡 resolver ✅, unit scaling lives in Orders |

Core CRUD, tiers, customer/tier prices and the **precedence + rebate** algorithm
are ✅ already at parity.

## B. Team / IAM

| Delta | Where | Status |
|---|---|---|
| Role set widened to **11** values | `TenantRole` enum (has 4) | ❌ extend enum |
| `can_edit_orders` / `can_see_shipped_orders` (per-membership) | `memberships` cols | ❌ |
| `canSeeFinancials()` gate (ADMIN/TEAM/MANAGER/SALES/ORDERS) | policy/gate | ❌ |
| Last-admin / self-delete guards | actions | ✅ |
| Memberships + invitations (token, 14-day expiry, accept) | models/actions | ✅ |

> The source's `User.role` becomes `memberships.roles` in the rebuild (already
> modelled). The order-permission flags and financial gate are the only real IAM
> gaps, and they're driven by the Orders module.

## C. Inventory

| Delta | Where | Status |
|---|---|---|
| `is_reconciliation` on movements (excluded from spend/exit) | `stock_movements` col | ❌ |
| **Overdraw guard** (`SELECT … FOR UPDATE`, no negative stock) | order deduction path | ❌ (lands with Orders) |
| **ORDER_DEDUCT → live-qty reconciliation** in spend reports | `inventory-spend` queries | ❌ |
| Catalog fields: `hide_from_portal`, `sales_unit`, `unit_size`, `pack_size` | `inventory_items` cols | ❌ |
| Vintage grouping: `base_product_id`, `is_auto_created`, `auto_created_at` | `inventory_items` cols | ❌ |
| Custom recipe lines: `custom_name`/`custom_cost`/`custom_unit`, nullable `input_id` | `recipe_items` cols | ❌ |
| Stocktake / inventory check → ADJUSTMENT (reconciliation) | `POST /inventory-items/check` | 🟡 endpoint documented; reconciliation flag missing |
| **Produce from recipe** (PRODUCTION_IN/OUT) | `POST /inventory-items/{id}/produce` | ❌ |
| Images / tech sheets | tables + endpoints | ❌ |

Item CRUD, the stock **ledger** (6 movement types), recipe replace, taxonomy and
analytics queries are ✅ already at parity.

## D. Orders *(entire module — documented, ❌ not built)*

New since the snapshot (all ❌): `is_backorder`/`backorder_date`,
`shipping_cost`/`shipping_paid_by_us`, `is_consignment`/`consignment_closed_at`,
`last_stale_notified_at`, nullable `order_items.inventory_item_id` +
`custom_description`, **consignment reports**, **notifications** (bell/push/WhatsApp),
**stale-order cron**, **1-hour edit window**, **order analytics**, and
**AI screenshot capture**. See flows 01/02/03/10 and the build plan.

## Out of scope (noted, not planned this pass)
- **AI integrations** — deferred. The platform will use **Laravel AI** for all model
  access; the order-from-screenshot capture (flow 03) and fuzzy matcher come later
  (draft-only, no stock effect, so no Orders rework needed).
- **Employees / HR** (`dashboard/employees/**`) — separate module (module 11 in `02-modules.md`).
- **Hospitality / agency price book**, **Wine Club**, **Cellar tasting reports**, **Kitchen** inventory — tracked elsewhere; keep the `is_agency` flag only.
