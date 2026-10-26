# 21 — Hospitality port plan (order-mgmt → terroir-bi)

Status: **research complete, no code written.** Source of truth is the prototype
(`/Users/omenejoseph/Sites/order-mgmt`); rules from [15-prototype-parity-audit](15-prototype-parity-audit.md)
apply: match **functionality and information**, not the UI. Phase 1 is a **like-for-like** port;
experience improvements are a later, separate task.

Everything below was read from the prototype source. Where a detail was *not* read, it is marked
**(unverified)**.

---

## 0. Scope: "hospitality" is four modules

| # | Prototype sidebar label | Route | What it is | Roles | Port phase |
|---|---|---|---|---|---|
| A | **Agencies** | `/dashboard/hospitality` | Programs, agency price book, reservations, analytics | ADMIN, HOSPITALITY | **1** |
| B | Kitchen | `/dashboard/kitchen` | Kitchen stock, stock-takes (Inventura), prep tickets | ADMIN, KITCHEN | 2 |
| C | Accommodation | `/dashboard/accommodation` + public `/villa/[token]` | Villas, rates, bookings, menu, guest portal, Stripe ordering | ADMIN, HOSPITALITY | 3a back office, 3b guest portal |
| D | **Hospitality** | `/dashboard/remaris` | Remaris POS sales analytics (restaurant + tasting room) | ADMIN (floor board: + TEAM) | 4 |

Naming trap: in the prototype "Agencies" = reservations (A) and "Hospitality" = the POS (D). terroir-bi's
nav currently has a *Sales → Agencies* item and a *Hospitality* category with Accommodation / Kitchen /
Hospitality. See open question Q2.

**Legacy data in the Bibich dump** (docs/18 §5): reservations 769, reservation status history 1,337,
programs 10, agency prices 362; villas 2, bookings 16, menu items 45, rates 10, sections 25. Kitchen and
Remaris row counts are in docs/18 §5 **(not re-read here)**.

**Explicitly out of scope** (agreed in research, confirm in Q7): the Remaris floor board / table
assignments / open-tables API (a self-declared spike; the bill-GUID match was proven not to work, only
a time-based match does), the reverted customer "Visits" tab, and ~9 unused Remaris chart components.

---

## 1. Foundations (do first, shared by every phase)

### 1.1 Module, plans, roles, capabilities, nav
| Change | File(s) | Notes |
|---|---|---|
| Add `Module::Hospitality` (+ `label()`); later split `Kitchen`, `Accommodation`, `Pos` if plans should sell them separately | `app/Enums/Module.php`, `database/seeders/PlanSeeder.php`, back-office plan editor | Today `navigation.ts` uses forward-looking keys (`hospitality`, `agencies`) with no enum case, so they stay hidden. Decide one module vs several (Q4). |
| Capabilities `hospitality.view` / `.manage` (+ `kitchen.*`, `accommodation.*`, `pos.view` later) | `app/Authorization/RoleCapabilities.php`, `routes/web.php` `can:` gates | `TenantRole::Hospitality` and `Kitchen` exist but grant `[]` today. Prototype rules: reservations/accommodation ADMIN+HOSPITALITY; kitchen ADMIN+KITCHEN; POS ADMIN only. |
| Update parity tests | `tests/Feature/Members/RoleParityTest.php`, `tests/Feature/Dashboard/DashboardRoleVisibilityTest.php` | The visibility test currently asserts "hospitality sees nothing" (lines ~99, 164, 227). It must be rewritten deliberately. |
| Nav hrefs + visit tracking | `resources/js/lib/navigation.ts` (lines ~115-123 and the Sales → Agencies item), `app/Support/NavCatalog.php` (`ROUTES`, `ALL_KEYS`) | Keep the two files in sync by hand (documented in NavCatalog). |
| Landing redirect by role | wherever post-login/dashboard routing lives **(unverified)** | Prototype sends HOSPITALITY to its module and KITCHEN to `/dashboard/kitchen`. |
| Sidebar customisation list | prototype `sidebar-settings.tsx` has per-route icons; check terroir-bi equivalent **(unverified)** | |

### 1.2 Conventions to follow (from memory + repo)
- ULID PKs (`HasUlids`), `BelongsToTenant`, `Auditable` where the model is user-edited, money as **bigint minor units**, snake_case API fields.
- Thin controllers → Actions / Services / Queries (transport-agnostic).
- Server-side search/filter/totals; persistent layout + `Link`; partial visits for period/tab switches (commit d452f7c pattern); `fetch` for background writes.
- No raw text (all strings through `t()`; add keys to `lang/en.json` **and** `hr.json`), no deprecated symbols, tests per screen, `npm run check` gate, mobile: no horizontal scroll, tables reflow to stacked cards under `sm`.
- Pickers inside expandable cards portal; pickers beside a mobile text input anchor `absolute` (see memory).

### 1.3 Legacy import (applies to phases 1-4)
Pattern: `app/Services/LegacyImport/Steps/*Step` extends `AbstractStep`, declares `name()` and `dependsOn()`,
reads legacy rows via `$this->rows($ctx, 'Table')`, writes via `$this->put(...)` which records the old→new id
in `IdMap`; `Reconciler` holds cent-level comparison checks; `Support/NotMigrated` lists tables with data that
are not imported (it currently lists `Villa`, `Reservation`, `Hospitality`, `Kitchen`, `Remaris` prefixes
as "deferred by design" — remove each prefix when its step lands).
- New steps: `HospitalityStep` (phase 1), `KitchenStep` (2), `AccommodationStep` (3), `RemarisStep` (4).
- Convert Prisma `Decimal` amounts to minor units; map `createdById` through the `User` id map; skip-and-report orphan rows like the existing steps do.
- Each step gets a test in `tests/Feature/LegacyImport/` and reconciler checks (row counts + sums to the cent).
- **Do not re-run the real Bibich import** blindly (memory: legacy-migration-bibich). Add the new steps as additive/idempotent runs and rehearse against a copy first.

---

## 2. Phase 1 — Agencies / Reservations (module A)

### 2.1 Tables (terroir-bi shape)
| Table | Columns | Notes |
|---|---|---|
| `hospitality_programs` | id, tenant_id, name, description?, duration_mins?, base_price (bigint), currency (default tenant), max_pax?, is_active, sort_order, timestamps | Soft delete = `is_active=false`. |
| `hospitality_agency_prices` | id, tenant_id, program_id, customer_id, price_per_pax (bigint), rebate_percent decimal(5,2)?, unique(program_id, customer_id) | Cascade on program/customer delete. |
| `reservations` | id, tenant_id, `date` datetime, pax, status (enum), price_per_pax (bigint), total_amount (bigint), guest_name?, guest_email?, guest_phone?, allergies?, notes?, cancel_reason?, program_id, customer_id? (null = walk-in), created_by_id, timestamps | Prototype also has `eInvoiceId`; terroir-bi has no e-invoice table. Recommend **omit** (Q6). Price and total are **snapshots**. |
| `reservation_status_histories` | id, reservation_id (cascade), status, note?, created_by_id, created_at | Mirrors `order_status_histories`. |

Enum `ReservationStatus`: `PENDING, CONFIRMED, INVOICE_SENT, PAID, COMPLETED, CANCELLED`.

### 2.2 Business rules (replicate exactly)
1. **Price resolution** on create (and on edit when program, customer or pax changed; *not* when only date/notes change):
   1. agency price for (program, customer) → use `price_per_pax`;
   2. else if customer exists, **is not** an agency and `rebate_percent > 0` → `base × (1 − r/100)`;
   3. else `base_price`.
   `total = price_per_pax × pax`. Agencies deliberately never get the customer-level rebate (the prototype shipped a bug the other way and repaired it with `scripts/fix-agency-reservation-prices.ts`).
   - Integer cents: the rebate path can yield fractional cents → pick one rounding rule (round half-up per pax, then ×pax) and unit-test it. Prototype used floats; legacy totals are the reference (see import tolerance below).
2. **Program base-price change** recomputes every agency price that carries a `rebate_percent > 0`: `round(base × (1 − r/100), 2)`.
3. **Status**: forward flow PENDING → CONFIRMED → INVOICE_SENT → PAID → COMPLETED, CANCELLED reachable from any non-terminal state. The prototype has **no transition validation** in the backend (any status accepted); the UI offers "next" and "cancel". Like-for-like = same, but enforce the UI's set server-side only if we decide to (Q8). Each change writes a history row; `cancel_reason` is stored only when cancelling **with** a note.
4. **Add note** = history row carrying the current status.
5. **Delete** only PENDING or CANCELLED; history cascades.
6. **Max pax** is displayed in the form, **not enforced**.
7. Create requires program + date + pax; guest name is required in the UI form only.
8. Agency pickers list `is_agency` customers (create form also filters `is_active`). Backend accepts any customer, but the UI never offers it.

### 2.3 Screens
| Screen | Content | Prototype source |
|---|---|---|
| Hospitality index, **Reservations tab** | Quick-stats tiles (Today / MTD / YTD each vs prior window, Avg pax, Confirmed/total); filters: search (guest name only), status, program, agency, date range; period selector defaulting to MTD; list sorted by date asc with program, guest/agency, pax, total, status badge, created-by | `app/dashboard/hospitality/page.tsx`, `reservation-list.tsx`, `reservation-filters.tsx`, `hospitality-quick-stats.tsx` |
| **Programs tab** | Add/edit program (name, base price, duration, max pax, description), activate/deactivate, agency-price count | `program-manager.tsx` |
| **Analytics tab** | Year selector (first season → next year); KPIs (reservations, revenue, guests, avg revenue/guest, upcoming count, pipeline value, conversion rate); monthly revenue vs previous year; by-program chart + performance table; top agencies; **agency performance** with custom date range, "compare to previous period", sortable columns, discount given / %. | `hospitality-analytics.tsx` (1,064 lines; chart/table internals **unverified**), `getHospitalityAnalytics`, `getAgencyPerformance` |
| **New reservation** | Program, agency (optional → walk-in), date, pax, live price/rebate/total preview, guest name/email/phone, notes | `reservation-form.tsx` |
| **Reservation detail** | Header (guest · pax); four stat cards (Pax, Price/person, Total, Agency); editable details (date, pax, program, agency, guest, allergies, notes); profitability card (base × pax, agency rebate %, revenue, revenue/person); status timeline with Add note; change-status card; invoice link (omit); delete | `reservation-detail-client.tsx` |
| **Customer detail** | Pricing tab: agency price table (add / override / remove per program); new Reservations tab (only if any exist) with rebate-% badge derived from base vs price | `customers/[id]/page.tsx`, `agency-pricing.tsx` |

Metric definitions to port as named query objects (and **decide** the inconsistencies, Q8):
- Analytics: exclude `CANCELLED` and customers with `exclude_from_stats`.
- Quick-stats tiles: prototype counts **all** statuses and ignores `exclude_from_stats` (inconsistent). Recommend aligning with analytics and noting it in the parity audit.
- Conversion = (CONFIRMED + INVOICE_SENT + PAID + COMPLETED) / all (incl. cancelled) in the year.
- Pipeline = sum of future reservations in PENDING/CONFIRMED/INVOICE_SENT/PAID.
- Discount given = Σ(program base × pax) − Σ(total) per agency.

### 2.4 Backend structure
```
app/Models/{HospitalityProgram,HospitalityAgencyPrice,Reservation,ReservationStatusHistory}.php
app/Enums/ReservationStatus.php
app/Services/Hospitality/ReservationPricing.php       # the one pricing rule (§2.2.1, .2)
app/Actions/Hospitality/{Create,Update}Reservation, ChangeReservationStatus, AddReservationNote, DeleteReservation,
                        {Create,Update,Deactivate}Program, SetAgencyPrice, RemoveAgencyPrice
app/Queries/{ReservationListQuery, HospitalityQuickStatsQuery, HospitalityAnalyticsQuery, AgencyPerformanceQuery}.php
app/Http/Controllers/Web/Hospitality/*Controller.php  # thin; Inertia pages
app/Http/Requests/Hospitality/*
```
Plus: `CustomerMergeService` must re-point reservations and merge agency prices (prototype counts both and
drops conflicting `(program, customer)` rows, `customers.actions.ts:1014-1170`); `CustomerPresenter` /
Show props gain agency prices + reservations; Global search entry for reservations (optional).

### 2.5 Dashboard / finance integration (**design risk**)
- Prototype "agencies" channel = reservations in `PAID`/`COMPLETED` whose customer `is_agency` and not excluded, by reservation date; it deliberately does **not** add "agency payment" inflows (double count).
- terroir-bi "agency" channel = **orders** whose customer type is `AGENCY` (`DashboardSummary.php:272`), subject to the new `revenue_basis` setting (`RevenueBasis`). `CustomerType` doc says hospitality "will arrive with their own module".
- If both feed the channel, an agency with both orders and reservations is counted twice. Options: (a) separate `hospitality` channel keyed on reservations; (b) reservations feed `agency`; (c) per-tenant switch. Recommended: **(a)**, add `hospitality` to the channel set, reuse `--ch-hospitality` colours, define DTC accordingly. Needs Q3.
- Also prototype "total realized revenue" (`dashboard-queries.ts:925-960`) counts direct (non-agency / walk-in) reservations; port with the same definition once Q3 is decided.
- Add to `RevenueBasisTest`-style coverage.

### 2.6 Import (`HospitalityStep`)
`dependsOn: ['customers', 'users']`. Order: programs → agency prices → reservations → status history.
Tables: `HospitalityProgram`, `HospitalityAgencyPrice`, `Reservation`, `ReservationStatusHistory`.
Reconcile: counts 10 / 362 / 769 / 1,337 and Σ `totalAmount` to the cent. Orphans (deleted customer/user) reported, not silently dropped. Keep legacy `pricePerPax`/`totalAmount` verbatim — do **not** re-price on import.

### 2.7 Phase-1 acceptance
Price rule table-tested (agency price, agency without price, direct rebate, walk-in); status/history; delete guard;
tenant isolation; role gates (HOSPITALITY can, others cannot); import + reconcile green; analytics numbers reproduce
prototype figures on the Bibich data for one chosen year; en + hr strings; `npm run check` green; mobile reflow.

---

## 3. Phase 2 — Kitchen (module B)

**Tables** (keep separate `kitchen_*`, do not merge into wine inventory — different items, units, locations;
the prototype has no link to inventory or reservations):
`kitchen_items` (name, category, group?, unit, current_stock decimal(12,3), min_stock?, cost_per_unit?, supplier text?, location, is_active, sort_order),
`kitchen_movements` (item, type IN/OUT/ADJUSTMENT, quantity signed, note, user),
`kitchen_checks` (date, status IN_PROGRESS/COMPLETED, note, completed_at, user),
`kitchen_check_items` (check, item, system_stock snapshot, physical_count?, difference?),
`kitchen_work_orders` (title, date, status PLANNED/IN_PROGRESS/COMPLETED/CANCELLED, assigned_to text?, notes, completed_at, user),
`kitchen_work_order_items` (work order cascade, item? nullable, custom_name?, quantity, unit?, notes).

**Rules**: `adjust(IN)` adds, `OUT` subtracts with **no negative guard** (consistent with the enological-stock rule),
`ADJUSTMENT` takes the new absolute value and stores the delta; opening stock writes no movement (consider writing one — Q8-style small decision).
Stock-take: start snapshots all active items (optionally per location); count updates `difference`; **complete** writes an
ADJUSTMENT movement per non-zero difference and sets stock to the counted value (loses any movement between snapshot and
completion — replicate, or apply the delta instead and document). Prep tickets never touch stock. Work-order line edit
replaces all lines (do it in a transaction — the prototype does not). Hardcoded categories (RAW_MATERIAL, SEMI_FINISHED,
FINISHED), locations (tasting_room, restaurant) and units → config/enums with translations; remove the hardcoded Croatian
note string.

**Screens**: tabs Inventory (grouped by category, add/edit/delete, adjust dialog), Inventura (count UI + print), Work orders
(list with status/assignee/date filters, form, detail, print), Print (stock list). Role KITCHEN + ADMIN.
Component internals **(unverified)**: validation messages, low-stock highlight rule, count UI.

**Import**: `KitchenStep` from the six `Kitchen*` tables.

---

## 4. Phase 3 — Accommodation (module C)

### 3a Back office
**Tables**: `villas` (name, slug unique per tenant, portal_token unique, address, coordinates, wifi_name/password, check-in/out time, instruction/rules/welcome/transport/amenities text, emergency/contact phone/email, logo, portal_tab_order json, is_active), `villa_rates` (villa, label, start/end date, nightly_rate bigint, min_nights?, notes), `villa_sections` (villa, title, icon, content, sort_order, is_active), `villa_bookings` (villa, guest name/email/phone, check_in/out, guests default 2, portal_token unique, notes, status UPCOMING/CHECKED_IN/CHECKED_OUT/CANCELLED, total_amount?, rebate_percent, auto_calculated_amount?, deposit_amount?, deposit_paid), `villa_menu_items` (villa, category, name, description, price, currency, image, is_active, sort_order, vat_included, inventory_item_id?), `villa_orders` (booking, status, total, delivery_date?, notes, stripe_payment_id?, paid_at?), `villa_order_items` (order cascade, menu_item nullOnDelete, quantity, unit_price, total, notes).

**Pricing service `BookingPricing`** (single implementation; the prototype has two): per night in `[check_in, check_out)`, pick the rate whose `start ≤ night ≤ end` (**inclusive end**), latest `start` wins on overlap, missing night contributes 0 and is reported; `subtotal`, `rebate = round2(subtotal × pct/100)` with pct clamped 0-100, `total = round2(subtotal − rebate)`. Use pure `Y-m-d` dates (prototype mixes local and UTC getters). `auto_calculated_amount` is a snapshot at save; `total_amount` is the editable final; **editing dates does not recompute**. `min_nights` stored, ignored.

**Other rules**: villa delete = soft (`is_active=false`); booking delete = hard (orders + items first); slug from name (**add uniqueness handling**, the prototype crashes on duplicates); section reorder = index; menu `sort_order` = max+1 per category; "create menu item for all villas" clones to every active villa; menu item may link a finished-goods inventory item (image only, **no stock decrement**); status free-form.

**Screens**: overview (open balance = unpaid deposits on non-cancelled future bookings, today and next-7-days check-in/out, per-villa 30-day occupancy, timeline), villas (list/new/edit with general / branding / pricing / portal / menu / bookings tabs), bookings (filters villa/status/search; form with live total + missing-nights warning + override; detail with portal link/QR, orders, status, delete), menu manager, orders (status select, paid/unpaid).

**Revenue definitions to unify (Q3)**: tile = `CHECKED_OUT` stays, series = `deposit_paid` bookings; both with `total ?? deposit` fallback. Pick one.

**Known prototype defects not to copy**: no overlap/double-booking check, no `check_out > check_in` validation, `createGuestOrder` blocks `COMPLETED` (a status that does not exist), unvalidated quantities, abandoned PENDING orders never expire, order-success page unauthenticated by id, external QR service leaks the token, N+1 order counts.

### 3b Guest portal + ordering (needs Q5)
Public token route (precedent: `PublicOrder.vue`, `PublicOrderController`). Booking token → personalised portal + ordering; villa (QR) token → generic portal. Languages en/hr/de/it/fr from a flat dictionary; language is client state, not persisted. Order flow: pick items (prices re-read server-side), delivery date/time, Stripe Checkout (card), signed webhook (`checkout.session.completed`) marks PAID and fires push notification. **VAT**: prototype UI adds a hardcoded 25 % to ex-VAT items but the server charges the raw price → define VAT once, server-side. terroir-bi's existing Stripe is SaaS billing; guest payments need per-tenant credentials or Connect, a tenant-aware webhook route, idempotency, and expiry of unpaid orders.

**Import**: `AccommodationStep` (villas, rates, sections, bookings, menu items, orders, order items; keep tokens so printed QR codes keep working).

---

## 5. Phase 4 — Remaris POS "Hospitality" (module D)

**Tables**: `pos_articles` (remaris_id, firma_id, name, code, barcode, sales_group, warehouse_group, unit, flags, current_price, + mapping: mapped_item_id, unit_fraction, channel RETAIL/HORECA/EXCLUDE, serving_type BOTTLE/GLASS/TASTING, components json `[{inventory_item_id, pour_ml}]`; unique(remaris_id, firma_id)), `pos_invoices` (remaris_id, firma_id, invoice/fiscal number, issued_at, accounting_date, total, is_cancelled, is_reversal, reversed_invoice_id, payment_method, jir, cash_register id/name, customer name/oib, cashier id/name, shift, guid), `pos_invoice_items` (… net_amount, vat_rate, pnp_rate, deposit_fee, tax group, sales group, article FK), `pos_payments`, `pos_sync_logs`.

**Integration**: HTTP Basic against `{base}/api/export/{endpoint}` (articles, invoices, payment methods, price lists, spatial data, daily totals, contacts). Prototype: env-only credentials, 15 s timeout, no retry/pagination, backfill splits by month. Port as one `RemarisSync` service run by the Laravel scheduler/queue (replaces three divergent copies + Vercel-hobby workarounds: daily cron, browser-triggered sync, floor polling). **Per-tenant encrypted settings**: base URL, credentials, firma ids, cash-register → location mapping, own-outlet customer names, VAT rate, legacy-currency cutover. Idempotency key `(remaris_id, firma_id)`; sync articles before invoices (item→article FK depends on it); replace items/payments on update.

**Defects to fix, not copy**: timestamps are local wall-clock stored as UTC (+2 h; skews hourly chart) → parse as `Europe/Zagreb` at import; HRK→EUR (÷7.5345 for ≤2022) applied at read time → convert once at import or centralise; VAT 25 % hardcoded in four files and applied to gross `total` ignoring PNP; revenue keyed on `issued_at` on the page but `accounting_date` in dashboards → choose one; cashier name never filled.

**Screens**: main analytics (location tabs, period + compare modes, KPI tiles, quick stats MTD/QTD/YTD vs LY, hourly revenue, daily heat, sales groups, top sellers by revenue/quantity, movers, payment mix, waiters, trailing-12, compare workbench with article × timeframe matrix), internal-channel view (per-channel cards, by-wine table with net revenue / COGS / margin, tastings, sent-vs-sold, unmapped wine lines) with article-mapping setup screen, sync button/log. COGS needs the inventory costing helper (`resolveItemUnitCost`) **(unread; must find terroir-bi's equivalent)**. Dashboard: add POS revenue to channel tiles and cash-flow (net of VAT).

**Import**: `RemarisStep`; decide dump import vs re-pull from the API (history goes back to 2015; Q4/Q9).

---

## 6. Component reuse matrix

Existing building blocks verified in `resources/js` (names as in repo):

| Need | Reuse | Where it fits |
|---|---|---|
| Page shell, header, tabs | `AppLayout`, `PageHeader`, `ui/Tabs` (segmented/filter), `useUrlTab`, `useVisitPending` | All index pages |
| Create/edit/view forms | `SidePanel` (the 480 px drawer used for every form), `FormField`, `FormSection`, `FieldRow`, `Input`, `Textarea`, `Select`, `Switch`/`SwitchRow`, `Checkbox` | Program, reservation, villa, rate, booking, menu item, kitchen item forms |
| Searchable pickers | `ui/Combobox` (matches label + keywords) | Agency, program, customer, villa, inventory-item pickers |
| Customer create-on-the-fly | `customers/CustomerFormPanel` (already has the agency toggle) | Reservation form |
| Agency price editing | pattern of `customers/CustomerPriceDialog`, `inventory/TierPriceDialog` | Agency price dialog |
| Money / quantity | `lib/money` (`formatMoney`, `unitPriceToMinor`, `formatQuantity`), `orders/QuantityStepper` | Totals, guest menu cart, kitchen quantities |
| Status flow | `orders/StatusStepper`, `ui/StatusChips`, `ui/Badge` (neutral/solid/success/destructive/outline/warning) | Reservation status, booking/order status |
| KPI tiles | `ui/StatCard` (label/value/hint/alert), `ui/AttentionBand`, `ui/MetaStrip`, `ui/Callout` | Quick stats, overview |
| Charts | `ui/BarChart` (1-2 series), `ui/LineChart` (multi-series), `ui/AreaChart`, `ui/StackedBar`, `ui/ProgressBar`, `inventory/SparkBars` | Monthly revenue, trends, payment mix |
| Dates | `ui/DateRangePicker`, `ui/Calendar`, `lib/dates` | Filters, custom ranges |
| Confirmations / undo | `useConfirm` (`confirmDialog`), `UndoToast`, `FlashMessages` | Delete reservation, delete booking |
| Reorder lists | `ui/GripHandle` (+ pattern in customer categories / work-order boards) | Villa sections, portal tabs, menu items |
| Uploads | `ui/UploadDropzone`, `useUpload` | Villa logo, menu images |
| Print | `cellar/PrintSheet` (hidden sheet + `print()`; replaces `window.open`) | Kitchen stock list, count sheet, prep ticket |
| Pagination, lists | `ui/Pagination`, `lib/listReturn` | Reservation list |
| Record history | `useRecordDrawer`, `cellar/RecordDrawer`, audit log | Optional change history |
| Public pages | `pages/PublicOrder.vue` + `PublicOrderController` | Guest portal skeleton |
| Language | `LanguageSwitcher` (app-level), `useTranslations` | Admin strings |

### 6.1 Components that do not exist yet — **for discussion before building**

| # | Gap | Needed by | Why existing pieces don't cover it | Proposal |
|---|---|---|---|---|
| G1 | **Shared data table** (sortable headers, sticky/total row, optional per-row link, stacked-card reflow under `sm`, empty state) | Reservation list, agency performance (with Prev/Chg columns), program performance, kitchen stock, rates, bookings, POS tables | No `ui/Table` in the repo; `Customers/Index.vue` hand-rolls `<table class="min-w-[66rem]">`. Memory says a ui/Table wrapper exists — it does not in `components/ui` **(verify branch)**. | `ui/DataTable.vue` + `ui/SortableHead.vue`, built once, mobile-first. Biggest reuse win. |
| G2 | **Date-time field** | Reservation date + time, guest order delivery time, kitchen ticket date | `Calendar`/`DateRangePicker` are date-only; `Input type=datetime-local` is the only fallback (poor on iOS, off-design) | `ui/DateTimeField.vue` (Calendar popover + time select, 15-min step) |
| G3 | **Period strip + compare control** | Reservation list (Today/MTD/YTD/custom), POS (presets + YoY/previous compare) | The Orders page has its own inline period strip + custom range; not extracted | Extract `ui/PeriodStrip.vue` (+ optional `CompareToggle`) from `pages/Orders/Index.vue` and reuse on Orders too (small refactor) |
| G4 | **Delta badge** (▲/▼ % vs previous window) | Quick-stats tiles, agency performance, POS KPIs | `StatCard` has `hint` text only, no delta/trend | Add optional `delta` prop to `StatCard` or a `ui/DeltaBadge.vue` |
| G5 | **Horizontal ranked bar** (top agencies, top sellers, by-program) | Analytics, POS | `BarChart` is vertical, 1-2 series | `ui/RankedBars.vue` (label, value, share). Cheap, no chart lib |
| G6 | **Donut / share chart** | Program mix, payment mix | `StackedBar` covers a single-row share and may be enough | Prefer reusing `StackedBar`; only build donut if design demands (decide with Q) |
| G7 | **Calendar heat strip / heatmap** | POS day-by-day revenue | None | `ui/HeatStrip.vue`, phase 4 only |
| G8 | **Grouped/combo chart** (this year vs last year per month, optional line) | Monthly revenue trend, POS trailing-12, compare workbench | `BarChart` supports exactly two series (current vs previous fits), `LineChart` multi-series. Compare workbench (up to 8 series × 6 timeframes) does not fit | Reuse `BarChart` (2 series) for reservations; extend for ≥3 series in phase 4 |
| G9 | **Timeline / activity list** with notes | Reservation status timeline + Add note; booking history | Order history UI is inline in `OrderViewPanel.vue` **(unverified how generic)** | Extract `ui/Timeline.vue` from OrderViewPanel if clean; otherwise build new |
| G10 | **Multi-select combobox with chips** | POS compare workbench (article list), kitchen assignee | `Combobox` is single-select | `ui/MultiCombobox.vue`, phase 4 |
| G11 | **Occupancy / booking timeline** (villas × days) | Accommodation overview | `Calendar` is a picker only | `accommodation/BookingTimeline.vue`, phase 3a |
| G12 | **QR code + copy-link card** | Villa and booking portal links | No QR dependency; prototype used a third-party image URL (leaks token) | Add a small QR lib (frontend) or server-rendered SVG; `portal/LinkCard.vue` (copy pattern exists in `OrderLinkDialog`) |
| G13 | **Public guest layout + guest language switcher** | Villa portal | `PublicOrder` exists but no multi-language public layout; `LanguageSwitcher` is app-level | `layouts/GuestLayout.vue` + guest switcher (en/hr/de/it/fr, persisted in cookie/localStorage) |
| G14 | **Guest cart / menu browser** | Villa ordering | `QuantityStepper` exists; no category-grouped menu + cart summary | `portal/MenuCart.vue`, phase 3b |
| G15 | **Dependent picker for item-or-free-text lines** | Kitchen prep ticket lines, villa menu | `OrderLineFields` is order-specific | Generalise or build `ItemOrCustomLine.vue` |
| G16 | **Inline "edit mode" card** (read view ↔ edit view in place) | Reservation detail | Prototype edits in place; terroir-bi's convention is drawer forms | Prefer the existing drawer (`SidePanel`) — no new component, but confirm (UX is "not like-for-like") |

Items G1-G5 and G9 are needed in **phase 1**; I recommend we agree on G1 (table) and G2 (date-time) first because
they gate the first screens.

---

## 7. Phasing and sequencing

| Step | Deliverable | Depends on | Size |
|---|---|---|---|
| 0 | Decisions Q1-Q9; foundations (§1) | — | S |
| 1a | Migrations, models, pricing service, programs + agency prices (+ customer Pricing tab, merge service) | 0, G1 | M |
| 1b | Reservations CRUD, status/history, list + filters + quick stats | 1a, G1-G4 | M |
| 1c | Analytics tab + agency performance | 1b, G5/G8 | M |
| 1d | Dashboard channel, role/nav wiring, tests | 1b, Q3 | S-M |
| 1e | `HospitalityStep` + reconcile + rehearsal | 1a-1b | S-M |
| 2 | Kitchen (all screens, print, import) | 0 | M |
| 3a | Accommodation back office + import | 0, G11, G12 | L |
| 3b | Guest portal + Stripe | 3a, Q5, G13/14 | L |
| 4 | Remaris sync, analytics, mapping, import | 0, Q4/Q9, G7/G8/G10 | XL |

---

## 8. Risks

1. Dashboard double counting (reservations vs orders for agencies) — resolve before shipping 1d.
2. Rounding drift between prototype floats and integer cents; reconcile against legacy totals.
3. Per-tenant generality: Remaris and villa branding are Bibich-specific in the prototype (locations, registers, outlet names, host, page title).
4. Guest payments architecture (Stripe per tenant) is a product decision, not a port.
5. Large POS history volume and timezone correction.
6. `DashboardRoleVisibilityTest` and `RoleParityTest` encode "hospitality sees nothing"; changing roles is deliberate and must update them.

## 9. Open questions

- **Q1 Scope**: all four modules, or phase 1 only now?
- **Q2 Naming/nav**: keep prototype names (Agencies = reservations, Hospitality = POS) or rename?
- **Q3 Revenue**: separate `hospitality` channel from reservations (recommended), or feed `agency`? Accommodation: `CHECKED_OUT` vs `deposit_paid`?
- **Q4 Tenancy/modules**: Bibich-only config or full per-tenant settings; one `Module::Hospitality` or four separately sellable modules?
- **Q5 Guest payments**: per-tenant Stripe keys, Connect, or defer online ordering?
- **Q6 e-invoice link** on reservations: omit (recommended) or keep a nullable column?
- **Q7 Exclusions**: confirm floor board, open-tables API, Visits tab, unused Remaris charts are out.
- **Q8 Behaviour fixes vs like-for-like**: status transition validation; quick-stats consistency; opening-stock movement in Kitchen; stock-take overwrite vs delta; max-pax enforcement; booking overlap checks.
- **Q9 POS history**: import from the dump or re-pull via API?

## 10. Not verified in this research
Full bodies of `reservation-detail-client.tsx`, `hospitality-analytics.tsx`, the kitchen components, the
Remaris dashboard/compare/mapping components; terroir-bi landing-redirect location; `resolveItemUnitCost`
equivalent; Kitchen/Remaris legacy row counts; whether a `ui/Table` exists on another branch.
