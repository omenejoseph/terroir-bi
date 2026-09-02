# Frontend screen plan — phased, with definitions of done

The build order for porting the TERROIR design onto the Inertia frontend, and
the bar each screen must clear before it counts as done.

Companion documents: [`16-inertia-frontend-migration.md`](16-inertia-frontend-migration.md)
(architecture) and [`design/`](design/) (the cached Figma tokens and screen
inventory, plus the gap register).

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
9. **Gaps recorded, never faked.** Any design element without backing data is
   omitted and logged in `docs/design/README.md`. Inventing a number to fill a
   card is a defect, not a placeholder.

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

## Phase 1 — Shared vocabulary ⏳ PARTLY DONE

Nothing after this phase should invent a primitive. Everything later composes.

| Piece | Status |
|---|---|
| `PageHeader`, `TabNav`, `AttentionBand`, `LevelBar`, `PeriodTabs`, `ProgressBar` | ✅ built |
| `Button`, `Card`, `Input`, `Label`, `Badge`, `InputError`, `StatCard`, `FlashMessages` | ✅ built |
| **`SidePanel` drawer** — 480px, header/body/footer, "Advanced" disclosure | ✅ built, proven with New Item |
| **`DataTable`** — grouped rows, group subtotal band, sticky header | ⚠️ inlined in Inventory; extract |
| **Global ⌘K search** in the topbar (`389:1592` header) | ❌ not built |
| **Collapsed `NavRail`** — the second nav state (`208:5577`) | ❌ not built |
| Form controls: `FormField`, `Select`, `Textarea`, `Toggle` | ✅ built |
| Form controls: combobox, date picker | ❌ not built |

**DoD:** every piece rendered in isolation and visually diffed against its
Figma node; `SidePanel` demonstrated with one real form end to end (New Item);
`DataTable` extracted from Inventory with that screen still passing its tests.

**Figma budget: 2 calls** — `317:468` (New Item, the simplest drawer) and
`322:704` (its Advanced-open state). Every other drawer is a body swap.

## Phase 2 — Inventory

Backend is complete for the list and detail; the analytics screens need work.

| Screen | Node | Size | Notes |
|---|---|---|---|
| Inventory (list) | `389:1592` | — | ✅ **done** |
| Product Detail | `449:1577` | L | 1927px tall — the richest detail page in the file |
| Item — View (drawer) | `378:1592` | M | Drawer form of the same entity |
| New Item (drawer) | `317:468` / `322:704` | M | ✅ **done** — delivered by Phase 1 |
| Bulk Edit | `270:9646` | M | `BulkUpdateInventoryItemsAction` exists |
| Inventory Check | `388:1592` | L | Prefer the "rethought" node over `271:12639` |
| Inventory Spend | `386:1673` | M | `InventorySpendController` exists |
| Analytics | `382:1592` | M | `InventoryAnalyticsQuery` exists |

**DoD:** all eight meet the nine criteria; `inventory` leaves `PENDING_MODULES`;
the Inventory `TabNav` has no disabled tabs left.

**Figma budget: 4 calls** — Product Detail, Bulk Edit, Inventory Check, Analytics.
Spend and the drawers derive from patterns already extracted.

## Phase 3 — Orders

Smallest page count, high business value, backend largely in place
(`OrderController`, `OrderCommentController`, `OrderPaymentController`).

| Screen | Node | Size |
|---|---|---|
| ORDERS — List | `455:1577` | M |
| Individual Order | `453:4938` | L |
| Order — View (drawer) | `376:1592` | M |
| Create Order (drawer) + Advanced | `335:4233` / `335:4331` | M |

**DoD:** the nine criteria; order status transitions covered by tests; the
public token order page (`routes/api.php`) left on the API untouched — it is
unauthenticated and must not move to session auth.

**Figma budget: 3 calls.**

## Phase 4 — Customers

| Screen | Node | Size |
|---|---|---|
| Customers (list) | `230:2395` | M |
| Individual Customer — Overview | `231:9336` | L (2046px) |
| Edit Customer | `231:9592` | M |
| Customer — Create / Edit (drawers) | `316:80` / `316:695` / `322:848` | M |
| Order History | `361:2157` | M (two states) |
| Analytics | `230:4717` | M |

**DoD:** the nine criteria; pricing-tier and consignment behaviour covered by
tests, since `05-pricing-engine.md` makes customer pricing the most
consequential logic in the system.

**Figma budget: 4 calls.**

## Phase 5 — Work Orders

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
