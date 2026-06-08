# 06 — REST API Reference

Target: a Laravel API exposed under `/api/v1`. Endpoints are derived from the
current server actions + route handlers and re-cast as resource REST. The
machine-readable contract is [`openapi/openapi.yaml`](openapi/openapi.yaml);
this file is the human narrative.

## Conventions

- **Base URL:** `/api/v1`
- **Auth:** `Authorization: Bearer <token>` (Sanctum/Passport). Token carries `tenant_id` + roles.
- **Tenant:** never sent by the client as a body field; always derived from the token (or order-token for public routes).
- **Content type:** `application/json` except file uploads (`multipart/form-data`).
- **Errors:** `422` validation (Laravel error bag), `401` unauth, `403` role denied, `404` not found (also returned for cross-tenant access), `409` conflict (duplicate SKU/email).
- **Money:** decimal strings. **IDs:** ULIDs.
- **Auth column** in tables below: role required (`ADMIN` etc.), or `public` (order-token), or `—` (any authenticated user).

---

## Auth & Users  (`/auth`, `/users`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/auth/login` | public | Email + password → token |
| POST | `/auth/logout` | — | Revoke token |
| GET | `/auth/me` | — | Current user + roles + tenant |
| GET | `/users` | ADMIN | List tenant users |
| POST | `/users` | ADMIN | Create user (`name,email,password,role`); bcrypt cost 12; default role `TEAM` |
| PATCH | `/users/{id}` | ADMIN | Update name/email/role/password |
| DELETE | `/users/{id}` | ADMIN | Delete — **blocked if self or has orders** |

Registration of the first user happens during tenant onboarding (flow 09).

---

## Customers & Pricing  (`/customers`, `/pricing-tiers`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/customers?search=` | — | List (with tier + order count). Diacritics-aware search |
| GET | `/customers/{id}` | — | Detail (tier, prices, orders) |
| GET | `/customers/{id}/insights` | — | totalSpend, orderCount, avgOrderValue, lastOrderDate, topProducts |
| POST | `/customers` | — | Create (`company_name,contact_name,email,…`) |
| POST | `/customers/quick` | — | Quick create (`name,email`) |
| PATCH | `/customers/{id}` | — | Update |
| DELETE | `/customers/{id}` | ADMIN | Soft-delete if it has orders, else hard delete |
| POST | `/customers/{id}/order-token` | ADMIN | Generate self-service token |
| DELETE | `/customers/{id}/order-token` | ADMIN | Revoke token |
| GET | `/pricing-tiers` | — | List tiers (+customer count) |
| POST | `/pricing-tiers` | — | Create (`name,description,rebate_percent`) |
| PATCH | `/pricing-tiers/{id}` | — | Update |
| DELETE | `/pricing-tiers/{id}` | — | Delete |
| PUT | `/inventory-items/{item}/tier-price/{tier}` | — | Upsert TierPrice (`price`) |
| DELETE | `/inventory-items/{item}/tier-price/{tier}` | — | Remove TierPrice |
| PUT | `/inventory-items/{item}/customer-price/{customer}` | — | Upsert CustomerPrice (`price`) |
| DELETE | `/inventory-items/{item}/customer-price/{customer}` | — | Remove CustomerPrice |
| GET | `/customers/{id}/resolved-prices?item_ids=` | — | Batch price resolution (see pricing engine) |

---

## Inventory & Recipes  (`/inventory-items`, `/recipes`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/inventory-items?search=&category=` | — | List active items |
| GET | `/inventory-items/sellable` | — | FINISHED + is_for_sale + has price |
| GET | `/inventory-items/{id}` | — | Detail (images, tech sheets, prices, last 50 movements, recipe) |
| POST | `/inventory-items` | — | Create. SKU unique per tenant → `409` on dup |
| PATCH | `/inventory-items/{id}` | — | Update. Triggers bottles↔cases stock conversion if unit changes |
| DELETE | `/inventory-items/{id}` | ADMIN | Soft-delete if in orders, else hard delete (+recipe cleanup) |
| POST | `/inventory-items/{id}/movements` | — | Manual stock movement (`type: MANUAL_IN\|MANUAL_OUT, quantity, note`) |
| POST | `/inventory-items/{id}/produce` | — | Produce from recipe (`display_quantity`) — consumes inputs, adds output |
| POST | `/inventory-items/{id}/images` | — | Add image (`url, alt`) |
| DELETE | `/inventory-items/{id}/images/{imageId}` | — | Remove image |
| POST | `/inventory-items/{id}/tech-sheets` | — | Add tech sheet (`name, url`) |
| DELETE | `/inventory-items/{id}/tech-sheets/{sheetId}` | — | Remove |
| GET | `/inventory-items/{id}/recipe` | — | Recipe rows + input costs |
| GET | `/inventory-items/{id}/recipe/available-inputs` | — | Eligible inputs (+ ready/aging wine lots as virtual inputs) |
| PUT | `/inventory-items/{id}/recipe` | — | Replace recipe (`items:[{input_id,quantity}]`); auto-updates output `cost_per_unit`; auto-creates RAW_MATERIAL for wine-lot inputs |
| GET | `/inventory-items/{id}/recipe/cost` | — | Computed recipe cost |
| POST | `/inventory-items/check` | ADMIN | Apply physical count (`[{item_id,system_stock,physical_count}]`) → ADJUSTMENT movements |
| PUT | `/inventory-items/reorder` | ADMIN | Bulk `sort_order` update |
| GET | `/inventory/analytics/value-by-category` | — | Stock value grouped by category |
| GET | `/inventory/analytics/stock-levels` | — | Top products stock |
| GET | `/inventory/analytics/low-stock` | — | Items below/near min |

---

## Orders  (`/orders`, public `/public`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/orders?status=&search=` | — | List with customer/creator/items/history |
| GET | `/orders/{id}` | — | Full detail incl. status history + notes |
| POST | `/orders` | — | Create order (see flow 01). Deducts stock, snapshots COGS, status `RECEIVED` |
| PATCH | `/orders/{id}/status` | — | Transition status (`status, note`) → appends history |
| POST | `/orders/{id}/items` | — | Append items (deduct stock, update total) |
| POST | `/orders/{id}/notes` | — | Add note |
| DELETE | `/orders/{id}` | ADMIN | Delete + restock via ADJUSTMENT movements |
| GET | `/public/{token}/catalog` | public | Tokenized catalog (resp. respects `hide_prices`) |
| POST | `/public/{token}/orders` | public | Customer self-service order; **server re-verifies prices** (flow 02) |

---

## Cellar / Production  (`/vessels`, `/wine-lots`)

All require `ADMIN` or `CELLAR`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/vessels` / `/vessels/{id}` | List / detail (with current lots) |
| POST | `/vessels` | Create vessel |
| POST | `/vessels/bulk` | Bulk create (`prefix,start_number,count(1–50),type,…`) |
| PATCH | `/vessels/{id}` | Update metadata |
| PATCH | `/vessels/{id}/position` | Set map coords (`position_x,position_y,room`) |
| DELETE | `/vessels/{id}/position` | Remove from map |
| DELETE | `/vessels/{id}` | Soft-delete if it has lots, else hard delete |
| GET | `/wine-lots` / `/wine-lots/{id}` | List / full detail (allocations, additions, analyses, tastings, transfers, bottlings) |
| POST | `/wine-lots` | Create lot (auto `lot_number`, optional initial vessel, grape cost calc) |
| PATCH | `/wine-lots/{id}` | Update (recalculates grape cost; can set status) |
| DELETE | `/wine-lots/{id}` | Delete + restore vessel volumes + cleanup mirror inventory |
| POST | `/wine-lots/{id}/assign-vessel` | Allocate to vessel (`vessel_id,volume`); capacity check; auto-rebalance |
| DELETE | `/vessel-lots/{id}` | Unassign allocation |
| POST | `/wine-lots/{id}/additions` | Add addition (cost roll-up) |
| DELETE | `/wine-lots/{id}/additions/{additionId}` | Remove |
| POST | `/wine-lots/{id}/analyses` | Add lab analysis |
| DELETE | `/wine-lots/{id}/analyses/{analysisId}` | Remove |
| POST | `/wine-lots/{id}/tasting-notes` | Add tasting note |
| DELETE | `/wine-lots/{id}/tasting-notes/{noteId}` | Remove |
| POST | `/cellar-transfers` | Rack/Blend/Split (`type,from_lot_id,to_lot_id,volume_liters,from_vessel_id?,to_vessel_id?`) |
| DELETE | `/cellar-transfers/{id}` | Reverse transfer |
| POST | `/wine-lots/{id}/bottlings` | Bottle → finished inventory + COGS (flow 06) |
| DELETE | `/bottlings/{id}` | Reverse bottling |

---

## Suppliers  (`/suppliers`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/suppliers?search=` | — | List (+cost count, price items) |
| GET | `/suppliers/{id}` | — | Detail (price list + recent 10 costs) |
| POST | `/suppliers` | ADMIN | Create (`company_name,tax_id(OIB),…`) |
| PATCH | `/suppliers/{id}` | ADMIN | Update |
| DELETE | `/suppliers/{id}` | ADMIN | Deactivate if it has costs, else delete |
| POST | `/suppliers/{id}/price-items` | ADMIN | Add price item (`description,unit_price,unit,inventory_item_id?`) |
| PATCH | `/supplier-price-items/{id}` | ADMIN | Update |
| DELETE | `/supplier-price-items/{id}` | ADMIN | Remove |

---

## Costs & Banking  (`/costs`, `/bank-transactions`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/costs?search=&category=&status=&supplier_id=` | — | List (diacritics-aware) |
| GET | `/costs/{id}` | — | Detail (items, attachments, supplier, bank txn, e-invoice) |
| GET | `/costs/categories` | — | Distinct categories |
| POST | `/costs` | ADMIN | Create (+nested items). Default status `PENDING` |
| PATCH | `/costs/{id}` | ADMIN | Update. Category change propagates to all costs of same supplier |
| PATCH | `/costs/{id}/status` | ADMIN | `PENDING\|APPROVED\|PAID` |
| DELETE | `/costs/{id}` | ADMIN | Delete (unlinks e-invoice; cascades items/attachments) |
| POST | `/costs/{id}/attachments` | ADMIN | Add attachment (`url,filename,type`) |
| DELETE | `/cost-attachments/{id}` | ADMIN | Remove |
| GET | `/costs/analytics/summary` | — | totals, counts by status |
| GET | `/costs/analytics/over-time` | — | Monthly totals |
| GET | `/costs/analytics/by-category` | — | |
| GET | `/costs/analytics/by-supplier` | — | |
| GET | `/costs/analytics/profit-loss` | — | Revenue vs costs by month |
| GET | `/bank-transactions?is_matched=&import_batch_id=` | — | List |
| POST | `/bank-transactions/import` | ADMIN | Bulk import raw rows → returns `import_batch_id` |
| POST | `/bank-transactions/check-duplicates` | ADMIN | Annotate rows: dup flags, supplier match, suggested category (3-level dedup) |
| POST | `/bank-transactions/import-as-costs` | ADMIN | Import rows → costs (+invoice match, attachments, price learning) |
| POST | `/bank-transactions/{id}/create-cost` | ADMIN | Convert one txn → cost (`category,description?,supplier_id?`) |
| POST | `/bank-transactions/{id}/match` | ADMIN | Link txn ↔ cost (`cost_id`) |
| DELETE | `/bank-transactions/{id}/match` | ADMIN | Unmatch |
| POST | `/costs/import-csv` | ADMIN | CSV import with supplier name matching |

---

## E-Invoice (Moj-eRačun)  (`/e-invoices`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/e-invoices?search=&status=&direction=` | — | List (with supplier + linked cost) |
| GET | `/e-invoices/{id}` | — | Detail (+linked cost & items) |
| POST | `/e-invoices/sync` | ADMIN | Manual inbox sync (`from?,to?`) → create/update + auto-cost |
| POST | `/e-invoices/test-connection` | ADMIN | Ping Moj-eRačun |
| POST | `/e-invoices/{id}/mark-paid` | ADMIN | Push paid status (process status `2`) |

> A scheduled job runs the equivalent of `autoSyncInbox()` per tenant (replaces
> the current fire-and-forget-on-page-load behavior). See `07-integrations.md`.

---

## AI & Document Parsing  (`/ai`, `/uploads`)

All require auth. Multipart upload. See `07-integrations.md` for models & schemas.

| Method | Path | Purpose |
|---|---|---|
| POST | `/ai/parse-bank-statement` | Image/PDF → `{transactions:[…]}` |
| POST | `/ai/parse-order-screenshot` | Image → `{customer, items[], notes}` with fuzzy product/customer matches |
| POST | `/ai/parse-receipt` | Image/PDF → `{vendor, vendor_oib, total, items[], category}` |
| POST | `/ai/suggest-category` | `{description, existing_categories?}` → `{category}` |
| POST | `/uploads/invoice` | Store invoice + parse (returns blob URL + parsed) |
| POST | `/uploads/image` | Compress (Sharp/Intervention → WebP ≤1200px) + store; returns `{url}` |

---

## Localization  (`/translations`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/translations?locale=hr` | public | Override map for locale (used by frontend) |
| PUT | `/translations` | ADMIN | Upsert `(locale,key,value)`; resets cache |
| DELETE | `/translations` | ADMIN | Delete `(locale,key)` |

---

## Tenancy / Platform *(new)*  (`/tenant`, platform `/admin`)

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/signup` | public | Create tenant + first ADMIN user (flow 09) |
| GET | `/tenant` | ADMIN | Current tenant + settings |
| PATCH | `/tenant/settings` | ADMIN | Update non-secret settings (currency, locale, company OIB) |
| PUT | `/tenant/secrets/eracun` | ADMIN | Set Moj-eRačun credentials (write-only, encrypted) |
| GET | `/tenant/subscription` | ADMIN | Plan & status |