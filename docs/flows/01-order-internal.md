# Flow 01 — Internal Order Creation

Staff member creates an order on behalf of a customer. Source:
`orders.actions.ts → createOrder`.

## Preconditions
- Authenticated user (any role that can create orders).
- Customer exists; inventory items exist and are sellable.

## Sequence

```mermaid
sequenceDiagram
    actor Staff
    participant API as POST /orders
    participant Price as PricingService
    participant DB as DB (transaction)

    Staff->>API: { customer_id, notes?, items:[{inventory_item_id, quantity, unit_type, unit_price}] }
    API->>API: Validate (FormRequest)
    API->>Price: (optional) verify/resolve unit prices for customer
    API->>DB: BEGIN
    API->>DB: total = Σ(unit_price × quantity)
    API->>DB: order_number = generateOrderNumber()
    loop each item
        API->>DB: cost_per_unit = item.cost_per_unit OR Σ(recipe input.cost × qty) [COGS snapshot]
    end
    API->>DB: INSERT order (status=RECEIVED, total, customer_id, created_by)
    API->>DB: INSERT order_status_history (RECEIVED, "Order created")
    loop each item
        API->>DB: INSERT order_item (incl. cost_per_unit snapshot)
        API->>DB: INSERT stock_movement (ORDER_DEDUCT, -quantity, ref=order_number)
        API->>DB: inventory_item.current_stock -= quantity
    end
    API->>DB: COMMIT
    API-->>Staff: { order_id, order_number }
```

## Side effects
- `orders` row created with status **RECEIVED**.
- One `order_status_history` row (`RECEIVED`, note "Order created").
- Per line: an `order_items` row, a **`ORDER_DEDUCT`** `stock_movements` row
  (negative), and `current_stock` decremented immediately.
- **COGS snapshot** frozen onto each line's `cost_per_unit` (from item cost or
  recipe roll-up) so margin is immune to later cost changes.

## Status lifecycle (shared by all order flows)

```mermaid
stateDiagram-v2
    [*] --> RECEIVED
    RECEIVED --> IN_PROCESS
    IN_PROCESS --> READY_TO_SHIP
    READY_TO_SHIP --> SHIPPED
    SHIPPED --> [*]
    note right of RECEIVED
        Transitions are not strictly enforced in the
        source — any → any is allowed via PATCH /orders/{id}/status,
        each writing an order_status_history row.
        Recommended: enforce forward-only in the rebuild.
    end note
```

## Adding items later (`POST /orders/{id}/items`)
Same per-item logic: snapshot COGS, append `order_item`, `ORDER_DEDUCT`
movement, decrement stock, and **increment `order.total_amount`**.

## Deleting an order (ADMIN, `DELETE /orders/{id}`)
Restores stock: per item an **`ADJUSTMENT`** movement (positive) + `current_stock`
incremented, then the order (and its cascade children) is deleted.

## Notes for Laravel
- Wrap in a single DB transaction (the source uses Prisma `$transaction`).
- `generateOrderNumber()` should be tenant-scoped and collision-safe.
- Validate `unit_type ∈ {bottles, cases}`, `quantity ≥ 1`, `unit_price ≥ 0`.