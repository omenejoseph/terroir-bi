# 22 — Help center plan

Goal: when someone in a winery account wonders "how do I…?", they find the
answer in seconds, without leaving the app and without asking support. The
help center is **searchable**, **shows only what applies to this account**
(plan modules + the member's role), is **in Croatian and English**, and opens
**in the context of the screen they're on**.

---

## 1. What exists to build on

| Piece | Where | How the help center uses it |
|---|---|---|
| Global search (⌘K) | `components/GlobalSearch.vue`, `Web\SearchController`, `GlobalSearchQuery` | Add a **"Help"** result group, so the box people already use answers questions too |
| Module + capability gating | `TenantModules`, `MembershipContext::can()`, `navigation.ts` | Hide articles about modules outside the plan, or actions the member can't take |
| Croatian/English catalogue | `lang/{en,hr}.json`, `TranslationCatalogParityTest` | UI copy; articles get their own per-locale files with the same parity test |
| Markdown rendering | `league/commonmark` (ships with Laravel, `Str::markdown()`) | Render articles server-side; no new dependency |
| Setup checklist | `lib/onboarding.ts`, `SetupBanner.vue`, `/setup` | Every step gets a "How to do this" link to its article |
| Platform admin | `/admin/*`, `AdminLayout` | Help analytics: searches with no results, unhelpful articles |

---

## 2. The experience

### 2.1 Three ways in

1. **⌘K search, everywhere.** Typing "how to bulk import" or "rabat" shows a
   *Help* group under the usual Orders / Customers / Inventory results. Enter
   opens the article in a side panel; nothing on the current page is lost.
2. **A "?" on every screen.** A small help button in the page header opens the
   side panel on the article for *this* screen (Inventory → "Managing your
   inventory"), with related articles below. On a screen with no article yet,
   it opens search.
3. **The Help Center page** (`/help`), linked from the sidebar footer next to
   "My profile". It has a big search box, topic cards (Getting started, Orders,
   Customers & pricing, Inventory, Cellar, Work orders, Settings & team) and
   "Popular" articles.

### 2.2 Inside an article

- A title and a one-line summary ("Take your first order in under a minute").
- Short, numbered steps with the **exact button labels** in the user's language,
  plus **deep links** into the app ("Open Pricing tiers →") that use the same
  `?setup=`-style banner pattern so the reader keeps their place.
- Screenshots where a step is hard to describe (phase 3).
- A "Was this helpful? 👍 👎" footer, with an optional "What was missing?" text box.
- Related articles, and **"Still stuck? Contact support"**, which opens a
  support request (`/support?new=1&from=<page>`) in the built-in ticketing
  system. The platform team works those from **Admin → Support**.

### 2.3 What the reader sees is filtered

Each article declares the module and (optionally) the capability it is about.
A plan without Cellar never shows cellar articles, and a SALES member never
sees "Managing team roles". Search and topic lists apply the same rule, so
nothing points somewhere the person can't go.

---

## 3. Content

### 3.1 Where articles live

**Markdown files in the repo**, one per article per locale:

```
resources/help/
  en/getting-started/first-order.md
  hr/getting-started/first-order.md
  en/customers/pricing-tiers.md
  …
```

Front matter carries everything the app needs:

```yaml
---
id: pricing-tiers            # stable, shared by both locales
title: Pricing tiers and rebates
summary: Price books for groups of customers, and how rebates combine.
topic: customers
module: customers            # App\Enums\Module key; omit for everyone
capability: customers.manage # optional; omit when viewing is enough
screens: [/customers/pricing-tiers]   # where the "?" button opens it
keywords: [rabat, discount, wholesale, veleprodaja, price list]
related: [customer-prices, add-customers]
updated: 2026-10-27
---
```

Why files, not a database: articles change together with the features they
describe. They are reviewed in the same pull request, versioned, and always in
step with the deployed code. An in-app editor (§6, phase 4) can come later,
for fixes between deploys.

### 3.2 First set of articles (≈35)

| Topic | Articles |
|---|---|
| Getting started | Your setup checklist · Company profile & order PDFs · Inviting your team and roles · Switching language |
| Orders | Taking an order · Order statuses and stock · Editing and duplicating orders · Order PDFs and confirmations · Allocations |
| Customers & pricing | Adding customers · Customer categories · Pricing tiers and rebates · Tier and customer prices (how prices are resolved) · Customer order links (self-service) · Consignment · Reorder radar |
| Inventory | Inventory basics (finished / semi-finished / materials) · Categories and vintages · Bulk import from CSV · Stock counts (inventory check) · Recipes and production · Costs and margins · Photos, tech sheets and documents · Low-stock alerts |
| Cellar | Vessels and rooms · Wine lots · Additions and analyses · Transfers and blends · Bottling · SO₂ · Tastings · Protocols |
| Work orders | Boards and tasks |
| Settings | Organisation settings · Revenue targets and cash on hand · Logs |

The pricing article reuses the precedence explained in `docs/05-pricing-engine.md`,
rewritten for winery staff instead of developers.

### 3.3 Writing rules

- Written for winery staff, not developers. One task per article. Steps start with a verb.
- Name buttons exactly as the UI does, in both languages. A CI check fails if a
  `**bold label**` in an article isn't a key in `lang/{locale}.json`, which
  catches articles that drift after a UI rename.
- Both locales required: a parity test like `TranslationCatalogParityTest`
  fails when an article `id` exists in one language but not the other.

---

## 4. Search

### 4.1 Approach

**Server-side, over a cached index built from the markdown.** At deploy (and in
tests) `php artisan help:index` parses every article into a JSON index per
locale: id, title, summary, keywords, headings and plain-text body, plus
module, capability and screens. It is cached and rebuilt when files change in
local development.

`GET /help/search?q=` scores matches in PHP, with weights title ≫ keywords >
headings > summary > body. It runs on *folded* text, lower-cased with
diacritics removed (č/ć→c, š→s, ž→z, đ→d), so "rabat", "rábat" and "racun"
match "račun". The query also matches whole words and word prefixes ("impor"
→ "import"). Results are filtered by module and capability, then the top 8 are
returned with a highlighted snippet.

With a few dozen articles this costs well under a millisecond, needs no
external service, and works identically on MySQL, Postgres and SQLite. If the
library grows into the hundreds, swap the scorer for Laravel Scout +
Meilisearch behind the same endpoint; nothing else changes.

### 4.2 Synonyms

A small per-locale synonyms map (`resources/help/synonyms.{en,hr}.php`):
discount ↔ rebate ↔ rabat, invoice ↔ račun, bottle ↔ boca, stock ↔ zaliha.
Zero-result searches (§6) show which ones to add.

---

## 5. Technical design

| Part | Detail |
|---|---|
| `App\Services\Help\HelpLibrary` | Loads and caches the parsed index; `find(id, locale)`, `forScreen(path)`, `topics()`; applies module/capability filtering via `TenantModules` + `MembershipContext` |
| `App\Services\Help\HelpSearch` | Folding, scoring, snippets; synonyms |
| `App\Console\Commands\BuildHelpIndex` | `help:index`, run in deploy and by a test bootstrap |
| `Web\HelpController` | `GET /help` (topics, popular), `GET /help/{id}` (article page), `GET /help/search` (JSON), `GET /help/panel/{id}` (JSON for the side panel) |
| `GlobalSearchQuery` / `SearchController` | Add a `help` category (always allowed, filtered per article) |
| `components/help/HelpPanel.vue` | Right-hand slide-in (reuses `SidePanel.vue`), article + related; keeps the page underneath |
| `components/help/HelpButton.vue` | "?" in `PageHeader`; resolves the article from the current path via `screens` |
| `pages/Help/Index.vue`, `pages/Help/Show.vue` | Help Center home and the full-page article (shareable URL) |
| Article HTML | Rendered server-side with CommonMark (safe mode: no raw HTML), links to `/…` kept as Inertia links |
| Feedback | `help_feedback` table (article id, locale, helpful bool, comment, user, tenant); `help_searches` logs query + result count (no personal data beyond tenant/user ids) |

Routes sit in the `tenant.web` group with no extra capability, since any member can
read help. Filtering happens per article.

---

## 6. Keeping it useful

- **Admin → Help insights** (platform admin): top searches, **searches with no
  results** (the to-do list for new articles and synonyms), and articles with
  the most 👎 and their comments.
- **Setup checklist link-up:** each step in `lib/onboarding.ts` gains a
  `help` article id. The setup banner and hub card show "How to do this".
- **Definition of done for features:** a PR that adds or changes a screen
  updates its article, or says why not. The label check (§3.3) makes stale
  button names fail CI.

---

## 7. Delivery phases

| Phase | Scope | Size |
|---|---|---|
| **H1 — Library + pages** | Markdown format, `help:index`, `HelpLibrary`, `/help` and `/help/{id}` pages, module/capability filtering, sidebar entry, locale parity test, first 12 articles (getting started, orders, customers & pricing) | M |
| **H2 — Search everywhere** | `HelpSearch` (folding, prefixes, synonyms, snippets), `/help/search`, Help group in ⌘K, search box on `/help`, search logging | M |
| **H3 — In context** | "?" button + side panel on every screen with an article, setup-checklist links, remaining ~23 articles, screenshots for the hardest flows (Playwright script against a demo tenant, so they can be regenerated) | M–L |
| **H4 — Learn from use** | 👍/👎 feedback, Admin → Help insights (zero-result searches, unhelpful articles), label-drift CI check; optional in-app editor for platform admins writing DB overrides on top of the files (same pattern as translation overrides) | M |

H1 + H2 already give a usable, searchable help center. H3 is what makes people
actually find it.

---

## 8. Open questions

1. **Who writes the articles?** I can draft all of them in both languages from
   the code and the screens. Someone at the winery should review the Croatian
   for tone and terminology.
2. ~~**Support contact**~~ resolved: "Still stuck?" opens a support ticket
   (Help & support, `/support`), answered from Admin → Support.
3. **Public or logged-in only?** As planned, help is inside the app. A public
   version at `help.<domain>` for prospects and search engines can reuse the
   same files later.
4. **Video:** short screen recordings for the hardest flows (cellar transfers,
   bottling) are worth considering after H3. They're not in this plan.
