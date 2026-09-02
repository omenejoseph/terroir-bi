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
| "Cover" column ("About 16 months") | No exit-rate / consumption model |
| "Level" bar captioned "of N max" | Only `min_stock` exists. The bar reads against the MINIMUM instead, and turns red below it — reusing `min_stock` as a maximum would invert the bar's meaning |
| "Reserved by open orders" chip | Same reservation gap as "Free to sell" |

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
