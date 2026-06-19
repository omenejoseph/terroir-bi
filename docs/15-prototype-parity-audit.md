# 15 — Prototype parity audit (rebuild vs order-mgmt)

Living tracker of where the **rebuild** (`terroir-bi`) diverges from the **prototype**
(`/Users/omenejoseph/Sites/order-mgmt`, Next.js 14 + Prisma), which is the feature/behaviour
**source of truth**. We burn this down module by module.

**Rules of the audit**
- Match **functionality** and **information displayed**, not the exact UI/layout.
- Skip cosmetic differences and rebuild-only improvements (keep those).
- Don't "fix" things deferred by design: hospitality & accommodation revenue channels; the
  customer-type-based channel model (rebuild drives revenue-by-channel off `CustomerType`);
  money as minor units; snake_case API fields.

Severity: **HIGH** = wrong/missing data or behaviour a user notices · **MED** = missing
secondary capability/info · **LOW** = minor.

> Note (orders): in the rebuild the inventory **item name already embeds the vintage**
> (e.g. `Plavac Mali 2021`) with a separate `vintage` field alongside. The prototype keeps
> them separate and appends. So we do **not** render a separate vintage on order lines — it
> would duplicate. Unit size (`750ml`) is a distinct field and is shown.

## Status

| Module | State |
|--|--|
| Orders — line-item display | ✅ **Done** (unit size in list + detail; case→bottle expansion in detail) |
| Inventory | ✅ **Done** (case-count display; post-hoc correction toggle; "By" column with `created_by`) |
| Cellar | ⬜ catalogued |
| Vineyards / Production / Work Orders | ⬜ catalogued |
| Dashboard / Costs / Inflows / Cash-flow / Suppliers | ⬜ catalogued |

---

## Orders ✅
| Sev | Divergence | Prototype | Rebuild | Status |
|--|--|--|--|--|
| HIGH | Unit size (`750ml`) + case→bottle expansion in line items | `dashboard/orders/page.tsx:181-188`, `components/orders/editable-order-items.tsx:330-436` | was name+qty only | **Done** — `OrderData::item()` emits `unit_size`+`bottles_per_case`; preview + `order-items-section` render them |

## Inventory
| Sev | Divergence | Prototype | Rebuild | Status |
|--|--|--|--|--|
| HIGH | Case **quantity** display — rebuild showed *bottles-per-case* not *number of cases* | `sortable-inventory-list.tsx` StockDisplay → `{bottles} ({floor(bottles/bpc)} cases)` | was `inventory/page.tsx` per-case hint | ✅ **Done** — list now shows bottle total + "(N cases)" |
| HIGH | Post-hoc **stock correction** (mark/unmark a movement as reconciliation after the fact) | `movement-history.tsx:23-52` → `inventory.actions.ts:291-309` | badge was read-only; backend `PATCH /stock-movements/{id}/reconciliation` already existed | ✅ **Done** — API client + `useSetMovementReconciliation` + toggle (MANUAL_IN/OUT) in `stock-tab.tsx` |
| MED | Movement history shows **who** made it | "By" column | was missing — no `created_by` column | ✅ **Done** — migration adds `created_by_id`; `StockLedger::record()` (the single sink) stamps `auth()->id()`; DTO/type expose `created_by`; "By" column added |

## Cellar
| Sev | Divergence | Notes |
|--|--|--|
| HIGH | Enological calculators (SO₂ K₂S₂O₅, acid, chaptalization, press yield, EU SO₂ compliance) | port `enological-calculators.ts`; rebuild only inline uplift `dose()` |
| HIGH | Vessel **detail** page (edit specs, per-vessel analyses/tastings, fault toggle, assign/unassign) | rebuild list + modal only; no `vessels/[id]` |
| HIGH | Tasting **reports**: detail/view/edit + add notes/wines to a report | rebuild list + minimal create dialog; reports orphaned |
| HIGH | Lot lifecycle actions: protocol assignment, quick actions, status transitions, addition/transfer/process/bottling forms | rebuild lot detail partial |
| MED | SO₂ depth: K₂S₂O₅ fallback, reference targets by wine type/material, per-vessel readings, plan vs update modes | rebuild simplified |
| MED | Cellar analytics: KPI row + full chart suite (pipeline, volume-by-type/vintage, grape variety, timeline, cost waterfall, lot-status board) | rebuild ~2 charts |
| MED | Vessel map **position editing** (drag/resize/multi-select/undo, batch save) | rebuild map read-only |
| MED | Bulk analysis CSV upload | route exists, UI minimal |
| MED | Multi-grape blend on lot creation | rebuild single grape field |
| LOW | Vessel fault tracking UI | missing |

## Vineyards / Production / Work Orders
| Sev | Divergence | Notes |
|--|--|--|
| HIGH | Parcel detail **metadata edit** (soil, elevation, planting year, row spacing, rootstock, training, orientation, slope, geo-polygon) | rebuild read-only basic fields → edit form + `PATCH /vineyard-parcels/{id}` |
| MED | Vineyard **map** view (geo edit, polygon, area calc) | rebuild grid cards only |
| MED | Parcel **ownership filter** (All/Own/Cooperant) | missing |
| MED | Grower **performance analytics** (reliability %, avg €/kg, seasons, contract count) | missing |
| MED | Work-order **wine-lot / vessel context** (`wine_lot_id`, `vessel_id`) | rebuild model lacks columns → migration + model + API if cellar routing needed |
| — | Intake: prototype `IntakeBooking` scheduling (time slots) vs rebuild entry-per-plan | product decision |
| — | Production full-page vs cards; WO day-view; WO search | UI/enhancement — no action |

## Dashboard / Costs / Inflows / Cash-flow / Suppliers
| Sev | Divergence | Notes |
|--|--|--|
| HIGH | Dashboard **key ratios/KPIs** (operating margin %, COGS % + amount, employee/marketing cost %, revenue/employee, avg order value, inventory turnover); `null` not `0` when data missing | missing; mirror prototype COGS snapshot→item→recipe fallback |
| HIGH | Dashboard **quick stats** Today/MTD/YTD over all *in-scope* realized revenue (wholesale shipped, agency, consignment sell-through; **excl. deferred** hospitality/accommodation/club) | verify `revenue_summary` covers in-scope streams |
| MED | Costs: **invoiced vs paid** split (invoiced = has e-invoice; paid = status PAID) | ensure backend computes split |
| MED | Costs: gross margin tied to period **revenue** | pull revenue into margin calc |
| MED | **Inflows analytics** (invoiced vs collected, pending, net cash flow, timing) | no analytics page |
| MED | Cash-flow KPIs: payment discipline (days-to-collect/pay, on-time %), outstanding **payables**, burn rate | rebuild has forecast + A/R aging only |
| MED | Revenue-by-channel **over time** stacked chart (in-scope channels) | missing |
| LOW | Supplier order **PDF export** (`generate-supplier-order-pdf.ts`) | rebuild create-only |
| LOW | Customer **reorder radar** backend data | component exists, needs data |
| LOW | Costs analytics: category period-over-period change; YoY completeness | partial |

---

# Displayed-information parity pass (per-screen field gaps)

A focused read-only sweep of *what data appears on screen* in every module vs the prototype
(not behaviour/styling). "On par" = same information shown.

## On par ✅
Orders (list + detail), Inventory (list card + overview + stock tab + movement history, incl.
"By"), Vineyard parcel **list**, Harvest intake, Production (list + detail), Work-orders **list/board**,
Cellar costs page, Bulk additions. Inventory overview actually shows **more** than the prototype.

## Quick field-level gaps (data exists, just not shown)
| Screen | Missing field(s) shown by prototype |
|--|--|
| Customers **list** card | total revenue, order count, "stats excluded" badge |
| Customer **detail** | YoY growth % (rebuild shows this-yr/last-yr totals, not the %) |
| Suppliers **list** | city, **cooperant** badge, cost-entries count |
| Supplier **detail** | cooperant badge |
| Vineyard parcel **detail/form** | location, lat/long, soil type, elevation, planting year, row spacing, rootstock, training, orientation, slope, notes, ownership/cooperant selector |
| Grape contract **form** | min Brix, max pH, delivery window, payment terms, notes |
| Work-order **detail** | category (editable), linked wine-lot, linked vessel |
| Lot **detail** — analysis | only 5 of ~13 params shown (missing TA, VA, RS, GF, malic, lactic, TPI, temp, density) |
| Lot **detail** — additions/transfers/processes | addition cost + total; transfer other-lot/date/by/note; process vessel + date |
| Lot **detail** — tastings | vessel context; created-by |
| Cellar tastings **list** | created-by, wine names |
| Costs **list** | due date + overdue/due-soon indicator, invoice/payment type, VAT, overdue count |
| Costs/Inflows **analytics** | per-KPI trend badges (YoY/period-over-period), on-time % |
| Inflows **list** | summary KPI bar (invoiced/collected/pending) |

### Done in the field-gap sweep ✅
- Customers list: **stats-excluded badge**. Suppliers list: **city**. Work-order detail: **category** select.
- Vineyard parcel **detail**: "Site details" card (location, soil, elevation, planting year, row spacing,
  rootstock, training, orientation, slope, coordinates).
- Customer order details: **revenue-trend chart** (trailing-12-month series added to
  `CustomerOrderAnalyticsQuery` + `RevenueChart`). *(Note: customer YoY % was already shown — not a gap.)*
- Card pattern (`MetaField`) applied to inventory/orders/customers cards.

- Grape-contract form: **min Brix, max pH, delivery window, payment terms, notes** ✅.
- Parcel create form: **agronomy inputs** (ownership, location, soil, elevation, planting year, row spacing,
  rootstock, training, orientation, slope) ✅.

- Customers-list **revenue + order count** ✅ — `ListCustomersQuery` withCount/withSum (non-consignment);
  `order_count` always, `revenue_minor` gated by `canSeeFinancials()`; shown as MetaFields on the card.

### Still pending in this sweep — need backend
- Supplier **cooperant** flag + **cost-entries count** (no column / aggregate).
- Work-order linked **wine-lot / vessel** (migration + relations).

## Bigger gaps — whole screen / widget absent
| Area | Missing |
|--|--|
| Cellar | **Vessel detail page** (specs, per-vessel analyses/tastings, fault, contents) — absent |
| Cellar | **Tasting report detail page** (per-wine scores/notes) — absent |
| Cellar | Dashboard **bottled-wines archive** section |
| Cellar | Analytics **KPI tiles** (needs-SO₂, needs-analysis, utilization, empty vessels) + timeline/cost-waterfall/lot-status-board charts |
| Cellar | Blend page **pre-blend analysis comparison** |
| Inventory | Stock tab: **profitability-by-tier**, **vintage-coverage** widget |
| Dashboard | **Key-ratio KPI cards** (operating margin, COGS %, rev/employee, AOV, inventory turnover…) + channel-revenue-over-time chart |
| Cash-flow | burn rate, outstanding **payables**, net working capital, payment-discipline card, cumulative + forecast charts, category comparison, top receivables/payables tables |

## Deferred by design — NOT gaps
Hospitality & accommodation revenue channels; POS (Remaris) inflow cards; bulk-analysis photo/OCR
(AI extraction); supplier-order PDF (separate item). Rebuild-only additions (top-products, stock-watch,
WO day-view/search, lots list page) are improvements — keep.
