# 07 — External Integrations

Three external integrations: **Moj-eRačun** (Croatian e-invoice network),
**Anthropic Claude** (document/image parsing), and **object storage**.

---

## 7.1 Moj-eRačun (e-invoice)

Source: `src/lib/e-racun-client.ts`, `src/actions/e-racun.actions.ts`.

- **Base URL:** `https://legacy-mer.moj-eracun.hr/apis/v2`
- **Auth:** every request body carries `{ Username, Password, CompanyId, SoftwareId }`.
  In SaaS these come from **`tenant_secrets`** (encrypted), **not** global env vars.

### Endpoints used

| Client method | HTTP | Path | Purpose |
|---|---|---|---|
| `ping()` | GET | `/Ping/` | Connectivity test |
| `queryInbox(from?,to?,statusId?)` | POST | `/queryInbox` | List incoming invoices (`InboxItem[]`) |
| `queryOutbox(from?,to?)` | POST | `/queryOutbox` | List outgoing invoices |
| `receive(electronicId)` | POST | `/receive` | Download UBL XML for one invoice |
| `updateProcessStatus(electronicId,statusId,rejectReason?)` | POST | `/UpdateDokumentProcessStatus` | Set process status (approve/reject/paid…) |
| `markPaid(electronicId,paidDate)` | POST | `/MarkPaid` | Mark invoice paid |

### `InboxItem` shape

```
ElectronicId:number, DocumentNr, DocumentTypeId, DocumentTypeName,
StatusId:number, StatusName, SenderBusinessNumber(OIB), SenderBusinessUnit,
SenderBusinessName, Sent(ISO), Delivered(ISO|null)
```

### Status codes

**Delivery `status`:** `10 Preparing, 20 Validating, 30 Sent, 40 Delivered, 45 Canceled, 50 Unsuccessful`
**`processStatus`:** `0 Approved, 1 Rejected, 2 Paid, 3 Partially Paid, 4 Confirmed, 99 Received`

### Sync pipeline (→ becomes per-tenant scheduled job)

The current `autoSyncInbox()` (no auth, fired on page load) and manual
`syncInbox(from,to)` (ADMIN) do:

1. `queryInbox()` → list of `InboxItem`.
2. **Upsert** each as an `e_invoices` row (match by `electronic_id`); new ones
   tagged `process_status = 99` (Received); resolve supplier by `SenderBusinessNumber` (OIB).
3. For new invoices: `receive(electronicId)` → store `xml_content`, parse UBL to
   extract `total_amount, invoice_date, due_date, currency, recipient_*`.
4. **Auto-create cost** (`autoCreateCost`): find/create supplier (OIB-first, then
   name with legal-suffix stripping), infer category, build `Cost` + `CostItem`s
   from parsed lines, link `e_invoice.cost_id`, learn supplier price list.
5. Backlog passes: process invoices that have XML but no cost; fetch XML for any missing.

Cost status is derived via `mapToCostStatus()`:
- `processStatus == 2` → `PAID`
- `processStatus in {0,4}` → `APPROVED`
- delivery `status == 40` → `APPROVED`
- else → `PENDING`

### Laravel rebuild notes

- Wrap the client as `EracunClient` taking per-tenant credentials in the constructor.
- Replace fire-and-forget with `php artisan schedule` → dispatch
  `SyncEinvoicesJob` **per active tenant that has credentials** (e.g. every 15–30 min).
- Parse UBL XML with a dedicated parser service (the current `parseInvoiceXml`
  helper); keep raw XML in `xml_content` for audit/reprocessing.
- All sync work runs inside a re-bound tenant context (job payload carries `tenant_id`).

---

## 7.2 Anthropic Claude (document & image parsing)

Source: `src/app/api/parse-*`, `upload-invoice`, `suggest-category`.

> **Model IDs in the current code are dated** (`claude-haiku-4-5-20251001`,
> `claude-sonnet-4-20250514`). On rebuild, target the latest models — e.g.
> **Opus 4.8** (`claude-opus-4-8`) for hardest extraction, **Sonnet 4.6**
> (`claude-sonnet-4-6`) for the default parsing tier, **Haiku 4.5**
> (`claude-haiku-4-5-20251001`) for cheap/fast classification. Confirm current
> IDs against the Claude API reference before coding.

| Endpoint | Current model | max_tokens | Input | Output |
|---|---|---|---|---|
| parse-bank-statement | Haiku | 16384 (streamed) | image/PDF ≤20 MB | `{transactions:[{date,description,amount,type,counterparty,reference,category}]}` |
| parse-order-screenshot | Haiku | 1024 | image ≤10 MB | `{customerName,customerId,customerConfidence,items:[{extractedName,quantity,unit,matched*,confidence,candidates[]}],notes}` |
| parse-receipt | Sonnet | 4096 (streamed) | image/PDF ≤20 MB | `{vendor,vendorOib,date,totalAmount,currency,reference,category,items[]}` |
| suggest-category | Haiku | 100 | `{description,existingCategories?}` | `{category}` |

Key behaviors to preserve:
- **Context injection:** parse prompts are seeded with the tenant's known
  suppliers (name, OIB) and recent costs/categories so output stays consistent.
- **"We are the buyer"** guard in receipt parsing (don't mistake our own company
  as the vendor; the source hardcodes Vina Bibich OIB `73774625855` — make this
  the **tenant's** `company_oib` from settings instead).
- **Croatian terminology** hints in prompts (Izvod, Opis plaćanja, Primatelj, OIB, PDV, Ukupno…).
- **Fuzzy matching** of parsed products/customers via the matcher service
  (`product-matcher.ts`): tiered exact → SKU → prefix → substring → Jaccard →
  Levenshtein, with vintage boost; confidence buckets high/medium/low/none.
- Strip markdown code fences from model output before JSON parse.

Rebuild as a queued `DocumentParsingService` (uploads can be large; don't block
the request). Use the tenant's Anthropic key if per-tenant, else the platform key.

---

## 7.3 Object storage

Source: `src/app/api/upload/route.ts`, `upload-invoice/route.ts` (Vercel Blob).

- Replace Vercel Blob with an S3-compatible `Storage` disk.
- **Image upload:** compress to WebP, max 1200px, ~80% quality (Sharp →
  Intervention Image or a queued `ImageOptimizeJob`). Limit 10 MB; types
  jpeg/png/webp/gif.
- **Invoice upload:** store original (PDF/image, ≤20 MB) then parse.
- **Tenant-prefixed keys** and signed URLs — see
  [`04-multi-tenancy-and-security.md`](04-multi-tenancy-and-security.md) §4.7.

---

## 7.4 Configuration matrix

| Setting | Scope | Where it lives (target) |
|---|---|---|
| Moj-eRačun Username/Password/CompanyId/SoftwareId | per-tenant | `tenant_secrets` (encrypted) |
| Company OIB (buyer identity for parsing) | per-tenant | `tenant_settings.company_oib` |
| Default currency / locale | per-tenant | `tenant_settings` |
| Anthropic API key | platform (or per-tenant) | env or `tenant_secrets` |
| Storage bucket / region | platform | env; keys prefixed per tenant |