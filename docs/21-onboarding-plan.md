# 21 — New-account onboarding plan

Goal: a new tenant admin goes from an empty account to **taking a first real
order** as fast as possible, then fills in deeper detail (recipes, cellar,
photos) at their own pace. They should always know what's left and why it matters.

---

## 1. What an account actually needs (audit of the current app)

The original list (inventory → pricing → customers → team → recipes/photos)
is right in spirit. Reading the schema and screens adds the items marked **new**.

### 1.1 Must-have before the first order ("go-live")

| # | Area | What | Why it blocks | Where it lives today |
|---|---|---|---|---|
| A | **Company profile** (new) | Company name, OIB/VAT, address, contact email/phone, logo, bank/IBAN | Order PDF and confirmation emails show the tenant. Today the PDF only prints `tenant.name`, so address, VAT and bank fields need adding. | `Settings/Index.vue` (name, tax ID only) |
| B | **Locale, timezone, currency** (new) | Confirm before any money is entered | Currency is **read-only after setup** ("changing it would relabel stored amounts"), so it must be confirmed in step 1. | Settings |
| C | **Revenue basis** (new) | "When shipped" vs "when created" | Changes every dashboard number from day one | Settings |
| D | Product categories | Category → subcategory tree, `has_vintage` flag | Products need a category; vintage handling depends on it | `Inventory/Categories.vue` |
| E | Finished products | name, SKU, category, vintage, unit size, unit, sales unit (bottles/cases), bottles per case, list price (`default_price`), for sale y/n | Nothing to sell without them | Inventory create/bulk import |
| F | **Opening stock** (new) | `current_stock` per sellable item | Orders deduct stock, and with no opening stock every order goes negative | Inventory check / stock adjust |
| G | Pricing tiers + rebate | e.g. Wholesale −15 %, Retail 0 % | Customers are priced through a tier or their own rebate | `Customers/PricingTiers.vue` |
| H | Customers | Company, email, type, tier and/or rebate | Orders need a customer | Customers |
| I | Team / you | At least the admin. Usually invite one more person. | Not strictly blocking, but sharing the setup work speeds it up | Settings → Team |

> **On "category rebates":** confirmed to mean the existing **pricing-tier
> rebate** (customer rebate overrides tier, not additive, see
> `05-pricing-engine.md`). No new pricing rule is needed.

### 1.2 Should-have (first week)

| Area | What | Why |
|---|---|---|
| **Cost per unit (COGS)** (new) | `cost_per_unit` on finished goods, or derived from recipe | Without it every margin/profit report shows 100 % margin |
| Tier prices | Per-product price per tier (`tier_prices`) | Only needed where a tier isn't just "list price − rebate" |
| Customer-specific prices | `customer_prices` | Negotiated prices. Rebate is **not** applied on top. |
| **Customer categories** (new) | Restaurants, shops, distributors… | Segmentation in analytics and reorder radar |
| **Customer order portal** (new) | Generate order tokens, `hide_prices`, `allow_single_bottle`, product visibility overrides | B2B self-service ordering is a headline feature |
| Semi-finished & raw materials | Bulk wine, glass, corks, labels, cartons, with unit + cost | Needed for recipes and production |
| **Min stock levels** (new) | `min_stock` | Drives low-stock alerts and reorder |
| Team roles | Roles (ADMIN, SALES, CELLAR, INVENTORY…) + order flags (`can_edit_orders`, `can_see_shipped_orders`) | Least privilege. Defaults are easy to get wrong. |
| **Suppliers** (new, if module enabled) | Suppliers + price lists | Costs, supplier orders, raw-material reorder |

### 1.3 Deepen (optional, module-dependent)

| Area | What |
|---|---|
| Product recipes (BOM) | Inputs per finished unit; enables production runs and computed COGS |
| Product photos, tech sheets, documents | Portal catalog quality; sales collateral |
| Previous-vintage links | Year-over-year comparisons per wine |
| **Cellar** (module) | Vessels/rooms, wine lots currently in tank/barrel, enological products, fermentation protocols |
| **Vineyards** (module) | Parcels, grape contracts |
| **Work orders** (module) | Default board, task categories |
| **Finance** (module) | Annual & channel revenue targets, cash on hand, cost categories |
| Personal | Each user: language, push notifications, shortcuts |

Every step must respect the tenant's **plan modules** (`Plan.modules`), so a
plan without Cellar never shows cellar steps.

---

## 2. Recommended onboarding experience

### 2.1 Principles

1. **Checklist derived from real data, not checkboxes.** "Products ✓" means
   the tenant *has* sellable products with a price, not that someone clicked
   "done". The status stays true if data is later deleted, and it works for
   tenants created by legacy import.
2. **Import first, type second.** Most wineries already have a spreadsheet or
   PDF price list. Offer both of the importers we already have up front:
   CSV template (`inventory/bulk-import/template`) and the **AI inventory list
   import** (`AiImportType::InventoryList`). Manual entry is the fallback.
3. **Use the real screens; don't build a parallel app.** The wizard only
   covers what's genuinely new (company profile, a quick product grid). Other
   steps open the existing page in *setup mode*: a slim banner explaining the
   step, a "Back to setup" link, and a contextual "Next step →" button.
4. **Get to a first order quickly.** The go-live section is short (about 30–60
   min with an import). Everything else is a "Deepen" section that never
   blocks.
5. **Skippable and resumable.** Any step can be skipped ("we don't use
   tiers"), and the admin can leave and return to the same place.

### 2.2 Flow

```
First login (admin, empty tenant)
   │
   ▼
Welcome screen ── "Let's set up <Winery>" · 3 short questions:
   │   • What do you sell? (own wine / also other products)
   │   • Who do you sell to? (wholesale, retail, agencies, ship-shop) → seeds customer types & suggested tiers
   │   • Do you have a product list file? (yes → import path / no → quick grid)
   ▼
Setup hub  /setup   (progress ring + 3 sections, always reachable from the sidebar & dashboard)
   │
   ├── 1 · Go live  ───────────────────────────────────────────────
   │     1.1 Company profile          (wizard form; currency confirm w/ warning)
   │     1.2 Product categories       (suggested starter tree, editable: Red / White / Rosé / Sparkling / Other)
   │     1.3 Finished products        (import CSV | AI import | quick grid)
   │     1.4 Opening stock            (inventory-check screen pre-filtered to for-sale items)
   │     1.5 Pricing tiers            (suggested from step-0 answers, rebate per tier)
   │     1.6 Customers                (import | add; tier/rebate picker inline)
   │     1.7 Invite your team         (role presets with plain-language descriptions)
   │     ★  "Place a test order" → preview PDF → delete or keep   ← go-live moment
   │
   ├── 2 · Sell better ───────────────────────────────────────────
   │     Product costs (COGS) · tier & customer prices · customer categories ·
   │     order portal links · min stock · raw & semi-finished materials · suppliers
   │
   └── 3 · Deepen (module-aware) ─────────────────────────────────
         Recipes · photos & tech sheets · vintage links · cellar · vineyards ·
         work-order boards · revenue targets & cash on hand
```

### 2.3 Key UX details

- **Hub card per step**: title, one-line *why it matters*, status (Not started /
  In progress *n of m* / Done / Skipped), primary action, and an estimated time.
  Done cards show a summary ("24 products, 3 without price → fix").
- **Quality nudges, not just counts**: "3 for-sale products have no price",
  "5 customers have no tier and no rebate, so they pay list price. Intended?",
  "12 products have no cost, so margins will be wrong".
- **Quick product grid**: a spreadsheet-like inline table (name, category,
  vintage, unit size, sales unit, bottles/case, list price, for sale, opening
  stock). Good for adding 10–40 wines in one sitting. It posts to the same
  validation as `InventoryController@store`.
- **Smart defaults**: unit size 0.75 L, sales unit bottles, 6 or 12 per case,
  `is_for_sale = true` for finished goods, currency/locale from tenant creation.
- **Pricing preview**: on the tiers step show a live table, "Cabernet 2022 list
  €18 → Wholesale €15.30 → Retail €18", using `PricingService` so it can't
  drift from real order pricing.
- **Portal preview**: "See what customer X sees" opens the public order page in
  a read-only preview.
- **Go-live celebration**: once the go-live section is complete, the dashboard
  banner turns into "You're live 🎉, create your first order" and then
  disappears; the hub stays reachable under Settings → Setup.
- **Dashboard empty states**: each dashboard widget with no data links to the
  setup step that fills it, instead of showing an empty chart.
- **Team-aware**: invited members with `inventory.manage` see the steps they're
  allowed to do, so an admin can hand "Products" to a colleague. Non-admins
  never see the full checklist.
- **Mobile**: the hub and each card must work at 390 px. Imports and the quick
  grid can say "best on desktop" but still work.

---

## 3. Technical design

### 3.1 Readiness service (single source of truth)

`app/Services/Onboarding/OnboardingStatus.php`. It returns an ordered list of
steps for the current tenant:

```php
final class OnboardingStep {
    public string $key;           // 'company_profile', 'finished_products', ...
    public string $section;       // 'go_live' | 'sell_better' | 'deepen'
    public ?Module $module;       // hidden when tenant plan lacks it
    public string $status;        // not_started | in_progress | done | skipped
    public int $done; public int $total;   // progress for "n of m"
    public array $issues;         // quality nudges [{message, count, link}]
    public string $route;         // where the primary action goes
    public ?string $permission;   // gate needed to act on it
}
```

Each step is a small class implementing `OnboardingCheck` with a cheap
aggregate query. Examples:

| Step | Done when |
|---|---|
| company_profile | name, tax id, address, contact email set |
| product_categories | ≥ 1 `product_categories` row (type finished) |
| finished_products | ≥ 1 active FINISHED item with `is_for_sale` and `default_price` |
| opening_stock | every for-sale item has a stock movement or `current_stock ≠ 0`, *or* step marked done |
| pricing_tiers | ≥ 1 tier *or* skipped |
| customers | ≥ 1 active customer |
| team | ≥ 2 active memberships or pending invite, *or* skipped |
| cogs | ≥ 90 % of for-sale items have `cost_per_unit` or a recipe |
| recipes | ≥ 1 finished item with recipe items |
| photos | ≥ 1 for-sale item with an image |

Cache the result per tenant for a short TTL and bust it from the relevant model
observers. Alternatively, compute on the hub page only and ship a lightweight
`{done, total, go_live_complete}` summary in Inertia shared props for the
sidebar badge.

### 3.2 Persistence (minimal)

Add `onboarding` JSON column to `tenant_settings`:

```json
{ "answers": {"sells":"own_wine","channels":["WHOLESALE","RETAIL"],"has_file":true},
  "skipped": ["pricing_tiers"],
  "manual_done": ["opening_stock"],
  "dismissed_at": null, "live_at": "2026-11-02T10:14:00Z" }
```

No per-step "done" flags beyond `manual_done` for steps that can't be inferred.

### 3.3 Company profile fields

New columns on `tenant_settings`: `address`, `city`, `zip`, `country`,
`contact_email`, `contact_phone`, `iban`, `bank_name`, `logo_object_key`
(upload via existing `uploads/presign`). Use them in `resources/views/pdf/order.blade.php`
and order confirmation emails.

### 3.4 Routes & pages

| Route | Purpose |
|---|---|
| `GET /setup` | Hub (`pages/Setup/Index.vue`), gate `settings.manage` or any step permission |
| `GET /setup/welcome`, `POST /setup/welcome` | 3 questions → seeds suggestions |
| `GET/PATCH /setup/company` | Company profile form |
| `GET /setup/products`, `POST /setup/products` | Quick product grid (batch create) |
| `POST /setup/steps/{key}/skip`, `/unskip`, `/done` | Skip / manual-done |
| `POST /setup/categories/starter` | Create suggested category tree |
| `POST /setup/test-order` | Creates a flagged test order (`exclude_from_stats` customer or order flag) |

*Setup mode* on existing pages: `?setup=<step>` query param → shared
`SetupBanner.vue` component rendering the explanation + next/back links. No
changes to the underlying controllers.

### 3.5 Entry points

- First login of an admin on a tenant with `live_at = null` → redirect to
  `/setup/welcome` (once; then to dashboard as usual).
- Sidebar item "Setup" with progress badge until `live_at` + dismissed.
- Dashboard banner until go-live.
- Platform admin tenant page: show each tenant's onboarding progress. Useful
  for support and for spotting stalled trials (`TenantStatus::Trial`).

### 3.6 Tests

- Unit test per `OnboardingCheck` (empty tenant / partial / done).
- Feature tests: module gating hides cellar steps; non-admin sees only
  permitted steps; skip/unskip; quick-grid validation matches inventory store;
  test order excluded from stats.
- BDD scenario: "empty tenant → go live" happy path.

---

## 4. Delivery phases

| Phase | Scope | Size |
|---|---|---|
| **P1, Hub + go-live** | `OnboardingStatus` + checks for go-live steps, `/setup` hub, setup-mode banner on existing pages, company profile fields + PDF usage, dashboard banner, sidebar entry | M |
| **P2, Fast data entry** | Welcome questions, starter categories, quick product grid, import choice screen (CSV / AI), opening-stock pre-filter, pricing preview | M |
| **P3, Sell better** | COGS, tier/customer price, customer category, portal, min-stock, materials, supplier checks + quality nudges; test order + portal preview | M |
| **P4, Deepen & ops** | Module-aware cellar/vineyard/work-order steps, recipes/photos checks, platform-admin progress view, analytics on where people stall | S–M |

**P1 status (built):** `OnboardingStatus` + `OnboardingStep`, `/setup` hub
(`pages/Setup/Index.vue`), skip / mark-done / dismiss
(`PATCH /setup/steps/{step}`, `POST /setup/dismiss`), `SetupBanner` on any
page opened with `?setup=<step>`, the dashboard `SetupReminder`, a "System ·
Setup" nav item, and company-profile fields (address, city, zip, country,
contact email/phone, bank, IBAN) on Settings and the order PDF. Deferred from
P1: the logo upload, the first-login redirect (it lands with P2's welcome
step), and the sidebar progress badge.

**P2 status (built):** `/setup/welcome` (three questions → ticked suggestions
for starter finished-goods categories and one pricing tier per sales channel,
created by `ApplyWelcomeAnswersAction`, never duplicated), `/setup/products`
(quick grid — generated SKUs, opening stock booked as a `MANUAL_IN` movement —
with the existing CSV import beside it; the "Finished products" step now opens
it), a live price preview on Pricing tiers (`TierPricePreview`, resolved by
`PricingService` itself), and the count sheet opening on finished goods from
the "Opening stock" step. The hub and dashboard card point to the welcome
questions until they're answered or skipped.

Deliberately not done in P2: the first-login redirect. Sending every admin of
an empty account to /setup/welcome on login would also catch existing tenants
and every dashboard test; the dashboard card's "Get started" does the same
job without hijacking navigation. AI import isn't offered on the import
screen because it has no web UI yet (API only).

Ship P1 alone first. It's useful even without new data-entry UI, because it
connects the screens that already exist.

---

## 5. Open questions

1. ~~**Category rebates**~~ — resolved: it means the tier rebate.
2. **VAT:** should prices be entered net or gross, and do we need a tenant VAT
   rate (or per-category rates) in setup? `order_items.unit_price_gross` exists,
   so confirm where the rate comes from today.
3. **Who creates tenants?** Today only platform admins (`Admin/TenantController@store`).
   Is self-serve signup planned? If so the welcome step should also cover plan
   choice and billing (Stripe settings exist).
4. **Sample data:** do we want an optional "explore with demo data" toggle,
   with one-click removal? It's nice for trials but needs careful cleanup.
5. **Legacy import:** for migrated tenants (`LegacyImport` service), should the
   hub open with go-live already complete and only quality nudges shown?
