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

### Correction: "Cover" is NOT a gap

An earlier revision listed Cover (and by implication velocity, realised margin
and exit-by-channel) as missing. That was wrong.
`InventoryItemStockAnalyticsQuery` already returns `days_of_stock_left`,
`velocity_per_day`, `cost_of_exits`, `revenue_realized`,
`mean_margin_percent`, a daily `spark` series, trailing-12-month `realized`
figures and `channels`. Product Detail (`449:1577`) is almost entirely backed.

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
