# Exporting the design by hand

**Short answer: yes, and it is the better path — but export less than you'd expect.**

The Figma account is capped at 20 MCP calls a month, which cannot cover 42
screens. Hand-exporting removes that ceiling entirely. But most of what those
calls would fetch is *already cached in this folder*, so the manual export only
needs to cover the two things the cache genuinely lacks.

## What is already here — do NOT re-export

| Already cached | Where | Covers |
|---|---|---|
| Layer tree + exact geometry | `frames/**/*.xml` | **12,657 nodes** — every element of every screen, with `x`, `y`, `width`, `height` |
| All visible copy | same files | **4,214 text nodes**, content carried in the `name` attribute, including the Croatian sample data |
| Design tokens | `tokens.json` | Colours, radii, type ramp, spacing |
| Screen inventory | `screens.json`, `frames/index.json` | All 52 frames, classified |

So spacing, sizing, layout structure, labels and sample content are **solved**.
Measuring them off a PNG would be strictly worse than reading the XML.

## What to export — the two real gaps

### 1. Screen renders (highest value)

The cache describes geometry but cannot show *appearance* — fills, strokes,
shadows, states. One PNG per screen closes that.

In Figma: select the frames → Export → **PNG, 2x** → Export.

**Keep whatever filenames Figma gives them.** Figma names each file after its
layer (`ORDERS - List.png`), and `frames/index.json` maps every layer name to
its node id, so they can be matched up afterwards. Renaming by hand is wasted
effort and a chance to introduce mistakes.

Drop them anywhere under:

```
docs/design/renders/
```

Subfolders are optional. Duplicated layer names across sections are the only
ambiguity, and there are only two (`Analytics`, `New Item`) — if in doubt, put
those in a subfolder named after their section.

**Priority order** — matching the phases in `../17-frontend-screen-plan.md`:

1. `317:468` New Item, `322:704` New Item — Advanced open  *(unblocks the drawer system, and with it 14 screens)*
2. `449:1577` Product Detail, `270:9646` Bulk Edit, `388:1592` Inventory Check, `382:1592` Analytics
3. `455:1577` Orders List, `453:4938` Individual Order, `376:1592` Order — View, `335:4233` Create Order
4. `230:2395` Customers, `231:9336` Customer Overview, `231:9592` Edit Customer, `230:4717` Analytics
5. `267:1781` Work Orders Main View + its four state variants

### 2. Icon SVGs

The MCP server returns icon assets as `figma.com` URLs, and **that host is
blocked by this environment's egress policy (HTTP 403)** — the assets cannot be
fetched from inside a session, only by you.

Good news: the design's own layer names call out Lucide by name
(`lucide/ticket-check`, `lucide/wallet-cards`, `Lucide Icons / hourglass`), and
the app already uses `lucide-vue-next`. **So only export an icon if it is NOT a
stock Lucide glyph.** Put any such custom marks in:

```
docs/design/icons/<name>.svg
```

Export as SVG with "Include id attribute" off and outline stroke on.

## What is not worth exporting

- **The `.fig` file.** Nothing in the toolchain can read it.
- **PDF exports.** Worse than PNG for inspection, larger, no benefit.
- **Whole-canvas exports.** A single image of all 42 screens is unusable at any
  resolution that keeps detail legible. Export per frame.
- **Anything for the Dashboard's blocked cards.** Those are gated on backend
  data that does not exist (see `README.md`), not on design access.

## After exporting

Nothing needs to be regenerated. Commit the files and say so — renders are read
directly when building each screen, and `frames/**/*.xml` is already the
measurement source of truth.

If a render and the cached XML ever disagree, the render wins: it reflects the
current file, whereas the XML was captured on 2026-09-02.
