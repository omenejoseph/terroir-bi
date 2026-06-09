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
- [x] Migration `customers`: `customer_type`, `oib`, `is_agency` (bool), `allow_single_bottle` (bool), `reorder_contacted_at` (ts null).
- [x] Migration `customer_product_overrides` (`customer_id`, `inventory_item_id`, `visible`, unique pair).
- [x] Update `Customer` model (+`productOverrides`) + `Store/UpdateCustomerRequest` + `CustomerData`.

### 2.2 Behavior
- [x] `LookupCompanyByVatService` (injectable HTTP, VIES) + `GET /customers/lookup-vat?vat=`.
- [x] Product-override upsert/list/delete endpoints (`CustomerProductOverrideController`).
- **Accept:** ✅ VIES fake returns parsed name/zip/city; new fields round-trip; overrides upsert/list/delete — `tests/Feature/Customers/CustomerParityTest.php`. `composer check` green (252 tests).

### 2b — Order-dependent customer features *(moved out of Phase 2; need order history)*
These read or reassign **orders**, so they land **after Phase 3 (Orders core)**:
- [ ] `ReorderRadarQuery` + `GET /customers/reorder-radar`; `POST /customers/{id}/contacted` (median order-gap → overdue buckets; `reorder_contacted_at` column is already in place).
- [ ] `PreviewCustomerMergeAction` / `MergeCustomersAction` + `POST /customers/merge[/preview]` (reassign orders/consignment/prices/overrides; drop unique collisions).
- [ ] `CustomerInsightsQuery` + `GET /customers/{id}/insights` (revenue trend, product performance incl. realized consignment, YoY).

---

## Phase 3 — Orders core (internal)

### 3.1 Schema & enums
- [x] Migrations: `orders`, `order_items`, `order_status_histories`, `order_notes` (all columns incl. backorder/shipping/consignment/`last_stale_notified_at`; nullable `order_items.inventory_item_id` + `custom_description`).
- [x] Indexes: `(status,created_at)`, `(customer_id,created_at)`, `(is_consignment)`.
- [x] `app/Enums/OrderStatus.php`.
- [x] Models + relations + Money/enum casts.

### 3.2 Services
- [x] `OrderNumberGenerator` (tenant-scoped `ORD-NNNNN`, unique-index backstop).
- [x] `CogsSnapshot` (recipe roll-up incl. custom lines, else `cost_per_unit` → display unit).
- [x] `OrderEditGuard` (1h; admins + `can_edit_orders` exempt; cost/shipping callers don't invoke it). Plus shared `OrderLineWriter` + `OrderTotals`.

### 3.3 Actions & endpoints (each transactional, deduction via StockLedger)
- [x] `CreateOrderAction` → `POST /orders` (resolve price/case-scale + optional override, COGS snapshot, deduct unless backorder, status history). *(NEW_ORDER notify → Phase 4.)*
- [x] `UpdateOrderStatusAction` → `PATCH /orders/{id}/status`.
- [x] `AddOrderItemsAction` → `POST /orders/{id}/items`.
- [x] `UpdateOrderItemAction` (qty/unit, rescales price + re-snapshots COGS) → `PATCH /order-items/{id}`; `UpdateOrderItemCostAction` → `PATCH /order-items/{id}/cost`.
- [x] `DeleteOrderItemAction` → `DELETE /order-items/{id}` (restock; last line protected).
- [x] `UpdateOrderShippingAction`, `UpdateOrderBackorderAction` (ADMIN), `UpdateOrderNotesAction`.
- [x] `DeleteOrderAction` → `DELETE /orders/{id}` (restock + cascade). *(inflow/deal nulling is a no-op until those tables exist.)*
- [x] `ListOrdersQuery` + `GET /orders`, `GET /orders/{id}` (hides SHIPPED per `can_see_shipped_orders`; COGS gated by `canSeeFinancials()`).

### 3.4 Deferred soft-deletes now wired
- [x] Customer & InventoryItem hard-delete → **deactivate when referenced by an order**.
- **Accept:** ✅ create deducts + snapshots COGS (immune to later cost edits); overdraw guard on create/add/edit; edit window enforced; delete restocks; shipped hidden; COGS gated — `tests/Feature/Orders/OrderTest.php` (13 tests). `composer check` green (296 tests).

---

## Phase 4 — Public, consignment, comments, notifications

### 4.1 Public token (flow 02) ✅
- [x] `GET /public/{token}/catalog` (`PublicTokenResolver` binds tenant from token; `PublicCatalogQuery` gates by `hide_from_portal`/overrides; `hide_prices` + `allow_single_bottle` honored).
- [x] `POST /public/{token}/orders` (re-resolve prices + reject mismatch, rate-limit 10/h, system-user attribution; reuses `CreateOrderAction`).
- **Accept:** ✅ tampered price → 422; unknown token → 404; stock deducts; attributed to a tenant admin — `tests/Feature/Orders/PublicOrderTest.php` (5 tests).

### 4.2 Consignment (flow 10)
- [x] Migrations: `consignment_reports`, `consignment_report_items` (qty in bottles); `ConsignmentReportKind` enum; models + `Order::consignmentReports`.
- [x] `ConsignmentService` (per-line tally + summary, per-bottle price/cost) and order-level `GET/POST /orders/{id}/consignment` (summary, sale, return, close).
- [—] Customer-level FIFO `/customers/{id}/consignment*` (place/sale/return) → **deferred to Phase 2b** (aggregation/FIFO layer over the order-level mechanics; pairs naturally with customer insights).
- **Accept:** ✅ SALE = revenue+COGS, no stock change; RETURN restocks; close auto-returns remainder + stamps closed; over-sale rejected; non-consignment guarded — `tests/Feature/Orders/ConsignmentTest.php` (6 tests). `composer check` green (320 tests).

> Note: deleting a consignment order currently restocks **all** catalog lines (Phase 3 `DeleteOrderAction`). The "remainder-only on delete" refinement moves with the customer-level FIFO work in Phase 2b.

### 4.3 Comments & notifications (module 10)
- [x] `POST /orders/{id}/comments`, `PATCH/DELETE /order-comments/{id}` (author/ADMIN; `mentions[]` of tenant members).
- [x] Migration `notifications` + model; `GET /notifications?unread=`, `POST /notifications/read`.
- [x] `Notifier` emits `NEW_ORDER` (create) / `ORDER_STATUS` (status) / `MENTION` + `REPLY` (comments), **after the order transaction commits**.
- [—] `push_subscriptions` + `POST /push-subscriptions`; Web Push / WhatsApp transports → **deferred** (the persisted in-app feed is the must-have and is done).
- [x] Scheduled `orders:stale` command (per-tenant; idle >24h; dedup via `last_stale_notified_at`; hourly in `routes/console.php`).
- **Accept:** ✅ new order notifies order-role members; status notifies followers (not the actor); mention → MENTION; read-marking works; comment edit is author/admin-only; stale command flags + stamps — `tests/Feature/Orders/NotificationTest.php` (6 tests). `composer check` green (335 tests).

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
