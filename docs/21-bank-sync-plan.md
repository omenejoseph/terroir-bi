# Bank sync with Enable Banking — plan

Each tenant connects its own business bank accounts. Their transactions are
read through Enable Banking and used to confirm, or create, the inflows and
costs that the cash-flow numbers are built from. The Cash Flow screen then
shows the real bank balance beside the books.

Status: **plan, not started.** Owner: eng. Provider: Enable Banking Oy,
Espoo, Finland — a registered account information service provider (AISP)
supervised by the Finnish FSA. It holds the PSD2 licence, so we don't need
one.

## Decisions

1. **One contract, many tenants.** We are Enable Banking's customer, with one
   application. Each tenant gives its own consent at its own bank. Their
   connections, accounts and transactions are tenant-scoped like everything
   else (`BelongsToTenant`).
2. **Bank transactions are evidence, not a third ledger.** Cash flow stays
   built from received inflows and paid costs (`CashFlowForecastQuery`,
   `CashFlowAnalysisQuery`). A bank transaction either confirms one of them
   (marks it received or paid, on the bank's date) or creates one. It is never
   counted on its own, so nothing is counted twice.
3. **Behind an interface.** `BankDataProvider`, with an Enable Banking
   implementation and a fake for tests and local work. Another provider can
   be added later without touching the screens.
4. **Business accounts only** (`psu_type: business`), for now.
5. **Where it lives in the app.** It sits inside the existing `cash_flow`
   module, with no new module:
   - Connecting, refreshing and matching need `finance.manage`.
   - Seeing balances and transactions needs `finance.view`.
   - Ignoring a transaction needs `finance.delete`.
6. **"Real time" means up to 4 background refreshes a day**, plus a refresh
   when someone presses the button. That is the PSD2 limit for access without
   the user present. It is plenty for cash flow.

## How Enable Banking works (to confirm in B0)

These details come from Enable Banking's public docs and samples. B0 confirms
each of them against the sandbox before anything is built on it.

- **API:** base `https://api.enablebanking.com`. Each request carries a
  short-lived JWT signed (RS256) with our application's private key; the
  header `kid` is the application id. We sign with `openssl_sign`, so no new
  dependency is needed.
- **Bank list:** `GET /aspsps?country=HR` returns the banks we can connect
  to. It is cached for 24 h.
- **Consent:** `POST /auth` takes `{ aspsp: {name, country}, access:
  {valid_until}, state, redirect_url, psu_type }` and returns the bank's
  approval URL. The bank sends the user back to our `redirect_url` with
  `code` and `state`.
- **Session:** `POST /sessions {code}` returns a session id plus the
  accounts, each with a `uid`. `DELETE /sessions/{id}` revokes it.
- **Data:**
  - `GET /accounts/{uid}/balances`.
  - `GET /accounts/{uid}/transactions?date_from=…`, paginated with
    `continuation_key`. Keep calling until no key comes back; an empty page
    *with* a key means "not ready yet".
- **Transaction identity:** `entry_reference` is the bank's own id. Some
  banks omit it or repeat it; Enable Banking then generates a synthetic one.
  We store a fallback hash too (see B3).
- **Commercial terms:**
  - The sandbox and "restricted" production (our own linked accounts) are
    free, with no contract.
  - Production for other companies' accounts needs a signed agreement and
    KYB (know-your-business) checks.
  - Pricing depends on connected accounts per month.
- **To check in B0:**
  - Which Croatian banks are listed: PBZ, Zagrebačka, Erste, OTP, RBA,
    Addiko, HPB.
  - How many days of history each gives on first connect.
  - The maximum `valid_until` per bank (expected 180 days).
  - Whether `psu_type: business` is supported per bank.

## Data model

All tables are tenant-scoped, ULID keys, `Auditable` where noted.

**`bank_connections`** (Auditable): one per consent at one bank.

| Column | Notes |
|---|---|
| `provider` | `enable_banking` |
| `aspsp_name`, `aspsp_country` | e.g. `Privredna banka Zagreb`, `HR` |
| `session_id` | **encrypted** cast |
| `valid_until` | consent end |
| `status` | `ACTIVE`, `EXPIRING`, `EXPIRED`, `REVOKED`, `ERROR` |
| `last_synced_at`, `last_error` | |
| `created_by_id` | |

**`bank_accounts`** (Auditable): accounts under a connection.

| Column | Notes |
|---|---|
| `bank_connection_id` | |
| `provider_uid` | **encrypted**; changes on reconnect |
| `iban` | **encrypted** |
| `iban_last4` | for display |
| `name`, `currency` | |
| `balance` (minor), `balance_type`, `balance_at` | latest balance |
| `included` | counts toward cash flow; default true |

**`bank_transactions`**

| Column | Notes |
|---|---|
| `bank_account_id` | |
| `provider_ref` | `entry_reference`, else a hash of date + amount + counterparty + remittance + occurrence |
| `booking_date`, `value_date` | |
| `amount` (minor, signed), `currency` | |
| `status` | `BOOKED`, `PENDING` |
| `counterparty_name` | |
| `counterparty_iban` | **encrypted** |
| `remittance` | the payment description (*opis plaćanja* / *poziv na broj*) |
| `raw` | **encrypted** JSON |
| `match_status` | `UNMATCHED`, `SUGGESTED`, `MATCHED`, `CREATED`, `IGNORED` |
| `matched_type` / `matched_id` | morph to Inflow or Cost |
| `matched_by_id`, `matched_at` | |
| unique | `(bank_account_id, provider_ref)` |

**`bank_match_rules`**: "this counterparty is this supplier/customer and
category". Learned when someone confirms or creates. Columns:
- `counterparty_iban_hash` or `counterparty_name`
- `direction`
- `customer_id` / `supplier_id`
- `category`
- `times_used`

**Existing tables gain:**
- **Inflows and costs:** a nullable `bank_transaction_id`, so the link shows
  both ways.
- **Inflows:** `received_at` is set from the bank's booking date.
- **Costs:** `paid_at` is set the same way.

## Slices

Each slice gets:
- `./check.sh be` and `./check.sh in` green.
- Feature tests for every endpoint.
- en/hr strings.
- Playwright at 1440 px and 390 px against the fake provider.
- Its own commit and push.

### B0 — Contract and sandbox spike (calendar time, in parallel)

- **Account and app:**
  - Create the Enable Banking account.
  - Register the application.
  - Set the redirect URL `https://<app-host>/settings/bank/callback` for
    each environment.
  - Keep the private key **out of the repo**. Set env `ENABLE_BANKING_APP_ID`
    and `ENABLE_BANKING_KEY_PATH`, or the key as a secret
    (`ENABLE_BANKING_PRIVATE_KEY`).
- **Spike script:**
  - Using the sandbox mock bank and then our own account in restricted mode,
    run the full flow: list banks → auth → callback → session → balances →
    transactions with continuation.
  - Record the answers to the "To check" list above in this doc.
- **Commercial:**
  - Start the production agreement and KYB.
  - Sign the data processing agreement (GDPR).
  - Get the price per connected account, which decides how it shows in
    plans.
- **Done when:** a real Croatian business account has synced in restricted
  mode, and the field mapping below is confirmed.

### B1 — Plumbing (≈2 days)

- **Config:** `config/banking.php` holds the provider, app id, key, base URL,
  consent length (days), sync interval and history days.
- **`BankDataProvider` interface:**
  - `banks(country)`
  - `startConsent(bank, redirectUrl, state, validUntil): url`
  - `completeConsent(code): {sessionId, validUntil, accounts[]}`
  - `balances(uid)`
  - `transactions(uid, from, to): iterable` (handles continuation)
  - `revoke(sessionId)`
- **`EnableBankingProvider`:**
  - Laravel `Http` client, JWT signing, retries with backoff on 429/5xx.
  - Maps errors to typed exceptions: `ConsentExpired`, `BankUnavailable`,
    `RateLimited`.
- **`FakeBankDataProvider`** with seeded banks and transactions. It is used
  in tests and on local and demo environments, so the screens can be built
  without bank access.
- **Migrations, models and casts** for the four tables, plus the
  `bank_transaction_id` columns.
- **Tests:**
  - JWT shape (header and claims), checked against a test key.
  - Transaction mapping from recorded sandbox JSON fixtures.
  - Continuation loop, including empty pages that carry a key.

### B2 — Connect a bank (≈2–3 days)

- **Settings › Bank** (`finance.view`; actions need `finance.manage`):
  - Lists connections: bank, accounts with last 4 IBAN digits and balance,
    last sync, status, and "valid until".
  - Actions per connection: Refresh, Reconnect, Disconnect.
  - An "Included in cash flow" switch on each account.
- **Connect:**
  - Country (defaults to the tenant's) → bank picker (`Combobox` from the
    cached list).
  - `POST settings/bank/connect` creates a one-time `state`, stored server
    side with tenant, user and expiry. It returns the bank's URL, and the
    page sends the browser there.
- **Callback:** `GET settings/bank/callback?code&state`.
  1. Checks the state, which is single-use and bound to the same user and
     tenant.
  2. Calls `completeConsent`.
  3. Creates the connection and its accounts.
  4. Queues the first sync.
  5. Returns to Settings with a flash message.
  - A cancel or error at the bank returns with a clear message and creates
    nothing.
- **Reconnect** runs the same flow for the same bank. New account uids are
  matched to existing accounts by IBAN, so history and matches stay. The
  old session is revoked.
- **Disconnect:**
  - Revokes the session and marks the connection `REVOKED`.
  - Keeps transactions that are already matched (they back up the books).
  - Unmatched transactions can be removed in the same step, after a
    confirmation.
- **Audit:** `bank.connected`, `bank.reconnected`, `bank.disconnected`.
- **Tests:**
  - The happy path with the fake provider.
  - State reuse, a wrong user or a wrong tenant → refused.
  - Reconnect keeps accounts.
  - Permissions: 403 without `finance.manage`; module off → 403.

### B3 — Sync (≈2–3 days)

- **`SyncBankAccount` job** (queued, one per account, unique per account so
  syncs don't overlap):
  - **Balances.** Store the "available" or "booked" balance, preferring the
    type each bank actually returns. B0 records which.
  - **Transactions:**
    - **First sync:** as much history as the bank gives, up to
      `history_days` (default 365; most banks give 90+ on first consent).
    - **Later syncs:** `date_from` is the latest booked date minus 10 days.
      That catches late bookings and pending payments that turn into booked
      ones.
    - **Upsert** by `(account, provider_ref)`.
    - **Pending transactions** in the window that the bank no longer returns
      are deleted. A pending one that becomes booked is replaced; its match,
      if any, moves to the booked row.
  - **After saving:** run the matcher (B5) on the new rows.
- **Schedule** (`routes/console.php`):
  - Every 6 hours, staggered per tenant, which stays within 4 a day.
  - Skipped for connections that aren't `ACTIVE` or `EXPIRING`.
- **Manual "Refresh":** available when the user is present, throttled to
  once per 5 minutes per account. The UI shows "syncing…" until the job ends.
- **Consent expiry:**
  - The connection becomes `EXPIRING` 14 days before `valid_until`.
  - Finance users get a notification, using the existing `Notifier` (in-app
    and web push), at 14 and 3 days.
  - A banner appears on Settings › Bank and Cash Flow.
  - When a bank says the consent is gone, the connection becomes `EXPIRED`
    with a "Reconnect" call to action.
- **Failures:**
  - `last_error` is stored and the connection's status becomes `ERROR` after
    3 failures in a row.
  - Audit `bank.sync_failed`, written once per failure streak.
- **Tests:**
  - Idempotent re-sync, with no duplicates.
  - Pending → booked replacement.
  - Continuation.
  - Expiry transitions and notifications.
  - Throttle.
  - Tenant isolation: tenant A's job never touches B.

### B4 — Bank transactions screen (≈2 days)

- **Finance › Bank** (`finance.view`), a new page beside Cash Flow:
  - **Account chips** with balances.
  - **Filters:** in/out, status (unmatched / suggested / matched / created /
    ignored), date range, search across counterparty and description.
  - **Table:** date, counterparty, description, amount (signed and
    coloured), status, and what it's matched to (with a link).
  - **Mobile:** stacked rows.
  - **Totals** for the filtered rows.
- **Row actions** (`finance.manage`): Confirm suggestion, Match…, Create…,
  Ignore, Unmatch. Ignore and Unmatch get the Undo toast from the cellar
  work.
- **Tests:** props envelope, filters, permissions.

### B5 — Matching and creating (≈1–2 weeks)

- **`BankMatcher` suggests a match for each new or unmatched row.**
  - **Money in** → `PENDING` inflows. **Money out** → `PENDING` or
    `APPROVED` costs.
  - Each candidate is scored on:
    - **Amount**: exact to the cent. Required at first; partial payments
      come later.
    - **Reference**: the inflow or cost `reference` (invoice number or
      *poziv na broj*) appears in the payment description. This is the
      strongest signal.
    - **Counterparty**: a rule match by IBAN, or a name similar to the
      customer or supplier.
    - **Date**: booking date within −7…+60 days of the record's date or
      due date.
  - The best candidate above a threshold becomes `SUGGESTED`. Ties stay
    `UNMATCHED`; the system doesn't guess between two equal invoices.
- **Confirm** (one row, or "Confirm all suggestions" for high scores):
  - **For an inflow:** `RECEIVED`, `received_at` = booking date,
    `payment_method` = `bank_transfer`.
  - **For a cost:** `PAID`, `paid_at` = booking date.
  - Both sides are linked (`bank_transaction_id`, `matched_*`).
  - The match rule is learned or updated.
  - Audit `bank.matched`.
  - Undo restores the record's previous status and dates.
- **Match… manually:** a search over open inflows and costs with the same
  scoring shown. In v1, one transaction may settle **several inflows of the
  same customer** (or costs of the same supplier) when they add up exactly.
- **Create… from an unmatched row:**
  - A new cost (money out) or inflow (money in), prefilled with date,
    amount, counterparty, the supplier or customer and category from a rule,
    and the description as its notes.
  - It is saved already `PAID` / `RECEIVED` and linked (`CREATED`).
  - The existing cost and inflow forms and Actions are used; only the
    prefill and link are new.
- **Ignore:**
  - For transfers between the tenant's own accounts (detected automatically
    when the counterparty IBAN is one of its connected accounts), loan
    principal, and similar.
  - Ignored rows are kept but count nowhere.
- **Tests:**
  - Scoring cases: exact reference wins; same amount for two invoices →
    no suggestion; date window.
  - Confirm and undo.
  - Create from a row.
  - Own-account transfers.
  - Rule learning.
  - Multi-invoice settle.
  - The double-count guard: a bank transaction alone never changes cash
    flow until it is matched or created.

### B6 — Cash Flow screen with the bank (≈3–5 days)

The server logic exists (`CashFlowController`, both queries). The Inertia
page does not; the navigation entry is still `href: null`.

- **`Finance/CashFlow.vue`:**
  - History in/out by month, the 6-month projection, and receivables and
    payables pending, built from the existing queries.
  - The navigation entry gets its `href`.
- **Bank panel** (only when a connection exists):
  - The total current balance of included accounts, with its timestamp and
    a per-account breakdown.
  - A **projected balance line**: today's bank balance + projected in −
    projected out, month by month.
  - **Bank vs. books** for the period: the sum of unmatched, not-ignored bank
    transactions, with a link to Finance › Bank filtered to them. That is the
    to-do list that keeps cash flow honest.
- **Change to `CashFlowForecastQuery`:** an optional opening balance (from
  bank accounts) and a projected-balance series. Without bank data, the
  numbers stay exactly as today.
- **Tests:** query with and without bank data; panel props; permission and
  module gating.

### B7 — Hardening and go-live (≈2 days)

- **Security:**
  - Encrypted casts on session ids, IBANs and raw payloads.
  - The private key comes from secrets only.
  - Logs redact IBANs and descriptions.
  - The callback is rate-limited.
- **Retention:**
  - When a tenant is deleted, its connections are revoked and its bank rows
    removed.
  - Unmatched rows older than 24 months are pruned on a schedule; matched
    rows are kept with their records.
- **Demo data:** the fake provider on the demo tenant, so the screens can be
  shown without a bank.
- **Docs:**
  - A short help text in Settings › Bank: what's read, who can see it, the
    180-day reconnect, and that read-only means we can't move money.
  - This file is updated with the B0 findings and the final status.
- **Rollout:**
  1. Our own accounts in restricted mode.
  2. 2–3 pilot tenants after the contract is signed.
  3. Everyone.

## Effort

| Slice | Effort |
|---|---|
| B0 contract, KYB, data processing agreement, sandbox spike | 1–4 weeks of calendar time, alongside B1–B4; ≈1 day of dev |
| B1 plumbing | ≈2 days |
| B2 connect | 2–3 days |
| B3 sync | 2–3 days |
| B4 transactions screen | ≈2 days |
| B5 matching and creating | 1–2 weeks |
| B6 Cash Flow screen with the bank | 3–5 days |
| B7 hardening and go-live | ≈2 days |
| **Total** | **≈3–4 weeks of development** |

B1–B4 can be built and demoed entirely on the fake provider while the
contract is in progress. Only B2/B3 against real banks wait for B0.

## Out of scope for v1

- **Payments (PISP):** we only read data.
- **Card-level details** beyond what the bank's statement line carries.
- **Partial payments and split matches** across different customers.
- **Multi-currency conversion:** non-EUR accounts show in their own
  currency, and EUR totals exclude them with a note.
- **Personal accounts.**
- **Other providers:** the interface allows them, but none are built.

## Risks

- **Bank coverage and data quality vary.** Some Croatian banks may give short
  history or thin descriptions, which weakens matching. B0 measures this
  before B5 is tuned.
- **Reconnect every ~180 days** is unavoidable under PSD2. The reminders in
  B3 are the mitigation.
- **Price per connected account** has to fit the plan pricing. Decide in B0
  whether bank sync is an add-on.
- **Contract lead time.** KYB and the agreement can take weeks, so start
  them first.
