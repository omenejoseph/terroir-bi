# 20 — Cellar parity matrix (order-mgmt → terroir-bi)

This document tracks, row by row, which cellar features from the prototype
(`order-mgmt`, Next.js + Prisma) have been ported to this app. It replaces the
cellar section of [`15-prototype-parity-audit.md`](15-prototype-parity-audit.md).

## How the port works

**Ground rules**

- **Parity first.** Every feature, screen and action in the prototype is ported
  to the Inertia UI kit before any experience redesign (Phase 3) begins. The
  parity gate is every row below at ✅ on both columns, followed by a
  side-by-side walkthrough on the same imported tenant data.
- **Correctness fixes go in during the port.** Prototype behaviour that
  corrupts data is *not* reproduced. Each such case is recorded in the **Fix**
  column, and the fixed behaviour becomes the parity target.
- **No Figma frames exist for cellar.** Screens are composed from
  `resources/js/components/ui` and follow the 9-point definition of done in
  [`17-frontend-screen-plan.md`](17-frontend-screen-plan.md), except the
  Figma diff.
- **Out of scope for parity:**
  - AI photo/OCR analysis (single scan and bulk photo upload), which stays deferred
  - the iOS voice assistant
  - the WhatsApp bot
  - the read-only MCP tools

**Columns**

- **Old** — the prototype source, as a path under `order-mgmt/src`.
- **BE** — backend in this repo. ✅ exists · 🟡 partial · ⬜ missing.
- **UI** — Inertia screen. ✅ shipped · 🟡 partial · ⬜ missing. Today there
  is no cellar UI, so every row is ⬜.
- **Fix** — the correctness change applied while porting.

**Abbreviations**

- `CA` = `actions/cellar.actions.ts`
- `CAA` = `actions/cellar-activity.actions.ts`
- `HA` = `actions/harvest.actions.ts`
- `C/` = `components/cellar/`
- `P/` = `app/dashboard/cellar/`

## Summary

| Area | Rows | BE ✅ | BE 🟡/⬜ | BE n/a | UI ✅ |
|---|---|---|---|---|---|
| A. Cellar home | 8 | 6 | 2 | 0 | 0 |
| B. Cellar map | 22 | 18 | 4 | 0 | 0 |
| C. Vessels | 11 | 9 | 2 | 0 | 0 |
| D. Wine lots | 30 | 21 | 8 | 1 | 0 |
| E. SO₂ | 14 | 14 | 0 | 0 | 0 |
| F. Additions & enological stock | 18 | 12 | 6 | 0 | 0 |
| G. Lab analyses | 9 | 7 | 0 | 2 | 0 |
| H. Transfers & racking | 4 | 4 | 0 | 0 | 0 |
| I. Blending | 9 | 4 | 5 | 0 | 0 |
| J. Planned work & Today | 20 | 20 | 0 | 0 | 0 |
| K. Tastings | 9 | 3 | 6 | 0 | 0 |
| L. Bottling | 4 | 3 | 1 | 0 | 0 |
| M. Fermentation protocols & monitoring | 7 | 6 | 1 | 0 | 0 |
| N. Analytics & costs | 10 | 5 | 5 | 0 | 0 |
| O. Compliance & records | 4 | 2 | 2 | 0 | 0 |
| P. Work-order integration | 3 | 2 | 1 | 0 | 0 |
| Q. Audit logging | 11 | 1 | 10 | 0 | 0 |
| **Total** | **193** | **137** | **53** | **3** | **0** |

---

## A. Cellar home — `/cellar`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| A1 | Header with "+ Wine Lot" and quick links (Costs admin-only, Today, Plan, Analytics, SO₂, Tastings, Blend, Bulk additions, Bulk analysis, Products, + Vessel) | `P/page.tsx` | — (navigation only) | ✅ | ⬜ | Replaced by a `CellarTabs` sub-nav plus header actions |
| A2 | Stat cards: active vessels, active lots, total volume (non-bottled) | `P/page.tsx` | `CellarAnalyticsQuery` | ✅ | ⬜ | |
| A3 | Embedded cellar map (see B) | `C/cellar-map.tsx` | `ListVesselsQuery` | ✅ | ⬜ | |
| A4 | Active lots grouped by vintage (newest first) with count and litres | `C/wine-lots-by-vintage` in `P/page.tsx` | `ListWineLotsQuery` (`exclude_bottled`) | ✅ | ⬜ | |
| A5 | Lot row: lot, grape, status pill, last analysis (date, pH, alc, free/total SO₂), last addition (name, qty, unit, date), volume current/initial | `P/page.tsx` | `WineLotData` (`latestAnalysis`, `latestAddition`) | ✅ | ⬜ | |
| A6 | Mobile card layout of the same rows | `P/page.tsx` | — | ✅ | ⬜ | |
| A7 | Collapsible "Bottled wines" archive by vintage: last bottling (count → item), counts of additions, analyses and bottlings | `P/page.tsx` | `ListWineLotsQuery` status=BOTTLED + counts | 🟡 | ⬜ | Add `withCount` to the query |
| A8 | Role landing: CELLAR users redirected from dashboard to cellar | `app/dashboard/page.tsx:120` | `NavCatalog` / home redirect | ⬜ | ⬜ | |

## B. Cellar map

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| B1 | Room tabs | `C/cellar-map.tsx` | `ListVesselsQuery` (room) | ✅ | ⬜ | |
| B2 | Reorder room tabs by drag (saved per browser) | `C/cellar-map.tsx` | — (localStorage) | ✅ | ⬜ | |
| B3 | Rename room inline (Esc cancels) | `CA:2511 renameCellarRoom` | `RenameCellarRoomAction`, `POST vessels/rename-room` | ✅ | ⬜ | |
| B4 | Vessel tiles shaped by type (tank legs/dome, barrel hoops) | `C/cellar-map.tsx` | — | ✅ | ⬜ | |
| B5 | Fill bar by capacity %, coloured by wine type or grape | `C/cellar-map.tsx`, `lib/cellar-colors` | `VesselData.lots` | ✅ | ⬜ | |
| B6 | Faulty overlay (red + warning icon) | `C/cellar-map.tsx` | `vessels.is_faulty` | ✅ | ⬜ | |
| B7 | SO₂ badge (free/total), vessel reading preferred over lot reading | `C/cellar-map.tsx` | `VesselData` latest SO₂ | ✅ | ⬜ | |
| B8 | Hover tooltip (type, material, capacity, fill, lots and litres, SO₂, projected SO₂ after latest addition) | `C/cellar-map.tsx` | `VesselData` + additions | 🟡 | ⬜ | Projection uses `cellar_additions.vessel_id` and the product uplift, **not** regex on the note |
| B9 | Click: vessel with wine → lot (`?vessel=`); empty vessel → vessel page | `C/cellar-map.tsx` | — | ✅ | ⬜ | |
| B10 | Edit mode lock/unlock | `C/cellar-map.tsx` | — | ✅ | ⬜ | |
| B11 | Drag to move; multi-select with shift/⌘; lasso; group move | `C/cellar-map.tsx` | `UpdateVesselLayoutAction` | ✅ | ⬜ | |
| B12 | Corner resize, including several vessels at once | `C/cellar-map.tsx`, `CA bulkUpdateVesselSize` | `UpdateVesselLayoutAction` | ✅ | ⬜ | |
| B13 | Mass resize W/H, "Match size" | `C/cellar-map.tsx` | `UpdateVesselLayoutAction` | ✅ | ⬜ | |
| B14 | Duplicate N (next free number, same padding, offset placement) | `CA:254 duplicateVessels` | — | ⬜ | ⬜ | |
| B15 | Remove from map (clear position only) | `CA removeVesselFromMap` | `UpdateVesselLayoutAction` (null position) | 🟡 | ⬜ | Confirm null positions are accepted |
| B16 | Undo ⌘/Ctrl+Z (20 steps), select/deselect all, autosave | `C/cellar-map.tsx` | — | ✅ | ⬜ | |
| B17 | "Unplaced" sidebar of vessels without a position | `C/cellar-map.tsx` | `ListVesselsQuery` | ✅ | ⬜ | |
| B18 | Quick bulk-add vessels (prefix, start #, count 1–50, type, material, capacity, name preview) | `CA:214 bulkCreateVessels` | `BulkCreateVesselsAction` | ✅ | ⬜ | BARRIQUE valid everywhere |
| B19 | Assign wine to selected vessels: existing (≤ unallocated) or new wine (grows lot); fill to capacity or split equally; preview | `CA:1085 bulkAssignLotToVessels` | — | ⬜ | ⬜ | |
| B20 | Unassign one row / Remove all | `CA unassignLotFromVessel` | `UnassignLotFromVesselAction` | ✅ | ⬜ | |
| B21 | Fullscreen, zoom buttons, mobile pinch-zoom, sidebar toggle | `C/cellar-map.tsx` | — | ✅ | ⬜ | |
| B22 | Errors surfaced | `alert()` | — | ✅ | ⬜ | Inline flash / `InputError`, never `alert()` |

## C. Vessels

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| C1 | Create vessel: name, type (Barrel/Barrique/Tank/Vat/Amphora), material, capacity, room (suggest existing), location, notes | `P/vessels/new`, `C/vessel-form.tsx`, `CA:74` | `CreateVesselAction`, `StoreVesselRequest` | ✅ | ⬜ | BARRIQUE passes validation (prototype rejected it) |
| C2 | Edit vessel | `CA:99` | `UpdateVesselAction` | ✅ | ⬜ | |
| C3 | Vessel detail header (type icon, material, location) | `P/vessels/[id]` | `VesselController::show` | ✅ | ⬜ | |
| C4 | Mark faulty / clear fault with optional note | `C/vessel-fault-toggle.tsx`, `CA:885` | `UpdateVesselAction` (`is_faulty`, `fault_note`) | ✅ | ⬜ | |
| C5 | Delete (click-twice confirm) | `C/delete-vessel-button.tsx`, `CA:126` | `DeleteVesselAction` (refuses while holding wine) | ✅ | ⬜ | Refuse instead of hiding a vessel that still holds wine |
| C6 | Overview: capacity bar, latest SO₂ for vessel, contents (lot, litres, link) | `P/vessels/[id]` | `VesselData` | ✅ | ⬜ | |
| C7 | Assign lot: lot picker with allocated/unallocated, volume ≤ min(free, lot) | `C/assign-lot-form.tsx`, `CA:964` | `AssignLotToVesselAction` | ✅ | ⬜ | Never silently take excess from other vessels |
| C8 | Analysis tab: new analysis per lot in the vessel; last 10 analyses, editable | `C/editable-vessel-analysis-list.tsx` | Analyses by `vessel_id` | 🟡 | ⬜ | Needs a per-vessel analyses read |
| C9 | Tasting tab: per-vessel notes (last 20), editable | `P/vessels/[id]` | Tasting notes by `vessel_id` | 🟡 | ⬜ | Needs note update (K3) |
| C10 | Details tab: vessel form | `C/vessel-form.tsx` | `UpdateVesselAction` | ✅ | ⬜ | |
| C11 | Status sync (AVAILABLE ↔ IN_USE) | `CA:50 syncVesselStatus` | `VesselVolumeSync` | ✅ | — | MAINTENANCE/RETIRED never overridden |

## D. Wine lots

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| D1 | Lots list (search, status filter) | `P/page.tsx` (no dedicated page) | `ListWineLotsQuery` | ✅ | ⬜ | Dedicated `/cellar/lots` page added (rebuild improvement) |
| D2 | Create lot: name, grape rows (variety suggestions, €/kg admin-only, kg), wine type, vintage, vineyard, initial volume, stage, starting vessel, notes | `P/lots/new`, `C/wine-lot-form.tsx`, `CA:376` | `CreateWineLotAction`, `StoreWineLotRequest` | ✅ | ⬜ | Starting vessel capacity-checked |
| D3 | Lot number LOT-YYYY-NNN; grape cost = Σ price×kg; weighted €/kg | `CA:376` | `LotNumberGenerator`, `CreateWineLotAction` | ✅ | — | |
| D4 | Edit lot (Details tab) | `CA:490` | `UpdateWineLotAction` | 🟡 | ⬜ | Keep `percentage` and `harvest_entry_id` on grape rows |
| D5 | Set status BOTTLED frees vessels | `CA:26` | `UpdateWineLotAction` | ✅ | ⬜ | |
| D6 | Delete lot (click-twice); restores vessels; removes transfers/bottlings; cleans mirror inventory item | `CA:2319` | — | ⬜ | ⬜ | Reverse bottling stock movements on delete |
| D7 | Header: name, status, type, lot no., grape, vintage, vineyard | `P/lots/[id]` | `WineLotData` detail | ✅ | ⬜ | |
| D8 | `?vessel=` focus banner ("Viewing vessel X — N L · Show all"); filters additions and analyses | `P/lots/[id]` | — | ✅ | ⬜ | Filter additions by `vessel_id` |
| D9 | Blend provenance (incoming BLEND transfers by source lot, L and %) | `P/lots/[id]` | `WineLotData.transfers` | ✅ | ⬜ | |
| D10 | Quick-action buttons (+Addition/+Analysis/+Transfer/+Process), hidden once bottled | `C/lot-detail-tabs.tsx` | — | ✅ | ⬜ | |
| D11 | Volume card (L vs capacity of vessels or initial; focused-vessel L; vessel links) | `P/lots/[id]` | `WineLotData` | ✅ | ⬜ | |
| D12 | Adjust volume ± (vessel picker if several; cap removal per vessel; proportional when no vessel) | `C/adjust-volume-form.tsx`, `CA:588` | `AdjustLotVolumeAction` | 🟡 | ⬜ | Reason **saved** as `CellarProcess` Loss/Gain; proportional split |
| D13 | Assign to vessel (searchable, shows current content) | `C/assign-vessel-form.tsx` | `AssignLotToVesselAction` | ✅ | ⬜ | |
| D14 | Blend in another vessel's wine into this lot (keeps identity, recomputes grape %) | `C/blend-in-form.tsx`, `CA:735` | — | ⬜ | ⬜ | Capacity checked; cost carried volume-weighted |
| D15 | Cost breakdown (admin): €/kg, kg, yield %, grape, additions, linked costs, total, per L, per 750 ml | `P/lots/[id]` | `LotCostService::breakdown` | 🟡 | ⬜ | Include linked `CostItem` costs; one cost/L definition |
| D16 | Grape breakdown table (admin, more than one grape) | `P/lots/[id]` | `WineLotData.grapes` | ✅ | ⬜ | |
| D17 | Latest analysis card (latest SO₂ with date/vessel + latest other params) | `P/lots/[id]` | `WineLotData.analyses` | ✅ | ⬜ | All 14 params, not 5 |
| D18 | Lot journey timeline (created, fills, additions, analyses, tastings, transfers, bottlings, processes) | `C/lot-timeline.tsx` | — | ⬜ | ⬜ | `LotTimelineQuery` |
| D19 | Additions tab: form + planned additions box + table (cost admin) + total + delete | `C/addition-form.tsx` | see F | ✅ | ⬜ | |
| D20 | Analysis tab: trend chart + form + editable list | `C/charts/parameter-trends.tsx` | see G | ✅ | ⬜ | |
| D21 | Tasting tab: form + editable list | `C/tasting-note-form.tsx` | see K | 🟡 | ⬜ | |
| D22 | Transfers tab: form + in/out list | `C/transfer-form.tsx` | see H | ✅ | ⬜ | |
| D23 | Processes tab: quick-pick chips + custom kind, date, volume, vessel, note; list; delete | `C/process-form.tsx`, `CA:1303` | `AddCellarProcessAction`, `DeleteCellarProcessAction` | ✅ | ⬜ | |
| D24 | Edit a process | — (prototype has none) | — | — | ⬜ | Added for symmetry (`UpdateCellarProcess`) |
| D25 | Protocol card (FERMENTING only): assign template | `C/lot-protocol-actions.tsx`, `HA:2156` | `AssignFermentationTemplateAction` | ✅ | ⬜ | |
| D26 | Generate today's tasks from protocol | `HA:2196` | `GenerateWorkOrdersFromProtocolAction` | 🟡 | ⬜ | Tasks land on the default board (prototype's were invisible) |
| D27 | Plan protocol additions (per additive per vessel; per_L / per_hL / per_kg / per_100kg; round + board card) | `CAA:515` | `PlanProtocolAdditionsAction`, `POST wine-lots/{lot}/protocol/plan-additions` | ✅ | ⬜ | Depends on J |
| D28 | Bottling section on lot (see L) | — | see L | ✅ | ⬜ | Prototype had no screen; added |
| D29 | Bottled lots read-only (actions hidden) | `C/lot-detail-tabs.tsx` | — | ✅ | ⬜ | |
| D30 | Harvest intake / press fraction creates or tops up a lot | `HA:388`, `addPressFraction` | Vineyards module | ✅ | — | Tracked in the vineyards matrix |

## E. SO₂ — `/cellar/so2`

Backend shipped in Phase 1b: every dose is computed on the server by `So2DosePlanner` (preview, apply and plan-for-team share it), so what the screen shows is what gets applied.

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| E1 | Load lots (FERMENTING/AGING/READY) + latest free SO₂ per lot+vessel | `P/so2/page.tsx` (raw `DISTINCT ON`) | `CellarSo2BoardQuery`, `GET cellar/so2` (latest per lot+vessel via `LatestAnalyses`) | ✅ | ⬜ | `CellarSo2BoardQuery` |
| E2 | SO₂ product list (name match on sulfit/so2/metabisulfit/sumpor; fallback all) | `P/so2/page.tsx` | `CellarSo2BoardQuery::products` (category SO2/SULFITE or name; fallback all) | ✅ | ⬜ | Match on category SO2/SULFITE first |
| E3 | Quick free/total SO₂ entry per lot and per vessel; "N entered"; save | `C/so2-management.tsx` | `BulkAddAnalysesAction` | ✅ | ⬜ | Vessel id saved on each row |
| E4 | Plan mode: status chips Critical <15 / Low 15–24 / OK 25–45 / High >45 / No data | `C/so2-management.tsx` | `So2Calculator::status`, board `summary` | ✅ | ⬜ | |
| E5 | Product picker with stock | `C/so2-management.tsx` | `EnologicalProductData` | ✅ | ⬜ | |
| E6 | "Set all" mg/L to add; per-row override; expanding a lot copies value to vessels | `C/so2-management.tsx` | `So2DosePlanner` (`mode` add or target), `POST cellar/so2/preview` | ✅ | ⬜ | Label honestly as "mg/L to add" (target mode in Phase 3) |
| E7 | Reference targets by wine type (red 28, white 35, rosé 32, orange 25, sparkling 30; +3 oak) | `C/so2-management.tsx` | `So2Calculator::referenceTarget` (+3 oak) | ✅ | ⬜ | Shared constant in the calculator service |
| E8 | Filters: status, material, search, "only needing SO₂" | `C/so2-management.tsx` | board fields (status, material/oak, search on client) | ✅ | ⬜ | |
| E9 | Row: current free + days since reading, add, projected, dose | `C/so2-management.tsx` | board `days_since`, preview lines | ✅ | ⬜ | |
| E10 | Dose calculation: uplift coefficient or K₂S₂O₅ fallback (×1.5), kg conversion | `lib/enological-calculators.ts` | `So2Calculator::doseForProduct` (uplift, K₂S₂O₅ g/kg; other units need uplift) | ✅ | ⬜ | Port the fallback and expose it |
| E11 | Summary: total dose, cost, low-stock warning; Select all needing SO₂ | `C/so2-management.tsx` | preview `totals` (`quantity`, `cost`, `short_by`) | ✅ | ⬜ | Stock shortfall **blocks** apply |
| E12 | Copy list / WhatsApp share / Print sheet | `C/so2-management.tsx` | preview lines carry the text (`note`); copy/share rendered in the UI | ✅ | ⬜ | Text translated via `hr.json` |
| E13 | Apply now (bulk additions per vessel) | `CA:1348 bulkAddAdditions` | `ApplySo2Action`, `POST cellar/so2/apply` | ✅ | ⬜ | Per-vessel `vessel_id`; stock-checked |
| E14 | Plan for cellar team (SO₂ round) + success screen | `CAA createSo2Round` | `CreateSo2RoundAction` (server doses), `POST cellar/so2-rounds` | ✅ | ⬜ | Depends on J |

## F. Additions & enological stock

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| F1 | Single addition: product from inventory (grouped, with stock) or manual entry | `C/addition-form.tsx`, `CA:1197` | `AddCellarAdditionAction` | ✅ | ⬜ | |
| F2 | Category from product or presets (SO2, Yeast, Nutrient, Bentonite, …) | `C/addition-form.tsx` | free-text category | ✅ | ⬜ | |
| F3 | Dose per hL × lot hL autofill; units list; cost (admin); note | `C/addition-form.tsx` | `StoreCellarAdditionRequest` | ✅ | ⬜ | Dose uses the **vessel's** volume when a vessel is chosen |
| F4 | Vessel for an addition | note text `→ VESSEL` | `cellar_additions.vessel_id` | ✅ | ⬜ | New `cellar_additions.vessel_id`; legacy import back-fills from note |
| F5 | Stock check blocks save | `CA:1197` | `AddCellarAdditionAction` (one rule) | ✅ | ⬜ | One rule for every addition path |
| F6 | Delete addition restores stock | `CA:1265` | `DeleteCellarAdditionAction` | ✅ | ⬜ | |
| F7 | Edit addition | — | — | ⬜ | ⬜ | Added (re-applies the stock delta) |
| F8 | Bulk additions page: product, dose + unit (g/L, g/hL, mg/L, mg/hL, mL/L, mL/hL, kg/hL), unit conversion, note | `P/bulk-additions`, `C/bulk-additions.tsx` | `BulkAddAdditionsAction` | ✅ | ⬜ | |
| F9 | Lot table: filters (status, grape, search), select all, per-lot amount/cost/vessel; summary; success screen | `C/bulk-additions.tsx` | — | ✅ | ⬜ | Stock shortfall blocks |
| F10 | Products list: category chips, stats (total, low stock, value) | `components/harvest/enological-products.tsx` | `EnologicalProductController::index` | ✅ | ⬜ | |
| F11 | Create/edit product incl. packaging, cost per package → per unit, min stock, manufacturer, supplier/price-list, SO₂ uplift + helper | `enological-products.tsx:108` | `Create/UpdateEnologicalProductAction` | ✅ | ⬜ | |
| F12 | Add/remove stock | `HA:1602` | `AdjustEnologicalStockAction` | ✅ | ⬜ | Note saved |
| F13 | Stock movement history | — | — | ⬜ | ⬜ | Added (prototype lost this) |
| F14 | Delete product (deactivate if used) | `HA` | `DeleteEnologicalProductAction` | ✅ | ⬜ | |
| F15 | Bulk import (paste TSV/CSV) | `enological-products.tsx` | — | ⬜ | ⬜ | |
| F16 | Bulk edit | `enological-products.tsx` | — | ⬜ | ⬜ | |
| F17 | Protocol needs / deficits | `getProtocolNeeds` | — | ⬜ | ⬜ | |
| F18 | Generate draft purchase orders by supplier | `generateDraftPurchaseOrders` | supplier-order Actions | ⬜ | ⬜ | Reuse existing supplier order creation |

## G. Lab analyses

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| G1 | Analysis form (collapsed): date, vessel, free/total SO₂, alc, pH, TA, VA, sugar, GF, malic, lactic, TPI, Brix (+Baumé), temp, density, note | `C/analysis-form.tsx`, `CA:1431` | `AddCellarAnalysisAction` (14 params) | ✅ | ⬜ | |
| G2 | Comma decimal input; validation (non-negative, alc ≤100, pH ≤14) | `lib/validators.ts` | FormRequest | ✅ | ⬜ | |
| G3 | Brix ↔ Baumé converter | `harvest/brix-baboo-input.tsx` | — | ✅ | ⬜ | |
| G4 | Edit analysis | `CA:1536` | `UpdateCellarAnalysisAction` | ✅ | ⬜ | The vessel can be changed |
| G5 | Delete analysis | `C/editable-analysis-list.tsx` | `DeleteCellarAnalysisAction` | ✅ | ⬜ | |
| G6 | Parameter trends: chart per selected parameter, all vessels (daily avg) or one vessel, min/max, fewer than 2 readings note | `C/charts/parameter-trends.tsx`, `CA:2639` | `LotAnalysisTrendQuery` | ✅ | ⬜ | Hand-written SVG (`AreaChart`) |
| G7 | Bulk analysis entry | `P/bulk-analysis` (photo) | `BulkAddAnalysesAction` | ✅ | ⬜ | Typed grid + CSV/paste; saves vessel and lab date |
| G8 | Photo scan → fields (single and bulk) | `api/parse-analysis`, `api/bulk-analysis` | — | — | — | **Deferred** (out of parity scope) |
| G9 | Bottle analysis on inventory item | `inventory/bottle-analysis-panel.tsx` | Inventory module | — | — | Tracked in the inventory matrix |

## H. Transfers & racking

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| H1 | Transfer form: type Rack/Blend/Split, volume, "this vessel" shortcut, from/to vessel (free space), note | `C/transfer-form.tsx`, `CA:1616` | `CreateTransferAction` | ✅ | ⬜ | Volume capped by the **source vessel**; Blend/Split offer a destination-lot picker |
| H2 | Same-lot transfer needs two different vessels; capacity check | `CA:1616` | `CreateTransferAction` | ✅ | — | |
| H3 | Transfers list in/out (direction, other lot link, type, date, user, note, ±L) | `P/lots/[id]` | `WineLotData.transfers` | ✅ | ⬜ | |
| H4 | Delete transfer reverses volumes | `CA:2374` | `DeleteTransferAction` | ✅ | ⬜ | Also reverses grape % on BLEND |

## I. Blending — `/cellar/blend`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| I1 | Blend by vessels: source vessels grouped by room, filter | `C/vessel-blend.tsx` | `ListVesselsQuery` | ✅ | ⬜ | |
| I2 | Source row: lot L, capacity, latest SO₂/pH/alc; All/Half; over-volume warning | `C/vessel-blend.tsx` | `VesselData` | ✅ | ⬜ | |
| I3 | Destination vessel (free space or in-place); blend name | `C/vessel-blend.tsx` | `ExecuteBlendRequest` | 🟡 | ⬜ | Support in-place destination |
| I4 | Execute → new BLEND lot (BLEND-YYMMDD-n, AGING, type, default name, latest vintage, grape %) | `CA:1752 executeBlend` | `BlendService`, `ExecuteBlendAction` | 🟡 | ⬜ | BLEND-YYMMDD numbering; **carry volume-weighted cost** |
| I5 | Projected blend by lot ratios: add lots, ratio %, distribute evenly, must total 100 | `C/blend-projection.tsx` | — (client side) | ✅ | ⬜ | |
| I6 | Target volume or vessel capacity; breakdown bar | `C/blend-projection.tsx` | — | ✅ | ⬜ | |
| I7 | Projected analysis + grading (optimal/watch/concern) + projected type | `C/blend-projection.tsx` | — | ⬜ | ⬜ | pH computed through [H⁺], not averaged |
| I8 | Print blend sheet | `C/blend-projection.tsx` | — | ⬜ | ⬜ | |
| I9 | Execute projection (pull from lots' vessels oldest-first) | `C/blend-projection.tsx` | `BlendService` | ⬜ | ⬜ | Map ratios → vessel sources server-side |

## J. Planned work & Today — `/cellar/plan`, `/cellar/today`

Backend shipped in Phase 1a: `app/Actions/Cellar/Activities/*`, `CellarTodayQuery`, `CellarPlanQuery`, API under `cellar/activities*`, `cellar/activity-rounds/*`, `cellar/so2-rounds`. Round cards land on the default board (`work_order_boards.is_default`, legacy flag imported). Completion runs through the manual-entry Actions and is idempotent (row lock, PLANNED only).

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| J1 | Data model: activities (type, status, payload, planned_for, sort, round, assignee, skip reason, deviated, applied_at, planned_by, executed_by) + rounds (name, work order) | `prisma/schema.prisma:623,656` | `cellar_activities`, `cellar_activity_rounds` | ✅ | — | `assigned_to_id` is a real FK |
| J2 | Legacy import of 11 activities + 3 rounds | — | `ProductionStep::activityRounds/activities` | ✅ | — | |
| J3 | Planner type chips: Addition, Analysis, Transfer, Process, Topping, Vineyard | `C/cellar-activities-planner.tsx` | — | ✅ | ⬜ | |
| J4 | Scope: one lot (+ its vessels) or whole cellar (spatial vessel picker: rooms, search, select per room, rubber band, fullscreen) | `C/vessel-map-picker.tsx` | — | ✅ | ⬜ | |
| J5 | Addition fields: product search with stock or free name; dose per hL / per L / per vessel / total split by volume | planner | — | ✅ | ⬜ | |
| J6 | Analysis fields: which readings to take | planner | — | ✅ | ⬜ | |
| J7 | Transfer fields: from/to vessel and litres | planner | — | ✅ | ⬜ | |
| J8 | Process kind / Topping litres / Vineyard task + parcels | planner | — | ✅ | ⬜ | Topping requires a source lot/vessel (no wine from nowhere) |
| J9 | Common: date, who (anyone/me/member), note, round name (auto) | planner | — | ✅ | ⬜ | |
| J10 | Named round mirrors a work-order card on the default board | `CAA:52` | — | ✅ | ⬜ | |
| J11 | Open plan grouped by day → round; move round (date + reason); delete round (keeps executed); delete single (not when done) | `P/activities` | — | ✅ | ⬜ | |
| J12 | Recently executed list with deviated / skipped badges | `P/activities` | — | ✅ | ⬜ | |
| J13 | Today: due today + overdue + done/skipped today; Mine/Everyone | `C/cellar-today.tsx` | — | ✅ | ⬜ | |
| J14 | Round progress bars; grouped by room, walking order by vessel name; overdue badge; assignee chip | `C/cellar-today.tsx` | — | ✅ | ⬜ | |
| J15 | ✓ Done as planned | `CAA:356` | — | ✅ | ⬜ | Dispatches to the same Actions as manual forms |
| J16 | Adjust (quantity/litres) → marks deviated | `CAA:356` | — | ✅ | ⬜ | |
| J17 | Analysis card "Enter" keypad for readings | `C/cellar-today.tsx` | — | ✅ | ⬜ | |
| J18 | Skip: Tomorrow / +3 days / date, or Not needed / Other | `skipCellarActivity` | — | ✅ | ⬜ | Reasons translated, not hard-coded Croatian |
| J19 | Completed-today list; "All done!" empty state | `C/cellar-today.tsx` | — | ✅ | ⬜ | |
| J20 | Round card closes when nothing left open | `CAA:356` | — | ✅ | ⬜ | Two-way: completing the card prompts to complete activities |

## K. Tastings — `/cellar/tastings`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| K1 | Lot/vessel tasting note: date, score 0–100, vessel, appearance, nose, palate, overall, note | `C/tasting-note-form.tsx` | `AddTastingNoteAction` | ✅ | ⬜ | |
| K2 | Delete note | | `DeleteTastingNoteAction` | ✅ | ⬜ | |
| K3 | Edit note | `updateCellarTastingNote` | — | ⬜ | ⬜ | |
| K4 | Reports list: title, date, wine count, first 4 wines, author, empty state | `P/tastings`, `actions/tastings.actions.ts` | `TastingReportController::index` | 🟡 | ⬜ | Add wines + author to the payload |
| K5 | New report: title, date, note, search-to-add wines (any non-archived lot), per-wine note + score | `C/tasting-report-form.tsx` | `TastingReportController::store` | 🟡 | ⬜ | Move to an Action + DTO |
| K6 | Report detail: date, author, count, average score, per-wine cards linking to lot | `P/tastings/[id]` | — | ⬜ | ⬜ | |
| K7 | Edit report (syncs lot notes: add/update/remove) | `P/tastings/[id]/edit` | — | ⬜ | ⬜ | |
| K8 | Delete report (removes its lot notes) | `tastings.actions.ts` | — | ⬜ | ⬜ | |
| K9 | Each wine in a report becomes a lot note | `tastings.actions.ts` | `TastingReportController::store` | ✅ | — | |

## L. Bottling

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| L1 | Bottle a lot: count, size 375/750/1500/3000, date, note; volume check | `C/bottling-form.tsx` (unused), `CA:2100` | `CreateBottlingAction` | ✅ | ⬜ | Prototype had **no screen**; added on the lot |
| L2 | Link existing inventory item → PRODUCTION_IN movement | `CA:2100` | `CreateBottlingAction` (`StockLedger`) | ✅ | ⬜ | |
| L3 | Create a new inventory item (name, SKU, size, price, bottles or cases, per case) | `CA:2100` | — | ⬜ | ⬜ | |
| L4 | Delete bottling reverses stock + lot status | `CA:2453` | `DeleteBottlingAction` | ✅ | ⬜ | Restores the vessels the wine came from |

## M. Fermentation protocols & monitoring

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| M1 | Protocol list + create/edit (stages: days, temp range, actions with type/product/dose/unit/timing) | `harvest/fermentation-templates.tsx` | `Create/UpdateFermentationTemplateAction` | ✅ | ⬜ | |
| M2 | Quick-start "Standard Red/White" templates | same | — | ✅ | ⬜ | Client-side presets |
| M3 | Duplicate template | same | `CreateFermentationTemplateAction` | ✅ | ⬜ | |
| M4 | Timeline view of stages | same | — | ✅ | ⬜ | |
| M5 | Structured additive doses (per_L/per_hL/per_kg/per_100kg) | `protocol-doses-editor.tsx`, `final-plan.actions.ts:86` | `protocol_additives`, template `additives[]` | ✅ | ⬜ | Needed by D27 |
| M6 | Delete (deactivate if used) | | `DeleteFermentationTemplateAction` | ✅ | ⬜ | |
| M7 | Fermentation monitor alerts (stuck, temp, VA, pH, MLF/ferment complete, stale) | `api/fermentation-monitor` (unrendered) | `FermentationMonitorQuery` | 🟡 | ⬜ | Rendered as an `AttentionBand` (prototype never showed it) |

## N. Analytics & costs

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| N1 | KPIs: wine in cellar, ready to bottle, avg cost/L (admin), est. bottles, overdue tasks | `P/analytics` | `CellarAnalyticsQuery` | 🟡 | ⬜ | |
| N2 | KPIs: needs SO₂ (≥30 d), needs analysis (≥30 d), vessel utilisation, empty vessels | `P/analytics` | — | ⬜ | ⬜ | Needs-SO₂ counts category SO2 **and** SULFITE |
| N3 | 12-month in/out timeline | `C/charts` | — | ⬜ | ⬜ | |
| N4 | Pipeline by status; volume by type; by vintage; grape mix | `C/charts` | `CellarAnalyticsQuery` | ✅ | ⬜ | |
| N5 | Cost waterfall top 10 (admin) | `C/charts` | `CellarCostsQuery` | 🟡 | ⬜ | |
| N6 | Lot health board (VA >0.7, free SO₂ <20, analysis age, SO₂ age) | `P/analytics` | `FermentationMonitorQuery` | 🟡 | ⬜ | |
| N7 | Costs page (admin): per lot grape + additions + linked costs, cost/L, per bottle, margin vs selling price | `P/costs`, `wine-cost-breakdown.tsx`, `api/wine-costs` | `CellarCostsQuery` | ✅ | ⬜ | Same cost/L as the lot page |
| N8 | Costs totals: COGS, average, cheapest, most expensive | `wine-cost-breakdown.tsx` | `CellarCostsQuery` | ✅ | ⬜ | |
| N9 | Costs filters (vintage, status) + expandable rows | same | — | ✅ | ⬜ | |
| N10 | Bulk wine drift between cellar and inventory (`produceItem` never drains lot) | `inventory.actions.ts:311` | Production module | ✅ | — | Rebuild drains through `LotVolumeService` — verify |

## O. Compliance & records

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| O1 | Cellar book event log (intake, press, vessel, addition, transfer, bottling) + CSV | `harvest/harvest-compliance.tsx` | — | ⬜ | ⬜ | Include **all** lots (blends, manual), processes, analyses and losses; CSV escaped |
| O2 | EU total-SO₂ compliance (red 150/200, white/rosé/orange 200/250 by sugar, sparkling 185) | `checkSO2Compliance` | `WineCompliance::checkSo2`, board `compliance`, `POST cellar/calculators` | ✅ | ⬜ | Uses `wine_type` enum (no ROSE/ROSÉ mismatch) |
| O3 | Traceability vineyard → harvest → lot → vessel → bottle | same | — | ⬜ | ⬜ | |
| O4 | Enological calculators (SO₂ + molecular, acid, general addition, chaptalisation) | `harvest/enological-calculators.tsx` (unrendered) | `So2Calculator`, `EnologicalCalculator`, `POST cellar/calculators` | ✅ | ⬜ | Rendered as a Calculators drawer |

## P. Work-order integration

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| P1 | Work order linked to wine lot and vessel | `work-order-form.tsx` | `work_orders.wine_lot_id/vessel_id` | ✅ | 🟡 | Add a wine-lot `Combobox` to `TaskPanel` |
| P2 | Protocol-generated tasks visible on board | `HA:2196` (board-less) | `GenerateWorkOrdersFromProtocolAction` | 🟡 | ⬜ | Assign to default board/list |
| P3 | Cellar round cards on board | `CAA:52` | `CreateCellarActivitiesAction` → default board | ✅ | ⬜ | Depends on J |

## Q. Audit logging

The old app wrote no audit trail for cellar work. The new app already has the
plumbing: `App\Services\Audit\Auditable` (a model trait that logs every
create/update/delete), `AuditLogger::record()` for richer hand-named entries,
and the tenant **Logs** page (`Web\LogController`). No cellar model uses any
of it yet.

Audit coverage is part of the parity gate for every cellar write path. Two
rules apply:

- **Trait as the floor.** Every cellar model gets `Auditable`. Each model's
  `auditCreateSnapshot()` returns its identifying fields: lot number, vessel
  name, volume, product, quantity.
- **Named entries for multi-row operations.** Operations that touch several
  rows also write one named `AuditLogger` entry that carries the whole story
  in its metadata. Examples: a blend, a bottling, a bulk addition, an SO₂
  round, completing a planned activity. The generic per-row update entry for
  the derived fields (`current_volume`, `current_stock`, `status`) is
  suppressed with `auditIgnoreOnUpdate()`, so the trail doesn't carry the
  same change twice.

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| Q1 | `Auditable` on Vessel, WineLot, WineLotGrape, VesselLot, CellarAnalysis, CellarAddition, CellarProcess, CellarTastingNote, TastingReport, CellarTransfer, Bottling, EnologicalProduct, FermentationTemplate, CellarActivity, CellarActivityRound | — | `Services/Audit/Auditable` | 🟡 | — | Create snapshot = identifying fields; derived volume/stock/status fields ignored on update |
| Q2 | Named entries for lot volume: `wine_lot.volume_adjusted` (vessel, ±L, reason), `wine_lot.assigned_to_vessel` / `unassigned_from_vessel`, `vessel.layout_updated` (count), `vessel.room_renamed` | — | `AuditLogger::record` in the Actions | ⬜ | — | |
| Q3 | Named entries for blends and transfers: `wine_lot.blended` (sources, L, destination, new lot), `wine_lot.blended_in`, `cellar_transfer.created/reversed` (from/to lot+vessel, L, type) | — | `BlendService`, transfer Actions | ⬜ | — | |
| Q4 | Named entries for bottling: `bottling.created/reversed` (bottles, size, L, inventory item, stock movement ref) | — | bottling Actions | ⬜ | — | |
| Q5 | Named entries for stock: `enological_product.stock_adjusted` (delta, before→after, reason, source: addition / bulk / SO₂ / manual / import) | — | addition + stock Actions | ⬜ | — | Every stock change is traceable to its cause |
| Q6 | Named entries for bulk operations: `cellar.bulk_additions_applied`, `cellar.bulk_analyses_recorded`, `cellar.so2_round_planned` (lot/vessel ids, dose, product) | — | `cellar.so2_applied`, `cellar.so2_round_planned`, `cellar.bulk_analyses_recorded` (bulk additions page still to do) | 🟡 | — | One summary entry plus per-row trait entries |
| Q7 | Named entries for planned work: `cellar_activity.completed` (deviated, planned vs actual), `skipped` (reason), `rescheduled` (from→to, reason), `cellar_activity_round.deleted` | — | Activities actions (`cellar_activity.*`, `cellar_activity_round.*`) | ✅ | — | |
| Q8 | Named entries for protocols: `wine_lot.protocol_assigned`, `wine_lot.protocol_tasks_generated` (count, day), `wine_lot.protocol_additions_planned` | — | protocol Actions | ⬜ | — | |
| Q9 | Cellar entries readable in the tenant Logs page (subject filter "Cellar", subject links to lot/vessel) | — | `Web\LogController`, `pages/Logs` | 🟡 | ⬜ | |
| Q10 | "History" panel on lot and vessel detail (audit entries for that subject) | — | `AuditLog` by subject | ⬜ | ⬜ | Who changed what, beside the lot timeline |
| Q11 | Tests: every cellar write Action asserts its audit entry (action, actor, subject, key metadata) | — | `tests/Feature/Cellar` | ⬜ | — | |

---

## Correctness fixes applied in the port (summary)

1. **One stock rule.** Every addition path (single, bulk, SO₂, planned) blocks
   on insufficient stock. The prototype only checked the single form.
2. **Additions record their vessel** in a column, not in the note text. The
   map projection and the vessel filter read that column.
3. **Volume adjustments keep their reason** as a Loss/Gain process.
4. **Topping draws from a source lot or vessel.** Wine is never created from
   nowhere.
5. **Blends inherit volume-weighted cost.**
6. **Editing a lot keeps grape percentages** and harvest links.
7. **Protocol tasks and round cards always land on a board.**
8. **One cost-per-litre definition** (`LotCostService`) is used on the lot,
   costs, bottling and analytics screens.
9. **Projected blend pH** is computed through [H⁺].
- **SO₂ doses for liquid products need an uplift coefficient.** The K₂S₂O₅ fallback only applies to g/kg products; the prototype put grams into ml products.
10. **Transfers are capped by the source vessel's volume**, not the lot's.
11. **Deleting a lot or a bottling reverses its stock movements.**
12. **The cellar book covers every lot**, not only harvest-linked ones, and its
    CSV export is escaped.
13. **Every cellar write is audited.** The prototype kept no audit trail (see Q).
14. **Strings are translated.** Hard-coded Croatian strings move to `hr.json`.

## Sign-off

| Step | Owner | Status |
|---|---|---|
| Matrix reviewed | product | ⬜ |
| Backend gaps closed (all BE ✅) | eng | ⬜ |
| Screens shipped (all UI ✅) | eng | ⬜ |
| Side-by-side walkthrough on imported tenant data | product + eng | ⬜ |
| Phase 3 experience proposals approved | product | ⬜ |
