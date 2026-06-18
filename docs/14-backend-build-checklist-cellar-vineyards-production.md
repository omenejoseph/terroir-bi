# 14 — Backend Build Checklist: Cellar · Vineyards · Production 🆕

Executable backlog for the second batch of modules. Strategy/rationale in
[`13-backend-plan-cellar-vineyards-production.md`](13-backend-plan-cellar-vineyards-production.md).

- Each task names the **artifact** and its **acceptance**.
- Tasks ordered so each is unblocked when reached.
- Gate every PR with `composer check` (Pint + PHPStan 8 + Pest).
- Suggested grouping = one PR per `### group`.

Progress key: `[ ]` todo · `[~]` in progress · `[x]` done.

---

## Phase A — Module enablement *(blocks routing for B–F)*

### A.1 Register the three modules
- [x] `app/Enums/Module.php`: add `Cellar='cellar'`, `Vineyards='vineyards'`, `Production='production'` + `label()` cases.
- [x] `app/Authorization/ModuleRegistry.php`: add `capabilities()` (`cellar.*`, `vineyards.*`, `production.*`) and `pathPrefixes()` entries (see plan §1).
- [x] `app/Authorization/RoleCapabilities.php`: grant new caps (ADMIN=`*`; MANAGER=view+manage; CELLAR=cellar.* + vineyards.* + production.view).
- **Accept:** ✅ the three modules appear as checkboxes in the Filament Plan editor (auto from `Module::cases()`); a tenant on a plan **without** a module gets `403 module_not_in_plan`; PHPStan clean. Tests: `tests/Feature/Cellar/CellarModuleGatingTest.php` (gating + capability + no-plan).

---

## Phase B — Cellar core (vessels + lots)

### B.1 Schema
- [x] Migrations: `vessels`, `wine_lots`, `wine_lot_grapes` (nullable `harvest_entry_id`, FK added in E), `vessel_lots`. (Columns/casts per plan §3.1.)
- [x] Enums: `VesselType`, `VesselStatus`, `WineLotStatus`, `WineType`. (`CellarTransferType` lands with transfers in C.2.)
- [x] Models: `Vessel`, `WineLot`, `WineLotGrape`, `VesselLot` (`BelongsToTenant`+`HasUlids`, relations/casts).

### B.2 Services
- [x] `app/Services/Cellar/LotNumberGenerator` (`LOT-YYYY-NNN`, tenant-scoped, collision-safe).
- [x] `app/Services/Cellar/VesselVolumeSync` (Σ vessel_lots → current_volume + status; `Quantity` math; orphan cleanup; leaves MAINTENANCE/RETIRED).
- [x] `app/Services/Cellar/LotVolumeService` (assign/unassign/adjust; `lockForUpdate`; capacity-guarded). Bulk equal/fill allocation deferred to a follow-up.
- **Accept:** ✅ feature tests prove volume = Σ vessel_lots and AVAILABLE/IN_USE derivation; no float drift.

### B.3 Vessel actions + endpoints
- [x] Actions: `CreateVessel`, `UpdateVessel`, `DeleteVessel` (empty-only guard), `UpdateVesselLayout` (single + batch position/size/room).
- [x] `BulkCreateVessels` (≤50) + `RenameCellarRoom` → `POST /vessels/bulk`, `POST /vessels/rename-room` (landed with Phase D). `DuplicateVessels` still deferred.
- [x] FormRequests + `VesselData` DTO + `ListVesselsQuery` (cellar map, vessels+lots by room).
- [x] Routes under `can:cellar.*` (module gating via `vessels` path prefix): `GET /vessels`, `PATCH /vessels/layout`, CRUD.
- **Accept:** ✅ map query returns vessels with lot summary; delete blocked while occupied; PHPStan clean.

### B.4 Wine lot actions + endpoints (One-Wine base)
- [x] Actions: `CreateWineLot` (multi-grape + optional starting vessel + grape cost), `UpdateWineLot` (status; BOTTLED frees vessels via `VesselVolumeSync`), `AdjustLotVolume`, `AssignLotToVessel`, `UnassignLotFromVessel`.
- [~] `BulkAssignLotToVessels` (equal/fill) — deferred follow-up.
- [x] `ListWineLotsQuery` + `WineLotData` DTO (detail w/ grapes + vessels); `GET /wine-lots`, `GET /wine-lots/{id}`.
- **Accept:** ✅ lot detail returns vessels+grapes; capacity-guarded assign; lot numbers increment per tenant; bottling frees vessels. Tests: `tests/Feature/Cellar/VesselTest.php`, `WineLotTest.php`.

---

## Phase C — Cellar quality (Analyses + ops + bottling)  *(DONE)*

### C.1 Analyses
- [ ] Migration `cellar_analyses`; `CellarAnalysis` model.
- [ ] Actions `AddCellarAnalysis`/`Update`/`Delete`; bulk insert action.
- [ ] `LotAnalysisTrendQuery`; endpoints `GET /wine-lots/{id}/analyses`, write + `POST /wine-lots/analyses/bulk`.
- **Accept:** per-vessel and lot-level analyses; trend series ordered by date.

### C.2 Additions, processes, tasting notes, transfers
- [ ] Migrations: `cellar_additions`, `cellar_processes`, `tasting_reports`, `cellar_tasting_notes`, `cellar_transfers` + models.
- [ ] Actions: additions add/delete (deduct/restore enological stock), `BulkAddAdditions`, processes add/delete, tasting add/update/delete, `CreateTransfer`/`DeleteTransfer` (reverse volumes).
- [ ] `So2Calculator` service + endpoint or compute helper.
- **Accept:** addition deducts enological stock and restores on delete; transfer updates both vessels via `VesselVolumeSync`; SO₂ dose unit-tested.

### C.3 Bottling (Inventory link)
- [ ] Migration `bottlings` + model; `LotCostService`.
- [ ] `CreateBottling` (per-bottle cost; `StockLedger` `PRODUCTION_IN`; optional new inventory item; BOTTLED cleanup) + `DeleteBottling` (reverse).
- **Accept:** bottling writes finished stock + frees emptied vessels; delete restores lot volume and reverses the stock movement. Test `tests/Feature/Cellar/BottlingTest.php`.

---

> **Phase C — DONE.** All migrations/models/services/actions/endpoints landed
> (analyses, additions, processes, tastings, transfers, bottling→stock via
> StockLedger, enological products, fermentation monitor, cellar-map SO₂
> enrichment). Tests: `tests/Feature/Cellar/CellarLifecycleTest.php`.
> `composer check` green (997 tests).

---

## Phase D — Cellar protocols + monitor  *(DONE)*

### D.1 Fermentation templates
- [ ] Migration `fermentation_templates` (`stages` json) + `enological_products`; models.
- [ ] CRUD actions for templates + enological products (`AdjustStock`).
- [ ] Delta migration: `work_orders` add nullable `wine_lot_id`, `vessel_id`.

### D.2 Protocol execution + monitor
- [ ] `AssignFermentationTemplate`; `GenerateWorkOrdersFromProtocol` (parse `stages`, compute fermentation day, idempotent per lot/day, attach `wine_lot_id`).
- [ ] `FermentationMonitorQuery` (alerts per plan §3.6) → `GET /cellar/fermentation-monitor`.
- **Accept:** generating twice in a day is idempotent; monitor flags stuck/temp/VA/pH/MLF/complete/stale with correct severities.

---

## Phase E — Vineyards

### E.1 Parcels + agronomy
- [ ] Migrations: `vineyard_parcels`, `phenology_logs`, `maturity_samples`, `crop_estimates`, `vineyard_applications`; delta `suppliers.is_cooperant`.
- [ ] Enums: ownership, phenology stage, application type. Models.
- [ ] Services: `CropYieldEstimator`, `PhiCalculator`.
- [ ] Actions: parcel CRUD + `UpdateParcelGeo`; agronomy create/delete (auto-yield, auto-PHI). DTOs + `ParcelDetailQuery`.
- **Accept:** crop yield + PHI formulas unit-tested; parcel detail includes agronomy + active contracts.

### E.2 Contracts
- [ ] Migration `grape_contracts` + model; CRUD actions (mark supplier cooperant; delete only if `delivered_kg=0`).
- [ ] `GrowerPerformanceQuery` (fulfillment %, avg brix/pH, reliability %).
- **Accept:** performance metrics correct; cooperant flag set/unset.

### E.3 Harvest plans + Intake (depends on Cellar B)
- [ ] Migrations: `harvest_plans`, `harvest_entries`, `intake_bookings`, `press_fractions`; add `wine_lot_grapes.harvest_entry_id` FK now.
- [ ] `HarvestIntakeService` (PLANNED→HARVESTED; create/blend `WineLot` via Cellar services; vessel assign; atomic).
- [ ] Actions: plan CRUD, entry add/update/remove, **`RecordHarvestIntake`**, booking CRUD + status, press fraction create/delete; `AutoPopulateHarvestPlan`.
- [ ] `HarvestRequirementsCalculator`; queries for plans/bookings/entries.
- **Accept:** intake creates exactly one lot (or blends), computes volume = kg×yield_ratio, flips status, all in one transaction; rollback on failure. Test `tests/Feature/Vineyards/HarvestIntakeTest.php`.

---

## Phase F — Production (Planner)

### F.1 Schema + calculator
- [ ] Migrations: `production_plans`, `production_plan_rows`; enum `PlanUnit` (liters/bottles/cases); models.
- [ ] `ProductionCalculator` (unit normalisation, recipe expansion w/ pack_size, cheapest supplier price, revenue/margin, shortfalls→supplier orders).
- [ ] `CalculateProductionPlan` (read-only) + endpoint.
- **Accept:** calculator margins/requirements match source formulas (unit tests with fixtures).

### F.2 Plan lifecycle + confirm
- [ ] Actions: `ListPlans`, `GetPlan`, `CreatePlan`, `UpdatePlan` (bulk row upsert), `DeletePlan` (DRAFT only).
- [ ] `PlanConfirmationService` (conflict detect; force→link/auto-create inventory item w/ SKU dedup + recipe clone + lineage; `cleanupAutoCreatedProducts`).
- [ ] `ConfirmPlan` endpoint (returns conflicts without `force`).
- **Accept:** confirm without force returns conflicts; with force creates/links items + sets `CONFIRMED`; cleanup removes stale auto-created only. Test `tests/Feature/Production/ConfirmPlanTest.php`.

---

## Phase G — AI & external *(iterative; some out of scope)*

### G.1 Analysis OCR (in scope, after C)
- [ ] `app/Services/Ai/Extractors/WineAnalysisExtractor` wired through existing `ai-imports`; gated by `module:ai_data_entry` + `ai.use`.
- [ ] Map extracted samples → `AddCellarAnalysis` / bulk insert.
- **Accept:** parses single + multi-sample images into analysis rows (mocked AI client in tests).

### G.2 Harvest predictions (in scope, after E)
- [ ] `HarvestPredictionService` (linear-regression Brix trend, days-to-target by red/white) → `GET /vineyard-parcels/harvest-predictions`.
- [ ] `WeatherProvider` interface + impl (injectable/mockable); graceful degrade if network policy blocks it.
- **Accept:** prediction math unit-tested without network; weather overlay optional.

### G.3 Satellite vineyard analysis *(out of scope — separate spike)*
- [ ] Decision recorded; not built in this pass (see plan §6 / §8.3).

---

## Status

**Cellar module: backend Phases A–D complete and a full Cellar frontend shipped**
(Next.js SPA: interactive map with drag/resize/lasso/undo/room-rename, wine-lot
list + lifecycle detail tabs, SO₂ page, recharts trends; `npm run check` green).
Vineyards (Phase E), Production (Phase F) and the AI/external work (Phase G)
remain for a later pass. `DuplicateVessels` and a bulk-analysis manual entry page
are small deferred follow-ups.

## Definition of done (whole batch)
- [x] Cellar toggles on/off per plan from the back office; gating + capability + tenant-isolation tests green. *(Vineyards/Production pending their phases.)*
- [x] Inventory↔Cellar link works (bottling→stock). *(Production confirm→items lands in Phase F.)*
- [x] `composer check` green (Pint + PHPStan 8 + Pest) — 997 backend tests; frontend `npm run check` green (288 tests).
- [ ] `docs/02-modules.md`, `03-entity-data-model.md`, `06-api-reference.md` updated for the new tables/endpoints.
