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
- **UI** — Inertia screen. ✅ shipped (page and component named) · 🟡 partial
  · ⬜ missing · — no screen needed.
- **Fix** — the correctness change applied while porting.

**Abbreviations**

- `CA` = `actions/cellar.actions.ts`
- `CAA` = `actions/cellar-activity.actions.ts`
- `HA` = `actions/harvest.actions.ts`
- `C/` = `components/cellar/`
- `P/` = `app/dashboard/cellar/`

## Summary

| Area | Rows | BE ✅ | BE 🟡/⬜ | BE n/a | UI ✅ | UI n/a |
|---|---|---|---|---|---|---|
| A. Cellar home | 8 | 8 | 0 | 0 | 8 | 0 |
| B. Cellar map | 22 | 22 | 0 | 0 | 22 | 0 |
| C. Vessels | 11 | 11 | 0 | 0 | 10 | 1 |
| D. Wine lots | 30 | 29 | 0 | 1 | 27 | 3 |
| E. SO₂ | 14 | 14 | 0 | 0 | 14 | 0 |
| F. Additions & enological stock | 18 | 18 | 0 | 0 | 18 | 0 |
| G. Lab analyses | 9 | 7 | 0 | 2 | 7 | 2 |
| H. Transfers & racking | 4 | 4 | 0 | 0 | 3 | 1 |
| I. Blending | 9 | 8 | 0 | 1 | 9 | 0 |
| J. Planned work & Today | 20 | 20 | 0 | 0 | 18 | 2 |
| K. Tastings | 9 | 9 | 0 | 0 | 8 | 1 |
| L. Bottling | 4 | 4 | 0 | 0 | 4 | 0 |
| M. Fermentation protocols & monitoring | 7 | 7 | 0 | 0 | 7 | 0 |
| N. Analytics & costs | 10 | 10 | 0 | 0 | 9 | 1 |
| O. Compliance & records | 4 | 4 | 0 | 0 | 4 | 0 |
| P. Work-order integration | 3 | 3 | 0 | 0 | 3 | 0 |
| Q. Audit logging | 11 | 11 | 0 | 0 | 2 | 9 |
| **Total** | **193** | **189** | **0** | **4** | **173** | **20** |

---

## A. Cellar home — `/cellar`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| A1 | Header with "+ Wine Lot" and quick links (Costs admin-only, Today, Plan, Analytics, SO₂, Tastings, Blend, Bulk additions, Bulk analysis, Products, + Vessel) | `P/page.tsx` | — (navigation only) | ✅ | ✅ `CellarTabs` + header actions | Replaced by a `CellarTabs` sub-nav plus header actions |
| A2 | Stat cards: active vessels, active lots, total volume (non-bottled) | `P/page.tsx` | `CellarAnalyticsQuery` | ✅ | ✅ `Cellar/Index` | |
| A3 | Embedded cellar map (see B) | `C/cellar-map.tsx` | `ListVesselsQuery` | ✅ | ✅ `Cellar/Index` (`CellarMap`) | |
| A4 | Active lots grouped by vintage (newest first) with count and litres | `C/wine-lots-by-vintage` in `P/page.tsx` | `ListWineLotsQuery` (`exclude_bottled`) | ✅ | ✅ `Cellar/Index` | |
| A5 | Lot row: lot, grape, status pill, last analysis (date, pH, alc, free/total SO₂), last addition (name, qty, unit, date), volume current/initial | `P/page.tsx` | `WineLotData` (`latestAnalysis`, `latestAddition`) | ✅ | ✅ `Cellar/Index` | |
| A6 | Mobile card layout of the same rows | `P/page.tsx` | — | ✅ | ✅ `Cellar/Index` (mobile cards) | |
| A7 | Collapsible "Bottled wines" archive by vintage: last bottling (count → item), counts of additions, analyses and bottlings | `P/page.tsx` | `ListWineLotsQuery` `with_counts` (counts + latest bottling) | ✅ | ✅ `Cellar/Index` (bottled archive) | Add `withCount` to the query |
| A8 | Role landing: CELLAR users redirected from dashboard to cellar | `app/dashboard/page.tsx:120` | `Web\DashboardController` redirects cellar-only members (active once `/cellar` route ships) | ✅ | ✅ `/cellar` route (landing redirect) | |

## B. Cellar map

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| B1 | Room tabs | `C/cellar-map.tsx` | `ListVesselsQuery` (room) | ✅ | ✅ `CellarMap` | |
| B2 | Reorder room tabs by drag (saved per browser) | `C/cellar-map.tsx` | — (localStorage) | ✅ | ✅ `CellarMap` (drag tabs, saved per browser) | |
| B3 | Rename room inline (Esc cancels) | `CA:2511 renameCellarRoom` | `RenameCellarRoomAction`, `POST vessels/rename-room` | ✅ | ✅ `CellarMap` | |
| B4 | Vessel tiles shaped by type (tank legs/dome, barrel hoops) | `C/cellar-map.tsx` | — | ✅ | ✅ `CellarMap` | |
| B5 | Fill bar by capacity %, coloured by wine type or grape | `C/cellar-map.tsx`, `lib/cellar-colors` | `VesselData.lots` | ✅ | ✅ `CellarMap` | |
| B6 | Faulty overlay (red + warning icon) | `C/cellar-map.tsx` | `vessels.is_faulty` | ✅ | ✅ `CellarMap` | |
| B7 | SO₂ badge (free/total), vessel reading preferred over lot reading | `C/cellar-map.tsx` | `VesselData` latest SO₂ | ✅ | ✅ `CellarMap` | |
| B8 | Hover tooltip (type, material, capacity, fill, lots and litres, SO₂, projected SO₂ after latest addition) | `C/cellar-map.tsx` | `VesselDetailQuery::so2` (vessel reading, latest addition, projected via uplift) | ✅ | ✅ `CellarMap` | Projection uses `cellar_additions.vessel_id` and the product uplift, **not** regex on the note |
| B9 | Click: vessel with wine → lot (`?vessel=`); empty vessel → vessel page | `C/cellar-map.tsx` | — | ✅ | ✅ `CellarMap` | |
| B10 | Edit mode lock/unlock | `C/cellar-map.tsx` | — | ✅ | ✅ `CellarMap` | |
| B11 | Drag to move; multi-select with shift/⌘; lasso; group move | `C/cellar-map.tsx` | `UpdateVesselLayoutAction` | ✅ | ✅ `CellarMap` | |
| B12 | Corner resize, including several vessels at once | `C/cellar-map.tsx`, `CA bulkUpdateVesselSize` | `UpdateVesselLayoutAction` | ✅ | ✅ `CellarMap` | |
| B13 | Mass resize W/H, "Match size" | `C/cellar-map.tsx` | `UpdateVesselLayoutAction` | ✅ | ✅ `CellarMap` | |
| B14 | Duplicate N (next free number, same padding, offset placement) | `CA:254 duplicateVessels` | `DuplicateVesselsAction`, `POST vessels/duplicate` | ✅ | ✅ `CellarMap` | |
| B15 | Remove from map (clear position only) | `CA removeVesselFromMap` | `UpdateVesselLayoutAction` (null position, tested) | ✅ | ✅ `CellarMap` | Confirm null positions are accepted |
| B16 | Undo ⌘/Ctrl+Z (20 steps), select/deselect all, autosave | `C/cellar-map.tsx` | — | ✅ | ✅ `CellarMap` | |
| B17 | "Unplaced" sidebar of vessels without a position | `C/cellar-map.tsx` | `ListVesselsQuery` | ✅ | ✅ `CellarMap` | |
| B18 | Quick bulk-add vessels (prefix, start #, count 1–50, type, material, capacity, name preview) | `CA:214 bulkCreateVessels` | `BulkCreateVesselsAction` | ✅ | ✅ `BulkVesselsPanel` | BARRIQUE valid everywhere |
| B19 | Assign wine to selected vessels: existing (≤ unallocated) or new wine (grows lot); fill to capacity or split equally; preview | `CA:1085 bulkAssignLotToVessels` | `BulkAssignLotToVesselsAction`, `POST wine-lots/{lot}/vessels/bulk` (fill or split, dry run) | ✅ | ✅ `AssignWinePanel` (server dry-run preview) | |
| B20 | Unassign one row / Remove all | `CA unassignLotFromVessel` | `UnassignLotFromVesselAction` | ✅ | ✅ `UnassignWinePanel` | |
| B21 | Fullscreen, zoom buttons, mobile pinch-zoom, sidebar toggle | `C/cellar-map.tsx` | — | ✅ | ✅ `CellarMap` zoom buttons, two-finger pinch zoom, fullscreen, Unplaced sidebar toggle | |
| B22 | Errors surfaced | `alert()` | — | ✅ | ✅ flash + inline field errors | Inline flash / `InputError`, never `alert()` |

## C. Vessels

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| C1 | Create vessel: name, type (Barrel/Barrique/Tank/Vat/Amphora), material, capacity, room (suggest existing), location, notes | `P/vessels/new`, `C/vessel-form.tsx`, `CA:74` | `CreateVesselAction`, `StoreVesselRequest` | ✅ | ✅ `VesselFormPanel` | BARRIQUE passes validation (prototype rejected it) |
| C2 | Edit vessel | `CA:99` | `UpdateVesselAction` | ✅ | ✅ `VesselFormPanel` | |
| C3 | Vessel detail header (type icon, material, location) | `P/vessels/[id]` | `VesselController::show` | ✅ | ✅ `Cellar/Vessels/Show` | |
| C4 | Mark faulty / clear fault with optional note | `C/vessel-fault-toggle.tsx`, `CA:885` | `UpdateVesselAction` (`is_faulty`, `fault_note`) | ✅ | ✅ `Cellar/Vessels/Show` | |
| C5 | Delete (click-twice confirm) | `C/delete-vessel-button.tsx`, `CA:126` | `DeleteVesselAction` (refuses while holding wine) | ✅ | ✅ `Cellar/Vessels/Show` | Refuse instead of hiding a vessel that still holds wine |
| C6 | Overview: capacity bar, latest SO₂ for vessel, contents (lot, litres, link) | `P/vessels/[id]` | `VesselData` | ✅ | ✅ `Cellar/Vessels/Show` | |
| C7 | Assign lot: lot picker with allocated/unallocated, volume ≤ min(free, lot) | `C/assign-lot-form.tsx`, `CA:964` | `AssignLotToVesselAction` | ✅ | ✅ `AssignWinePanel` from the vessel page | Never silently take excess from other vessels |
| C8 | Analysis tab: new analysis per lot in the vessel; last 10 analyses, editable | `C/editable-vessel-analysis-list.tsx` | `VesselDetailQuery` analyses (last 10 in vessel) | ✅ | ✅ `Cellar/Vessels/Show` | Needs a per-vessel analyses read |
| C9 | Tasting tab: per-vessel notes (last 20), editable | `P/vessels/[id]` | `VesselDetailQuery` tasting notes (last 20 in vessel) | ✅ | ✅ `Cellar/Vessels/Show` | Needs note update (K3) |
| C10 | Details tab: vessel form | `C/vessel-form.tsx` | `UpdateVesselAction` | ✅ | ✅ `Cellar/Vessels/Show` | |
| C11 | Status sync (AVAILABLE ↔ IN_USE) | `CA:50 syncVesselStatus` | `VesselVolumeSync` | ✅ | — | MAINTENANCE/RETIRED never overridden |

## D. Wine lots

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| D1 | Lots list (search, status filter) | `P/page.tsx` (no dedicated page) | `ListWineLotsQuery` | ✅ | ✅ `Cellar/Lots/Index` | Dedicated `/cellar/lots` page added (rebuild improvement) |
| D2 | Create lot: name, grape rows (variety suggestions, €/kg admin-only, kg), wine type, vintage, vineyard, initial volume, stage, starting vessel, notes | `P/lots/new`, `C/wine-lot-form.tsx`, `CA:376` | `CreateWineLotAction`, `StoreWineLotRequest` | ✅ | ✅ `LotFormPanel` | Starting vessel capacity-checked |
| D3 | Lot number LOT-YYYY-NNN; grape cost = Σ price×kg; weighted €/kg | `CA:376` | `LotNumberGenerator`, `CreateWineLotAction` | ✅ | — | |
| D4 | Edit lot (Details tab) | `CA:490` | `UpdateWineLotAction` + `GrapeComposition` (rows matched by variety) | ✅ | ✅ `LotFormPanel` (edit) | Keep `percentage` and `harvest_entry_id` on grape rows |
| D5 | Set status BOTTLED frees vessels | `CA:26` | `UpdateWineLotAction` | ✅ | ✅ `Cellar/Lots/Show` | |
| D6 | Delete lot (click-twice); restores vessels; removes transfers/bottlings; cleans mirror inventory item | `CA:2319` | `DeleteWineLotAction`, `DELETE wine-lots/{lot}` (cellar.delete) | ✅ | ✅ `Cellar/Lots/Show` | Reverse bottling stock movements on delete |
| D7 | Header: name, status, type, lot no., grape, vintage, vineyard | `P/lots/[id]` | `WineLotData` detail | ✅ | ✅ `Cellar/Lots/Show` | |
| D8 | `?vessel=` focus banner ("Viewing vessel X — N L · Show all"); filters additions and analyses | `P/lots/[id]` | — | ✅ | ✅ `Cellar/Lots/Show` | Filter additions by `vessel_id` |
| D9 | Blend provenance (incoming BLEND transfers by source lot, L and %) | `P/lots/[id]` | `WineLotData.transfers` | ✅ | ✅ `Cellar/Lots/Show` | |
| D10 | Quick-action buttons (+Addition/+Analysis/+Transfer/+Process), hidden once bottled | `C/lot-detail-tabs.tsx` | — | ✅ | ✅ `Cellar/Lots/Show` | |
| D11 | Volume card (L vs capacity of vessels or initial; focused-vessel L; vessel links) | `P/lots/[id]` | `WineLotData` | ✅ | ✅ `Cellar/Lots/Show` | |
| D12 | Adjust volume ± (vessel picker if several; cap removal per vessel; proportional when no vessel) | `C/adjust-volume-form.tsx`, `CA:588` | `AdjustLotVolumeAction` (reason → Loss/Gain process, proportional, per-vessel cap) | ✅ | ✅ `VolumePanel` (adjust) | Reason **saved** as `CellarProcess` Loss/Gain; proportional split |
| D13 | Assign to vessel (searchable, shows current content) | `C/assign-vessel-form.tsx` | `AssignLotToVesselAction` | ✅ | ✅ `VolumePanel` (assign) | |
| D14 | Blend in another vessel's wine into this lot (keeps identity, recomputes grape %) | `C/blend-in-form.tsx`, `CA:735` | `BlendIntoLotAction`, `POST wine-lots/{lot}/blend-in` (`carried_cost`) | ✅ | ✅ `VolumePanel` (blend in) | Capacity checked; cost carried volume-weighted |
| D15 | Cost breakdown (admin): €/kg, kg, yield %, grape, additions, linked costs, total, per L, per 750 ml | `P/lots/[id]` | `LotCostService::breakdown` (grape + additions + linked `cost_items.wine_lot_id` + `carried_cost`; per L over initial volume) | ✅ | ✅ `Cellar/Lots/Show` | Include linked `CostItem` costs; one cost/L definition |
| D16 | Grape breakdown table (admin, more than one grape) | `P/lots/[id]` | `WineLotData.grapes` | ✅ | ✅ `Cellar/Lots/Show` | |
| D17 | Latest analysis card (latest SO₂ with date/vessel + latest other params) | `P/lots/[id]` | `WineLotData.analyses` | ✅ | ✅ `Cellar/Lots/Show` | All 14 params, not 5 |
| D18 | Lot journey timeline (created, fills, additions, analyses, tastings, transfers, bottlings, processes) | `C/lot-timeline.tsx` | `LotTimelineQuery`, `GET wine-lots/{lot}/timeline` | ✅ | ✅ `Cellar/Lots/Show` | `LotTimelineQuery` |
| D19 | Additions tab: form + planned additions box + table (cost admin) + total + delete | `C/addition-form.tsx` | see F | ✅ | ✅ `Cellar/Lots/Show` + `AdditionPanel` | |
| D20 | Analysis tab: trend chart + form + editable list | `C/charts/parameter-trends.tsx` | see G | ✅ | ✅ `Cellar/Lots/Show` + `AnalysisPanel` (trend `AreaChart`) | |
| D21 | Tasting tab: form + editable list | `C/tasting-note-form.tsx` | `UpdateTastingNoteAction`, `PATCH wine-lots/{lot}/tasting-notes/{note}` | ✅ | ✅ `TastingPanel` | |
| D22 | Transfers tab: form + in/out list | `C/transfer-form.tsx` | see H | ✅ | ✅ `TransferPanel` | |
| D23 | Processes tab: quick-pick chips + custom kind, date, volume, vessel, note; list; delete | `C/process-form.tsx`, `CA:1303` | `AddCellarProcessAction`, `DeleteCellarProcessAction` | ✅ | ✅ `ProcessPanel` (suggested kinds + free text) | |
| D24 | Edit a process | — (prototype has none) | — | — | — | Added for symmetry (`UpdateCellarProcess`) |
| D25 | Protocol card (FERMENTING only): assign template | `C/lot-protocol-actions.tsx`, `HA:2156` | `AssignFermentationTemplateAction` | ✅ | ✅ `ProtocolCard` | |
| D26 | Generate today's tasks from protocol | `HA:2196` | `GenerateWorkOrdersFromProtocolAction` (default board) | ✅ | ✅ `ProtocolCard` | Tasks land on the default board (prototype's were invisible) |
| D27 | Plan protocol additions (per additive per vessel; per_L / per_hL / per_kg / per_100kg; round + board card) | `CAA:515` | `PlanProtocolAdditionsAction`, `POST wine-lots/{lot}/protocol/plan-additions` | ✅ | ✅ `ProtocolCard` | Depends on J |
| D28 | Bottling section on lot (see L) | — | see L | ✅ | ✅ `BottlingPanel` | Prototype had no screen; added |
| D29 | Bottled lots read-only (actions hidden) | `C/lot-detail-tabs.tsx` | — | ✅ | ✅ `Cellar/Lots/Show` | |
| D30 | Harvest intake / press fraction creates or tops up a lot | `HA:388`, `addPressFraction` | Vineyards module | ✅ | — | Tracked in the vineyards matrix |

## E. SO₂ — `/cellar/so2`

Backend shipped in Phase 1b: every dose is computed on the server by `So2DosePlanner` (preview, apply and plan-for-team share it), so what the screen shows is what gets applied.

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| E1 | Load lots (FERMENTING/AGING/READY) + latest free SO₂ per lot+vessel | `P/so2/page.tsx` (raw `DISTINCT ON`) | `CellarSo2BoardQuery`, `GET cellar/so2` (latest per lot+vessel via `LatestAnalyses`) | ✅ | ✅ `Cellar/So2` | `CellarSo2BoardQuery` |
| E2 | SO₂ product list (name match on sulfit/so2/metabisulfit/sumpor; fallback all) | `P/so2/page.tsx` | `CellarSo2BoardQuery::products` (category SO2/SULFITE or name; fallback all) | ✅ | ✅ `Cellar/So2` | Match on category SO2/SULFITE first |
| E3 | Quick free/total SO₂ entry per lot and per vessel; "N entered"; save | `C/so2-management.tsx` | `BulkAddAnalysesAction` | ✅ | ✅ `Cellar/So2` (Update tab, `So2UpdateTable`) | Vessel id saved on each row |
| E4 | Plan mode: status chips Critical <15 / Low 15–24 / OK 25–45 / High >45 / No data | `C/so2-management.tsx` | `So2Calculator::status`, board `summary` | ✅ | ✅ `Cellar/So2` | |
| E5 | Product picker with stock | `C/so2-management.tsx` | `EnologicalProductData` | ✅ | ✅ `Cellar/So2` | |
| E6 | "Set all" mg/L to add; per-row override; expanding a lot copies value to vessels | `C/so2-management.tsx` | `So2DosePlanner` (`mode` add or target), `POST cellar/so2/preview` | ✅ | ✅ `Cellar/So2` | Label honestly as "mg/L to add" (target mode in Phase 3) |
| E7 | Reference targets by wine type (red 28, white 35, rosé 32, orange 25, sparkling 30; +3 oak) | `C/so2-management.tsx` | `So2Calculator::referenceTarget` (+3 oak) | ✅ | ✅ `Cellar/So2` | Shared constant in the calculator service |
| E8 | Filters: status, material, search, "only needing SO₂" | `C/so2-management.tsx` | board fields (status, material/oak, search on client) | ✅ | ✅ `Cellar/So2` | |
| E9 | Row: current free + days since reading, add, projected, dose | `C/so2-management.tsx` | board `days_since`, preview lines | ✅ | ✅ `Cellar/So2` | |
| E10 | Dose calculation: uplift coefficient or K₂S₂O₅ fallback (×1.5), kg conversion | `lib/enological-calculators.ts` | `So2Calculator::doseForProduct` (uplift, K₂S₂O₅ g/kg; other units need uplift) | ✅ | ✅ `Cellar/So2` | Port the fallback and expose it |
| E11 | Summary: total dose, cost, low-stock warning; Select all needing SO₂ | `C/so2-management.tsx` | preview `totals` (`quantity`, `cost`, `short_by`) | ✅ | ✅ `Cellar/So2` | Stock shortfall **blocks** apply |
| E12 | Copy list / WhatsApp share / Print sheet | `C/so2-management.tsx` | preview lines carry the text (`note`); copy/share rendered in the UI | ✅ | ✅ `Cellar/So2` (copy, WhatsApp, `PrintSheet`) | Text translated via `hr.json` |
| E13 | Apply now (bulk additions per vessel) | `CA:1348 bulkAddAdditions` | `ApplySo2Action`, `POST cellar/so2/apply` | ✅ | ✅ `Cellar/So2` | Per-vessel `vessel_id`; stock-checked |
| E14 | Plan for cellar team (SO₂ round) + success screen | `CAA createSo2Round` | `CreateSo2RoundAction` (server doses), `POST cellar/so2-rounds` | ✅ | ✅ `Cellar/So2` | Depends on J |

## F. Additions & enological stock

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| F1 | Single addition: product from inventory (grouped, with stock) or manual entry | `C/addition-form.tsx`, `CA:1197` | `AddCellarAdditionAction` | ✅ | ✅ `AdditionPanel` | |
| F2 | Category from product or presets (SO2, Yeast, Nutrient, Bentonite, …) | `C/addition-form.tsx` | free-text category | ✅ | ✅ `AdditionPanel` | |
| F3 | Dose per hL × lot hL autofill; units list; cost (admin); note | `C/addition-form.tsx` | `StoreCellarAdditionRequest` | ✅ | ✅ `AdditionPanel` | Dose uses the **vessel's** volume when a vessel is chosen |
| F4 | Vessel for an addition | note text `→ VESSEL` | `cellar_additions.vessel_id` | ✅ | ✅ `AdditionPanel` | New `cellar_additions.vessel_id`; legacy import back-fills from note |
| F5 | Stock check blocks save | `CA:1197` | `AddCellarAdditionAction` (one rule) | ✅ | ✅ `AdditionPanel` | One rule for every addition path |
| F6 | Delete addition restores stock | `CA:1265` | `DeleteCellarAdditionAction` | ✅ | ✅ `Cellar/Lots/Show` (delete, stock restored) | |
| F7 | Edit addition | — | `UpdateCellarAdditionAction`, `PATCH wine-lots/{lot}/additions/{addition}` (stock moved under the stock rule) | ✅ | ✅ `AdditionPanel` | Added (re-applies the stock delta) |
| F8 | Bulk additions page: product, dose + unit (g/L, g/hL, mg/L, mg/hL, mL/L, mL/hL, kg/hL), unit conversion, note | `P/bulk-additions`, `C/bulk-additions.tsx` | `BulkAddAdditionsAction` | ✅ | ✅ `Cellar/BulkAdditions` (unit conversion mg/g/kg, mL/L) | |
| F9 | Lot table: filters (status, grape, search), select all, per-lot amount/cost/vessel; summary; success screen | `C/bulk-additions.tsx` | — | ✅ | ✅ `Cellar/BulkAdditions` | Stock shortfall blocks |
| F10 | Products list: category chips, stats (total, low stock, value) | `components/harvest/enological-products.tsx` | `EnologicalProductController::index` | ✅ | ✅ `Cellar/Products` | |
| F11 | Create/edit product incl. packaging, cost per package → per unit, min stock, manufacturer, supplier/price-list, SO₂ uplift + helper | `enological-products.tsx:108` | `Create/UpdateEnologicalProductAction` | ✅ | ✅ `ProductFormPanel` (package → per-unit cost, SO₂ uplift helper) | |
| F12 | Add/remove stock | `HA:1602` | `AdjustEnologicalStockAction` → `EnologicalStockLedger` (note saved) | ✅ | ✅ `Cellar/Products` | Note saved |
| F13 | Stock movement history | — | `enological_stock_movements` via `EnologicalStockLedger`, `GET enological-products/{p}/movements` | ✅ | ✅ `Cellar/Products` (stock history drawer) | Added (prototype lost this) |
| F14 | Delete product (deactivate if used) | `HA` | `DeleteEnologicalProductAction` | ✅ | ✅ `Cellar/Products` | |
| F15 | Bulk import (paste TSV/CSV) | `enological-products.tsx` | `ImportEnologicalProductsAction`, `POST enological-products/import` (TSV/CSV, EN/HR headers, dry run) | ✅ | ✅ `Cellar/Products` (paste import with preview) | |
| F16 | Bulk edit | `enological-products.tsx` | `BulkUpdateEnologicalProductsAction`, `PATCH enological-products/bulk` | ✅ | ✅ `Cellar/Products` (bulk edit drawer) | |
| F17 | Protocol needs / deficits | `getProtocolNeeds` | `ProtocolNeedsQuery`, `GET enological-products/needs` (structured doses × basis) | ✅ | ✅ `Cellar/Products` (Protocol needs tab) | |
| F18 | Generate draft purchase orders by supplier | `generateDraftPurchaseOrders` | `DraftEnologicalPurchaseOrdersAction` → `CreateSupplierOrderAction`, `POST enological-products/draft-orders` | ✅ | ✅ `Cellar/Products` | Reuse existing supplier order creation |

## G. Lab analyses

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| G1 | Analysis form (collapsed): date, vessel, free/total SO₂, alc, pH, TA, VA, sugar, GF, malic, lactic, TPI, Brix (+Baumé), temp, density, note | `C/analysis-form.tsx`, `CA:1431` | `AddCellarAnalysisAction` (14 params) | ✅ | ✅ `AnalysisPanel` | |
| G2 | Comma decimal input; validation (non-negative, alc ≤100, pH ≤14) | `lib/validators.ts` | FormRequest | ✅ | ✅ `AnalysisPanel` | |
| G3 | Brix ↔ Baumé converter | `harvest/brix-baboo-input.tsx` | — | ✅ | ✅ `AnalysisPanel` | |
| G4 | Edit analysis | `CA:1536` | `UpdateCellarAnalysisAction` | ✅ | ✅ `AnalysisPanel` | The vessel can be changed |
| G5 | Delete analysis | `C/editable-analysis-list.tsx` | `DeleteCellarAnalysisAction` | ✅ | ✅ `AnalysisPanel` | |
| G6 | Parameter trends: chart per selected parameter, all vessels (daily avg) or one vessel, min/max, fewer than 2 readings note | `C/charts/parameter-trends.tsx`, `CA:2639` | `LotAnalysisTrendQuery` | ✅ | ✅ `Cellar/Lots/Show` (trend chart per parameter, per vessel or averaged) | Hand-written SVG (`AreaChart`) |
| G7 | Bulk analysis entry | `P/bulk-analysis` (photo) | `BulkAddAnalysesAction` | ✅ | ✅ `Cellar/BulkAnalyses` (grid + paste from the lab sheet) | Typed grid + CSV/paste; saves vessel and lab date |
| G8 | Photo scan → fields (single and bulk) | `api/parse-analysis`, `api/bulk-analysis` | — | — | — | **Deferred** (out of parity scope) |
| G9 | Bottle analysis on inventory item | `inventory/bottle-analysis-panel.tsx` | Inventory module | — | — | Tracked in the inventory matrix |

## H. Transfers & racking

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| H1 | Transfer form: type Rack/Blend/Split, volume, "this vessel" shortcut, from/to vessel (free space), note | `C/transfer-form.tsx`, `CA:1616` | `CreateTransferAction` | ✅ | ✅ `TransferPanel` | Volume capped by the **source vessel**; Blend/Split offer a destination-lot picker |
| H2 | Same-lot transfer needs two different vessels; capacity check | `CA:1616` | `CreateTransferAction` | ✅ | — | |
| H3 | Transfers list in/out (direction, other lot link, type, date, user, note, ±L) | `P/lots/[id]` | `WineLotData.transfers` | ✅ | ✅ `Cellar/Lots/Show` (Transfers tab) | |
| H4 | Delete transfer reverses volumes | `CA:2374` | `DeleteTransferAction` (from the destination vessel; undoes grape mix and carried cost) | ✅ | ✅ `Cellar/Lots/Show` (reverse) | Also reverses grape % on BLEND |

## I. Blending — `/cellar/blend`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| I1 | Blend by vessels: source vessels grouped by room, filter | `C/vessel-blend.tsx` | `ListVesselsQuery` | ✅ | ✅ `Cellar/Blend` | |
| I2 | Source row: lot L, capacity, latest SO₂/pH/alc; All/Half; over-volume warning | `C/vessel-blend.tsx` | `VesselData` | ✅ | ✅ `Cellar/Blend` | |
| I3 | Destination vessel (free space or in-place); blend name | `C/vessel-blend.tsx` | `BlendService` (in-place destination; drawn from each chosen vessel) | ✅ | ✅ `Cellar/Blend` | Support in-place destination |
| I4 | Execute → new BLEND lot (BLEND-YYMMDD-n, AGING, type, default name, latest vintage, grape %) | `CA:1752 executeBlend` | `BlendService` (BLEND-YYMMDD[-n], default name/vintage/type, `carried_cost`) — status stays BLENDED (rebuild choice) | ✅ | ✅ `Cellar/Blend` | BLEND-YYMMDD numbering; **carry volume-weighted cost** |
| I5 | Projected blend by lot ratios: add lots, ratio %, distribute evenly, must total 100 | `C/blend-projection.tsx` | — (client side) | ✅ | ✅ `Cellar/Blend` | |
| I6 | Target volume or vessel capacity; breakdown bar | `C/blend-projection.tsx` | — | ✅ | ✅ `Cellar/Blend` | |
| I7 | Projected analysis + grading (optimal/watch/concern) + projected type | `C/blend-projection.tsx` | `BlendProjector`, `POST wine-lots/blend/project` (pH via [H⁺], grades, composition, cost/L) | ✅ | ✅ `Cellar/Blend` | pH computed through [H⁺], not averaged |
| I8 | Print blend sheet | `C/blend-projection.tsx` | Client-side print of the projection | — | ✅ `Cellar/Blend` (`PrintSheet`) | |
| I9 | Execute projection (pull from lots' vessels oldest-first) | `C/blend-projection.tsx` | `BlendProjector::sources`, `POST wine-lots/blend/by-ratio` (oldest vessel first) | ✅ | ✅ `Cellar/Blend` | Map ratios → vessel sources server-side |

## J. Planned work & Today — `/cellar/plan`, `/cellar/today`

Backend shipped in Phase 1a: `app/Actions/Cellar/Activities/*`, `CellarTodayQuery`, `CellarPlanQuery`, API under `cellar/activities*`, `cellar/activity-rounds/*`, `cellar/so2-rounds`. Round cards land on the default board (`work_order_boards.is_default`, legacy flag imported). Completion runs through the manual-entry Actions and is idempotent (row lock, PLANNED only).

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| J1 | Data model: activities (type, status, payload, planned_for, sort, round, assignee, skip reason, deviated, applied_at, planned_by, executed_by) + rounds (name, work order) | `prisma/schema.prisma:623,656` | `cellar_activities`, `cellar_activity_rounds` | ✅ | — | `assigned_to_id` is a real FK |
| J2 | Legacy import of 11 activities + 3 rounds | — | `ProductionStep::activityRounds/activities` | ✅ | — | |
| J3 | Planner type chips: Addition, Analysis, Transfer, Process, Topping, Vineyard | `C/cellar-activities-planner.tsx` | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J4 | Scope: one lot (+ its vessels) or whole cellar (spatial vessel picker: rooms, search, select per room, rubber band, fullscreen) | `C/vessel-map-picker.tsx` | — | ✅ | ✅ `Cellar/Plan` (`VesselMapPicker`) | |
| J5 | Addition fields: product search with stock or free name; dose per hL / per L / per vessel / total split by volume | planner | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J6 | Analysis fields: which readings to take | planner | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J7 | Transfer fields: from/to vessel and litres | planner | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J8 | Process kind / Topping litres / Vineyard task + parcels | planner | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | Topping requires a source lot/vessel (no wine from nowhere) |
| J9 | Common: date, who (anyone/me/member), note, round name (auto) | planner | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J10 | Named round mirrors a work-order card on the default board | `CAA:52` | — | ✅ | ✅ `Cellar/Plan` (`PlanBuilder`) | |
| J11 | Open plan grouped by day → round; move round (date + reason); delete round (keeps executed); delete single (not when done) | `P/activities` | — | ✅ | ✅ `Cellar/Plan` (`PlanList`) | |
| J12 | Recently executed list with deviated / skipped badges | `P/activities` | — | ✅ | ✅ `Cellar/Plan` (`PlanList`) | |
| J13 | Today: due today + overdue + done/skipped today; Mine/Everyone | `C/cellar-today.tsx` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | |
| J14 | Round progress bars; grouped by room, walking order by vessel name; overdue badge; assignee chip | `C/cellar-today.tsx` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | |
| J15 | ✓ Done as planned | `CAA:356` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | Dispatches to the same Actions as manual forms |
| J16 | Adjust (quantity/litres) → marks deviated | `CAA:356` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | |
| J17 | Analysis card "Enter" keypad for readings | `C/cellar-today.tsx` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | |
| J18 | Skip: Tomorrow / +3 days / date, or Not needed / Other | `skipCellarActivity` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | Reasons translated, not hard-coded Croatian |
| J19 | Completed-today list; "All done!" empty state | `C/cellar-today.tsx` | — | ✅ | ✅ `Cellar/Today` (`TodayCard`) | |
| J20 | Round card closes when nothing left open | `CAA:356` | — | ✅ | ✅ automatic (round card closes server-side) | Two-way: completing the card prompts to complete activities |

## K. Tastings — `/cellar/tastings`

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| K1 | Lot/vessel tasting note: date, score 0–100, vessel, appearance, nose, palate, overall, note | `C/tasting-note-form.tsx` | `AddTastingNoteAction` | ✅ | ✅ `TastingPanel` | |
| K2 | Delete note | | `DeleteTastingNoteAction` | ✅ | ✅ `Cellar/Lots/Show` | |
| K3 | Edit note | `updateCellarTastingNote` | `UpdateTastingNoteAction`, `PATCH wine-lots/{lot}/tasting-notes/{note}` | ✅ | ✅ `TastingPanel` (edit) | |
| K4 | Reports list: title, date, wine count, first 4 wines, author, empty state | `P/tastings`, `actions/tastings.actions.ts` | `TastingReportController::index` + `TastingReportData` (author, wine count, average, preview) | ✅ | ✅ `Cellar/Tastings/Index` | Add wines + author to the payload |
| K5 | New report: title, date, note, search-to-add wines (any non-archived lot), per-wine note + score | `C/tasting-report-form.tsx` | `SaveTastingReportAction`, `POST tasting-reports` (wines → lot notes) | ✅ | ✅ `TastingReportPanel` | Move to an Action + DTO |
| K6 | Report detail: date, author, count, average score, per-wine cards linking to lot | `P/tastings/[id]` | `GET tasting-reports/{report}` (wines, scores, lot links) | ✅ | ✅ `Cellar/Tastings/Show` | |
| K7 | Edit report (syncs lot notes: add/update/remove) | `P/tastings/[id]/edit` | `SaveTastingReportAction`, `PATCH tasting-reports/{report}` (sync notes by lot) | ✅ | ✅ `TastingReportPanel` (edit) | |
| K8 | Delete report (removes its lot notes) | `tastings.actions.ts` | `DeleteTastingReportAction`, `DELETE tasting-reports/{report}` | ✅ | ✅ `Cellar/Tastings/Show` (delete) | |
| K9 | Each wine in a report becomes a lot note | `tastings.actions.ts` | `TastingReportController::store` | ✅ | — | |

## L. Bottling

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| L1 | Bottle a lot: count, size 375/750/1500/3000, date, note; volume check | `C/bottling-form.tsx` (unused), `CA:2100` | `CreateBottlingAction` | ✅ | ✅ `BottlingPanel` | Prototype had **no screen**; added on the lot |
| L2 | Link existing inventory item → PRODUCTION_IN movement | `CA:2100` | `CreateBottlingAction` (`StockLedger`) | ✅ | ✅ `BottlingPanel` | |
| L3 | Create a new inventory item (name, SKU, size, price, bottles or cases, per case) | `CA:2100` | `CreateBottlingAction` `new_item` (via `CreateInventoryItemAction`) | ✅ | ✅ `BottlingPanel` | |
| L4 | Delete bottling reverses stock + lot status | `CA:2453` | `DeleteBottlingAction` (stock out; wine back into `bottling_vessels`) | ✅ | ✅ `Cellar/Lots/Show` (reverse) | Restores the vessels the wine came from |

## M. Fermentation protocols & monitoring

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| M1 | Protocol list + create/edit (stages: days, temp range, actions with type/product/dose/unit/timing) | `harvest/fermentation-templates.tsx` | `Create/UpdateFermentationTemplateAction` | ✅ | ✅ `ProtocolFormPanel` (stages by day with tasks; doses are the structured additives) | |
| M2 | Quick-start "Standard Red/White" templates | same | — | ✅ | ✅ `ProtocolFormPanel` quick start (red, white) | Client-side presets |
| M3 | Duplicate template | same | `CreateFermentationTemplateAction` | ✅ | ✅ `Cellar/Protocols` | |
| M4 | Timeline view of stages | same | — | ✅ | ✅ `Cellar/Protocols` (stage timeline on each card) | |
| M5 | Structured additive doses (per_L/per_hL/per_kg/per_100kg) | `protocol-doses-editor.tsx`, `final-plan.actions.ts:86` | `protocol_additives`, template `additives[]` | ✅ | ✅ `ProtocolFormPanel` (additive doses per L / hL / kg / 100 kg) | Needed by D27 |
| M6 | Delete (deactivate if used) | | `DeleteFermentationTemplateAction` | ✅ | ✅ `Cellar/Protocols` | |
| M7 | Fermentation monitor alerts (stuck, temp, VA, pH, MLF/ferment complete, stale) | `api/fermentation-monitor` (unrendered) | `CellarAnalyticsQuery` `alerts` (`FermentationMonitorQuery`) | ✅ | ✅ `Cellar/Protocols` (fermentation monitor) | Rendered as an `AttentionBand` (prototype never showed it) |

## N. Analytics & costs

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| N1 | KPIs: wine in cellar, ready to bottle, avg cost/L (admin), est. bottles, overdue tasks | `P/analytics` | `CellarAnalyticsQuery` `kpis` (avg cost/L only with finance.view) | ✅ | ✅ `Cellar/Analytics` | |
| N2 | KPIs: needs SO₂ (≥30 d), needs analysis (≥30 d), vessel utilisation, empty vessels | `P/analytics` | `CellarAnalyticsQuery` `kpis` (needs SO₂ by SO2/SULFITE category or name, needs analysis, utilisation, empty vessels) | ✅ | ✅ `Cellar/Analytics` | Needs-SO₂ counts category SO2 **and** SULFITE |
| N3 | 12-month in/out timeline | `C/charts` | `CellarAnalyticsQuery` `timeline` (12 months in/out) | ✅ | ✅ `Cellar/Analytics` | |
| N4 | Pipeline by status; volume by type; by vintage; grape mix | `C/charts` | `CellarAnalyticsQuery` | ✅ | ✅ `Cellar/Analytics` | |
| N5 | Cost waterfall top 10 (admin) | `C/charts` | `CellarCostsQuery` breakdown per lot (grape, additions, linked, carried) — waterfall drawn client-side | ✅ | ✅ `Cellar/Analytics` (most expensive lots, finance only) | |
| N6 | Lot health board (VA >0.7, free SO₂ <20, analysis age, SO₂ age) | `P/analytics` | `CellarAnalyticsQuery` `health` (VA, free SO₂, ages → red/amber/green) | ✅ | ✅ `Cellar/Analytics` (lot health table) | |
| N7 | Costs page (admin): per lot grape + additions + linked costs, cost/L, per bottle, margin vs selling price | `P/costs`, `wine-cost-breakdown.tsx`, `api/wine-costs` | `CellarCostsQuery` | ✅ | ✅ `Cellar/Costs` | Same cost/L as the lot page |
| N8 | Costs totals: COGS, average, cheapest, most expensive | `wine-cost-breakdown.tsx` | `CellarCostsQuery` | ✅ | ✅ `Cellar/Costs` | |
| N9 | Costs filters (vintage, status) + expandable rows | same | — | ✅ | ✅ `Cellar/Costs` | |
| N10 | Bulk wine drift between cellar and inventory (`produceItem` never drains lot) | `inventory.actions.ts:311` | Production module | ✅ | — | Rebuild drains through `LotVolumeService` — verify |

## O. Compliance & records

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| O1 | Cellar book event log (intake, press, vessel, addition, transfer, bottling) + CSV | `harvest/harvest-compliance.tsx` | `CellarBookQuery`, `GET cellar/book` (+ `format=csv`, quoted) | ✅ | ✅ `Cellar/CellarBook` + CSV | Include **all** lots (blends, manual), processes, analyses and losses; CSV escaped |
| O2 | EU total-SO₂ compliance (red 150/200, white/rosé/orange 200/250 by sugar, sparkling 185) | `checkSO2Compliance` | `WineCompliance::checkSo2`, board `compliance`, `POST cellar/calculators` | ✅ | ✅ `Cellar/So2` (EU limit on each lot) + `Calculators` | Uses `wine_type` enum (no ROSE/ROSÉ mismatch) |
| O3 | Traceability vineyard → harvest → lot → vessel → bottle | same | `TraceabilityQuery`, `GET wine-lots/{lot}/trace` (parcel → harvest → lot ← blends → vessels → bottles) | ✅ | ✅ `Cellar/Lots/Show` (Details › Traceability) | |
| O4 | Enological calculators (SO₂ + molecular, acid, general addition, chaptalisation) | `harvest/enological-calculators.tsx` (unrendered) | `So2Calculator`, `EnologicalCalculator`, `POST cellar/calculators` | ✅ | ✅ `Calculators` on `Cellar/So2` | Rendered as a Calculators drawer |

## P. Work-order integration

| # | Feature / action | Old | New backend | BE | UI | Fix |
|---|---|---|---|---|---|---|
| P1 | Work order linked to wine lot and vessel | `work-order-form.tsx` | `work_orders.wine_lot_id/vessel_id` | ✅ | ✅ `TaskPanel` wine-lot `Combobox` | Add a wine-lot `Combobox` to `TaskPanel` |
| P2 | Protocol-generated tasks visible on board | `HA:2196` (board-less) | `GenerateWorkOrdersFromProtocolAction` → `WorkOrderBoard::defaultBoard()` | ✅ | ✅ `ProtocolCard` → cards on the default board | Assign to default board/list |
| P3 | Cellar round cards on board | `CAA:52` | `CreateCellarActivitiesAction` → default board | ✅ | ✅ rounds land on the board from `Cellar/Plan` and `Cellar/So2` | Depends on J |

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
| Q1 | `Auditable` on Vessel, WineLot, WineLotGrape, VesselLot, CellarAnalysis, CellarAddition, CellarProcess, CellarTastingNote, TastingReport, CellarTransfer, Bottling, EnologicalProduct, FermentationTemplate, CellarActivity, CellarActivityRound | — | `Auditable` on all 18 cellar models (snapshots; derived volume/stock/layout fields ignored) | ✅ | — | Create snapshot = identifying fields; derived volume/stock/status fields ignored on update |
| Q2 | Named entries for lot volume: `wine_lot.volume_adjusted` (vessel, ±L, reason), `wine_lot.assigned_to_vessel` / `unassigned_from_vessel`, `vessel.layout_updated` (count), `vessel.room_renamed` | — | `wine_lot.volume_adjusted`, `.assigned_to_vessel(s)`, `.unassigned_from_vessel`, `vessel.duplicated`, `wine_lot.deleted` | ✅ | — | |
| Q3 | Named entries for blends and transfers: `wine_lot.blended` (sources, L, destination, new lot), `wine_lot.blended_in`, `cellar_transfer.created/reversed` (from/to lot+vessel, L, type) | — | `wine_lot.blended`, `wine_lot.blended_in`, `cellar_transfer.created`, `cellar_transfer.reversed` | ✅ | — | |
| Q4 | Named entries for bottling: `bottling.created/reversed` (bottles, size, L, inventory item, stock movement ref) | — | `bottling.created`, `bottling.reversed` | ✅ | — | |
| Q5 | Named entries for stock: `enological_product.stock_adjusted` (delta, before→after, reason, source: addition / bulk / SO₂ / manual / import) | — | `enological_product.stock_adjusted` (delta, before→after, source) on every stock change | ✅ | — | Every stock change is traceable to its cause |
| Q6 | Named entries for bulk operations: `cellar.bulk_additions_applied`, `cellar.bulk_analyses_recorded`, `cellar.so2_round_planned` (lot/vessel ids, dose, product) | — | `cellar.bulk_additions_applied`, `cellar.bulk_analyses_recorded`, `cellar.so2_applied`, `cellar.so2_round_planned` | ✅ | — | One summary entry plus per-row trait entries |
| Q7 | Named entries for planned work: `cellar_activity.completed` (deviated, planned vs actual), `skipped` (reason), `rescheduled` (from→to, reason), `cellar_activity_round.deleted` | — | Activities actions (`cellar_activity.*`, `cellar_activity_round.*`) | ✅ | — | |
| Q8 | Named entries for protocols: `wine_lot.protocol_assigned`, `wine_lot.protocol_tasks_generated` (count, day), `wine_lot.protocol_additions_planned` | — | `wine_lot.protocol_assigned`, `.protocol_tasks_generated`, `.protocol_additions_planned` | ✅ | — | |
| Q9 | Cellar entries readable in the tenant Logs page (subject filter "Cellar", subject links to lot/vessel) | — | `Web\LogController` `?area=cellar` / `?subject_id=` filters | ✅ | ✅ `Logs/Index` All / Cellar filter, links to lot and vessel | |
| Q10 | "History" panel on lot and vessel detail (audit entries for that subject) | — | `CellarHistoryQuery`, `GET wine-lots/{lot}/history`, `GET vessels/{vessel}/history` | ✅ | ✅ `HistoryList` on lot and vessel pages | Who changed what, beside the lot timeline |
| Q11 | Tests: every cellar write Action asserts its audit entry (action, actor, subject, key metadata) | — | `CellarAuditCoverageTest` sweep (every write path → expected action, actor set) | ✅ | — | |

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
15. **Unit prices keep fractions of a cent.** Enological products are priced
    per gram or millilitre (K₂S₂O₅ ≈ 0.006 €/g); product and addition unit
    prices keep four decimals of a cent (`UnitPrice`), totals are whole cents.
16. **Additions from stock are costed** at the product's price unless one is
    given, so the lot's cost of goods includes them.
17. **The SO₂ board shows a lot's worst vessel.** The lot row's free SO₂ is
    the lowest any of its vessels is at — the reading doses are planned
    from — not a lot-level reading that a newer vessel reading contradicts;
    EU limits use the highest total SO₂ in the lot.

## Sign-off

| Step | Owner | Status |
|---|---|---|
| Matrix reviewed | product | ⬜ |
| Backend gaps closed (all BE ✅) | eng | ✅ 189 rows; 4 not applicable |
| Screens shipped (all UI ✅) | eng | ✅ 173 rows; 20 need no screen |
| Side-by-side walkthrough on imported tenant data | product + eng | ✅ eng — 16 screens; 9 findings fixed, 6 differences kept (below) |
| Phase 3 experience proposals approved | product | ✅ all eight approved as mocked |
| Phase 3 built (all eight proposals) | eng | ✅ `ae2e902`…`53f0aab`, Today undo after — see below; product walk-through pending |

## Side-by-side walkthrough (slice 2.9)

**Setup.** The old app (order-mgmt, `next build && next start`) ran on its demo
database in Postgres 16, with a planned SO₂ round, an addition and an overdue
process added for Today and Plan. That database was imported with
`php artisan legacy:import` into a fresh terroir-bi tenant, and both apps were
screenshotted screen by screen with Playwright on the same data. The paired
screenshots are on the shareable matrix page.

| Screen | Result |
|---|---|
| Cellar home | Match — 6 vessels, 3 active lots, 8650 L; same last analysis and addition per lot |
| Today | Match — same 5 items, round 0/3, overdue item |
| Plan | Fixed — assignee list (below) |
| SO₂ | Match — same status counts, lots, free SO₂, days since |
| Lot (active) | Fixed — latest analysis; grape table cost and total |
| Lot (bottled) | Fixed — bottling run now imported |
| Vessel | Fixed — header and contents text |
| Blend | Match |
| Bulk additions | Match (Ready lots also listed) |
| Bulk analyses | Different by decision — grid + paste, AI photos deferred |
| Tasting reports, report | Match |
| Enological products | Fixed — inventory value 3,851.00 € in both |
| Protocols | Match |
| Analytics | Different — Needs SO₂ (below) |
| Costs | Match — COGS 3.26 € in both |

**Found and fixed** (each with a test):

1. Legacy transfers with free-text types (`Pretok`, `Sljubljivanje`,
   `Topping-up`) were skipped on import. They are now typed from their lots —
   same lot = RACK, another lot = BLEND, a split label = SPLIT — and reported.
2. Legacy bottling runs were not imported (and not listed as not-migrated).
   They now import with their inventory item; with no record of which vessels
   they drew from, reversing one returns the wine to the lot only.
3. Enological unit prices below a cent were rounded to whole cents
   (correctness fix 15).
4. Additions from stock carried no cost (correctness fix 16).
5. Lot detail lists came back in database order, so "Latest analysis" showed an
   older reading on imported data. Every lot list is newest first.
6. Assignee pickers (Plan, SO₂ rounds) offered suspended members — including
   the import placeholder — and people without cellar access. They now list
   active members whose roles grant `cellar.manage`.
7. The lot's grape table gained the cost column and total row.
8. The vessel header repeated room and location; contents repeated the vintage.
9. "Blended from 1 source lots" reworded.

**Differences kept on purpose:**

- **Needs SO₂** reads 2, the old app 3. The old KPI counts only additions in
  category `SULFITE`; the demo's SO₂ additions are `ENOLOGICAL`, so a lot
  sulphited 7 days earlier was flagged. The new KPI recognises SO₂ products.
- **SO₂ products** also include products named with a subscript "SO₂", which
  the old filter missed.
- **Bulk analysis** is a typed grid with paste; AI photo reading is deferred.
- **Bulk additions** also lists Ready lots.
- **Mark faulty** is a switch on the vessel's Details tab.
- **Per-litre costs below a cent** show as 0,00 € (old: 0,0002 €); lot totals match.

## Phase 3 — experience improvements (built)

Each proposal was built as its own slice on top of the parity screens,
with feature tests for every new endpoint, en/hr strings, and a Playwright
pass at 1440 px and 390 px on the imported demo tenant.

| # | Proposal | Commit | What shipped | Kept out / differs from the mock |
|---|---|---|---|---|
| 3.1 | Lot workspace | `ae2e902` | One page instead of nine tabs: vitals (volume, free/total and molecular SO₂ against target, pH · alc · VA, cost/L for finance, next planned task), an action bar, the journey feed with type filters and per-row Edit / Delete / Reverse, SO₂ sparkline, vessels, protocol; details, traceability and history in a "Lot details" panel; vessel switcher | — |
| 3.2 | Map as the control surface | `4029882` | Select in view mode (shift/⌘-click, lasso, a Select toggle for touch) → bar: Record SO₂, Plan addition / readings, Blend these, Move wine, Assign wine; colour by SO₂ / wine / fill with a legend; badges show free SO₂ and days since. `?vessels=` / `?lots=` preselect on SO₂, Plan and Blend (tenant ids only) | — |
| 3.3 | One Record flow | `da64bae` | Record drawer from anywhere (button or `R`): pick a vessel or lot, then Reading / Addition / Move / Process / Tasting / Volume; "save & record another" keeps the place. Low-SO₂ hint on readings links to SO₂. Global search finds lots and vessels | The drawer doesn't remember the last product and dose |
| 3.4 | SO₂ by target level | `f6f33ef` | Target mode by default, rows prefilled from wine type and oak, below-target lots preselected; molecular now → after, total after against the EU limit, a stock bar, and a confirmation sheet listing every dose. The board row shows the lot's worst vessel | "Add a fixed amount" stays as the second mode |
| 3.5 | Today on a phone | `9a09892` | Progress bar, walk order per room, readings open on the card with "Save · next", swipe left to skip / postpone, ticks saved at once and queued offline in the browser (409 = already settled, 422 shown on the card) | — |
| 3.6 | Blend workbench | `cffb15e` | One bench: ratio sliders that rebalance, live projection against spec ranges with out-of-range messages, cost/L for finance, saved trials (new `blend_trials` table) to load or delete, a confirmation before executing; litres-by-vessel kept as the second mode | — |
| 3.7 | Alerts where you work | `ef313f5` | "Needs attention" on the cellar home and each lot: fermentation-monitor alerts, low / unread SO₂, high VA and overdue tasks, most urgent first, each with one action (plan a reading — or "Reading planned · date" if it already is — plan SO₂, plan a warm/cool task, open Today); "Hide until tomorrow" per viewer | Informational alerts (MLF / fermentation complete) stay on Analytics |
| 3.8 | Undo and confirmations | `53f0aab` | Deleting an addition, reading, process, tasting note or transfer happens at once with a 10 s Undo toast; undo re-runs the create Action (stock drawn again, wine moved again) with the original date and author, single-use, same person, 10 minutes, audited as `cellar.undo`. Skipping or moving a task on Today offers the same Undo: still queued on the phone → dropped from the queue; already sent → the undo joins the same offline queue and is retried until the server answers (Today undos last a day; a retried undo that already went through counts as done); the task goes back to planned on its old date with its old note, and its round's board card reopens. Reversing a bottling keeps its confirmation. Deleting a lot lists what goes (`GET cellar/lots/{lot}/delete-impact`) and asks for the lot number | — |

