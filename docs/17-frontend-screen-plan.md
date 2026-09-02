# Frontend screen plan — phased, with definitions of done

The build order for porting the TERROIR design onto the Inertia frontend, and
the bar each screen must clear before it counts as done.

Companion documents: [`16-inertia-frontend-migration.md`](16-inertia-frontend-migration.md)
(architecture) and [`design/`](design/) (the cached Figma tokens and screen
inventory, plus the gap register).

---

## Where we are

**20 of 42 screens done. Phases 0–4 complete; Phase 5 (Work Orders) is next.**

| Phase | Screens | Status |
|---|---|---|
| 0 · Foundation | — | ✅ **Done** |
| 1 · Shared vocabulary | — | ✅ **Done for what Phase 2 needed**; 4 pieces deferred (below) |
| 2 · Inventory | 8 / 8 | ✅ **Done** |
| 3 · Orders | 4 / 4 | ✅ **Done** |
| 4 · Customers | 6 / 6 | ✅ **Done** |
| 5 · Work Orders | 0 / 6 | ⏭️ **Next** |
| 6 · Dashboard completion | partial | Blocked on backend |
| 7 · Retire React frontend | — | Last |

### Screens shipped

| Screen | Node | Verified against |
|---|---|---|
| ExpandedNav | `547:1568` | design context |
| Dashboard | `208:5577` | design context |
| Inventory list | `389:1592` | ⚠️ screenshot diff — no render exported |
| Product Detail | `449:1577` | render |
| Item — View (drawer) | `378:1592` | render |
| New Item (+ Advanced) | `317:468` / `322:704` | render |
| Bulk Edit | `270:9646` | render |
| Inventory Check | `271:12639` | render |
| Inventory Spend | `386:1673` | render |
| Analytics | `382:1592` | render |
| Orders list | `455:1577` | render |
| Order — View (drawer) | `376:1592` / `453:4938` | render |
| Create Order (+ Advanced) | `335:4233` / `335:4331` | render + frame XML |
| Customers list | `230:2395` | render |
| Customers · Analytics | `230:4717` | render |
| Customer detail · Overview | `231:9336` | render |
| Customer detail · Pricing / Order History / Komisija | `361:2157` | frame XML |
| Customer — Create / Edit (drawer) | `316:80` / `316:695` / `322:848` / `231:9592` | render |
| Merge customers (drawer) | — | not designed; built because the affordance is |

### Fidelity corrections applied across every built screen

These were found by measuring the exported renders and the cached Figma frame
XML rather than by eye, and they changed every screen at once. Recorded here so
the next screen starts from the corrected vocabulary instead of re-introducing
the drift.

| What was wrong | What the design actually specifies | Evidence |
|---|---|---|
| `--radius: 8px`, so every card, button, input, badge, chip, tab and bar was rounded | **Square corners everywhere.** The file *defines* `rounded-lg 8` / `radius-lg 10` as variables and applies them nowhere. The only curved shapes are the Switch track/knob and the small circular status dots | Corner profiles measured pixel-by-pixel in the 2x renders: cards, the header search field, the primary button and the tab pill all step from background to border with zero inset |
| Type ramp roughly one Tailwind step too large throughout (H1 30px, stat values 24px, body 13px) | **10 / 11 / 12 / 13 / 14 / 20 / 30.** H1 is 20px; stat values are 30px; body, buttons, chips, tabs and table cells are 12px; table column headers are 10px; 13px is used *only* for sidebar category headers | Figma text nodes carry their own advance widths in the cached frame XML; each was fitted against `canvas.measureText` in Figtree, so the sizes are exact rather than estimated |
| `tracking-tight` on headings and figures | Tracking is **0** everywhere (`tokens.json` records `tracking.normal: 0`) | Removing it brought the H1's ink box from 163 to 171 render px against the design's 169 |
| One button height (36px) | **Two.** Page toolbars are 28px with a 12px label; form footers are 36px with a 14px label | `382:1592` "Bulk Import" is 103x28; `322:704` "Create item" is 99x36 |
| Tabs defaulted to an underline variant | The design has **no underline tabs.** A 32px grey track holds a 24px flat pill — white for page-level navigation, solid dark for in-card pickers — plus standalone bordered filter buttons on the inventory category row | `382:1592` tab strip measured: track 32px, pill 24px, 12px horizontal padding, no shadow |
| Figtree loaded from Google Fonts at runtime | **Self-hosted** (`public/fonts/figtree`). A third-party font that fails to load silently falls back to a wider system face and breaks every measurement the design depends on — which is exactly what was happening in headless verification | `document.fonts.size` was 0 in the screenshot browser before the change |

### Deferred from Phase 1

None of these blocked Phase 2, so they were left until a screen needs them:

- **`DataTable` extraction** — still inline per screen, now across six tables. Customers was meant to force it and did not: the two customer tables are plain, but Inventory groups and bands, Orders stacks line items per row, and the customer detail's "Products bought" groups again. What they actually share is the chrome — the bordered card, the header band, the horizontal scroll container, the empty row — not the cells. **Extract that shell (not a column API) at Phase 5**, and leave the cells to each screen.
- **Global ⌘K search field** — `Kbd` exists; the field and its behaviour do not. `Combobox` is now most of what it needs.
- **Collapsed `NavRail`** — the design's second nav state.
- ~~**Combobox / date picker**~~ — ✅ **built**, ahead of Phase 5. `Combobox` is a type-to-filter select matching on hidden `keywords`, so a product is findable by SKU or vintage without either cluttering its label; it is adopted by the Orders customer and product pickers and the Customers tier picker. `Calendar` + `DateRangePicker` complete three controls the design draws but never specifies: the Orders period strip's "Custom" tab, the Orders toolbar's "Date range" filter (which now drives the same window rather than competing with it), and the customer detail's "Products bought" range. Short fixed enum lists (customer type, unit, order status) deliberately keep the native `Select` — five options need no filtering and the native control is better on touch.

### Open questions

1. **Which Inventory Check is current?** The build follows the exported original
   `271:12639`; the canvas also holds `388:1592` "Inventory Check — rethought".
2. **`389:1592` (Inventory list) has never been exported.** It was built from
   `get_design_context` early on and the claim that every screen had been
   render-diffed was wrong — it had not. A `get_screenshot` diff found several
   drifts (see below). Exporting it would still help for future changes.
3. **Channel taxonomy** — see the gap register in [`design/README.md`](design/README.md).

---

## Scope

The Figma Desktop canvas (`32:2`) holds **52 frames**. Nine are designer
annotations (`Note · …`) and one is a loose component, leaving **42 real
screens**:

| Kind | Count | Meaning |
|---|---|---|
| Full page (1369px) | 27 | A route with its own URL |
| Side panel (480px) | 14 | A drawer over the current page — **not** a route |
| Nav component (240px) | 1 | `ExpandedNav`, already built |

**That 480px column is the single most important planning fact in this
document.** The entire "Forms" section — Create Order, New Item, New Task,
Customer Create/Edit, Item View, Order View and their "Advanced open" states —
is one drawer system, not fourteen pages. Build the drawer once in Phase 1 and
each subsequent form is a body inside it. Treating them as pages would triple
the work and produce a UI that contradicts the design.

## Definition of done — every screen

A screen is done when **all nine** hold. No partial credit; a screen that misses
one is in progress.

1. **Routed correctly.** Registered in `routes/web.php` under `tenant.web`,
   carrying the same `can:*` gate as its API counterpart. A drawer adds no route.
2. **No duplicated logic.** The web controller consumes the existing
   Action / Query / Service. If logic is only in the API controller, extract it
   first and point both at the extraction — that refactor is part of the screen.
3. **Typed end to end.** Page props typed against a TypeScript mirror of the PHP
   DTO in `resources/js/types/`. No `any`, no untyped `usePage()`.
4. **Token-only styling.** Composed from `resources/js/components/ui`. No
   hard-coded colour, radius or font size anywhere in the page component.
5. **Green gate.** `./check.sh be` and `./check.sh in` both pass — Pint,
   `vue-tsc`, Vite build, full PHPUnit suite.
6. **Tested.** A feature test asserting the Inertia envelope
   (`assertInertia` → component + key props), one asserting a member *without*
   the capability is refused, and one per write action.
7. **Visually diffed.** Rendered at 1369px against its Figma node and compared.
   Not "it looks right" — screenshot both and check them side by side.
8. **Navigable.** Its `navigation.ts` entry moves from `href: null` to a real
   path, so the sidebar stops showing it disabled.
9. **Every element present; no invented data.** Build the whole screen the
   design shows, including controls and sections whose behaviour does not exist
   yet — those carry an `@todo` naming what is missing and what would implement
   it. Omitting them makes the app quietly diverge from the design, and the
   divergence is invisible until someone compares by eye.

   The line is between *chrome* and *figures*: render the card, the button and
   the column, but never fabricate a number to fill them. Say "not tracked",
   "not calculated yet", or withhold the total — an invented figure is a defect,
   not a placeholder. Data gaps still go in `docs/design/README.md`.

## Definition of done — every phase

- Every screen in the phase meets all nine criteria above.
- No regression: the full suite is green and no previously-ported screen broke.
- `docs/design/README.md` updated with any new gaps the phase surfaced.
- The phase's Figma extractions are cached in `docs/design/` so the work is
  reproducible without re-spending calls.

## The binding constraint: Figma quota

The authorised Figma account is on a **Starter** plan — **20 MCP tool calls per
month**, not per day. One `get_design_context` call per screen means **42 calls
minimum**, so at the current plan this work spans **three months or more**, and
that assumes zero calls wasted on re-reads.

Two ways out, and the plan should state which one is chosen:

- **Upgrade to Professional** with a Full/Dev seat → 200 calls/day, and the
  schedule collapses to the engineering effort alone. *Recommended.*
- **Stay on Starter** and follow the per-phase call budgets below, which assume
  one extraction per *pattern* rather than per screen, with variants inferred
  from the cache.

Every phase below lists its call budget on the Starter assumption.

---

## Phase 0 — Foundation ✅ DONE

Inertia + Vue 3 + TypeScript scaffold, session auth, session-based tenant
resolution, the shared service extractions, and the design tokens.

**Exit criteria — met:** session login/logout/tenant switch working; tokens
mirrored from Figma; `ExpandedNav` built from `547:1568`; 1153 tests green.

## Phase 1 — Shared vocabulary ✅ DONE (4 pieces deferred)

Nothing after this phase should invent a primitive. Everything later composes.

| Piece | Status |
|---|---|
| `PageHeader`, `Tabs`, `AttentionBand`, `LevelBar`, `ProgressBar`, `SectionHeader`, `MetaStrip`, `Separator` | ✅ built |
| `Button`, `Card`, `Input`, `Label`, `Badge`, `InputError`, `StatCard`, `FlashMessages` | ✅ built |
| **`SidePanel` drawer** — 480px, header/body/footer, "Advanced" disclosure | ✅ built, proven with New Item |
| **`DataTable`** — grouped rows, group subtotal band, sticky header | ⏳ deferred — extract when Orders needs a third list |
| **Global ⌘K search** in the topbar (`389:1592` header) | ⚠️ `Kbd` built; the field is not |
| **Collapsed `NavRail`** — the second nav state (`208:5577`) | ⏳ deferred |
| Form controls: `FormField`, `FieldRow`, `FormSection`, `Select`, `Textarea`, `Switch`, `SwitchRow`, `Checkbox` | ✅ built |
| Form controls: combobox, date picker | ⏳ deferred — first needed by Orders |

Components are derived from the design's own vocabulary rather than invented —
see [`design/COMPONENTS.md`](design/COMPONENTS.md), which counts every
`<instance>` and repeated frame name across all 54 cached frames and maps them
to Vue.

**DoD:** every piece rendered in isolation and visually diffed against its
Figma node; `SidePanel` demonstrated with one real form end to end (New Item);
`DataTable` extracted from Inventory with that screen still passing its tests.

**Figma budget: 2 calls** — `317:468` (New Item, the simplest drawer) and
`322:704` (its Advanced-open state). Every other drawer is a body swap.

## Phase 2 — Inventory ✅ DONE

Backend is complete for the list and detail; the analytics screens need work.

| Screen | Node | Size | Notes |
|---|---|---|---|
| Inventory (list) | `389:1592` | — | ✅ **done** |
| Product Detail | `449:1577` | L | ✅ **done** — stock tab; the other 7 tabs render disabled |
| Item — View (drawer) | `378:1592` | M | ✅ **done** — render-diffed |
| New Item (drawer) | `317:468` / `322:704` | M | ✅ **done** — delivered by Phase 1 |
| Bulk Edit | `270:9646` | M | ✅ **done** — a mode of the list, not a route |
| Inventory Check | `271:12639` | L | ✅ **done** — built from the exported original; the `388:1592` "rethought" variant is still undecided |
| Inventory Spend | `386:1673` | M | ✅ **done** — render-diffed |
| Analytics | `382:1592` | M | ✅ **done** |

**DoD:** all eight meet the nine criteria; `inventory` leaves `PENDING_MODULES`;
the Inventory tab strip has no disabled tabs left.

**Status: 8 of 8 done.** All four module tabs (Inventory, Analytics, Spend,
Check) are live and cross-linked, plus the New Item and Item — View drawers.

**Figma budget: 4 calls** — Product Detail, Bulk Edit, Inventory Check, Analytics.
Spend and the drawers derive from patterns already extracted.

## Phase 3 — Orders ✅ DONE

| Screen | Node | Size | Status |
|---|---|---|---|
| ORDERS — List | `455:1577` | M | ✅ **done** — render-diffed |
| Individual Order | `453:4938` | L | ✅ **done** — this frame is the Order — View drawer shown *in context* over the list, not a separate page |
| Order — View (drawer) | `376:1592` | M | ✅ **done** — render-diffed |
| Create Order (drawer) + Advanced | `335:4233` / `335:4331` | M | ✅ **done** |

**Status: 4 of 4.** `453:4938` turned out to be the same drawer as `376:1592`
over the list rather than a fourth build, so the phase is three surfaces.

What the backend gained, all shared with the API rather than duplicated:

- `OrderPresenter` — list and detail presentation, financial gating, presigned
  line thumbnails. `Api\OrderController` was refactored onto it, so the JSON
  API and the Vue drawer cannot disagree about an order.
- `OrderPipelineQuery` — the order-to-cash card, six stages in one pass.
- `OrderStatusCountsQuery` — the chip row; counts ignore the status filter so
  the chips do not collapse to the set you already selected.
- `OrderFormOptions` — the Create drawer's two pickers.
- `OrderFilters`, `OrderStatus::label()`, and `from`/`to` bounds on
  `ListOrdersQuery`.

Two rules the web layer adds and tests cover: `hide_shipped` is applied
server-side on every read (a member without the flag cannot reach a shipped
order by editing `?status=`), and the drawer's detail is an `Inertia::optional`
prop, so the list never pays for lines, timelines and comment threads nobody
opened.

The unauthenticated public token order page stayed on `routes/api.php`, as
planned — it resolves its tenant from the token, not the session.

**Design divergences** (all in the gap register): the pipeline's stage
vocabulary, the Channel/Date range/Rep filters, bulk actions and columns, the
profitability rebate split, and comment reactions.

## Phase 4 — Customers ✅ DONE

| Screen | Node | Size | Status |
|---|---|---|---|
| Customers (list) | `230:2395` | M | ✅ **done** — render-diffed |
| Analytics | `230:4717` | M | ✅ **done** — render-diffed |
| Individual Customer — Overview | `231:9336` | L (2046px) | ✅ **done** — render-diffed |
| Order History | `361:2157` | M | ✅ **done** — a tab of the detail page, not a route |
| Customer — Create / Edit (drawers) | `316:80` / `316:695` / `322:848` | M | ✅ **done** |
| Edit Customer | `231:9592` | M | ✅ **done** — this frame's page is a superseded iteration (see below); its drawer is what it contributes |

**Two frames describe the same customer page.** `231:9592` shows an earlier
layout behind its Edit drawer — "Realized sales", "YOY Growth", "Annual
projection" — while `231:9336` is the later, richer Overview with the order
rhythm strip, the money split, and "Products bought". The build follows
`231:9336`; `231:9592` contributed only its drawer. **Worth confirming with the
designer**, since the earlier frame has forecast cards the later one drops.

What the backend gained, all shared with the API rather than duplicated:

- `CustomerPresenter` — list and detail presentation with revenue gating.
  `Api\CustomerController` was refactored onto it.
- `DeleteCustomerAction` — the deactivate-vs-delete rule, which had been inline
  in the API controller and would have been copied into the web one.
- `CustomerAttentionQuery` — the overview's three-card band; each card either
  fires with its numbers or is absent, never a zero.
- `CustomerRhythmQuery` — the order-rhythm strip, using the same `OrderCadence`
  the reorder radar uses, so a customer overdue there is overdue here.
- `CustomerProductsQuery` — "Products bought", including a coverage-derived
  signal per product.
- `CustomerFilters`, and a `customer_type` filter on `ListCustomersQuery`.

`Customer::effectiveRebatePercent()` now uses the eager-loaded tier when the
caller has one; it was costing a query per row on any customer list.

**Pricing is the tab that matters.** It reports what each customer actually
pays and names the rule that decided it — customer price, tier price or list
price — by asking `PricingService` rather than re-deriving the precedence, and
a feature test pins all three cases including that a customer price is absolute
and takes no rebate.

The Order History tab reuses Phase 3's `ListOrdersQuery` + `OrderPresenter`
outright, including the shipped-visibility rule.

**Design divergences** (all in the gap register): the Type column's Hotel and
Restaurant values, the Analytics rebate-performance card, the overview's
gross-profit split, the price-ladder and concentration cards, and the
next-3-months forecast.

## Phase 5 — Work Orders ⏭️ NEXT

Seven frames, but they are **states of one screen**, not seven screens: Main
View, New Task, Existing Task, Filters, Recent Activity, Assign quickly, Search.

| Screen | Node |
|---|---|
| Main View + its five states | `267:1781` and siblings |
| New / View Task (drawers) | `317:369`, `318:402`, `321:618`, `324:862`, `325:982` |

**DoD:** the nine criteria; each of the five interaction states reachable and
visually diffed, not just the default view.

**Figma budget: 3 calls** — Main View, one filter state, one task drawer.

## Phase 6 — Dashboard completion

**Blocked on backend work, not on design.** The design specifies cards that no
query can currently supply. Each needs scoping as a feature before the screen
can be finished:

| Card | Missing |
|---|---|
| Revenue vs. target | No annual revenue target is stored |
| Target by channel + pace | No per-channel targets |
| Runway, net cash flow, expense split | No runway or expense-category aggregate |
| Reorder pipeline | No reorder-pipeline query |
| DTC Revenue | No direct-to-consumer channel |
| Free to sell / Cover (Inventory) | No order-line reservations; no exit-rate model |
| Max stock (Level bar) | Only `min_stock` exists, so the bar reads against the minimum |

Also unresolved: `revenue_by_channel` returns
`wholesale / retail / agency / shipshop / other`, but the design shows
`Wholesale / Accommodation / Agencies`. **The channel taxonomy needs a product
decision before that card can be exact.**

**DoD:** each row above is either implemented with a real query and a test, or
explicitly cancelled and struck from the design. No card ships with invented data.

## Phase 7 — Retire the React frontend

Only once every route the Next.js app serves has an Inertia equivalent.

**DoD:**
- `PENDING_MODULES` in `navigation.ts` is empty and no nav entry has `href: null`.
- Every route in `frontend/src/app` has a counterpart in `routes/web.php`, or is
  deliberately dropped with a note saying why.
- The public order (`/order/[token]`) and supplier-portal
  (`/supplier-portal/[token]`) pages are re-homed or consciously kept on the API
  — they are unauthenticated and cannot use session auth as-is.
- PWA and Web Push registration ported (`VAPID_PUBLIC_KEY` plumbing).
- `frontend/`, `deploy-frontend.sh`, the Cloudflare Worker and the `fe` target
  in `check.sh` are all deleted in one commit.
- The full suite green with no reference to `frontend/` remaining.

---

## Sequencing rationale

Phase 1 comes first because 14 screens are drawers and every later phase spends
them. Inventory precedes Orders because its backend is the most complete, so it
exercises the vocabulary with the least backend risk. Dashboard completion is
last of the build phases because it is the only one gated on product decisions
rather than engineering. Retirement is genuinely last: deleting `frontend/`
before parity removes the reference implementation the port is checked against.

## Risks

| Risk | Mitigation |
|---|---|
| Figma quota (20/month) throttles everything | Upgrade the plan, or hold to the per-pattern budgets and lean on `docs/design/` |
| Design outruns the backend (Phase 6) | Scope those queries as features now, in parallel with Phases 2–5 |
| Drawer system done badly | It is Phase 1 and gated on one real end-to-end form before anything depends on it |
| Icons drift from the design | The design uses Lucide by name; `lucide-vue-next` is pinned. Figma asset export is blocked by egress policy |
| Two frontends drift while both live | `frontend/` is reference-only — no new features land there |
