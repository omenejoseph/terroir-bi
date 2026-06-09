# Flow 02 — Public (Self-Service) Order via Token

A B2B customer places an order through a tokenized catalog link, **without
logging in**. Source: `public-order.actions.ts → getPublicCatalog, createPublicOrder`.

## Preconditions
- Customer has an `order_token` (issued by ADMIN via `POST /customers/{id}/order-token`).
- Customer `is_active = true`.

## Sequence — browse then order

```mermaid
sequenceDiagram
    actor Customer
    participant Cat as GET /public/{token}/catalog
    participant Ord as POST /public/{token}/orders
    participant Price as PricingService
    participant DB as DB (transaction)

    Customer->>Cat: open catalog link (token)
    Cat->>DB: find customer by order_token (resolves TENANT too)
    alt not found or inactive
        Cat-->>Customer: 404
    else ok
        Cat->>DB: load FINISHED + is_for_sale + has price
        Cat->>Price: resolvePricesForCustomer(customer, item_ids)
        Cat-->>Customer: { customer{hide_prices}, products[{price?, image}] }
    end

    Customer->>Ord: { items:[{inventory_item_id, quantity, unit_type, unit_price}], notes? }
    Ord->>DB: re-resolve customer + tenant by token (active?)
    Ord->>Price: resolvePricesForCustomer(...) [SERVER TRUTH]
    Ord->>Ord: reject if any submitted unit_price ≠ resolved price
    Ord->>DB: BEGIN
    Ord->>DB: created_by = system/admin user; total = Σ(price×qty)
    Ord->>DB: INSERT order (status=RECEIVED)
    Ord->>DB: INSERT order_status_history (RECEIVED, "Order placed by customer via self-service link")
    loop each item
        Ord->>DB: COGS snapshot; INSERT order_item
        Ord->>DB: INSERT stock_movement (ORDER_DEDUCT); current_stock -= qty
    end
    Ord->>DB: COMMIT
    Ord-->>Customer: { order_number }
```

## Security-critical rules
- **The token is the credential** and also resolves the tenant — one lookup
  binds both. No cross-tenant access possible because every subsequent query is
  tenant-scoped.
- **Prices are never trusted from the client.** The server re-resolves prices
  and rejects mismatches (anti-tampering). Same applies to which items are
  orderable (only active, FINISHED, `is_for_sale`).
- Order is attributed to a **system/admin user** as `created_by` (audit trail
  preserved), not to the customer.
- Respect `customers.hide_prices` in the catalog response.
- See [`../04-multi-tenancy-and-security.md`](../04-multi-tenancy-and-security.md)
  §4.6 for token entropy, rate limiting, and rotation.

## 🆕 Catalog gating (post-snapshot)
The catalog is filtered by, in order:
- `is_active = true`, `category = FINISHED`, `is_for_sale = true`, **not** `hide_from_portal`;
- **plus** any item explicitly set `visible = true` in `customer_product_overrides` for this customer (and minus any set `visible = false`);
- sorted by group → subcategory → `sort_order`.

Orderable **units** respect `customers.allow_single_bottle` — when false, only
case quantities are offered even if the bottle price resolves.

## 🆕 Abuse controls
- **Rate limit:** ~10 orders per token per hour.
- **Quantity bounds:** 1–99,999 per line.
- Longer transaction timeout (cold starts) — keep the deduction logic identical to flow 01.

## Side effects
Identical to internal order creation (status RECEIVED, history, per-item
ORDER_DEDUCT + stock decrement, COGS snapshot, NEW_ORDER notifications) — only
the actor (system/admin user) and the history note differ.