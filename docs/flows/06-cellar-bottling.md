# Flow 06 — Bottling (Lot → Finished Inventory + COGS)

Convert bulk wine-lot volume into counted bottles, write finished-goods stock,
and roll up cost into a per-bottle COGS. Source: `cellar.actions.ts → createBottling`.
Requires `ADMIN` or `CELLAR`.

## Sequence

```mermaid
sequenceDiagram
    actor Cellar
    participant API as POST /wine-lots/{id}/bottlings
    participant DB as DB (transaction)

    Cellar->>API: { bottle_count, bottle_volume_ml=750, inventory_item_id?, note? }
    API->>API: volume_used = round(bottle_count × bottle_volume_ml / 1000, 2)
    API->>API: assert lot.current_volume ≥ volume_used

    rect rgb(235,245,255)
    note over API: COGS roll-up
    API->>DB: total_addition_cost = Σ cellar_additions.total_cost
    API->>API: total_lot_cost = (lot.grape_cost ?? 0) + total_addition_cost
    API->>API: cost_per_liter = total_lot_cost / lot.initial_volume
    API->>API: cost_per_bottle = round(cost_per_liter × bottle_volume_ml/1000, 2)
    end

    API->>DB: BEGIN
    API->>DB: INSERT bottling (volume_used, counts, item?)
    API->>DB: lot.current_volume -= volume_used; if ≤0 status=BOTTLED
    loop each vessel_lot of this lot
        API->>DB: deduct PROPORTIONALLY (ratio = vl.volume / Σ vessel volume)
        API->>DB: vessel.current_volume -= deduct; sync status
    end
    API->>DB: sync RAW_MATERIAL mirror item.current_stock = lot.current_volume
    opt inventory_item_id (finished product)
        API->>DB: item.current_stock += bottle_count
        API->>DB: if cost_per_bottle>0 → item.cost_per_unit = cost_per_bottle
        API->>DB: stock_movement (PRODUCTION_IN, +bottle_count, unit=bottles, ref=lot_number)
    end
    API->>DB: COMMIT
    API-->>Cellar: ok
```

## COGS model
```
total_lot_cost  = grape_cost + Σ addition.total_cost
cost_per_liter  = total_lot_cost / lot.initial_volume      // initial, not current
cost_per_bottle = cost_per_liter × (bottle_volume_ml / 1000)
```
This `cost_per_bottle` becomes the finished item's `cost_per_unit`, which then
feeds the **order COGS snapshot** (Flow 01) — closing the grape-to-margin loop.

## Side effects
- New `bottlings` row; lot volume consumed; lot → **BOTTLED** if emptied.
- Vessel volumes reduced **pro-rata** across all the lot's vessel allocations.
- RAW_MATERIAL mirror item stock re-synced to remaining lot volume.
- If a finished item is targeted: stock incremented, `cost_per_unit` set, and a
  **`PRODUCTION_IN`** stock movement recorded (`ref = lot_number`).

## Reverse (`DELETE /bottlings/{id}`)
- Returns `volume_used` to the lot; **forces lot status back to AGING**.
- Decrements the finished item stock by `bottle_count`; deletes the matching
  `PRODUCTION_IN` movement.
- Vessel volumes are **not** auto-restored (assume manual racking if needed).

## Notes for Laravel
- Use decimal math; the source rounds to 2 dp at each step.
- `bottle_volume_ml` typical values 750 / 375 / 1500.
- Guard against divide-by-zero when `initial_volume` or total vessel volume is 0.