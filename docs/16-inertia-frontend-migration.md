# Inertia + Vue 3 frontend migration

Replaces the decoupled Next.js SPA in `frontend/` with an Inertia (Vue 3 +
TypeScript) frontend served by Laravel itself. This document records the
architecture decisions and the remaining work.

## Why

`frontend/` is a separate Next.js 15 app (~342 files, ~50k lines, 62 routes)
that talks to `routes/api.php` with a bearer token held in `localStorage`, and
deploys separately to Cloudflare Workers via OpenNext (`deploy-frontend.sh`).

That split costs a full duplicate type layer, a duplicated role→capability map,
a second deploy pipeline, and a round trip for every page's data. Inertia
removes all four: controllers hand page props straight to Vue components,
authentication is an ordinary Laravel session, and there is one deploy.

## Decisions

| Decision | Choice | Consequence |
|---|---|---|
| Auth | Session (cookie) via the `web` guard | No token in `localStorage`; CSRF applies; API token flow untouched for the public portals |
| Tenancy | New `session` strategy in `ResolveTenant` | The session *nominates* a tenant; membership is still verified per request |
| Serving | Laravel serves the app | `deploy-frontend.sh` and the Worker retire once parity lands |
| Shared logic | Existing Actions / Queries / Services | Web and API controllers differ only in envelope |
| Capabilities | Resolved server-side, shared as a flat list | Deletes `frontend/src/lib/auth/capabilities.ts`, which had to be hand-synced with `RoleCapabilities.php` |

Both frontends run side by side until the port completes; `frontend/` stays as
the reference implementation and is deleted at parity.

## Layout

```
resources/
  css/app.css              design tokens (see "Design" below)
  js/
    app.ts                 Inertia bootstrap, per-page code splitting
    pages/                 one component per route (Inertia's default path)
    layouts/               AppLayout (sidebar + topbar), AuthLayout
    components/ui/         token-driven primitives (Button, Card, Input, …)
    composables/useAuth.ts capabilities + current user
    lib/                   cn(), money formatting, navigation map
    types/                 mirrors of the PHP DTOs
app/Http/Controllers/Web/  Inertia controllers (Api/ is unchanged)
```

## How a module gets ported

1. **Find the inline logic in the API controller.** Most are already thin —
   they delegate to `app/Actions`, `app/Queries` and `app/Services`. Anything
   still inline gets extracted first so both transports share it.
2. **Add the Web controller** returning `Inertia::render()` with the same data
   the API returns.
3. **Add routes** to `routes/web.php` under `tenant.web`, carrying the same
   `can:*` middleware as the API route.
4. **Build the Vue page** against a TypeScript type mirroring the DTO.
5. **Add feature tests** using `assertInertia`.
6. **Move the module** out of `PENDING_MODULES` and into `NAV_ITEMS` in
   `resources/js/lib/navigation.ts`.

Inventory is the worked example: `ListInventoryItemsQuery` (already shared) plus
two new extractions — `InventoryItemPresenter` (list/detail mapping, including
the signed lead-image URL) and `DeleteInventoryItemAction` (the deactivate-vs-
delete rule) — are now consumed identically by
`Api\InventoryItemController` and `Web\InventoryController`.

## Design

`resources/css/app.css` holds the design tokens. Every primitive references a
token (`bg-primary`, `border-input`, `rounded-lg`); no component hard-codes a
colour, so re-theming is a one-file change.

The current values are **carried over from the outgoing Next.js app as a
placeholder**. The TERROIR Figma file is the authority — once the Figma
connector is available, replace that token block with the file's published
variables and treat any divergence from the design as a bug.

## Status

Ported: session auth, tenant switching, dashboard, inventory (index/show/
create/update/delete).

Pending (still served by `frontend/`): orders, customers, suppliers, costs,
inflows, cash-flow, cellar, vineyards, production, work-orders, ai-imports,
team, settings — plus the public order and supplier-portal token pages, which
are unauthenticated and should keep using the API.

## Checks

`./check.sh` now runs three sides:

```bash
./check.sh be    # composer check  — Pint + PHPStan + tests
./check.sh in    # npm run check   — vue-tsc + Vite build (the Inertia app)
./check.sh fe    # legacy Next.js app in frontend/
```
