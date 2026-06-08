# Winery Order Management — Re-Architecture Blueprint

This folder is the **single source of truth** for rebuilding the current
(vibe-coded) Next.js + Prisma application as a **Laravel API backend** for a
**multi-tenant B2B SaaS**.

The current app is a winery / cellar management system: it tracks production
(grapes → wine lots → bottling → finished goods), inventory, B2B customers and
pricing, orders (internal + customer self-service), supplier costs, bank
statement reconciliation, and Croatian e-invoice (Moj-eRačun) integration.

Everything here is derived directly from the existing source code, so the
target Laravel system can be recreated module-by-module without losing business
rules.

## How to read this folder

| # | Document | What it gives you |
|---|----------|-------------------|
| 00 | [`01-system-overview.md`](01-system-overview.md) | Domain context, glossary, current → target stack mapping, role model |
| 01 | [`02-modules.md`](02-modules.md) | The 8 bounded contexts / consumable modules and their boundaries |
| 02 | [`03-entity-data-model.md`](03-entity-data-model.md) | Full ERD (Mermaid), relationship map, and per-table data dictionary |
| 03 | [`04-multi-tenancy-and-security.md`](04-multi-tenancy-and-security.md) | Tenant isolation model, recommended strategy, security controls, threat checklist |
| 04 | [`05-pricing-engine.md`](05-pricing-engine.md) | The price-resolution precedence rules (the single most important business algorithm) |
| 05 | [`06-api-reference.md`](06-api-reference.md) | REST endpoint catalogue grouped by module, auth, request/response shapes |
| 06 | [`07-integrations.md`](07-integrations.md) | Moj-eRačun e-invoice, Anthropic AI parsing, object storage |
| 07 | [`flows/`](flows/) | **Resource-creation flow diagrams** (one file per pathway, Mermaid sequence + state) |
| 08 | [`openapi/openapi.yaml`](openapi/openapi.yaml) | Machine-readable OpenAPI 3.1 spec (import into Postman/Swagger/codegen) |

## Flow diagrams index

The `flows/` folder documents every resource-creation pathway end to end:

- [`flows/01-order-internal.md`](flows/01-order-internal.md) — Staff creates an order (stock deduction + COGS snapshot)
- [`flows/02-order-public-token.md`](flows/02-order-public-token.md) — Customer self-service order via tokenized catalog link
- [`flows/03-order-from-screenshot.md`](flows/03-order-from-screenshot.md) — AI-assisted order capture from a WhatsApp screenshot
- [`flows/04-inventory-production.md`](flows/04-inventory-production.md) — Producing finished goods from a recipe (BOM)
- [`flows/05-cellar-lot-lifecycle.md`](flows/05-cellar-lot-lifecycle.md) — Wine lot lifecycle, vessel allocation, transfers
- [`flows/06-cellar-bottling.md`](flows/06-cellar-bottling.md) — Bottling: lot volume → finished inventory + COGS roll-up
- [`flows/07-cost-bank-import.md`](flows/07-cost-bank-import.md) — Bank statement import, dedup, supplier match → costs
- [`flows/08-einvoice-sync.md`](flows/08-einvoice-sync.md) — Moj-eRačun inbox sync → auto cost creation
- [`flows/09-tenant-onboarding.md`](flows/09-tenant-onboarding.md) — New tenant signup & provisioning (new for SaaS)

## Conventions used in these docs

- **Tables** use `snake_case` (Laravel/Eloquent convention). The current Prisma
  models use `PascalCase`; a mapping is given in the data model doc.
- Every tenant-owned table gains a `tenant_id` foreign key — see the
  multi-tenancy doc for why and how it is enforced.
- Money is stored as `decimal(14,2)`; volumes/weights as `decimal(12,3)`.
- All IDs are recommended to stay as **ULIDs/UUIDs** (the current app uses
  `cuid`), never auto-increment integers, to avoid cross-tenant enumeration.