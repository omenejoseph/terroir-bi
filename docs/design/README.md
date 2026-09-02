# Design cache — TERROIR Figma

Local, checked-in copy of what was extracted from the Figma design, so the port
can continue **without further Figma calls**.

| File | Contents |
|---|---|
| `tokens.json` | Colour / radius / type / spacing variables (`get_variable_defs`, node `208:5577`) |
| `screens.json` | Every section and screen on the Desktop canvas (`get_metadata`, node `32:2`) |
| `frames/**/*.xml` | Per-screen layer trees — **12,657 nodes** with exact `x`/`y`/`width`/`height`, and **4,214 text nodes** whose `name` carries the visible copy |
| `frames/index.json` | Section → screen → node id → file map |
| `EXPORTING.md` | What to export from Figma by hand, and what is already covered here |

Between them, `frames/` and `tokens.json` mean **layout, sizing and copy for all
42 screens are already available offline**. What they cannot show is
appearance — fills, shadows, states — which is what a PNG render adds. See
`EXPORTING.md`.

## Source

- File: `8sj4JnUBwmvwOK5VPrFLHJ` — "TERROIR (Copy)"
- Canvas: `32:2` ("Desktop") — **6 sections, 52 screens**
- Captured: 2026-09-02

The original file (`Wgak7ikeFSL3MPCxugI6Yj`) is **not reachable** by the
authorized Figma account; the copy above is. Use the copy's file key.

## Why this cache exists

The Figma account is on a **Starter** plan: **20 MCP tool calls per month**, not
per day. Screen-by-screen extraction would exhaust that in one sitting, so
everything pulled is written here and treated as the source of truth. Read this
folder first; only call Figma for something genuinely absent.

## Where the tokens are applied

`resources/css/app.css`. Every primitive references a token, so re-theming is a
one-file change. Keep that block and `tokens.json` in step.

Notable corrections these tokens forced over the initial placeholder:

- The sidebar is **light `#fafafa`**, not a dark charcoal rail.
- Canvas is **`#ffffff`**, not a warm alabaster tint.
- Foreground is **`#0a0a0a`**; the neutral ramp is `#737373` / `#525252`.
- Component radius is **8px**, not 14px.

## Renders

`renders/**/*.png` are 2x exports made by hand from Figma, filed by section and
named `<screen>--<node-id>.png`; `renders/index.json` maps each back to its
section, screen and node. They were exported with Figma's own filenames and
renamed programmatically — there is no need to rename by hand.

Where a render and a `frames/*.xml` capture disagree, **the render wins**: the
XML was captured 2026-09-02 and the design has moved since.

### Geometry drift observed 2026-09-02 → export

| Screen | Node | Cached height | Rendered height |
|---|---|---|---|
| Edit Customer | `231:9592` | 933 | **1288** |
| Individual Order | `453:4938` | 1024 | **1404** |

Both grew, so those two frames gained content after the capture. Re-capture
their metadata before working on them, or measure from the render.

### Which "Inventory Check"?

The export is `271:12639` (the original, 1369×1497). The canvas also holds
`388:1592` "Inventory Check — rethought" (1369×1219), which the screen plan
prefers. Confirm with the designer which is current before building it.

## Placeholders and `@todo`

Screens are built complete: every control and section the design shows is
rendered, even where the behaviour behind it does not exist. Each carries an
`@todo` in the component naming what is missing and what would implement it —
`grep -rn "@todo" resources/js` lists the outstanding work.

What is never faked is a **figure**. A card with no data says so ("not
tracked", "Runway is not calculated yet") or withholds the total, rather than
showing a plausible number.

## Known deviations

**Icons.** The design's own layer names call out Lucide (`lucide/ticket-check`,
`lucide/wallet-cards`, `Lucide Icons / hourglass`), so the app uses
`lucide-vue-next` — the same set, and the set the outgoing React app used. The
exported SVG assets could **not** be downloaded: the Figma asset host is blocked
by the execution environment's egress policy (HTTP 403 at the proxy). If a glyph
ever disagrees with the design, the design wins — export it by hand.

**Typeface.** Figma's `family/sans` variable says **Inter**, but every text node
in the design renders **Figtree**. The app loads Figtree. Worth confirming with
the designer which is intended.

**Nav rail.** The design has two nav states: `ExpandedNav` (`547:1568`, 240px,
implemented) and a collapsed `NavRail` used on the Dashboard screen. The
collapsed state is not built yet.

## Gaps — design ahead of the backend

The Dashboard design (`208:5577`) specifies cards the API cannot yet supply.
These are deliberately **absent** from the Vue page rather than filled with
invented numbers:

| Design element | Missing backing data |
|---|---|
| "Revenue vs. target" (68% of annual target) | No annual revenue target is stored |
| "Target by channel" + pace commentary | No per-channel targets |
| "Runway — 4,2 months", net cash flow, expense split | No runway/expense-category aggregate |
| "Reorder pipeline" | No reorder-pipeline query |
| "DTC Revenue" | No direct-to-consumer channel |

Inventory (`389:1592`) adds three more:

| Design element | Missing backing data |
|---|---|
| "Free to sell" column | Order lines do not reserve stock, so there is nothing to subtract |
| "Level" bar captioned "of N max" | Only `min_stock` exists. The bar reads against the MINIMUM instead, and turns red below it — reusing `min_stock` as a maximum would invert the bar's meaning |
| "Reserved by open orders" chip | Same reservation gap as "Free to sell" |

Orders (`455:1577`, `376:1592`, `335:4233`) adds five:

| Design element | Missing backing data |
|---|---|
| Pipeline stages "Draft / Confirmed / Picking-packed / Delivered" | `OrderStatus` is Received / In Process / Ready to Ship / Shipped. The card keeps the design's shape and shows those four plus Invoiced and Paid (derived from inflows) — relabelling real statuses to a fulfilment vocabulary the system does not track would report something the data does not mean. See `App\Queries\OrderPipelineQuery` |
| Toolbar filters "Channel / Date range / Rep" | Channel would have to be inferred from the customer's type (a different axis), and there is no order-owner column for Rep. Rendered, with a `@todo` |
| "Bulk actions" and "Columns" | No multi-select or column-preference layer on orders |
| Profitability's "Rebate · 18%" and "Net revenue" rows | Line totals are stored with the rebate already applied and the gross figure is not kept, so the three-line split cannot be reconstructed without assuming the arithmetic. Shown as Revenue / COGS / Gross profit / Margin |
| Comment reactions (emoji counts) | No reactions table |

Customers (`230:2395`, `230:4717`, `231:9336`) adds five:

| Design element | Missing backing data |
|---|---|
| Type column values "Hotel" and "Restaurant" | `App\Enums\CustomerType` is Wholesale / Retail / Agency / Shipshop / Other. Offering types no customer can hold would make the Type filter return nothing, so the enum's labels are used |
| Analytics' "Rebate performance" card | No per-line rebate is stored — order lines keep the post-rebate total only — so the cost of rebates across the book cannot be summed. The fourth card reports average order value, which the query does supply |
| Overview's "Where the money goes" (gross profit vs COGS) | Per-customer COGS is not computed anywhere. The card splits revenue on an axis the data does track: direct sales against consignment |
| "Price ladder" and "Concentration" cards | Both need per-category revenue-per-bottle and a concentration measure; the products query supplies volume and share, so those two cards are folded into "Products bought" rather than fabricated |
| "Next 3 months" forecast card | `CustomerOrderAnalyticsQuery` supplies `expected_next_3m`, but only where a prior year exists to compare against; the design's card is the empty state for exactly that case and is deferred with the forecast work |

Three controls the design draws but never specifies were built in the app's own
vocabulary rather than guessed at: the **combobox** behind "Search product by
name, SKU or vintage…" (`335:4233`), and the **calendar** behind the Orders
"Custom" period tab, the "Date range" toolbar filter (`455:1577`) and the
customer detail's "Products bought" range (`231:9336`). The canvas has a
`SelectPopup` (`267:6332`) whose geometry the combobox follows — 32px options,
12px labels, a 16px check on the selected one — but no calendar of any kind.

Two Customers elements are built beyond what the design specifies, because the
backing existed and the affordance would otherwise be a dead button. **Merge**
(the selection bar) opens a drawer that asks which record survives — a merge
needs that decision and the bar cannot express it — then calls
`CustomerMergeService`. The **"Signal"** column on "Products bought" is derived
from order coverage rather than written by hand; a product appearing in most of
a customer's orders earns "In nearly every order", and anything the coverage
cannot justify gets no label at all.

Two Orders elements are deliberately built differently rather than omitted.
The **Create Order** drawer shows list prices with a caption saying the
customer's tier and rebate are applied on submit — `OrderLineWriter` is the
only thing that may price a line, and quoting a number it will not honour
would be worse than admitting the estimate. The **"Import screenshot"** button
is rendered with a `@todo`: it is a whole OCR feature, not a missing column.

### Correction: "Cover" is NOT a gap

An earlier revision listed Cover (and by implication velocity, realised margin
and exit-by-channel) as missing. That was wrong.
`InventoryItemStockAnalyticsQuery` already returns `days_of_stock_left`,
`velocity_per_day`, `cost_of_exits`, `revenue_realized`,
`mean_margin_percent`, a daily `spark` series, trailing-12-month `realized`
figures and `channels`. Product Detail (`449:1577`) is almost entirely backed.

### Inventory list: reorder grip is presentational

`389:1592` puts a drag grip at the head of every row and band. `sort_order`
exists on the model, but drag-to-reorder is not wired and
`BulkUpdateInventoryItemsRequest` does not accept `sort_order`. The grip renders
(the design's rhythm depends on that column) but carries no interaction and is
hidden from assistive tech rather than announcing a control that does nothing.

The list also omits two columns the design shows: **Free to sell** (no order
reservations) and **Cover** (computable per item, but only via the spend query
— worth wiring when the list gains a per-item exit rate).

### Item — View: two sections not built

`378:1592` carries two sections the schema cannot yet fill:

| Section | Missing |
|---|---|
| "Who's buying it" | No per-item customer attribution — needs order lines rolled up by customer, plus a share-of-their-volume figure |
| "Timeline" | No audit trail. Stock movements cover adjustments, but not "item created" or field edits |

Its free-to-sell waterfall also deducts **Allocated**, **Reserved by open
orders** and **On consignment**. None of the three exists, so each row says so
rather than showing `0` — a zero asserts there are none, which is a claim the
data cannot make — and the `= Free to sell` total is withheld rather than
silently equalling physical stock.

### But "Exit by channel" is on a different axis

The design's card breaks exits down by **customer sales channel** — Internal /
POS, Distributor / Importer, Retailer / Shop. The query breaks them down by
**movement type**, yielding `sales` / `production` / `manual`. Both are called
"channel" and neither is wrong, but they answer different questions.

Showing the design's version needs sales-channel attribution carried from the
order (and its customer) onto the stock movement. Until then the card renders
the movement-type axis with honest labels.

`revenue_by_channel` returns `wholesale / retail / agency / shipshop / other`;
the design shows `Wholesale / Accommodation / Agencies`. The channel taxonomy
needs reconciling before that card can be exact.

Each of these is a backend feature, not a port — scope them before promising
pixel parity on the dashboard.

## Refreshing

```
get_metadata(fileKey, nodeId)        # structure — cheap, cache to screens.json
get_variable_defs(fileKey, nodeId)   # tokens — needs a FRAME, not a canvas
get_design_context(fileKey, nodeId)  # reference code + screenshot — the expensive one
```

`get_design_context` output for large frames is truncated at ~100k chars; pull a
child region instead of the whole screen when that happens.
