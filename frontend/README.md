# Terroir BI — Frontend

A **decoupled Next.js (App Router) SPA** that consumes the Terroir BI Laravel API.
It is a pure client-side app: it talks to the backend over plain HTTP with a
Sanctum Bearer token, so it can be deployed and detached from Laravel at any time.

## Why this shape

The Laravel API is **stateless** (Sanctum personal access tokens, tenant-bound)
rather than cookie/session based. That makes a standalone SPA the natural fit —
there is no shared runtime with Laravel, only the API contract. Inertia was
intentionally **not** used: it couples the frontend to Laravel controllers and
session auth and wouldn't consume this token API.

## Getting started

```bash
cp .env.local.example .env.local   # set NEXT_PUBLIC_API_URL
npm install
npm run dev                        # http://localhost:3000
```

The only link to the backend is `NEXT_PUBLIC_API_URL` (default
`http://localhost/api/v1`). Point it anywhere and the app follows.

| Script              | Purpose                          |
| ------------------- | -------------------------------- |
| `npm run dev`       | Dev server on :3000              |
| `npm run build`     | Production build                 |
| `npm run start`     | Serve the production build       |
| `npm run typecheck` | `tsc --noEmit`                   |
| `npm run test`      | Run the test suite once          |
| `npm run test:watch`| Tests in watch mode              |
| `npm run check`     | **CI gate:** typecheck + tests   |

## Architecture

```
src/
  app/                       # Routes (App Router)
    login/                   # Public auth screen
    (app)/                   # Authenticated group: guard + responsive shell
      dashboard/  inventory/  customers/
    layout.tsx  providers.tsx  manifest.ts  globals.css
  components/
    ui/                      # Decoupled, restyleable primitives (Button, Card…)
    app-shell.tsx            # Responsive sidebar/drawer chrome
    tenant-switcher.tsx  language-switcher.tsx  protected-route.tsx
  lib/
    api/                     # The ONLY HTTP boundary (client + per-resource modules)
    auth/                    # Auth context + token storage
    config.ts  types.ts  query.ts  utils.ts
  i18n/                      # Translations (catalogs + runtime)
  hooks/                     # Data hooks (TanStack Query)
```

**Layering rule:** UI components never call `fetch` and never hard-code colors or
strings. Data flows `lib/api → hooks → pages`; styling flows from design tokens;
text flows from the i18n catalogs.

### Styling — restyle in one place

All design decisions live in **CSS variables in `src/app/globals.css`**
(colors, radius, dark mode). Components reference tokens (`bg-primary`,
`rounded-lg`) and declare their variants once via `class-variance-authority`
(see `components/ui/button.tsx`). To rebrand, edit the `--primary` tokens — you
should not need to touch a single component. The setup is shadcn/ui-compatible
(`components.json`), so `npx shadcn@latest add <component>` works for new primitives.

### Auth & multi-tenancy

`lib/auth/context.tsx` owns the session. The Bearer token is **tenant-bound**:
`switch-tenant` returns a *new* token which replaces the old one. Login → token
stored → `me()` restores the session on reload. Routes under `(app)/` are guarded
by `ProtectedRoute`.

### Internationalisation — no raw text

Croatian-first (`hr`), with `en`, matching the backend's supported locales. Every
user-facing string is a key resolved by `t()` (`src/i18n`). The active locale is
persisted and sent to the API as the `X-Locale` header, so server messages
(validation, etc.) come back localized too. Tenant-managed overrides from
`GET /translations` are merged on top of the bundled catalogs. Add a string to
`src/i18n/messages/en.ts` (canonical shape) and every locale is type-checked to
provide it.

### PWA — installable & mobile-first

- `src/app/manifest.ts` → web app manifest (`/manifest.webmanifest`).
- `public/sw.js` → service worker (network-first navigations, SWR for assets,
  **never** caches `/api/*` to avoid cross-tenant/auth leaks), registered in
  production by `components/service-worker-registrar.tsx`.
- The shell is responsive: sidebar on desktop, slide-in drawer on mobile.
- Replace the placeholder icon at `public/icons/icon.svg` with real PNG assets
  (192/512 + maskable) referenced in `manifest.ts` before shipping.

## Adding a module (pattern)

1. `lib/api/<resource>.ts` — typed endpoint calls via the shared `api` client.
2. `lib/types.ts` — mirror the backend DTO.
3. `hooks/use-<resource>.ts` — TanStack Query hook.
4. `app/(app)/<resource>/page.tsx` — UI using `ui/` primitives + `t()`.

`inventory` is the worked example; `customers` is a stub to fill in.