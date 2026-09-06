# Inventory: closing the remaining detail-screen `@todo`s

[`17-frontend-screen-plan.md`](17-frontend-screen-plan.md) counts Inventory's
8 screens as done — and they are, as screens. What's left is inside two of
them: six stub tabs on Product Detail, and five stub affordances on the
Item — View drawer, plus four smaller gaps on Analytics/Spend/Check. This
tracks that remaining work.

## Two things this plan corrects vs. the `@todo` comments in code

1. **The audit trail already has data.** `InventoryItem` now `use`s
   `App\Services\Audit\Auditable` (added after the Inventory screens shipped),
   so every create/update on an item is already written to `audit_logs`
   (`inventory_item.created` / `inventory_item.updated`, with changed fields)
   — see `App\Services\Audit\Auditable` and `App\Http\Controllers\Web\LogController`
   (the tenant's own `/logs` page, same query shape). **Audit trail** and the
   "who last edited it" half of **Provenance line** are now read-side only: no
   new logging to build, just a filtered query + a small UI.
2. **Recipe, Produce, Images and Analysis are API-only today.** Their actions
   (`SetRecipeAction`, `ProduceItemAction`) and controllers
   (`Api\InventoryMediaController`, `Api\BottleAnalysisController`) are wired
   only into `routes/api.php` (built for the old Next.js SPA), with **no**
   `Web` counterpart. Following this app's own convention (every domain has a
   thin `Web` controller mirroring the `Api` one, same Actions —
   see `OrderController`/`InventoryController` already doing this), each of
   these four tabs needs a small Web controller method + route added, not
   just a Vue component. **Pricing** is the exception: `CustomerPriceController`
   is already under `Web/`, so that tab is UI-only.

## Tier A — Product Detail tabs (`resources/js/pages/Inventory/Show.vue`)

| Tab | Backend today | What's needed |
|---|---|---|
| **Details** | ✅ **Done** — the drawer's "Item details" fields were lifted into `ItemDetailFields.vue` and are now shared by both the drawer and this tab; editing opens the same `ItemFormPanel.vue` the Inventory list uses (which gained a `reloadOnly` prop so its post-edit partial reload can target this page's own `item` prop instead of the list's) |
| **Recipe** | ✅ **Done** — `Web\InventoryController::updateRecipe` (same `SetRecipeAction`/`SetRecipeRequest` as the API), plus a new `App\Services\Inventory\RecipeInputOptions` (any active item, not just sellable ones) for the "add ingredient" picker; `RecipeEditor.vue` |
| **Produce** | ✅ **Done** — `Web\InventoryController::produce` (same `ProduceItemAction`/`ProduceItemRequest` as the API); `ProduceForm.vue`, which reads the recipe from the tab above and declines to offer a run when there isn't one |
| **Images** | ✅ **Done** — new `Web\UploadController::presign()` (mirrors `Api\UploadController`) + `Web\InventoryMediaController` (mirrors `Api\InventoryMediaController`, both now sharing a new `InventoryMediaPresenter`); `ImagesGallery.vue` + a new generic `UploadDropzone.vue` (no `Dropzone.vue` existed in `resources/js` — that name only existed in the retired `frontend/` SPA, a plan inaccuracy) |
| **Docs** | ✅ **Done** — one tab covering both tech sheets and documents (the design's own framing), through the same `Web\InventoryMediaController`; `DocsPanel.vue` |
| **Pricing** | ✅ **Done** — reading rows needed a new `App\Queries\ItemPricingQuery` (`Api\PriceController` now delegates to it too, so the two transports can't disagree); writing reused `Web\CustomerPriceController` for customer overrides and a new small `Web\TierPriceController` (mirroring it) for tier prices |
| **Analysis** | ✅ **Done** — new `Web\BottleAnalysisController` (same `StoreBottleAnalysisRequest`/`BottleAnalysisData` as the API); `AnalysisPanel.vue` (an entry form plus a full-history table) |

## Tier B — Item — View drawer (`resources/js/components/inventory/ItemViewPanel.vue`)

| Item | What's needed |
|---|---|
| **Audit trail** | ✅ **Done** — `App\Queries\ItemAuditTrailQuery` (mirrors `LogController`'s query, filtered to one subject), folded into `InventoryController::index()`'s existing `Inertia::optional` props (same `?item=` key `itemMovements` already uses) and rendered as the drawer's Timeline section |
| **Provenance line — "last edited by"** | ✅ **Done** — same query's newest entry, rendered above the Stock section |
| **Provenance line — ranking** ("Lowest stock of the six wines") | ✅ **Done** — `App\Queries\ItemStockRankingQuery`, ranking within the item's own `group`; the drawer only names the two extremes ("Lowest"/"Highest") the design shows, and states a plain rank in between rather than inventing ordinal grammar |
| **Customer attribution** | ✅ **Done** — `App\Queries\ItemCustomerAttributionQuery`, the inverse of `CustomerProductsQuery`: order lines for this item rolled up by customer, with share of volume and last order date |
| **Inline pricing/details edit** | Cosmetic only — replace the "opens the shared form" button with real inline fields. Low value, do last |
| **"Open in Orders"** | ✅ **Done** — a dedicated `item_id` filter on `ListOrdersQuery`/`OrderFilters` (exact match against order lines, not folded into `search`), a dismissible chip on the Orders list naming the item (`itemFilterName` prop), and a real link from the drawer to `/orders?item_id={item.id}` |

## Tier C — Inventory Analytics (`resources/js/pages/Inventory/Analytics.vue`)

| Item | What's needed |
|---|---|
| **"Add costs" deep-link** | ✅ **Done** — `?missing_cost=1` filter added to `InventoryItemFilters`/`ListInventoryItemsQuery` (active + no `cost_per_unit`, same criteria `InventoryAnalyticsQuery` counts), a dismissible chip on the list, preserved across the list's own search/pagination reloads |
| **Range picker** | ✅ **Done** — `movements12m()` takes a `$months` argument (3/6/12, an unrecognised value falls back to 12); a `Tabs` selector on the "In and out" card, matching the period-selector pattern already used on Inventory Spend/Show |
| **Unit switch** (bottles/cases/value) | ✅ **Done** — `stockLevels()` rows now carry `bottles_per_case` and `value` (at list price); a 3-way `Tabs` toggle re-scales the "Stock against movement" bars, falling back to "no case size"/"not priced" for a row that can't be expressed in the selected unit |
| **Bulk Import** | ✅ **Done** — see the shared note below |

## Tier D — Inventory Spend (`resources/js/pages/Inventory/Spend.vue`)

| Item | What's needed |
|---|---|
| **Bulk Import** | ✅ **Done** — same feature as Analytics's, one shared build (see below); also fixed a pre-existing gap on this page where the header's "Bulk Import"/"New Item" buttons had no `can('inventory.manage')` gate at all |
| **"Check order → stock link"** | ✅ **Done** — see the design note below. New `App\Queries\OrderStockReconciliationQuery`; `OrderStockReconciliationPanel.vue` opens over the page's own window |

## Tier E — Inventory Check (`resources/js/pages/Inventory/Check.vue`)

| Item | What's needed |
|---|---|
| **History panel** | ✅ **Done** — `CheckHistoryPanel.vue`, a side panel over the existing `history` prop (no backend change needed) |

## Bulk Import (shared by Analytics + Spend) — ✅ Done

**Scope decided:**
- **Format: CSV only** — no XLSX. The app has no spreadsheet library anywhere
  (`CsvExporter` covers every existing export with plain `fputcsv`), and
  adding one just for this would be a new dependency for a format users can
  already produce from any spreadsheet tool via "Save as CSV."
- **Columns:** `name, sku, category, group, unit, current_stock, min_stock,
  bottles_per_case, cost_per_unit, default_price` — identity, classification
  and the financial/stock fields, not just name/SKU/category. Costs and
  stock levels are exactly what a "bulk import" is for in practice (a
  supplier price refresh, a stocktake reconciliation).
- **Create vs. update: both, matched by SKU.** A row whose SKU already exists
  updates that item — only the columns the row actually fills in, leaving
  the rest untouched — matching how a real re-import (refresh prices, top up
  stock) is expected to behave; any other SKU creates a new item.
- A bad row (missing SKU, missing name on a new item, an unrecognised
  category) is skipped with a reason, not fatal to the rest of the file — the
  redirect's flash message reports created/updated counts plus up to 5 skip
  reasons.

**One thing this correction flags for later:** `AiImportType::InventoryList`
(`App\Actions\Ai\CommitAiImportLineAction::commitInventory()`) already does a
*different*, AI-vision-driven bulk import of inventory items from an
uploaded document — but it is API-only today (no Web UI in `resources/js`
at all) and, unlike this feature, only ever creates (never updates by SKU).
It was deliberately not touched or ported here: doing so would have been a
much larger, separate effort (upload → async extraction → line review/edit →
commit), not a fit for this plan's "Bulk Import" scope, which this plan
always framed as a deterministic CSV importer. Worth a future decision on
whether the two should ever merge.

**Built:** `App\Actions\Inventory\BulkImportInventoryItemsAction` (parsing +
per-row validation + the create/update decision, writing through the same
`CreateInventoryItemAction`/`UpdateInventoryItemAction` every other write
path uses) behind `Web\InventoryController::bulkImport()` +
`bulkImportTemplate()` (a "Download template" CSV); `BulkImportDialog.vue`,
mounted on all three pages that had a dead "Bulk Import" button (Inventory
list, Analytics, Spend).

## Suggested build order

Smallest/independent first, so each lands and verifies on its own rather than
one giant change:

1. **Check history panel** (Tier E) — pure UI, data already there.
2. **"Add costs" deep-link** (Tier C) — a filter param, no new query.
3. ✅ **"Open in Orders"** (Tier B) — a link, using what Orders already supports.
4. ✅ **Audit trail + "last edited by"** (Tier B) — one new read query, reused in two places.
5. ✅ **Details tab** (Tier A) — reuse existing markup, zero new backend.
6. ✅ **Pricing tab** (Tier A) — needed one new read query + one new small Web controller, beyond what the plan first assumed.
7. ✅ **Provenance ranking** + **Customer attribution** (Tier B) — two new, independent queries.
8. ✅ **Recipe** and **Produce** tabs (Tier A) — each needed one new Web action-wiring + one editor UI.
9. ✅ **Images** and **Docs** tabs (Tier A) — each needed Web upload/attach wiring + a gallery/list UI.
10. ✅ **Analysis** tab (Tier A) — Web wiring + results UI. **All of Tier A (Product Detail) is now done.**
11. ✅ **Range picker** + **unit switch** (Tier C) — query changes + chart controls.
12. ✅ **Bulk Import** (Tiers C/D) — CSV, matched by SKU (create or update), one shared build across all three "Bulk Import" buttons.
13. ✅ **"Check order → stock link"** (Tier D) — the biggest, most open-ended item; needed its own design pass (see below), done last as planned.

## "Check order → stock link" — the design pass this plan asked for

**What "mismatch" turned out to mean.** Stock is deducted at order/line
*write* time (`App\Services\Orders\OrderLineWriter`), never on a status
transition — so this plan's own "shipped order lines" framing doesn't match
how the system actually works, and the report is deliberately **not**
scoped to `OrderStatus::Shipped`; a still-`Received` order that already
deducted is just as relevant as a `Shipped` one, and status has no bearing
on whether a mismatch exists.

A mismatch is: an (order, item) pair where the *live* order state disagrees
with what `stock_movements` actually recorded, in either direction —

- **never deducted**: a line that should have moved stock
  (`orders.deduct_stock = true`) but has no matching total in the ledger —
  the exact failure the page's own "sitting untouched" callout already
  warned about;
- **orphaned**: a recorded deduct whose order/line was since edited down,
  removed, or the whole order deleted. `App\Queries\InventorySpendQuery::
  deductFactors()` already *silently* scales these out of the headline
  totals (self-healing, per its own docblock) — this report surfaces the
  same disagreement as a visible row instead of only correcting past it.

Backorders (`deduct_stock = false`) that legitimately never deducted are
excluded — that's working as intended, not a mismatch.

**Built:** new `App\Queries\OrderStockReconciliationQuery` (a fresh,
standalone read — deliberately not a refactor of `InventorySpendQuery`'s own
proven, bug-history-documented scaling logic, to keep this report from
risking a regression there); `OrderStockReconciliationPanel.vue`, opened
from Inventory Spend's existing "Check order → stock link" button, lazily
loaded over the page's own window. Web-only (no `routes/api.php` mirror) —
a pure UI convenience with no external API consumer, same as e.g.
`SearchController`/`NotificationController`.
