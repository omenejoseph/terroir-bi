# 05 — Pricing Engine (price resolution)

> This is the single most important business algorithm in the system. Get it
> wrong and customers are billed incorrectly. Source: `src/lib/pricing.ts`
> (`resolvePrice`, `resolvePricesForCustomer`).

## 5.1 Inputs

To resolve a price you need: a **customer** (with its `rebate_percent` and
optional `pricing_tier`) and an **inventory item** (with `default_price`).
Three pricing layers can apply, plus a rebate.

| Layer | Table | Meaning |
|---|---|---|
| Customer-specific price | `customer_prices(inventory_item_id, customer_id)` | Absolute negotiated price for this one customer |
| Tier price | `tier_prices(inventory_item_id, pricing_tier_id)` | Price book for the customer's tier |
| Default price | `inventory_items.default_price` | List price |
| Rebate | `customers.rebate_percent` **or** `pricing_tiers.rebate_percent` | Percentage discount |

## 5.2 Precedence (highest wins)

```
1. CustomerPrice exists?      → return it AS-IS   (NO rebate applied)
2. else TierPrice exists?     → apply rebate to it, return
3. else default_price exists? → apply rebate to it, return
4. else                       → return 0
```

## 5.3 Rebate selection

The rebate is **not** additive. Customer-level rebate overrides tier-level:

```
rebate_percent =
    customer.rebate_percent > 0
        ? customer.rebate_percent
        : (customer.pricing_tier?.rebate_percent ?? 0)
```

Applied as:

```
final = round( base * (1 - rebate_percent/100), 2 )   // only when rebate_percent > 0
```

Important: the **customer-specific price (layer 1) ignores rebate entirely** —
it is treated as the final negotiated number. Rebate only modifies tier price
and default price.

## 5.4 Decision diagram

```mermaid
flowchart TD
    A[resolvePrice customer, item] --> B{CustomerPrice exists?}
    B -- yes --> R1[return CustomerPrice.price  •  no rebate]
    B -- no --> C{Customer has pricing_tier?}
    C -- yes --> D{TierPrice exists for item+tier?}
    D -- yes --> E[base = TierPrice.price]
    D -- no --> F[base = item.default_price or 0]
    C -- no --> F
    E --> G{rebate_percent > 0?}
    F --> G
    G -- "customer.rebate>0" --> H[rebate = customer.rebate_percent]
    G -- "else tier.rebate>0" --> I[rebate = tier.rebate_percent]
    G -- "neither" --> J[rebate = 0]
    H --> K[final = round base*（1-rebate/100), 2]
    I --> K
    J --> L[final = base]
    K --> M[return final]
    L --> M
```

## 5.5 Worked examples

| Scenario | CustomerPrice | TierPrice | default_price | customer.rebate | tier.rebate | Result |
|---|---|---|---|---|---|---|
| Negotiated price wins | 50.00 | 100.00 | 120.00 | 10% | 5% | **50.00** (rebate ignored) |
| Tier + customer rebate | – | 100.00 | 120.00 | 10% | 5% | **90.00** (10% off tier) |
| Tier + tier rebate only | – | 100.00 | 120.00 | 0% | 5% | **95.00** (5% off tier) |
| Default + tier rebate | – | – | 120.00 | 0% | 5% | **114.00** |
| No tier, default + customer rebate | – | – | 120.00 | 10% | – | **108.00** |
| Nothing priced | – | – | null | – | – | **0.00** |

## 5.6 Batch resolution

`resolvePricesForCustomer(customerId, [itemIds])` loops `resolvePrice` per item
and returns `{ itemId: finalPrice }`. Used by:

- Order creation form (pre-fill unit prices)
- **Public catalog** (`getPublicCatalog`) — falls back to default price
- **Public order verification** (`createPublicOrder`) — re-resolves and rejects
  any client-submitted `unit_price` that does not match the server value

## 5.7 Laravel implementation sketch

```php
class PricingService
{
    public function resolve(Customer $customer, InventoryItem $item): string // decimal string
    {
        // 1. Customer-specific absolute price — no rebate.
        $cp = CustomerPrice::where('customer_id', $customer->id)
            ->where('inventory_item_id', $item->id)->value('price');
        if ($cp !== null) return $this->money($cp);

        // 2. Tier price, else 3. default price.
        $base = null;
        if ($customer->pricing_tier_id) {
            $base = TierPrice::where('pricing_tier_id', $customer->pricing_tier_id)
                ->where('inventory_item_id', $item->id)->value('price');
        }
        $base ??= $item->default_price ?? 0;

        // Rebate: customer overrides tier.
        $rebate = $customer->rebate_percent > 0
            ? $customer->rebate_percent
            : (optional($customer->pricingTier)->rebate_percent ?? 0);

        $final = $rebate > 0 ? $base * (1 - $rebate / 100) : $base;
        return $this->money($final);
    }

    private function money($v): string { return number_format((float) $v, 2, '.', ''); }
}
```

> Use bcmath / decimal handling rather than float arithmetic for production
> money math; the example uses float only for parity with the current code's
> `Math.round(x*100)/100` behavior. In the implemented backend, prices are stored
> as **integer minor units** and resolved via `app/Services/Pricing/PricingService.php`
> (`round(base × (10000 − rebate_bp) / 10000)`).

## 5.8 Unit scaling & catalog gates 🆕

The resolver returns a **per-bottle** price. Order/catalog code derives the line
price from the chosen unit:

```
case price   = bottle_price × inventory_item.bottles_per_case
bottle price = bottle_price
```

Catalog visibility and orderable units layer on top of pricing (they don't change
the resolved number):

- `inventory_items.hide_from_portal` and `is_for_sale` gate which items appear.
- `customer_product_overrides.visible` overrides visibility per customer.
- `customers.hide_prices` masks prices in the self-service catalog.
- `customers.allow_single_bottle` exposes the per-bottle option (else case-only).

**Agency pricing** (`customers.is_agency`) is a *separate* hospitality price book
(`hospitality_agency_prices`, price-per-pax) and does **not** flow through this
resolver. It belongs to the out-of-scope Hospitality module; keep the `is_agency`
flag but defer the price book.