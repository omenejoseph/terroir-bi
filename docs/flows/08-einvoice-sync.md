# Flow 08 — Moj-eRačun Inbox Sync → Auto Cost Creation

Poll the tenant's Moj-eRačun inbox, store e-invoices, fetch & parse UBL XML, and
auto-create cost records. Source: `e-racun.actions.ts` (`autoSyncInbox`,
`syncInbox`, `autoCreateCost`, `findOrCreateSupplier`, `fetchAndStoreXml`,
`mapToCostStatus`), `e-racun-client.ts`.

> **Rebuild change:** the current app calls `autoSyncInbox()` fire-and-forget on
> page load with global credentials. In SaaS this becomes a **scheduled queued
> job per tenant** using that tenant's encrypted credentials.

## Sequence

```mermaid
sequenceDiagram
    participant Cron as Scheduler (per tenant)
    participant Job as SyncEinvoicesJob
    participant API as Moj-eRačun /apis/v2
    participant DB

    Cron->>Job: dispatch { tenant_id } (every 15–30 min)
    Job->>DB: load tenant_secrets (eracun_*)
    Job->>API: POST /queryInbox {creds, From?, To?}
    API-->>Job: InboxItem[]
    loop each item
        Job->>DB: upsert e_invoice by electronic_id
        Job->>DB: resolve supplier by SenderBusinessNumber (OIB)
        alt new
            Job->>DB: process_status = 99 (Received); track as new
        else existing
            Job->>DB: update status/statusName/dates
        end
    end
    loop new invoices (then backlog: xml-but-no-cost; then missing-xml ≤10)
        Job->>API: POST /receive {ElectronicId}
        API-->>Job: UBL XML
        Job->>DB: store xml_content + parse(total, dates, recipient, lines)
        Job->>Job: autoCreateCost()
    end
```

## autoCreateCost detail

```mermaid
flowchart TD
    A[invoice has xml, no cost] --> B[parse UBL → lineItems, total, dates]
    B --> C[findOrCreateSupplier senderOib, senderName]
    C --> C1{OIB match?}
    C1 -- yes --> S[use supplier]
    C1 -- no --> C2{name match (strip d.o.o./d.d./obrt…)?}
    C2 -- yes --> S2[use + backfill OIB]
    C2 -- no --> C3[create supplier  (handle P2002 race → refetch by OIB)]
    S --> D[determineCategory]
    S2 --> D
    C3 --> D
    D --> E[total = parsed ?? invoice.total ?? Σ lines]
    E --> F[INSERT cost + cost_items, status = mapToCostStatus]
    F --> G[e_invoice.cost_id = cost.id]
    G --> H[updateSupplierPriceList from lines]
```

## Status → cost status mapping (`mapToCostStatus`)
| Condition | Cost status |
|---|---|
| `processStatus == 2` (Paid) | `PAID` |
| `processStatus in {0,4}` (Approved/Confirmed) | `APPROVED` |
| delivery `status == 40` (Delivered) | `APPROVED` |
| otherwise | `PENDING` |

## Manual sync (`POST /e-invoices/sync`, ADMIN)
Same pipeline but operator-triggered with optional `from`/`to`; fetches XML and
auto-creates costs for **all** matched invoices (not only new), returns
`{ total, created, updated }`.

## Side effects
- `e_invoices` rows created/updated (idempotent on `electronic_id`).
- Suppliers auto-created/backfilled (OIB-first), price lists learned.
- `costs` (+`cost_items`) auto-created and linked 1:1 to the e-invoice.
- Errors are logged and non-blocking (one bad invoice doesn't halt the sync).

## Idempotency & isolation requirements
- Upsert by `(tenant_id, electronic_id)`; never double-create costs (guard:
  only auto-create when `cost_id` is null and `xml_content` present).
- Job payload carries `tenant_id`; the worker re-binds tenant context and loads
  that tenant's credentials only.