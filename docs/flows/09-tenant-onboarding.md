# Flow 09 — Tenant Onboarding & Provisioning *(new for SaaS)*

There is no equivalent in the current single-tenant code; this is the
SaaS-specific bootstrap pathway. It creates the `tenant`, its first `ADMIN`
user, default settings, and (optionally) seed reference data.

## Sequence

```mermaid
sequenceDiagram
    actor Prospect
    participant API as POST /signup
    participant DB as DB (transaction)
    participant Job as ProvisionTenantJob

    Prospect->>API: { company_name, slug, admin_name, admin_email, password, default_locale=hr, plan? }
    API->>API: validate; assert slug not taken
    API->>DB: BEGIN
    API->>DB: INSERT tenant (status=TRIAL, plan)
    API->>DB: INSERT tenant_settings (default_currency=EUR, locale, company_oib=null)
    API->>DB: INSERT user (role=ADMIN, bcrypt cost 12)  [tenant_id bound]
    API->>DB: COMMIT
    API->>Job: dispatch ProvisionTenantJob { tenant_id }
    API-->>Prospect: { token, tenant{slug} }

    Job->>DB: seed default pricing tiers (e.g. Wholesale, Retail)
    Job->>DB: seed cost categories / translation defaults (optional)
```

## What gets provisioned
- `tenants` row (status `TRIAL`, linked `plan`).
- `tenant_settings` (currency, locale, empty `company_oib`).
- First `users` row with role `ADMIN` (the tenant owner).
- Optional seed: default pricing tiers, starter translation overrides.
- **No** integration secrets yet — the admin sets Moj-eRačun credentials later
  via `PUT /tenant/secrets/eracun` before e-invoice sync (Flow 08) can run.

## Post-onboarding checklist surfaced to the admin
1. Set company OIB (used as the "we are the buyer" identity in receipt parsing).
2. Add Moj-eRačun credentials (enables e-invoice auto-sync).
3. Create inventory items / import catalog.
4. Add customers & pricing tiers.
5. Invite team users (`TEAM` / `CELLAR` / `ORDERS`).

## Isolation guarantees established here
- Every row created in this flow carries the new `tenant_id` (via the
  `BelongsToTenant` creating-hook).
- The returned auth token embeds `tenant_id` + roles; all subsequent requests
  are tenant-scoped by the global scope.
- `slug` uniqueness is the only **global** uniqueness introduced; everything
  else is tenant-scoped.

## Tenant teardown (related)
Cancellation sets `tenants.status = CANCELED`; a background
`PurgeTenantJob` exports then deletes/anonymizes tenant data (never a raw FK
cascade). See [`../04-multi-tenancy-and-security.md`](../04-multi-tenancy-and-security.md).