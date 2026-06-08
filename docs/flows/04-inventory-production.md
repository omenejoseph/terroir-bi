# Flow 04 — Inventory Production (from Recipe / BOM)

Produce a quantity of a finished/semi-finished item by consuming its recipe
inputs. Source: `inventory.actions.ts → produceItem`, `recipe.actions.ts`.

## Preconditions
- Output item has a recipe (`recipe_items` with `output_id = item`).
- Every input has sufficient `current_stock`.

## Sequence

```mermaid
sequenceDiagram
    actor Staff
    participant API as POST /inventory-items/{id}/produce
    participant DB as DB (transaction)

    Staff->>API: { display_quantity }   // e.g. 120 bottles
    API->>DB: load output + recipe inputs
    loop each input
        API->>API: needed = recipe.quantity × display_quantity
        API->>API: assert input.current_stock ≥ needed
    end
    API->>API: storage_qty = unit==cases ? display_quantity / bottles_per_case : display_quantity
    API->>DB: BEGIN
    loop each input
        API->>DB: stock_movement (PRODUCTION_OUT, -needed, ref=PROD-{sku})
        API->>DB: input.current_stock -= needed
    end
    API->>DB: stock_movement (PRODUCTION_IN, +storage_qty, ref=PROD-{sku})
    API->>DB: output.current_stock += storage_qty
    API->>DB: COMMIT
    API-->>Staff: ok
```

## Recipe definition & costing (`PUT /inventory-items/{id}/recipe`)

```mermaid
flowchart TD
    A[Set recipe items] --> B{input id starts with 'lot-'?}
    B -- yes --> C[resolve wine lot → ensure RAW_MATERIAL mirror item exists]
    C --> D[auto-create item: group=Wine, unit=liters, cost=grape_cost/initial_volume]
    B -- no --> E[use input item id]
    D --> F[replace all recipe_items for output]
    E --> F
    F --> G[cost_per_bottle = Σ input.cost_per_unit × qty]
    G --> H{output unit == cases?}
    H -- yes --> I[cost_per_display = cost_per_bottle × bottles_per_case]
    H -- no --> J[cost_per_display = cost_per_bottle]
    I --> K[update output.cost_per_unit]
    J --> K
```

## Side effects
- Inputs: **`PRODUCTION_OUT`** movements (negative) + stock decremented.
- Output: **`PRODUCTION_IN`** movement (positive) + stock incremented.
- Setting a recipe **auto-recomputes and stores** the output's `cost_per_unit`
  (which later feeds the order COGS snapshot in Flow 01).
- Wine-lot recipe inputs **auto-create** a `RAW_MATERIAL` inventory mirror item
  (`sku = lot_number`, `group = Wine`) — the only place inventory items are
  auto-created from the cellar side.

## Unit/case arithmetic
- Output stored in cases: `display_quantity` (bottles) is divided by
  `bottles_per_case` for storage; movement records the storage quantity.
- Recipe `quantity` is expressed **per bottle**; multiply by `display_quantity`
  (bottles) to get consumption.

## Constraints
- Recipe input rule: `output_id != input_id`; no duplicate inputs; `(output_id,input_id)` unique.
- `getAvailableInputs` restricts eligible inputs by output category
  (`SEMI_FINISHED` may only consume `RAW_MATERIAL`) and surfaces `READY`/`AGING`
  wine lots with `current_volume > 0` as virtual inputs.