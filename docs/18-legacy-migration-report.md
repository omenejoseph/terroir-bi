# 18 — Legacy → terroir-bi migration report

**Source:** the proof-of-concept's production database (single-tenant Prisma/Neon Postgres, dump of 2026-10-03, 130 tables, 750k lines).
**Target:** tenant **Bibic Winery** (`bibic-winery`), EUR, Croatian default locale, in the rebuilt multi-tenant app.
**Method:** repeatable import (`legacy:import`), independent check (`legacy:reconcile`), file copy (`legacy:copy-media`). Runbook: [16-legacy-migration.md](16-legacy-migration.md).

## 1. Verdict

The commercial and operational data came across completely and reconciles to the cent: **16,007 of 16,048 rows imported, 41 deliberately skipped** (each listed in §4), and **76 of 76 comparison checks pass with 0 failures**. The modules the rebuild has not built yet (POS sync, hospitality, HR, CRM, wine club, kitchen, e-invoices, bank transactions) were **not** migrated (§5). Role permissions now match the legacy app (§7), and the 105 inventory images have been copied to the bucket and verified.

## 2. What was migrated

| Area | Table | Legacy rows | Imported | Skipped |
|---|---|---:|---:|---:|
| Users & access | users | 11 | 9 | 2 |
| Pricing | pricing tiers | 1 | 1 | – |
|  | customer prices | 19 | 19 | – |
|  | customer product overrides | 28 | 28 | – |
| Inventory | inventory | 132 | 131 | 1 |
|  | inventory images | 105 | 105 | – |
|  | recipes | 183 | 183 | – |
| Customers | customers | 155 | 155 | – |
|  | customer categories | 7 | 7 | – |
| Suppliers | suppliers | 243 | 243 | – |
|  | supplier price items | 1,399 | 1,390 | 9 |
| Orders | orders | 353 | 353 | – |
|  | order items | 2,010 | 2,010 | – |
|  | order status histories | 1,124 | 1,124 | – |
|  | order notes | 289 | 289 | – |
|  | order note reactions | 1 | 1 | – |
|  | consignment reports | 13 | 13 | – |
|  | consignment report items | 50 | 50 | – |
| Stock | stock movements | 2,812 | 2,811 | 1 |
| Finance | costs | 1,011 | 1,011 | – |
|  | cost items | 4,047 | 4,047 | – |
|  | inflows | 612 | 584 | 28 |
| Cellar | enological products | 6 | 6 | – |
|  | fermentation templates | 3 | 3 | – |
|  | vessels | 218 | 218 | – |
|  | wine lots | 51 | 51 | – |
|  | wine lot grapes | 19 | 19 | – |
|  | vessel lots | 147 | 147 | – |
|  | cellar analyses | 497 | 497 | – |
|  | cellar additions | 283 | 283 | – |
|  | cellar processes | 2 | 2 | – |
|  | cellar transfers | 45 | 45 | – |
|  | tasting reports | 1 | 1 | – |
|  | cellar tasting notes | 8 | 8 | – |
| Production | work order boards | 2 | 2 | – |
|  | work orders | 91 | 91 | – |
|  | production plans | 3 | 3 | – |
|  | production plan rows | 2 | 2 | – |
| Vineyards | vineyard parcels | 20 | 20 | – |
|  | phenology logs | 4 | 4 | – |
|  | crop estimates | 6 | 6 | – |
|  | vineyard applications | 1 | 1 | – |
|  | grape contracts | 33 | 33 | – |
|  | intake bookings | 1 | 1 | – |
| **Total** | | **16,048** | **16,007** | **41** |

## 3. How it was verified

`legacy:reconcile` derives the expected figure from the legacy data using the same documented exclusions as the import, then compares it with what is in the new database. All **76** comparisons pass. Headline totals (legacy = new):

| Check | Value |
|---|---:|
| Orders: sum of order totals | €819960.02 (353 orders, 2,010 lines, lines sum to the same) |
| Inflows: sum of VAT | €125619.10 |
| Costs: sum of totals / VAT | €600373.78 / €70546.28 |
| Inflows: sum of amounts | €782139.25 |
| Open receivables (pending, not credit notes) | €612673.28 |
| Inventory: total current stock | 120558.93 units |
| Cellar: vessel capacity / volume | 231320 L / 108778 L |
| Vineyards: parcel area | 12.94 ha |

Re-running the import changes nothing (idempotent), and a dry run rolls back completely. The import writes straight to the tables, so legacy order numbers, stock levels, totals and timestamps are preserved and no stock deduction, notification or push fired.

## 4. Decisions, exclusions and transformations

| Area | What happened |
|---|---|
| Users | 9 of 11 migrated with their **bcrypt hashes** (staff keep their passwords). `admin@example.com` and the developer account were test accounts and were skipped. A suspended **Legacy Import** user owns the 11 rows whose creator had no equivalent (phenology logs, crop estimates, treatments). |
| Inventory | Seed item "Premium Red Blend" (`FP-REDWINE-001`, no orders) skipped. Blank/`units` sales unit became *bottles*. Prices stay per bottle, as in legacy. |
| Customers | 155 imported; 123 had no type and were classified by the same rules as the earlier backfill (84 are agencies, as flagged in legacy). 32 customers carry one of the old app's 7 categories (Restaurant, Hotel, …), now a real feature of the new app (see §8). 1 customer had no email and got a placeholder. Customer **portal links** (order tokens) are preserved. |
| Suppliers | 9 price items were whitespace-duplicates of newer ones and were collapsed. A supplier portal token is kept only if the portal was enabled. |
| Orders | Legacy numbers like `266104` kept verbatim; new orders start at `ORD-00001`. 147 lines of unit type *units* became *bottles*. 316 orders keep stock-deduction behaviour because the legacy ledger recorded a deduction for them. |
| Stock | 181 stock-deduct movements reference orders that no longer exist; kept as unlinked history. 20 movements lost precision (3-decimal column). Legacy stock is authoritative. |
| Finance | **28 inflow rows skipped:** 14 cancelled invoices and their 14 reversing credit notes (net zero; the rebuild has no *cancelled* state and would otherwise count them as receivables). Inflow VAT (€125,619.10 on 480 entries) is carried across: the new app gained a VAT field on inflows (§8). The 7 cost lines longer than 255 characters are kept whole: the description columns were widened (§8). |
| Cellar / vineyards | Volumes kept as legacy figures: 2 vessels are over capacity in the legacy data, 1 vessel and 9 lots disagree with their contents. 6 crop estimates only had a yield, so their sampling inputs are stored as 0. |
| Work orders | All 91 imported. The one *cancelled* work order is kept as such: the new app gained a Cancelled status (§8). |

Full per-row warnings are in the import report JSON (`storage/app/private/legacy-import/`).

## 5. Not migrated

57 legacy tables with data (124,573 rows) have no destination in the rebuild yet:

| Area | Rows | Tables |
|---|---:|---|
| POS sync (Remaris) | 114,786 | RemarisArticle (774), RemarisInvoice (19,448), RemarisInvoiceItem (72,462), RemarisPayment (19,445), RemarisSyncLog (2,657) |
| hospitality reservations | 2,106 | Reservation (769), ReservationStatusHistory (1,337) |
| in-app notifications | 2,064 | Notification (2,064) |
| HR | 1,819 | Employee (22), EmployeeDailyLog (1,661), EmployeeSchedule (136) |
| e-invoice XML | 1,265 | EInvoice (1,265) |
| inflow line items | 1,181 | InflowItem (1,181) |
| bank transactions | 545 | BankTransaction (545) |
| hospitality | 372 | HospitalityAgencyPrice (362), HospitalityProgram (10) |
| hospitality / villas | 98 | Villa (2), VillaBooking (16), VillaMenuItem (45), VillaRate (10), VillaSection (25) |
| kitchen | 94 | KitchenCheck (2), KitchenCheckItem (30), KitchenItem (33), KitchenMovement (29) |
| harvest planning inputs | 48 | HarvestPlanningInput (46), HarvestVarietalPrice (2) |
| CRM | 42 | Activity (2), Contact (3), Deal (3), DealNote (5), DealStageHistory (8), LeadSource (3), PipelineStage (18) |
| inventory group ordering | 34 | InventoryGroupOrder (34) |
| surveys | 21 | SatisfactionResponse (3), SatisfactionSurvey (1), SurveyQuestion (17) |
| cash-flow planner | 20 | CashContractor (1), CashScenario (1), CashWorker (18) |
| wine club | 18 | ClubMembership (3), ClubMembershipEvent (6), ClubShipment (3), ClubShippingZone (1), ClubTier (1), ClubTierItem (4) |
| translation overrides are platform-wide in the rebuild (Filament /admin) | 16 | TranslationOverride (16) |
| planned cellar activities | 14 | CellarActivity (11), CellarActivityRound (3) |
| push endpoints are bound to the old origin | 10 | PushSubscription (10) |
| final production plan | 7 | ProductionFinalPlan (1), ProductionFinalPlanItem (6) |
| board lists | 6 | BoardList (6) |
| CRM / sales projects | 4 | SalesPipeline (3), SalesProject (1) |
| sales projects | 3 | ProjectExpense (3) |

The big ones (POS sync, hospitality, HR) are deferred modules, not data loss: the dump remains the source if they are built. **Bank transactions and e-invoice XML** (545 + 1,265 rows) matter for finance: costs and inflows came across, but their links to the bank statement and the e-invoice are gone. **Inventory group ordering (34), board lists (6) and planned cellar activities (14)** may deserve a home in the new app. Customer categories no longer belong on this list: they were added to the new app (§8).

## 6. Open items

1. **Images:** done. All 105 inventory images were copied to the R2 bucket (~1.9 MB); a re-run of `legacy:copy-media` found 105 already present, 0 failed.
2. **Data to fix by hand:** the placeholder email on one customer; 8 items whose stock their movements don't explain (opening balances); the over-capacity vessels.
3. **Smoke test in the browser:** the API responds on every endpoint tried (46 of 47 parameterless GETs return 200, the 47th needs a query parameter; no 5xx), but the screens have not been walked through with the imported data.
4. **Postgres:** the rebuild had never run on Postgres. One MySQL-only date expression broke the dashboard and was fixed (`SqlDate`); production per CI is MySQL, so re-check on whichever database is deployed.
5. **Filip's saved sidebar order** and the push-notification subscriptions were not migrated (users re-subscribe).

## 7. Permissions: now matched to the legacy app

Role **names** map one-to-one (same 11 roles) and each person's roles and the order-edit flags came across correctly. The first import left the rebuild's own capability map in place, which was more permissive than the old app (finance edit for TEAM/ORDERS/MANAGER, supplier and cellar access for MANAGER, work orders for every member, …). It has since been rebuilt from the old app's real checks (every `requireRole`/`hasAnyRole` in its pages and server actions, plus its sidebar) and is pinned by `tests/Feature/Members/RoleParityTest`.

| Role | Capabilities (ADMIN holds everything) |
|---|---|
| TEAM | create customers, orders, inventory view/edit/stock, price lists (view), production plans, purchase orders (view), work orders, figures |
| ORDERS | orders, create customers, price lists (view), work orders, figures |
| CELLAR | cellar, vineyards, work orders |
| INVENTORY | inventory view, stock movements, price lists (view) |
| MANAGER, SALES | figures only (their real work, people management and the pipeline, is not built here yet) |
| HOSPITALITY, KITCHEN, EMPLOYEE, WINE_CLUB | none (modules not built here yet) |

Admin-only, as in the old app: costs, money received, cash flow, cellar costs, suppliers, purchase-order changes, price editing, customer list/detail/editing/deleting/links, bulk inventory operations and analytics, order deletion, settings, team, logs.

The dashboard follows the same rules block by block (`DashboardSummary`): revenue figures need `financials.view`; key ratios, cash flow, runway and receivables need `finance.view`; order counts `orders.view`; low stock `inventory.view`; the reorder pipeline `customers.create`; tasks `work_orders.use`. A restricted block is neither computed nor sent (null), and `visible` lists the blocks a member holds. Pinned by `DashboardRoleVisibilityTest`.

Two multi-role rules are enforced: roles add up, and **anyone holding MANAGER never gets work orders** even alongside another role that would grant them (the old sidebar's `excludeRoles`), unless they are also ADMIN.

New capabilities introduced to express the old app's finer distinctions: `customers.create`, `inventory.stock`, `inventory.bulk`, `inventory.analytics`, `supplier_orders.view`, `work_orders.use`; routes were regrouped under them (static-segment routes kept ahead of their `{id}` wildcards).

Judgement calls where the old app was inconsistent: customer list/detail follow the menu and page guards (admin) rather than its looser read actions; Sales quick-create was not carried; price-list reading is kept to TEAM/ORDERS/INVENTORY; the new AI data entry is admin-only because it creates costs, money received and suppliers; cellar costs are admin-only.

Effect on the nine migrated people: Dragan, Ramon, Slavica and Bojan no longer edit costs/money received; Vesna and Bojan no longer reach suppliers, cellar, vineyards or production; Bojan and Vesna (MANAGER) have no work orders; Dragan and Ramon lose inventory edit; everyone still sees the figures they saw before.

## 8. Changes made to the new app because of the migration

The first import exposed gaps in the new app. Four were closed rather than worked around:

| Change | Why | Effect |
|---|---|---|
| **Customer categories** (a list each organisation manages, assigned per customer, filterable) | The old app had descriptive labels (Restaurant, Hotel, …) that the fixed sales-channel type cannot express; the first import discarded them | 7 categories and 32 customer labels migrated, a Categories tab under Customers, a Category column and filter, a Category field on the customer form |
| **Cancelled work-order status** | The old app had cancelled tasks | The board gains a Cancelled column; cancelled tasks are never overdue and not "open work" on the dashboard |
| **VAT on money-received entries** | Old entries carried VAT, the new ones had no field | New field (optional, "unknown" is distinct from 0), carried in the data interface; €125,619.10 on 480 entries imported |
| **Invoice marker on costs and money received** (`is_invoice`) | The old app's invoice, VAT and spend summaries counted entries linked to an e-invoice (costs) or of type invoice (money in). The rebuild's analytics keyed off a reserved category "Invoice" that no imported entry carries, so those cards would have read zero | Set from the old e-invoice links: 685 costs (VAT €70,528.74) and 521 money-received entries, both matching the old database. Analytics, the Invoices tab and the average/days-to-pay figures honour the flag as well as the category |
| **Net amount and VAT total** | The old app showed "incl. PDV" / "PDV · Net" on every entry and a VAT total for money received | Costs and money received now return `net_amount` (total minus VAT, once VAT is known); the money-received analytics gain a `vat` total that skips credit notes |
| **Longer cost descriptions** | Real courier and notary lines run to ~400 characters, and the form already allowed more than the column held | Cost and cost-line descriptions are unlimited text (a 2,000-character ceiling on lines); the 7 truncated lines were restored |

Also fixed: the dashboard crashed on PostgreSQL (a MySQL-only date expression).

**VAT semantics, verified against the old app:** the total is gross (VAT included) and net is total minus VAT; the imported implied rates are identical (25% on 379 costs and 488 money-received entries, 13%, 5%, …); cost lines are net (489 of 520 costs with VAT have lines summing to the net), which is why 523 line sums differ from the gross header. Not carried: the old app auto-filled VAT from e-invoice XML and by AI-scanning inflow invoices (the rebuild has neither feed, and its AI invoice reader does not extract VAT), and its cost summaries also skipped credit-card aggregates and internal suppliers (nothing in your data is affected).

**Not yet a screen:** Costs and Inflow are still placeholders in the new app's menu. Both data sets are stored and served by the data interface, but nobody can browse or enter costs or money-received entries from a page yet.

## 9. Reproduce

```bash
php artisan legacy:import --dry-run     # everything runs, then rolls back
php artisan legacy:import               # idempotent
php artisan legacy:reconcile            # exit 1 on any mismatch
php artisan legacy:copy-media --dry-run # then without --dry-run (already done for this tenant)
```
