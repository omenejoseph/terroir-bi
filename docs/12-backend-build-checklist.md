# 12 — Backend Build Checklist (parity + Orders) 🆕

The **executable backlog** for the Laravel backend. Strategy/rationale lives in
[`11-backend-implementation-plan.md`](11-backend-implementation-plan.md); the
deltas being closed are in [`10-migration-deltas.md`](10-migration-deltas.md).
This file is the ordered, tickable task list.

- Each task names the **artifact** to create/change and its **acceptance**.
- Tasks are ordered so each is unblocked when reached.
- Gate every PR with `composer check` (Pint + PHPStan 8 + Pest).
- Suggested grouping = one PR per `### group`.

Progress key: `[ ]` todo · `[~]` in progress · `[x]` done.

---

## Phase 0 — Foundations  *(blocks Orders)*

### 0.1 Roles & membership flags
- [x] Extend `app/Enums/TenantRole.php` to 11 cases (`ADMIN, TEAM, CELLAR, ORDERS, MANAGER, SALES, HOSPITALITY, KITCHEN, EMPLOYEE, WINE_CLUB, INVENTORY`).
- [x] Migration: add `can_edit_orders` (bool, default false) + `can_see_shipped_orders` (bool, default false) to `memberships`.
- [x] `Membership` casts/fillable + `canEditOrders()`/`canSeeShippedOrders()` (ADMIN overrides); `MembershipContext::canSeeFinancials()` (via `financials.view` capability on ADMIN/TEAM/MANAGER/SALES/ORDERS) + `canEditOrders()`/`canSeeShippedOrders()`.
- [—] ~~Seeder maps legacy `role` → `memberships.roles`~~ — N/A: the Laravel app has no legacy `users.role` column (roles already live on `memberships.roles`). This is a **data-cutover** task for the actual migration, not backend code.
- **Accept:** ✅ PHPStan level 8 clean; tests for `canSeeFinancials`/`canEditOrders`/`canSeeShippedOrders` (`tests/Feature/Members/OrderPermissionsTest.php`).

### 0.2 StockLedger deduction/restoration
- [x] Extend `app/Services/Inventory/StockLedger.php`: `deduct(item, qty, unitType, reference, note)` → convert via `bottles_per_case`, `lockForUpdate()`, guard negative (throw `InsufficientStockException`), write `ORDER_DEDUCT`, decrement `current_stock` — one transaction.
- [x] `restore(item, qty, unitType, type, reference)` mirror for deletes/returns.
- [x] Map `InsufficientStockException` → `422` (self-rendering) with the source's message text.
- **Accept:** ✅ `tests/Feature/Inventory/StockLedgerDeductTest.php` proves no negative stock under the guard and correct bottles↔cases conversion.

---

## Phase 1 — Inventory parity

### 1.1 Schema
- [x] Migration `inventory_items`: `unit_size`, `sales_unit`, `pack_size` (default 1), `hide_from_portal` (bool), `is_auto_created` (bool), `auto_created_at` (ts null), `base_product_id` (FK self, null).
- [x] Migration `stock_movements`: `is_reconciliation` (bool default false).
- [x] Migration `recipe_items`: `input_id` nullable; add `custom_name`, `custom_unit`, `custom_cost` (bigInteger null, MoneyCast).
- [x] Update models (fillable/casts/relations: `baseProduct`; `RecipeLineData` null-safe for custom lines).

### 1.2 Behavior
- [x] `ProduceItemAction` + `POST /inventory-items/{id}/produce` (guarded PRODUCTION_OUT inputs, PRODUCTION_IN output, ref `PROD-{sku}`).
- [x] `ApplyInventoryCheckAction` + `POST /inventory-items/check` → `ADJUSTMENT` rows `is_reconciliation=true`, ref `INVCHECK-{date}` (computed vs live stock).
- [x] `PATCH /stock-movements/{id}/reconciliation` toggles the flag (no stock change); `is_reconciliation` also threadable through manual `POST .../stock`.
- [—] `inventory_images` + `inventory_tech_sheets` tables + add/remove endpoints → **deferred to Phase 1b** (pure CRUD, no business logic; keeps this PR focused).
- [x] `Store/UpdateInventoryItemRequest` + `InventoryItemData` carry the new fields. *(Portal `hide_from_portal` **filter** in `ListInventoryItemsQuery` lands with the public catalog in Phase 4.)*
- **Accept:** ✅ produce respects input stock (guarded) and rejects no-recipe; check writes reconciliation adjustments vs live stock — `tests/Feature/Inventory/ProduceAndCheckTest.php`. `composer check` green (243 tests).

---

## Phase 2 — Customers & Pricing parity

### 2.1 Schema
- [ ] Migration `customers`: `customer_type`, `oib`, `is_agency` (bool), `allow_single_bottle` (bool), `reorder_contacted_at` (ts null).
- [ ] Migration `customer_product_overrides` (`customer_id`, `inventory_item_id`, `visible`, unique pair).
- [ ] Update `Customer` model + `Store/UpdateCustomerRequest`.

### 2.2 Behavior
- [ ] `LookupCompanyByVatService` (injectable HTTP) + `GET /customers/lookup-vat?vat=`.
- [ ] `ReorderRadarQuery` + `GET /customers/reorder-radar`; `POST /customers/{id}/contacted`.
- [ ] `PreviewCustomerMergeAction` / `MergeCustomersAction` + `POST /customers/merge[/preview]`.
- [ ] Product-override upsert/list/delete endpoints.
- [ ] *(deferred to after Phase 4)* `CustomerInsightsQuery` + `GET /customers/{id}/insights`.
- **Accept:** VIES mock returns parsed address; radar buckets match median-gap rules; merge reassigns children + drops collisions in one transaction.

---

## Phase 3 — Orders core (internal)

### 3.1 Schema & enums
- [ ] Migrations: `orders`, `order_items`, `order_status_histories`, `order_notes` (all columns per data-model §3.4, incl. backorder/shipping/consignment/`last_stale_notified_at`; nullable `order_items.inventory_item_id` + `custom_description`).
- [ ] Indexes: `(status,created_at)`, `(customer_id,created_at)`, `(is_consignment)`.
- [ ] `app/Enums/OrderStatus.php`.
- [ ] Models + relations + Money/enum casts.

### 3.2 Services
- [ ] `OrderNumberGenerator` (tenant-scoped, collision-safe).
- [ ] `CogsSnapshot` (item `cost_per_unit` or recipe roll-up → display unit).
- [ ] Edit-window guard/policy (1h; admins + `can_edit_orders` exempt; cost/shipping exempt).

### 3.3 Actions & endpoints (each transactional, deduction via StockLedger)
- [ ] `CreateOrderAction` → `POST /orders` (price verify, COGS snapshot, deduct unless backorder, status history, `NEW_ORDER`).
- [ ] `UpdateOrderStatusAction` → `PATCH /orders/{id}/status`.
- [ ] `AddOrderItemsAction` → `POST /orders/{id}/items`.
- [ ] `UpdateOrderItemAction` (qty/unit) → `PATCH /order-items/{id}`; `UpdateOrderItemCostAction` → `PATCH /order-items/{id}/cost`.
- [ ] `DeleteOrderItemAction` → `DELETE /order-items/{id}` (restock; block last line).
- [ ] `UpdateOrderShippingAction`, `UpdateOrderBackorderAction`, `UpdateOrderNotesAction`.
- [ ] `DeleteOrderAction` → `DELETE /orders/{id}` (restock, null inflow/deal links, cascade).
- [ ] `ListOrdersQuery` + `GET /orders`, `GET /orders/{id}`.

### 3.4 Deferred soft-deletes now wired
- [ ] Customer & InventoryItem hard-delete → **soft-delete when referenced by an order**.
- **Accept:** create deducts + snapshots COGS; COGS immune to later cost edits; edit window enforced; delete restocks exactly.

---

## Phase 4 — Public, consignment, comments, notifications

### 4.1 Public token (flow 02)
- [ ] `GET /public/{token}/catalog` (resolve tenant+customer; gate by `hide_from_portal`/overrides/`allow_single_bottle`).
- [ ] `POST /public/{token}/orders` (re-resolve prices, reject mismatch, rate-limit, system-user attribution; reuse `CreateOrderAction`).
- **Accept:** tampered price → rejected; cross-tenant impossible; rate limit enforced.

### 4.2 Consignment (flow 10)
- [ ] Migrations: `consignment_reports`, `consignment_report_items` (qty in bottles).
- [ ] Order-level `GET/POST /orders/{id}/consignment*` (summary, sale, return, close).
- [ ] Customer-level FIFO `/customers/{id}/consignment*` (place/sale/return).
- **Accept:** SALE = revenue+COGS no stock change; RETURN restocks; close auto-returns remainder; delete restocks remainder only.

### 4.3 Comments & notifications (module 10)
- [ ] `POST /orders/{id}/comments`, `PATCH/DELETE /order-comments/{id}` (author/ADMIN; parse `@mentions`).
- [ ] Migration `notifications`; `GET /notifications`, `POST /notifications/read`.
- [ ] `Notifier` service emits `NEW_ORDER/ORDER_STATUS/MENTION/REPLY` **after commit**.
- [ ] `push_subscriptions` + `POST /push-subscriptions`; Web Push/WhatsApp **stubbed/queued**.
- [ ] Scheduled `orders:stale` command (idle >24h, dedup via `last_stale_notified_at`).
- **Accept:** mention notifies the mentioned user; transports never break the order txn.

---

## Phase 5 — Analytics (backend)

- [ ] `OrderAnalyticsQuery` + `GET /orders/analytics?period=` (revenue/COGS/margin, top customers/products, low-margin), gated by `canSeeFinancials()`.
- [ ] Spend reconciliation: scale `ORDER_DEDUCT` to live line qty; exclude `is_reconciliation`.
- [ ] `CustomerInsightsQuery` + `GET /customers/{id}/insights` (now computable).
- **Accept:** analytics exclude reconciliations.

> **AI integrations are skipped this pass.** When they land they'll be built on
> **Laravel AI** (not a hand-rolled Anthropic client). The order-from-screenshot
> capture (`POST /ai/parse-order-screenshot`, flow 03) is **deferred** — and since
> it's draft-only (touches no stock), it can be added later without reworking the
> Orders module. The fuzzy product/customer matcher it depends on is likewise
> deferred. See `07-integrations.md`.

---

## Cross-cutting done-criteria
- [ ] `php artisan migrate:fresh --seed` green; migrations reversible.
- [ ] Every new field validated; cross-tenant access → `404`.
- [ ] Pest covers business rules: overdraw guard, COGS immutability, public price re-verify, consignment remainder-only restock, edit-window, last-admin guard.
- [ ] `docs/openapi/live.yaml` + `06-api-reference.md` updated as endpoints ship.

## Sequencing at a glance
```
0.1 roles/flags ─┐
0.2 StockLedger ─┴─▶ 1 Inventory ─▶ 2 Customers ─▶ 3 Orders core ─▶ 4 public/consignment/notify ─▶ 5 analytics/AI
```
PRs 0→5 in order; 1 and 2 may run in parallel once 0 lands.
