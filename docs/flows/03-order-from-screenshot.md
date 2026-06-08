# Flow 03 — AI-Assisted Order Capture (Screenshot)

Staff pastes a WhatsApp/email screenshot; the system extracts items and
customer, fuzzy-matches them to the catalog, and pre-fills a draft order which
staff confirms (then Flow 01 runs). Source: `api/parse-order-screenshot`,
`product-matcher.ts`.

## Sequence

```mermaid
sequenceDiagram
    actor Staff
    participant AI as POST /ai/parse-order-screenshot
    participant Claude as Anthropic (vision)
    participant Match as MatcherService
    participant DB
    participant Ord as POST /orders (Flow 01)

    Staff->>AI: multipart image (≤10MB jpeg/png/webp)
    AI->>Claude: image + extraction prompt (qty/unit rules, HR+EN)
    Claude-->>AI: { customerName?, items:[{extractedName, quantity, unit?}], notes? }
    AI->>DB: load active sellable FINISHED items + active customers (tenant-scoped)
    AI->>Match: matchCustomer(name, customers)
    loop each extracted item
        AI->>Match: matchProduct(extractedName, products)
        Note over Match: tiered exact → SKU → prefix → substring → Jaccard → Levenshtein (+vintage boost)
    end
    AI-->>Staff: items[] with matchedProductId + confidence + candidates[]
    Staff->>Staff: review / correct low-confidence matches
    Staff->>Ord: confirm → createOrder(customer_id, items[])
```

## Extraction parsing rules (preserve in prompt)
- `Shiraz 3#` → name `Shiraz`, qty 3, unit `cases` (`#` = cases).
- `Product N` (no marker) → unit `null` (ask staff).
- `Product Nx` / `Product xN` → unit `bottles`.
- Croatian: `boca/boce`=bottles, `kutija/karton/kut`=cases, `kom`=pieces.
- Preserve vintage in the product name (`Plavac 2022`).

## Matching confidence buckets
`≥0.85 high` · `≥0.6 medium` · `≥0.4 low` (no auto-pick) · `<0.4 none`. Always
return top-3 candidates so staff can override.

## Notes
- This flow **produces a draft** — no stock is touched until the staff confirm
  step runs Flow 01. Keep this human-in-the-loop.
- Run the Claude call in a queued job if latency matters; the matcher is local
  and fast.