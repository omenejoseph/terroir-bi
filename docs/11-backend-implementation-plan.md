# 11 — Backend Implementation Plan (Laravel) 🆕

Scope: **Laravel backend only** (no frontend/Next work in this pass). Two goals:

1. **Parity** — bring the already-built Customers/Pricing, Team/IAM, and Inventory
   modules up to the source app's current `main` (the deltas in
   [`10-migration-deltas.md`](10-migration-deltas.md)).
2. **Orders** — build the Orders module **like-for-like** with the source
   (internal, public-token, consignment, notifications, analytics — see flows
   01/02/03/10).

## Conventions to follow (match the existing codebase)

- **Migrations** in `database/migrations`; money = `bigInteger` minor units + `MoneyCast`; stock/volume = `decimal(12,3)`; ULID PKs; every tenant table uses `BelongsToTenant` + `tenant_id`.
- One **Action** per write use-case (`app/Actions/<Domain>/<Verb>Action.php`), thin **Controllers** (`app/Http/Controllers/Api`), **FormRequest** validation, read paths via **Query** objects (`app/Queries`), cross-cutting logic in **Services** (`app/Services`), enums in `app/Enums`, response/DTO shaping via `DataTransferObjects` / API Resources.
- **Tenant scoping** is automatic via `TenantScope`; public token routes resolve the tenant from the token (see flow 02) and must set tenant context before any query.
- **Quality gate per PR:** `composer check` (Pint + PHPStan level 8 + Pest). Add tests with each phase; keep PHPStan green (type the new money/enum casts).

---

## Phase 0 — Foundations the Orders module depends on

These are small but unblock everything else; do them first.

1. **Extend `TenantRole` enum** to the 11 values (`docs/03 §3.5`). Update any role-set validation. Map legacy comma-separated `User.role` → `memberships.roles` in the seeder.
2. **Membership order flags + financial gate.** Migration: add `can_edit_orders` (bool, default false) and `can_see_shipped_orders` (bool, default false) to `memberships`. Add a `MembershipContext` helper `canSeeFinancials()` and `canEditOrders()`; wire a Gate/Policy.
3. **`StockLedger` deduction service.** Extend the existing `app/Services/Inventory/StockLedger.php` with a `deduct()` path that: converts `unit_type`→storage `unit` via `bottles_per_case` (6-dp), locks the row (`lockForUpdate()`), guards against negative stock (throws a domain exception → `422`), writes the `ORDER_DEDUCT` movement, and decrements `current_stock` — all inside a transaction. Mirror a `restore()` for deletes/returns. **Everything else (orders, public, consignment) reuses this.**

## Phase 1 — Inventory parity

Migrations (additive, no data loss):
- `inventory_items`: `unit_size`, `sales_unit`, `pack_size` (default 1), `hide_from_portal` (bool), `is_auto_created` (bool), `auto_created_at` (ts nullable), `base_product_id` (FK self, nullable).
- `stock_movements`: `is_reconciliation` (bool, default false).
- `recipe_items`: make `input_id` nullable; add `custom_name`, `custom_unit`, `custom_cost` (bigInteger nullable).

Behavior:
- **Produce from recipe** — `ProduceItemAction` + `POST /inventory-items/{id}/produce`: validate inputs in stock, write `PRODUCTION_OUT` per input and `PRODUCTION_IN` for the output, reference `PROD-{sku}`, all in one transaction via `StockLedger`.
- **Stocktake / check** — `ApplyInventoryCheckAction` + `POST /inventory-items/check`: per line, write an `ADJUSTMENT` movement with `is_reconciliation = true`, reference `INVCHECK-{date}`.
- **Reconciliation toggle** — `PATCH /stock-movements/{id}/reconciliation` flips the flag (no stock change).
- **Spend reconciliation** — when the spend/analytics queries land, scale each order's recorded `ORDER_DEDUCT` to the order's *current* line qty (`min(1, live/recorded)`), and exclude `is_reconciliation` rows. (Spend reporting itself can be a later analytics sub-phase.)
- **Images / tech sheets** — `inventory_images`, `inventory_tech_sheets` tables + add/remove endpoints (already in docs §3.4 / API ref).

Update `StoreInventoryItemRequest`/`UpdateInventoryItemRequest` and `ListInventoryItemsQuery` (portal filters) for the new columns.

## Phase 2 — Customers & Pricing parity

Migrations:
- `customers`: `customer_type`, `oib`, `is_agency` (bool), `allow_single_bottle` (bool), `reorder_contacted_at` (ts nullable).
- New table `customer_product_overrides` (`customer_id`, `inventory_item_id`, `visible`, unique pair).

Behavior:
- **VIES lookup** — `LookupCompanyByVatService` + `GET /customers/lookup-vat?vat=`: call EU VIES, parse name/address; tolerate OIB-only (HR) and prefixed VAT; return `{error}` on miss. (Outbound HTTP — respect the env network policy; make it injectable/mockable for tests.)
- **Reorder radar** — `ReorderRadarQuery` + `GET /customers/reorder-radar`: median order-gap, overdue ratio buckets (due/overdue/at-risk), value-weighted rank, hide accounts contacted since last order. `POST /customers/{id}/contacted` sets/clears `reorder_contacted_at`.
- **Merge** — `PreviewCustomerMergeAction` / `MergeCustomersAction` + endpoints: reassign child rows (orders, prices, overrides, consignment, …) inside a transaction, drop unique-key collisions toward the winner, delete losers.
- **Product overrides** — upsert/list/delete endpoints backed by `customer_product_overrides`.
- **Customer insights** — `CustomerInsightsQuery` + `GET /customers/{id}/insights` (revenue trend, product performance incl. realized consignment, YoY). *Depends on Orders + consignment data — schedule after Phase 4.*

Update customer FormRequests for the new fields (validate `oib`, booleans).

## Phase 3 — Orders core (internal)

Migrations: `orders`, `order_items`, `order_status_histories`, `order_notes`
(per data model §3.4, including `is_backorder`, `backorder_date`, `shipping_cost`,
`shipping_paid_by_us`, `is_consignment`, `consignment_closed_at`,
`last_stale_notified_at`; nullable `order_items.inventory_item_id` +
`custom_description`). Add the three indexes.

Enum: `OrderStatus` (`RECEIVED, IN_PROCESS, READY_TO_SHIP, SHIPPED`).

Service: `OrderNumberGenerator` (tenant-scoped, collision-safe).
Service: `CogsSnapshot` — resolve per-unit cost (item `cost_per_unit` or recipe roll-up via existing recipe cost calc), scaled to display unit.

Actions / endpoints (each a transaction; deduction via `StockLedger`):
- `CreateOrderAction` → `POST /orders` (flow 01): resolve+verify prices via `PricingService`, snapshot COGS, deduct stock (unless backorder), write status history, fire `NEW_ORDER` notifications.
- `UpdateOrderStatusAction` → `PATCH /orders/{id}/status` (history + `ORDER_STATUS` notify).
- `AddOrderItemsAction` → `POST /orders/{id}/items`; `UpdateOrderItemAction` (qty/unit) and `UpdateOrderItemCostAction` → `PATCH /order-items/{id}[/cost]`; `DeleteOrderItemAction` → `DELETE /order-items/{id}` (restock; block last line).
- `UpdateOrderShippingAction`, `UpdateOrderBackorderAction`, `UpdateOrderNotesAction`.
- `DeleteOrderAction` → `DELETE /orders/{id}` (ADMIN): restock, null `inflow.order_id`/`deal.order_id` (when those exist), cascade children.
- Reads: `ListOrdersQuery` (status/search, hide SHIPPED per membership flag) + `GET /orders`, `GET /orders/{id}`.

**Edit-window policy:** enforce the 1-hour rule (admins + `can_edit_orders` exempt; shipping/cost edits always allowed) in a Policy or a shared guard used by the line-edit actions.

Now backfill the deferred **soft-delete rules**: customer/inventory hard-delete → soft-delete when referenced by an order (the docs flag these as "lands with Orders").

## Phase 4 — Public token, consignment, comments, notifications

- **Public order** (flow 02): `GET /public/{token}/catalog`, `POST /public/{token}/orders`. Resolve tenant+customer from token; apply catalog gating (`hide_from_portal`, overrides, `allow_single_bottle`); re-resolve prices server-side and reject mismatches; rate-limit; attribute to a system/admin user. Reuse `CreateOrderAction` internals.
- **Consignment** (flow 10): `consignment_reports` + `consignment_report_items`; order-level `GET/POST /orders/{id}/consignment*` and customer-level FIFO `/customers/{id}/consignment*`. SALE = revenue+COGS no stock change; RETURN = restock via `StockLedger`; close = auto-return remainder. Quantities normalized to bottles.
- **Comments**: `order_notes` threaded comments + `POST /orders/{id}/comments`, edit/delete (author/ADMIN). Parse `@mentions` → notifications.
- **Notifications** (module 10): `notifications` table, `GET /notifications`, `POST /notifications/read`. Emit `NEW_ORDER/ORDER_STATUS/MENTION/REPLY` via a `Notifier` service. Web Push (`push_subscriptions` + `POST /push-subscriptions`) and WhatsApp are best-effort transports — **stub/queue them**; the persisted feed is the must-have.
- **Stale-order job**: scheduled command flagging unshipped orders idle > 24h, deduped via `last_stale_notified_at`.

## Phase 5 — Analytics & AI (backend)

- **Order analytics** — `OrderAnalyticsQuery` + `GET /orders/analytics?period=`: revenue/COGS/margin, top customers/products, low-margin alerts, price realization. Respect `canSeeFinancials()`.
- **Customer insights** (deferred from Phase 2) now computable.
- **AI screenshot** (flow 03) — `POST /ai/parse-order-screenshot`: Claude vision extract → fuzzy product/customer match → **draft** response (no stock touched). Use the latest Claude models per [`07-integrations.md`](07-integrations.md); make the client injectable for tests; gate behind per-tenant AI key.

---

## Dependency order (summary)

```
Phase 0 (roles, flags, StockLedger.deduct)
   ├─ Phase 1 Inventory parity
   ├─ Phase 2 Customers parity (insights deferred)
   └─ Phase 3 Orders core ── Phase 4 public/consignment/comments/notifications ── Phase 5 analytics/AI
```

## Definition of done (per phase)
- Migrations reversible; `php artisan migrate:fresh --seed` green.
- Models typed, `MoneyCast`/enum casts in place; PHPStan level 8 clean.
- FormRequests validate every new field; cross-tenant access returns `404`.
- Pest feature tests cover happy path + the **business rules** (overdraw guard,
  COGS snapshot immutability, price re-verification on public orders, consignment
  remainder-only restock, edit-window enforcement, last-admin guard).
- `composer check` passes; API reference + OpenAPI `live.yaml` updated for shipped endpoints.

## Risks / watch-items
- **Money type drift**: docs show `decimal(14,2)` but the schema uses integer minor units — new money columns must use `bigInteger` + `MoneyCast`.
- **`order_token` tenancy**: globally-unique token must resolve tenant safely (flow 02 / security doc §4.6).
- **Concurrency**: deduction must hold the row lock for the whole transaction to honor the overdraw guard.
- **Notifications coupling**: keep transport (push/WhatsApp) out of the order transaction — fire after commit, best-effort.
