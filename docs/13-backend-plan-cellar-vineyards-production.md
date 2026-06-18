# 13 — Backend Plan: Cellar, Vineyards & Production 🆕

Scope: **Laravel backend only** (frontend/React is a separate follow-up pass).
This is the "second plan" — it ports the next batch of modules from the source
Next.js/Prisma app (`order-mgmt`) into the established terroir-bi architecture:

| New module | Sub-features (source) | Module enum key |
|---|---|---|
| **Cellar** | Cellar Map · One-Wine · Analyses · Protocols | `cellar` |
| **Vineyards** | Parcels · Contracts · Intake | `vineyards` |
| **Production** | Planner | `production` |

Each is a **billable module**, enabled per plan from the Filament back office
exactly like Orders (see §1). Strategy/rationale lives here; the ordered,
tickable backlog is in
[`14-backend-build-checklist-cellar-vineyards-production.md`](14-backend-build-checklist-cellar-vineyards-production.md).

> `02-modules.md` already reserves a **Cellar / Production** bounded context and
> documents the deliberate **bidirectional Inventory↔Cellar** link (bottling
> writes finished stock into Inventory; recipes/production consume wine lots).
> This plan realises that context.

---

## Conventions to follow (unchanged from the first plan)

- **Migrations** in `database/migrations`; ULID PKs (`ulid('id')->primary()`);
  every tenant table carries `foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete()`
  and uses the `BelongsToTenant` trait (auto-scope + auto-assign, fail-closed).
- **Money** = `bigInteger` minor units + `MoneyCast` (never `decimal` for currency).
- **Quantities / volumes / weights** = `decimal(12,3)` + the `Quantity` string-math helper.
- **Lab measurements** (pH, Brix, acidity, SO₂, density, temperature, %) =
  `decimal` with feature-appropriate precision (see §3.1); cast `'decimal:N'`.
- One **Action** per write use-case (`app/Actions/<Domain>/<Verb>Action.php`,
  single `execute()`, wrapped in `DB::transaction`, side-effects after commit).
- **Thin Controllers** (`app/Http/Controllers/Api`) → validate via **FormRequest**,
  delegate to Action/Query, return a **DTO** (`toArray()`); never leak Eloquent.
- **Read paths** via **Query** objects (`app/Queries`); list endpoints return
  `{ data, meta }` (paginated) like Orders.
- **Enums** in `app/Enums`; cross-cutting logic in **Services** (`app/Services/<Domain>`).
- **Capability-gated routes**: `middleware('can:<cap>')`; **module-gated routes**:
  `middleware('module:<key>')` (see §1).
- **Quality gate per PR:** `composer check` (Pint + PHPStan level 8 + Pest). Tests
  ship with each phase; keep PHPStan green (type all new casts).

---

## 1. Module enablement (back office → plan → tenant)

This is the **first deliverable** and unblocks everything: it is how a module is
"turned on for a plan." It mirrors the existing Orders gating end-to-end, so once
done the three new modules behave like every shipped module.

The mechanism (already built, we only extend it):

1. **`app/Enums/Module.php`** — add three cases + `label()`:
   ```php
   case Cellar     = 'cellar';
   case Vineyards  = 'vineyards';
   case Production  = 'production';
   // label(): 'Cellar', 'Vineyards', 'Production'
   ```
   The Filament `PlanForm` renders a `CheckboxList` over `Module::cases()`, so the
   new modules **appear automatically** in the back office plan editor — an admin
   ticks them per plan, identical to ticking "Orders". No Filament change needed.

2. **`app/Authorization/ModuleRegistry.php`** — register each module's
   capabilities and API path-prefixes (the prefix→module map drives
   `EnforceModuleAccess` and the nav source):
   ```php
   // capabilities()
   Module::Cellar->value     => ['cellar.view', 'cellar.manage', 'cellar.delete'],
   Module::Vineyards->value  => ['vineyards.view', 'vineyards.manage', 'vineyards.delete'],
   Module::Production->value  => ['production.view', 'production.manage', 'production.delete'],

   // pathPrefixes()  (each prefix belongs to exactly one module)
   Module::Cellar->value     => ['vessels', 'wine-lots', 'enological-products',
                                 'fermentation-templates', 'tasting-reports', 'bottlings', 'cellar'],
   Module::Vineyards->value  => ['vineyard-parcels', 'grape-contracts', 'harvest-plans',
                                 'harvest-entries', 'intake-bookings', 'press-fractions'],
   Module::Production->value  => ['production-plans'],
   ```

3. **`app/Authorization/RoleCapabilities.php`** — grant the new capabilities to
   roles. Recommended mapping (the `CELLAR` `TenantRole` already exists):
   - `ADMIN` → all nine new capabilities.
   - `MANAGER` → `*.view` + `*.manage` for all three.
   - `CELLAR` → `cellar.*` + `vineyards.*` + `production.view`.
   - `STAFF`/others → `*.view` where appropriate.

4. **`EnforceModuleAccess` middleware** — no code change; it already maps the
   request's first path segment → module via `ModuleRegistry::pathPrefixes()` and
   returns `403 module_not_in_plan` if the tenant's plan lacks it. Apply the
   `module:{key}` middleware on each route group (§ per-module specs) so a tenant
   without the module in its plan is hidden from those endpoints.

5. **`app/Services/Auth/SessionBuilder.php`** — already exposes
   `plan->moduleKeys()` to the SPA, so the React app will only show the new
   modules once the plan includes them. No change needed.

**Net cost of "enableable from the back office":** ~3 edited files (enum,
registry, role matrix) + `module:` middleware on the new route groups. Everything
else is automatic.

> **Decision (recommended):** keep **Analyses** and **Protocols** as *features of
> the Cellar module* (not separate billable modules) — they share `cellar.*`
> capabilities and the `wine-lots` path prefix. If product wants them billed
> separately later, splitting is a registry-only change. Same for **Intake**
> inside Vineyards. (Flagged in §6 Open questions.)

---

## 2. Build order & cross-module dependencies

Source FK reality drives the order:

- Harvest **Intake** (Vineyards) creates **wine lots** and assigns **vessels** (Cellar).
- `wine_lot_grapes` links `harvest_entries` (Vineyards) ↔ `wine_lots` (Cellar).
- `press_fractions` link `harvest_entries` ↔ `wine_lots` ↔ `vessels`.
- Production **Planner** confirm creates **inventory_items** (Inventory — already built)
  and reads **recipes** + **supplier pricing** (already built).
- **Bottling** (Cellar) writes finished stock into Inventory via the existing `StockLedger`.

So the phases are:

```
Phase A  Module enablement (§1)                     ← unblocks routing/gating
Phase B  Cellar core: vessels, wine_lots, lot ops   ← Vineyards intake depends on it
Phase C  Cellar quality: analyses, additions, processes, tastings, transfers, bottling
Phase D  Cellar protocols: fermentation_templates + work-order generation + monitor
Phase E  Vineyards: parcels (+agronomy), contracts, harvest plans, intake, press fractions
Phase F  Production: planner (calculator) + plan confirm / vintage management
Phase G  AI & external (analysis OCR, harvest predictions, fermentation alerts wiring)
```

Phases B→F are each shippable PR groups. Phase G is optional/iterative (see §5).

---

## 3. Cellar module

Source of truth: `order-mgmt/src/actions/cellar.actions.ts` (~2660 lines),
`harvest.actions.ts` (protocols), and the `parse-analysis` / `bulk-analysis` /
`fermentation-monitor` API routes.

### 3.1 Tables (migrations)

All tenant-scoped, ULID PKs, `timestamps()`. Money via MoneyCast; volumes/weights
`decimal(12,3)`; measurements as noted.

| Table | Key columns (beyond `id`, `tenant_id`, timestamps) |
|---|---|
| `vessels` | `name`, `type` (enum), `material?`, `capacity_liters decimal(12,3)`, `current_volume decimal(12,3) default 0`, `location?`, `status` (enum), `is_active bool`, `is_faulty bool`, `fault_note?`, `room? default 'Main Cellar'`, `position_x int?`, `position_y int?`, `map_width int?`, `map_height int?`, `rotation int?` |
| `wine_lots` | `lot_number` (unique per tenant), `name`, `grape_variety`, `vintage`, `vineyard?`, `wine_type?` (enum), `initial_volume decimal(12,3)`, `current_volume decimal(12,3)`, `status` (enum), `grape_cost bigInteger?` (Money), `grape_price_per_kg bigInteger?` (Money), `harvest_weight_kg decimal(12,3)?`, `fermentation_template_id?` (FK) |
| `wine_lot_grapes` | `wine_lot_id` (FK cascade), `grape_variety`, `percentage decimal(5,2)?`, `price_per_kg bigInteger?`, `weight_kg decimal(12,3)?`, `harvest_entry_id?` (FK, added in Phase E) — unique `(wine_lot_id, grape_variety)` |
| `vessel_lots` | `vessel_id` (FK cascade), `wine_lot_id` (FK cascade), `volume decimal(12,3)`, `added_at` |
| `cellar_analyses` | `wine_lot_id` (FK cascade), `vessel_id?` (FK), `created_by_id` (FK users), `date`, plus measurements: `ph decimal(4,2)?`, `total_acidity decimal(5,2)?`, `volatile_acidity decimal(5,3)?`, `alcohol decimal(5,2)?`, `residual_sugar decimal(6,2)?`, `free_so2 decimal(6,2)?`, `total_so2 decimal(6,2)?`, `brix decimal(5,2)?`, `temperature decimal(5,2)?`, `density decimal(6,4)?`, `malic decimal(5,2)?`, `lactic decimal(5,2)?`, `tpi decimal(6,2)?`, `glucose_fructose decimal(6,2)?`, `note?` |
| `cellar_additions` | `wine_lot_id` (FK cascade), `enological_product_id?` (FK), `created_by_id`, `name`, `category?`, `quantity decimal(12,3)`, `unit`, `cost_per_unit bigInteger?`, `total_cost bigInteger?`, `note?` |
| `cellar_processes` | `wine_lot_id` (FK cascade), `vessel_id?`, `created_by_id`, `date`, `kind`, `volume decimal(12,3)?`, `note?` — index `(wine_lot_id, date)`, index `kind` |
| `tasting_reports` | `created_by_id`, `title?`, `date`, `note?` (groups notes into a session) |
| `cellar_tasting_notes` | `wine_lot_id` (FK cascade), `vessel_id?`, `tasting_report_id?` (FK nullOnDelete), `created_by_id`, `date`, `appearance?`, `nose?`, `palate?`, `overall?`, `score int?`, `note?` |
| `cellar_transfers` | `from_lot_id` (FK), `to_lot_id` (FK), `from_vessel_id?`, `to_vessel_id?`, `created_by_id`, `type` (enum RACK/BLEND/SPLIT), `volume_liters decimal(12,3)`, `note?` |
| `bottlings` | `wine_lot_id` (FK), `inventory_item_id?` (FK nullOnDelete), `created_by_id`, `bottle_count int`, `bottle_volume_ml int default 750`, `volume_used decimal(12,3)`, `date`, `note?` |
| `fermentation_templates` | `name`, `wine_type?`, `yeast_strain?`, `target_temp_min decimal(5,2)?`, `target_temp_max decimal(5,2)?`, `punchdown_schedule?`, `maceration?`, `nutrients?`, `mlf bool`, `description?`, `estimated_duration int?`, `stages json`, `is_active bool` |
| `enological_products` | `name`, `category`, `unit`, `current_stock decimal(12,3) default 0`, `min_stock decimal(12,3)?`, `cost_per_unit bigInteger?`, `manufacturer?`, `packaging_size?`, `so2_uplift_per_unit decimal(8,4)?`, `supplier_id?` (FK), `supplier_price_item_id?` (FK), `is_active bool` |

**Deltas to existing tables:**
- `work_orders`: add nullable `wine_lot_id` (FK) and `vessel_id` (FK) so protocol
  generation can attach tasks to a lot/vessel (source links these).

### 3.2 Enums
`VesselType` (BARREL, BARRIQUE, TANK, VAT, AMPHORA), `VesselStatus` (AVAILABLE,
IN_USE, MAINTENANCE, RETIRED), `WineLotStatus` (FERMENTING, AGING, READY, BOTTLED,
BLENDED), `WineType` (RED, WHITE, ROSE, ORANGE, SPARKLING, DESSERT),
`CellarTransferType` (RACK, BLEND, SPLIT).

### 3.3 Models
`Vessel`, `WineLot`, `WineLotGrape`, `VesselLot`, `CellarAnalysis`,
`CellarAddition`, `CellarProcess`, `TastingReport`, `CellarTastingNote`,
`CellarTransfer`, `Bottling`, `FermentationTemplate`, `EnologicalProduct` — all
`BelongsToTenant` + `HasUlids`, relations + casts only (logic lives in services).
`stages` cast `'array'`.

### 3.4 Services (the non-trivial logic)
- **`LotNumberGenerator`** — tenant-scoped `LOT-YYYY-NNN` (and `BLEND-YYMMDD-NN`
  for minted blends), collision-safe like `OrderNumberGenerator`.
- **`VesselVolumeSync`** — the single source of truth for vessel occupancy:
  recompute `vessel.current_volume = Σ vessel_lots.volume` and derive `status`
  (AVAILABLE when ≤ 0.001 L, else IN_USE). Called by every op that moves wine.
  Also clears orphan `vessel_lots` when a lot becomes BOTTLED (fixes "ghost
  vessel"). Use `Quantity` string math (no float drift), `lockForUpdate()` on
  affected rows.
- **`LotVolumeService`** — `adjust()` (proportional distribution across a lot's
  vessels), `assignToVessel()` / `unassign()` (capacity-checked), bulk
  `equal`/`fill` allocation modes.
- **`BlendService`** — `blendIntoLot()` (top up dest from a source vessel-lot,
  recompute grape composition by volume) and `executeBlend()` (multi-source pull
  into one destination vessel → mints a new `WineLot`, aggregates compositions,
  writes a `CellarTransfer` per pull).
- **`So2Calculator`** — `dose = (target − current) × volumeL / so2UpliftPerUnit`;
  pure, unit-tested.
- **`LotCostService`** — `grape_cost = price_per_kg × harvest_weight_kg`;
  total lot cost = grape_cost + Σ addition.total_cost; per-bottle costing for
  bottling → feeds `cost_per_unit` on the produced inventory item.

### 3.5 Actions (write use-cases — one `execute()`, transactional)
Vessels: `CreateVessel`, `UpdateVessel`, `DeleteVessel` (guard: only empty),
`BulkCreateVessels` (sequential names, max 50), `DuplicateVessels`,
`UpdateVesselLayout` (position/size/room, incl. batch for drag-and-drop),
`RenameCellarRoom`. Wine lots: `CreateWineLot` (multi-grape), `UpdateWineLot`
(status transitions; BOTTLED frees vessels), `AdjustLotVolume`, `AssignLotToVessel`,
`UnassignLotFromVessel`, `BulkAssignLotToVessels`, `BlendIntoLot`, `ExecuteBlend`.
Quality: `AddCellarAnalysis`/`Update`/`Delete`, `AddCellarTastingNote`/`Update`/`Delete`,
`AddCellarAddition` (deduct enological stock)/`Delete` (restore), `BulkAddAdditions`,
`AddCellarProcess`/`Delete`. Transfers: `CreateTransfer`/`DeleteTransfer` (reverse
volumes). Bottling: `CreateBottling` (writes `PRODUCTION_IN` via `StockLedger`,
optional new inventory item, BOTTLED cleanup), `DeleteBottling` (reverse).
Protocols: `AssignFermentationTemplate`, `GenerateWorkOrdersFromProtocol`,
`Create/Update/Delete FermentationTemplate`. Enological: CRUD + `AdjustStock`.

### 3.6 Queries / read endpoints
- **Cellar Map** — `CellarMapQuery`: vessels (+ their non-bottled `vessel_lots`
  with lot summary + latest free-SO₂) grouped by room. `GET /vessels` (map view)
  and layout-mutation endpoints under `vessels`.
- **One-Wine** (lot detail) — `WineLotDetailQuery` loads the lot with vessels,
  analyses, additions, processes, transfers, tasting notes, bottlings, grapes;
  `GET /wine-lots/{id}`. `LotAnalysisTrendQuery` → time-series for charts.
- **Analyses** — `GET /wine-lots/{id}/analyses` + the nested write endpoints;
  bulk insert `POST /wine-lots/analyses/bulk`.
- **Protocols** — `GET /fermentation-templates`, assign + generate endpoints.
- **Fermentation monitor** — `FermentationMonitorQuery` (alerts: stuck ferment,
  temp out of band, high VA, high pH, MLF complete, ferment complete, stale/no
  analysis) → `GET /cellar/fermentation-monitor`.

### 3.7 Routes (shape)
```php
Route::middleware(['tenant', 'module:cellar'])->group(function () {
    Route::middleware('can:cellar.view')->group(function () {
        Route::get('vessels', ...); Route::get('wine-lots', ...);
        Route::get('wine-lots/{wineLot}', ...);
        Route::get('cellar/fermentation-monitor', ...);
        Route::get('fermentation-templates', ...); Route::get('enological-products', ...);
    });
    Route::middleware('can:cellar.manage')->group(function () {
        // vessel CRUD + layout, lot CRUD + volume/vessel/blend ops,
        // analyses/additions/processes/tastings, transfers, bottling,
        // template CRUD + assign/generate, enological CRUD + stock
    });
    Route::middleware('can:cellar.delete')->group(function () { /* destroys */ });
});
```

---

## 4. Vineyards module

Source: `order-mgmt/src/actions/harvest.actions.ts`, the `harvest-predictions`,
`analyze-vineyard`, and `weather` API routes.

### 4.1 Tables
| Table | Key columns |
|---|---|
| `vineyard_parcels` | `name`, `grape_variety`, `area_hectares decimal(8,4)?`, `location?`, `elevation int?`, `soil_type?`, `planting_year int?`, `row_spacing decimal(5,2)?`, `vine_count int?`, `rootstock?`, `training?`, `orientation?`, `slope decimal(5,2)?`, `latitude decimal(9,6)?`, `longitude decimal(9,6)?`, `geo_polygon json?`, `geo_area_calculated decimal(10,4)?`, `ownership` (enum OWN/COOPERANT, default OWN), `cooperant_supplier_id?` (FK suppliers), `weather_station_id?`, `is_active bool`, `notes?` |
| `phenology_logs` | `parcel_id` (FK cascade), `created_by_id`, `date`, `stage` (enum), `progress_percent decimal(5,2)?`, `photo_url?`, `note?` |
| `maturity_samples` | `parcel_id` (FK cascade), `created_by_id`, `date`, `brix decimal(5,2)?`, `ph decimal(4,2)?`, `total_acidity decimal(5,2)?`, `temperature decimal(5,2)?`, `note?` |
| `crop_estimates` | `parcel_id` (FK cascade), `created_by_id`, `date`, `cluster_count int`, `avg_cluster_weight decimal(8,2)`, `sample_vine_count int`, `estimated_yield_kg decimal(12,3)` (computed), `note?` |
| `vineyard_applications` | `parcel_id` (FK cascade), `created_by_id`, `date`, `type` (enum SPRAY/FERTILIZER/HERBICIDE/OTHER), `product?`, `dosage?`, `phi_days int?`, `phi_end_date date?` (computed), `weather?`, `note?` |
| `grape_contracts` | `supplier_id` (FK), `parcel_id?` (FK), `season`, `status` (enum), `grape_variety`, `estimated_kg decimal(12,3)`, `delivered_kg decimal(12,3) default 0`, `price_per_kg bigInteger` (Money), `min_brix decimal(5,2)?`, `max_ph decimal(4,2)?`, `delivery_window?`, `payment_terms?`, `notes?` |
| `harvest_plans` | `name`, `season`, `status` (enum PLANNING…), `yield_ratio decimal(5,3) default 0.650`, `created_by_id`, `notes?` |
| `harvest_entries` | `harvest_plan_id` (FK cascade), `parcel_id?` (FK), `contract_id?` (FK), `supplier_id?` (FK), `planned_vessel_id?` (FK), `wine_lot_id?` (FK, unique), `status` (enum PLANNED/HARVESTED/PROCESSED), `source` (enum OWN/CONTRACT/EXTERNAL), `grape_variety?`, `planned_date?`, `estimated_yield_kg decimal(12,3)?`, `actual_date?`, `actual_yield_kg decimal(12,3)?`, `actual_volume_liters decimal(12,3)?`, `grape_price_per_kg bigInteger?`, `brix decimal(5,2)?`, `ph decimal(4,2)?`, `titrable_acidity decimal(5,2)?`, `temperature decimal(5,2)?`, `condition_score int?`, `condition_notes?`, `photo_url?`, `notes?` |
| `intake_bookings` | `harvest_plan_id?` (FK), `supplier_id?` (FK), `date`, `time_slot?`, `grape_variety?`, `estimated_kg decimal(12,3)?`, `grower_name?`, `status` (enum SCHEDULED/ARRIVED/PROCESSED/CANCELLED), `notes?` |
| `press_fractions` | `harvest_entry_id` (FK cascade), `wine_lot_id?` (FK), `vessel_id?` (FK), `fraction_type` (enum FREE_RUN/LIGHT_PRESS/HARD_PRESS/MUST), `volume_liters decimal(12,3)`, `yield_percent decimal(5,2)?`, `press_program?`, `pressure_bar decimal(5,2)?`, `note?` |

**Delta to existing `suppliers`:** add `is_cooperant bool default false` (set when
a grape contract / cooperant parcel references the supplier).

### 4.2 Services
- **`CropYieldEstimator`** — `(cluster_count × avg_cluster_weight × vine_count) / sample_vine_count / 1000`.
- **`PhiCalculator`** — `phi_end_date = date + phi_days`.
- **`HarvestIntakeService`** — the heart of **Intake**: validate entry is PLANNED,
  compute `actual_volume = actual_yield_kg × plan.yield_ratio` (or override),
  compute grape cost, **create or blend into a `WineLot`** (via Cellar's
  `LotNumberGenerator` + `BlendService`), create the `WineLotGrape`, optionally
  assign to a vessel (`VesselVolumeSync`), flip entry → HARVESTED. (Cross-module:
  Vineyards calls Cellar services.)
- **`GrowerPerformanceQuery`** — fulfillment % (`delivered/estimated`), avg
  brix/pH across deliveries, reliability % across a supplier's contracts.
- **`HarvestRequirementsCalculator`** — demand-driven planning: given finished
  products + quantities + yield ratio, expand recipes, group by grape variety,
  match parcels, suggest vessels, surface material shortfalls + supplier orders;
  feeds `AutoPopulateHarvestPlan`.

### 4.3 Actions
Parcels: CRUD + `UpdateParcelGeo`. Agronomy: create/delete for phenology,
maturity, crop estimate (auto-yield), vineyard application (auto-PHI). Contracts:
CRUD (mark supplier cooperant; delete only if `delivered_kg = 0`). Harvest plans:
CRUD (+ `AutoPopulateHarvestPlan`). Harvest entries: `AddHarvestEntry`,
`UpdateHarvestEntry`, `RemoveHarvestEntry` (PLANNED only), **`RecordHarvestIntake`**
(→ `HarvestIntakeService`). Intake bookings: CRUD + status transitions. Press
fractions: create/delete.

### 4.4 Queries / endpoints
`GET /vineyard-parcels` (+ `{id}` detail with agronomy + active contracts +
entries), `GET /grape-contracts` (+ grower performance), `GET /harvest-plans`
(+ `{id}` detail with entries/bookings/fractions), `GET /intake-bookings`
(calendar filter by plan/date), `GET /harvest-entries`. Predictions endpoint
`GET /vineyard-parcels/harvest-predictions` (see §5).

### 4.5 Routes
`Route::middleware(['tenant','module:vineyards'])` with `can:vineyards.view` /
`can:vineyards.manage` / `can:vineyards.delete` groups, same shape as §3.7.

> **Decision (recommended):** `harvest_plans`/`harvest_entries`/`intake_bookings`
> live under **Vineyards** (the source "Harvest" area). The **Production** module
> owns only the finished-goods **Planner** (`production_plans`). This keeps the
> prefix→module map unambiguous. If a tenant has Vineyards without Production they
> still get full harvest/intake; Production adds the bottling-demand planner on top.

---

## 5. Production module (Planner)

Source: `production.actions.ts`, `production-plan.actions.ts`.

### 5.1 Tables
| Table | Key columns |
|---|---|
| `production_plans` | `name`, `status` (enum DRAFT/CONFIRMED/CANCELLED), `created_by_id`, `confirmed_at?`, `notes?` |
| `production_plan_rows` | `plan_id` (FK cascade), `base_item_id` (FK inventory_items), `created_item_id?` (FK inventory_items), `new_vintage?`, `quantity decimal(12,3)`, `plan_unit` (enum liters/bottles/cases), `sort_order int default 0` |

Reuses existing Inventory columns added in the first plan (`base_product_id`,
`is_auto_created`, `auto_created_at`, recipes) — no Inventory schema change needed.

### 5.2 Services
- **`ProductionCalculator`** — the Planner engine: normalise plan units → bottles,
  expand recipes (handle `pack_size`, ceil to whole packs), resolve costs from
  supplier price items (cheapest), compute per-product revenue/margin, aggregate
  material requirements, group shortfalls by supplier into suggested supplier
  orders. Pure/deterministic → heavily unit-tested. (All the formulas are
  enumerated in the research notes; replicate exactly, using `Quantity` + `Money`.)
- **`PlanConfirmationService`** — vintage management on confirm: detect
  name+vintage conflicts; without `force` return the conflict list; with `force`
  link to existing or **auto-create** a new `InventoryItem` (`SKU = base-SKU-vintage`
  dedup, clone recipe, `base_product_id` lineage, `is_auto_created=true`,
  `is_active=false`). Then `cleanupAutoCreatedProducts()` (30-day, zero-stock,
  unreferenced) — reuse/extend Inventory's existing auto-created concept.

### 5.3 Actions / endpoints
`ListPlans`, `GetPlan`, `CreatePlan`, `UpdatePlan` (bulk upsert rows),
`DeletePlan` (DRAFT only), **`CalculateProductionPlan`** (→ `ProductionCalculator`,
read-only compute endpoint), **`ConfirmPlan`** (→ `PlanConfirmationService`).
Optionally `GenerateSupplierOrdersFromShortfall` (reuse Suppliers module's
create-order action). Routes under `Route::middleware(['tenant','module:production'])`
with `can:production.*` groups.

---

## 6. AI & external integrations (Phase G — iterative / partly out of scope)

These existed in the source but depend on external services or AI; treat
separately so the core CRUD ships first.

- **Analysis OCR** (`parse-analysis`, `bulk-analysis`) — **port via the existing
  AI pipeline**: add an `app/Services/Ai/Extractors/WineAnalysisExtractor`, expose
  through the established `ai-imports` flow, **gated by the `AiDataEntry` module**
  (consistent with current scan/extract features). Output maps to
  `AddCellarAnalysis` / bulk insert. *In scope, after Cellar core.*
- **Fermentation monitor** (`fermentation-monitor`) — pure computation over
  `cellar_analyses`; **fully in scope** as a Cellar Query (§3.6).
- **Harvest predictions** (`harvest-predictions`) — linear-regression Brix trend +
  days-to-target is pure PHP (**in scope**, `HarvestPredictionService`); the
  **weather** overlay is an outbound HTTP call — wrap in an injectable
  `WeatherProvider` (mockable in tests), respect the env network policy, and make
  it degrade gracefully when unavailable.
- **Satellite vineyard analysis** (`analyze-vineyard`: high-res imagery + CV row
  detection + Claude vision) — **recommend OUT OF SCOPE for this port** / separate
  spike. It needs a satellite provider + a CV component with no Laravel analogue;
  it is not required for Parcels CRUD. Flagged as an open question.

---

## 7. Testing strategy

Mirror `tests/Feature/Orders/*` using `InteractsWithTenancy` + `RefreshDatabase`,
`Sanctum::actingAs`, `X-Tenant` header. Minimum per module:
- **Module gating:** a tenant whose plan lacks the module gets `403 module_not_in_plan`
  on its endpoints; a tenant whose plan includes it (or has no plan) passes.
- **Capability gating:** `*.view` vs `*.manage` vs `*.delete` enforced.
- **Tenant isolation:** cross-tenant read/write blocked (global scope).
- **Core invariants (unit + feature):**
  - Vessel volume always equals Σ vessel_lots; bottling frees vessels (no ghosts).
  - Lot-number sequence is tenant-scoped and collision-safe.
  - SO₂ dose, crop-yield, PHI, blend-composition, harvest-volume formulas.
  - Intake transitions PLANNED→HARVESTED and creates/blends a lot atomically.
  - Production calculator margins/requirements; confirm conflict + auto-create + cleanup.
  - Bottling writes `PRODUCTION_IN` through `StockLedger` (Inventory link).

---

## 8. Open questions (recommend defaults, confirm if you disagree)

1. **Billing granularity** — bill Analyses/Protocols/Intake as part of their
   parent module (Cellar/Vineyards)? *Recommended: yes.* Splitting later is a
   registry-only change.
2. **Harvest planning home** — `harvest_plans` under Vineyards, Planner under
   Production (per §4.5)? *Recommended: yes.*
3. **Satellite `analyze-vineyard`** — defer to a separate spike? *Recommended: yes*
   (external imagery + CV; not needed for Parcels CRUD).
4. **Weather provider** — which API (the source's provider, or Open-Meteo)? Needs
   the env network policy to allow it; otherwise ship predictions without the
   weather overlay.
```

