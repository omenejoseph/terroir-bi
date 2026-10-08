# 16 — Migrating the legacy (Vercel/Prisma) Bibich data

One-way, repeatable import of the proof-of-concept's production database (single-tenant
Prisma/Neon Postgres) into one tenant of this app. Code: `app/Services/LegacyImport/`,
commands `legacy:import`, `legacy:reconcile`, `legacy:copy-media`.

## Runbook

1. **Source.** Restore the dump into a scratch Postgres DB (`psql -d bibich -f bibic.sql`) or
   point at a read-only Neon role. Configure the read-only `legacy` connection in `.env`:
   `LEGACY_DB_DATABASE` (+ `_HOST/_PORT/_USERNAME/_PASSWORD/_SSLMODE` if not local). The dump holds
   password hashes, portal tokens and push keys: keep it out of the repo.
2. **Target tenant.** `php artisan tenant:create --name="Bibic Winery" --slug=bibic-winery --currency=EUR --locale=hr --admin-first-name=… --admin-last-name=… --admin-email=… --admin-password=…`
   (the import reuses the admin user and restores their legacy password hash).
3. **Import.** `php artisan legacy:import --dry-run` (everything runs, then rolls back), then
   `php artisan legacy:import`. Options: `--only=orders,costs` (steps), `--tenant=<slug>`.
   Safe to re-run: rows are upserted by the `legacy_id_map` table (old cuid → new ULID).
4. **Reconcile.** `php artisan legacy:reconcile` compares counts and totals table by table
   (exit code 1 on any FAIL; JSON in `storage/app/private/legacy-import/`). A PASS means everything that
   should have arrived did, with the same totals — expected exclusions are listed below.
5. **Media.** `php artisan legacy:copy-media --dry-run` checks every Vercel Blob URL is reachable,
   then `php artisan legacy:copy-media` copies the files to the tenant's bucket namespace
   (`tenants/<id>/inventory/…`) and records size and content type. Needs the uploads disk
   (`UPLOADS_DISK`, default R2) configured. `--limit=N` for a trial, `--force` to re-copy.

## Conventions

- Rows are written with the query builder, **not** the Actions: legacy order numbers, stock levels,
  totals and timestamps are preserved, and no stock deduction, notification, push or audit entry
  fires. `inventory_items.current_stock` stays the legacy figure.
- Money: legacy `numeric` major units → bigint minor units (`round(x*100)`). Quantities → 3 decimals.
- Order numbers are kept verbatim (`266104`); `OrderNumberGenerator` only counts `ORD-` numbers,
  so new orders start at `ORD-00001`.
- A suspended "Legacy Import" user (`legacy-import@bibich.invalid`, cannot log in) owns rows whose
  creator was a test account or has no equivalent.

## Roles

Role names map one-to-one and each person's roles carry over. What each role can do is the old app's, enforced by `RoleCapabilities` and pinned by `tests/Feature/Members/RoleParityTest` (see report §7). Holding MANAGER removes work orders even alongside another role.

## Deliberate exclusions and mappings

| Area | Rule |
|---|---|
| Users | `admin@example.com` and the developer account are not migrated; comma-separated `role` → membership roles |
| Inventory | seed item `FP-REDWINE-001` skipped; `units`/blank sales unit → bottles; prices stay per bottle |
| Customers | `customerType` free text → enum with the same rules as the 2026_06_14 backfill (agency flag wins); blank email → `legacy+<id>@import.invalid`; `paymentTermsDays` dropped. The descriptive label (the same text) becomes a customer **category** via the non-destructive `customer_categories` step (creates missing categories, labels only customers that have none) |
| Suppliers | portal token kept only if the portal was enabled; whitespace-duplicate price items collapse to the newest |
| Orders | order line unit `units` → bottles; `deduct_stock` true only where the legacy ledger has an `ORDER_DEDUCT` for that number; `unit_price_gross` empty |
| Stock | movements of skipped items skipped; 181 `ORDER_DEDUCT` movements reference orders that no longer exist (kept as unlinked history); 3-decimal rounding |
| Costs | free-text categories kept; descriptions kept whole (columns are text); bank/e-invoice links dropped |
| Inflows | cancelled invoice + reversing credit note pairs skipped (net zero, would otherwise inflate receivables); description moved into notes; VAT carried (`vat_amount`); type INVOICE/PAYMENT collapsed |
| Cellar | volumes kept as legacy figures; disagreements with vessel contents are reported, not fixed |
| Work orders | `CANCELLED` imported as the Cancelled status; lists/`listOrder` dropped; boards lose privacy/members |
| Vineyards | crop estimates without sampling inputs get zeros (yield kept); dosage number+unit → one text |

`legacy:import` ends with a table of every legacy table that has data and no destination
(POS sync, hospitality, HR, CRM, e-invoices, bank transactions, …) with row counts and reasons.

## Verified against the 2026-10-03 dump

All 76 reconciliation checks pass (orders €819,960.02; costs €600,373.78; vessels 231,320 L
capacity; …). Informational: 8 items whose stock isn't explained by movements (opening balances),
1 vessel whose volume ≠ its contents.
