# OSC Privilege Club — Progress

Running record of what is built, verified and outstanding. Companion to
[IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (the build design) and
[DECISIONS.md](DECISIONS.md) (the decision register).

**Last updated:** 2026-09-02

> ### Picking this up cold
>
> **Start here, then read `DECISIONS.md`. Between them they are the whole
> picture; nothing else needs reading first.**
>
> 1. Read **Handover — 2 Sep 2026** immediately below. It supersedes the older
>    framing of Sprint 3: the dev-store script has now partly run, and **D5 has
>    been revised**, which added a build item to the sprint.
> 2. The re-authorisation is **done**. The app holds all eight scopes and all six
>    webhook topics are registered. Do not repeat it.
> 3. The online redemption **mechanism has changed**. The discount function is
>    Plus-only and OSC is on Grow, so production online redemption is a
>    single-use code. See D5 `REVISED`.
> 4. Nothing is left assumed silently. C1 now has a **chosen default** (auto-apply
>    link) that is **not** client-confirmed.

---

## Handover — 2 Sep 2026

### Where the dev-store script got to

Run against the live dev shop `loyalty-system.myshopify.com`.

| Step | Result |
| --- | --- |
| Prerequisites | **Done.** Grant landed 19:03 on 1 Sep: all 8 scopes including `write_products`, `loadOfflineSession` valid, Admin API reachable. All 6 webhook topics registered on the live tunnel, no `[stale]`, no duplicates |
| **A1** create the discount | **BLOCKED.** `"Shop must be on a Shopify Plus plan to activate functions from a custom app."` Revised D5 |
| **A2** sync exclusions | **PASSED.** rules v3, 17 products, 17 flags, `shop config written`. **Closed V6a's last unverified element** — read back from Shopify, see *A2 — V6a verified live* below |
| **A3** member + balance | **PASSED.** Account id 10, `ashish.agrawal@dotsquares.com`, customer `9671675085040`, 1000 points → `voucher_balance_pence = 5000`. Postcode set to `SP4 6AB` so the POS postcode search is testable — and that is the exact input behind the fixed `MemberCardNumber::parse()` defect, so B4 doubles as a live regression check |
| **A4** hold a quote | **PASSED.** `PC-97244372`, 5000p, state `quoted`. Customer metafield read back from Shopify in the app-reserved namespace `app--414539251713`. **This is the thing the V4 spike could not prove**: Shopify really does populate an app-reserved metafield from a `metafieldsSet` write |
| **A5** online checkout proof | **Superseded and blocked.** Blocked on the plan, and as written it proves the *function*, which is no longer the production path. Its replacement is the code path, which is not built |
| **B / C / offline** | **Pending the iPad run.** Not blocked by anything — POS uses `applyCartDiscount`, which has no plan requirement |

### What was fixed to get there

Four separate faults, each of which failed silently. Worth knowing because the
first three will recur on any new machine or tunnel.

1. **Webhook registration could not run from a plain terminal.** `shopify app dev`
   injects `HOST` and the credentials only into the backend it spawns. Fixed:
   `serve.mjs` records them to a gitignored handshake, `App\Lib\DevTunnel` reads
   it, and `ShopifyServiceProvider` re-records on every boot so it self-heals.
   No manual exports. See *Dev notes* in `README.md`.
2. **The app could never re-authorise itself.** `EnsureShopifyInstalled` asked
   "does a row with a token exist?" while everything else asked
   `Utils::loadOfflineSession()`, which fails on a scope mismatch. The app served
   normally while every Admin API call threw. Fixed to ask the same question, and
   it now logs when a stale grant is sent back through OAuth.
3. **The released app version still carried the scaffold URL.** Under
   `use_legacy_install_flow` the OAuth `redirect_uri` is validated against the
   **released** allowlist, which `shopify app dev` does not publish. Consent
   succeeded and the callback had nowhere to return to. Fixed by deploying real
   URLs; **any tunnel change now needs a `deploy`, not just a restart.**
4. **The OAuth state cookie expired in 60 seconds.** `shopify-api-php` hard-codes
   `strtotime('+1 minute')`, so a merchant reading the scope list ran out of time
   and the callback threw `CookieNotFoundException` — while still landing them in
   a working-looking app. Now `config('shopify.oauth_cookie_minutes')`, default 10.

`ShopifyAuthController` now logs `OAuth begin` / `callback received` / `granted` /
`callback failed`. None of the above was diagnosable before that went in.

### The code-based gateway — planned, NOT started

No gateway code has been written. The plan, and the three decisions taken on
2 Sep 2026:

- **Gift-card check first** — and it is **NOT cleared**. Shopify's documentation
  does not say whether a fixed-amount code applies to gift-card products. If it
  does, it is a cash-out hole: buy a gift card with loyalty value. The function
  got this free from `isGiftCard` in its input query; a code does not.
  **This is the gate on starting the build**, and it needs an empirical test on
  the dev store (issue a code, put a gift card in a cart, look at checkout)
  rather than more searching.
- **Reuse unexpired codes** rather than minting one per quote, so a redeem control
  that re-quotes on every cart change does not cost an API write each time.
- **C1 — auto-apply link, as the chosen default, client confirmation pending.**
  The agreed UI's account-page *"Shop with my £15"* button maps straight onto
  `/discount/PC-…`. **Not closed**; it must not be recorded as confirmed.

The shape of the work, from reading the seam:

- The interface has **two** verbs, not three — `mechanism()`, `publish()`,
  `withdraw()`. There is no `confirm()`; the docblock narrates one but never
  declares it. "Mark used" is **not ours**: Shopify enforces `usageLimit: 1`.
- **Confirmation already works.** `AdminApiOrderSource.php:46` already selects
  `... on DiscountCodeApplication { code }` and `:134` already feeds it to
  `RedemptionReference::extractFromAll()`. Make the code *be* the reference and
  `orders/paid` confirms a code redemption with no change at all.
- **The reference is already a valid code.** `PC-` + 8 digits, digits-only by
  design because it "gets read aloud across a counter".
- **The column already exists**: `loyalty_redemptions.shopify_discount_gid`.
- Files, in order: migration adding `discount_code` to the `discount_mechanism`
  enum (**verify on MySQL 8.4, not just SQLite**); `DiscountCodeWriter` +
  `AdminApiDiscountCodeWriter` mirroring the `MetafieldWriter` pair; the gateway;
  **collapse the gateway choice into one place** — it is currently duplicated at
  `RedemptionController:79-80` and `:151-152`, `QuoteExpirySweep:36-37` and
  `AppServiceProvider:100,104`, which is how a half-swapped mechanism happens;
  tests against the existing ladder fixtures; an orphan-code sweep.
- **The compromise to keep in view.** The function re-applied the D8 ladder to the
  live cart; a code cannot. `minimumRequirement.subtotal` covers the min-basket
  and below-basket rungs, but **not the eligible-subtotal rule** — a code applies
  to the whole order. Harmless under the launch rules (`mode: all`), a real gap
  the day OSC configures an exclusion.

### D5 code path built — 2 Sep 2026

The single-use discount code gateway is written and green; nothing is released.

- **New:** `DiscountCodeWriter` + `AdminApiDiscountCodeWriter`
  (`discountCodeBasicCreate` / `discountCodeDeactivate`), `DiscountCodeGateway`,
  one additive migration extending `discount_mechanism` with `discount_code`,
  `FakeDiscountCodeWriter`, and `DiscountCodeRedemptionTest` (12 tests).
- **The online mechanism is now chosen in exactly one place** — the
  `RedemptionGateway` binding in `AppServiceProvider`. `RedemptionController`
  had the choice duplicated in `store()` and `destroy()`; both now go through
  one `gatewayFor()`.
- **A real bug fell out of it.** `QuoteExpirySweep` chose its gateway by
  **channel**, so once the online binding changed it would have withdrawn a
  function-published quote through the code gateway — clearing a metafield that
  was never written and leaving a live code standing. It now dispatches on the
  redemption's recorded `discount_mechanism`, which is what that column is for.
  Two existing tests caught it and needed no edits.
- **The code's `endsAt` is the quote's `quote_expires_at`**, so Shopify and the
  expiry sweep cannot disagree about whether an offer still stands.
- **No gift-card guard, and that is evidence-based** — see the 2 Sep register
  rows for paid order `#1001`.
- **No new command and no new schedule entry:** `eachShop()` already writes the
  C12 per-run audit entry, so an expired code needs no ledger row and no
  per-quote audit row.
- Backend **438 tests / 2026 assertions**. The new migration is verified on
  **MySQL 8.4** as well as SQLite: the column reads
  `enum('function','pos_cart_discount','discount_code')`, still NOT NULL, and
  still refuses an unknown value.
- `docs/DEV_STORE_TEST_SCRIPT.md` section A is rewritten as A1–A4 for the code
  flow; the old A1–A5 metafield steps are gone.

### On your side

1. **Step B on the iPad** — moved to the morning of 3 Sep 2026. Member details
   are in the A3 row above; card number **`0000-0010`**. After *Apply*, the check
   is `shopify_location_id` (must be a number, not NULL) and `staff_reference`.

### Also worth knowing

- **The test script's own ordering is wrong.** A2 comes before A3, but
  `ShopScopedCommand::shops()` derives its shop list from `LoyaltyAccount` and A3
  creates the first account — so on a fresh store A2 reports `No shops to sweep`
  and looks like a failure. Use `--shop=<domain>`, or move A2 after A3.
- **The held quote `PC-97244372` expired at 19:44 UTC on 1 Sep and was not swept**
  — `loyalty:expire-quotes` runs on the scheduler and there is no `schedule:run`
  cron on this machine, only a queue worker. It will sit at `quoted`. Not a bug.
- The dev tunnel URL at handover was
  `https://calibration-bay-soil-reproduce.trycloudflare.com`. It **will** change
  on the next `shopify app dev`, and when it does the app config needs
  re-deploying, not just re-registering.

| | |
| --- | --- |
| **Current phase** | **Sprint 3 — dev-store script in progress, and its scope has grown.** V4, V6a and V7 resolved by spike; ladder green on both sides; discount function, gateway, POS endpoints, POS tile, `orders/paid` confirmation and quote expiry all built. **Script steps A1–A2 run on 1–2 Sep 2026: A2 passed and closed V6a's last unverified element; A1 failed on a plan constraint that revised D5.** A **code-based redemption gateway** is now Sprint 3 work |
| **Test suite** | Backend **426 / 1,978** · Console **134** · Discount function **43** (25 ladder + 18 against real compiled Wasm) · POS tile **31** — all passing. Backend green on MySQL 8.4 as well as SQLite |
| **Schema** | 15 loyalty tables, applied to MySQL 8.4 and verified on SQLite. One Sprint 2 migration: release allocations and reversal tracking |
| **API** | 19 admin endpoints, each guarded, audited and tested · 4 business webhook topics, fast-acknowledged and queued · 6 scheduled commands |
| **Console** | 7 screens built (A1–A5, A10, A12), browser-verified; 3 stubbed, all owned by later sprints |
| **Blocking** | **C1** blocks the end of Sprint 3 (the storefront control). **C6 (live)** blocks go-live. Four deployment requirements code cannot supply — cron, queue worker, scopes, real app URL — are in the README and on the go-live checklist |

---

## Sprint 1 — status

### Complete

**Schema — all 15 tables.** Six migrations, `2026_08_26_000001` to `000006`.
Applied to MySQL 8.4 and exercised on SQLite by the suite, so the portability
requirement holds in both directions. `CHECK` constraints are MySQL-only by
design, because SQLite cannot add one with `ALTER TABLE`.

Client-feedback amendments applied and verified by probe:

- `loyalty_accounts.email` and `email_normalised` nullable, `uq_account_email`
  retained (MD1). Two no-email members coexist under the unique index, both
  findable by card and by postcode plus surname, and a duplicate email is still
  rejected.
- `dob_month` / `dob_day` are real writable columns and the authoritative
  birthday (MD2). A day-and-month birthday persists with `date_of_birth` NULL; a
  full date populates both, leap day included.
- `migration_records.decision` extended with `combine` and `marketing_only`
  (MD3, MD5).

**Models — 8.** `LoyaltyAccount` (normalisation on save, `hasNoEmail()`,
`hasBirthday()`), `LedgerEntry`, `LotAllocation`, `RulesVersion`, `Reward`,
`Redemption`, `StaffRole`, `AuditEntry`. `LedgerEntry` and `AuditEntry` refuse
updates and deletes at the model, so append-only is enforced rather than
documented.

**Domain layer.**

| Component | What it does |
| --- | --- |
| `Support\Money\Pence` | Integer minor units, floor-to-increment, floor-at-zero |
| `Rules\RuleSet` | Typed view over one version; launch values from Blueprint §06 |
| `Rules\RuleSchema` | Full-snapshot validation, cross-field rules, currency guard |
| `Rules\RulesVersionRepository` | `current()`, `asAt()`, `save()`, `diff()`, idempotent baseline seed |
| `Loyalty\LedgerPosting` | Named constructors per entry type, so bucket arithmetic is never hand-written at a call site |
| `Loyalty\LotAllocator` | FIFO consumption; pure `plan()` plus DB-bound `allocate()` |
| `Loyalty\BalanceCalculator` | Ledger sums, pure `derive()` for the voucher balance, `refreshCache()`, `verify()` |
| `Loyalty\LedgerService` | The only write path: idempotent, rule-versioned, cache-consistent |

**Commands.** `loyalty:rebuild-balances` (corrects drift, reports what changed)
and `loyalty:verify-ledger` (changes nothing, exits non-zero on drift so cron can
alarm on it).

**Shared arithmetic fixtures.** `fixtures/loyalty-arithmetic.json` at the project
root, consumed by PHPUnit now and by the discount function Vitest suite in
Sprint 3. Sections: balance derivation (9 cases), lot allocation (6), adjustment
(4), redemption ladder (8, the Sprint 3 contract). The Blueprint worked examples
are the first case in each.

**Identity, roles and audit.**

| Component | What it does |
| --- | --- |
| `Identity\SessionTokenVerifier` | Verifies an App Bridge session token and adds the two checks the JWT library omits: `aud` must be this app, and the shop comes from `dest` (V3) |
| `Identity\VerifiedStaffToken` | The trustworthy parts of a verified token |
| `Identity\StaffRoleResolver` | Role lookup, the C6 first-Administrator bootstrap, role assignment, per-person adjustment limits |
| `Identity\LastAdministratorException` | Refuses a change that would leave a shop with no Administrator |
| `Support\Audit\RequestContext` | Who is acting, from where, on which request; a singleton so no actor is threaded through call sites |
| `Support\Audit\AuditAction` | The closed list of audited actions, and which demand a reason |
| `Support\Audit\AuditLogger` | Writes entries; enforces the reason requirement centrally; `logModelChange()` records only what moved |
| `Http\Middleware\EnsureStaffRole` | The `staff.role:{floor}` alias — server-side authorisation on every endpoint |

The four services plus `RequestContext` are registered as singletons in
`AppServiceProvider`, which is what lets any service write a correctly attributed
audit entry without being handed an actor.

**Week-zero validations.** V1, V3, V5 and V8 all resolved — see `DECISIONS.md`.
V5 changed the scope list and simplified M6; V3 found and fixed a too-short test
secret; V8 aligned extension versions and removed an unused function target,
both now guarded by tests.

**The admin console API — 16 endpoints.** The fifteen in plan section 5.3, plus
one the agreed UI needs and the plan does not list (see below). Registered under
`/api/admin` outside the web middleware group: the console calls them with an App
Bridge session token in the Authorization header, so there is no cookie session
to protect and CSRF does not apply.

| Method and path | Role | What it answers |
| --- | --- | --- |
| `GET /me` | Viewer | Role, and a named permission set the `RoleGate` can branch on |
| `GET /overview` | Viewer | Dashboard tiles, enrolment trend, recent activity, job health |
| `GET /members` | Viewer | Customers list; one search box over five identifiers |
| `POST /members` | Agent | Enrol; email optional (MD1), day-and-month birthday (MD2) |
| `GET /members/{id}` | Viewer | The whole profile screen bar the paged ledger |
| `PATCH /members/{id}` | Agent | Correct profile fields; an email change is refused |
| `GET /members/{id}/ledger` | Viewer | Movement history, filtered, with the balance after each entry |
| `POST /members/{id}/adjustments` | Agent | Preview or post a manual adjustment |
| `POST /members/{id}/rebuild-cache` | Manager | Re-sum from the ledger; returns before and after |
| `GET /ledger` | Viewer | Programme ledger and the Loyalty screen tiles |
| `GET /rules` | Viewer | What is in force, plus every version before it |
| `GET /rules/versions/{version}` | Viewer | A historical rule set with its difference |
| `POST /rules` | Administrator | Save a new version; a partial payload is rejected |
| `GET /audit` | Manager | Who did what, when, before to after, and why |
| `GET /staff` | Administrator | Staff, roles and adjustment limits |
| `PUT /staff/{staffId}` | Administrator | Assign a role; the last Administrator is protected |

**The sixteenth endpoint.** `GET /api/admin/ledger` is not in plan section 5.3.
The Loyalty screen (A5) in the agreed UI needs a programme-wide ledger with type
and channel filters, and neither `/overview` nor the member-scoped ledger is
that: one is a dashboard of totals, the other is scoped to a person. Recorded
here rather than folded into an existing endpoint, because pretending it was
always there would make the plan and the code disagree.

**Shaped to the agreed UI (v1.1 FINAL).** Each response answers a whole screen:

- **Customer profile.** Available and pending balances, voucher value,
  increments, points committed, remainder, points to the next five pounds,
  lifetime spend, last purchase, when pending points clear, what expires inside
  the warning window, and the vouchers tab — one call. The ledger is separate
  because it is paged and filtered.
- **Customers list.** Searches email, name, postcode, legacy Dynamics card and
  the club card number at once, or any one of them on its own. A member with no
  email held is findable by card, by postcode and by surname, and there is a
  filter for them.
- **Adjust points.** `preview=true` writes nothing and returns the projection
  behind "New balance will be 390 points, 15 pounds available". It needs no
  reason, because the projection has to appear before anyone has written one.
  The real post returns the same projection block alongside the balances that
  now hold, so the preview and the confirmation cannot disagree.
- **Audit log.** Filterable by user, action, subject, channel and date range,
  returning before and after states, a flattened per-field change list, the
  reason, and the filter vocabularies — including every actor who has appeared
  on the shop, so someone whose role was revoked stays selectable.
- **Loyalty screen.** Programme ledger with type and channel filters, each row
  naming its member, and the tiles — in circulation, pending, earned today,
  reversed today, expiring soon, voucher value outstanding — in the same
  response.

**New in the domain layer.**

| Component | What it does |
| --- | --- |
| `Members\MemberSearch` | One search box over five identifiers; the no-email path is structural, not an afterthought |
| `Loyalty\AdjustmentService` | Projection, per-person limit, composed reason, posting and audit in one transaction |
| `Loyalty\ExpiryOutlook` | What is due to expire and when, for a member or the whole shop |
| `Loyalty\ProgrammeSummary` | The dashboard and Loyalty tiles, computed once so two screens cannot disagree |
| `Loyalty\RunningBalance` | The balance after each entry on a ledger page, from one aggregate query |
| `Support\Api\ApiException` | A named constructor per business refusal, rendering the standard envelope |
| `Support\Api\Permissions` | Permission to role floor, as data, so the console does not restate the role model |
| `Support\Members\MemberCardNumber` | The club card number, derived from the account id (C11) |

**Error envelope.** `{ error: { code, message, details } }` everywhere, including
Laravel's own validation and 404 responses, which are translated in
`bootstrap/app.php`. Codes in use: `invalid_session_token`, `no_role_assigned`,
`role_floor_not_met`, `validation_failed`, `not_found`, `account_merged`,
`adjustment_limit_exceeded`, `duplicate_email`, `email_immutable`,
`last_administrator`.

### Verified behaviour

Each of these is a passing test, not an assertion of intent:

- An earn lands in pending and is not redeemable until it matures.
- Maturity moves points between buckets without creating any.
- The same idempotency key cannot post twice; a redelivery returns the original.
- A ledger entry cannot be updated or deleted.
- A correction is a new row, and lifetime points count credits only.
- A clawback may take available below zero while the voucher balance floors at
  zero (Q3 recommendation).
- Consumption allocates against the oldest earning first.
- Pending points cannot be consumed.
- Every entry records the rule version in force **when the event happened**, so
  an entry dated before a rule change uses the older version.
- Posting to a merged account is refused.
- A rule change is never retrospective.
- Rebuilding a 61-entry mixed history from a zeroed cache reproduces the balance
  exactly.

On identity, roles and audit:

- A token issued for a different app is refused, and a token signed with the
  wrong secret, an expired one, and one with no usable subject.
- The shop always comes from the token, even when a `shop` query parameter
  disagrees — asserted at both the verifier and the HTTP layer.
- A staff member from another shop cannot borrow a role.
- The first staff member on an unconfigured shop becomes Administrator, once, and
  it is audited as `staff.bootstrapped`.
- A second staff member is granted nothing automatically.
- The full 4×4 role-by-floor matrix over HTTP: 200 where the held role meets the
  floor, 403 `role_floor_not_met` with required and held in the details where it
  does not.
- A revoked role takes effect on the very next request, with the same token still
  in the browser.
- The last Administrator cannot be demoted; once a second exists, they can.
- An action that requires a reason is refused without one, and a blank reason does
  not satisfy it.
- An unknown audit action is refused, so a typo cannot create a category nobody
  looks at.
- An audit entry cannot be edited or removed.
- Manager and above are unrestricted on adjustments; an Agent gets the rule
  default unless given a personal override.
- The staff name falls back to the identifier, because `read_users` is protected.

On the API, over HTTP, through the real middleware:

- Every route under `/api/admin` declares a role floor, and each floor is the one
  the plan gives it. A test also fails if an endpoint is added or removed without
  the plan table being updated, so the two cannot drift apart quietly.
- Every endpoint refuses a staff member with no role, and refuses the role
  immediately below its floor with `role_floor_not_met` and both the required and
  the held role in the details.
- A member belonging to another shop is **not found**, never forbidden — asserted
  on the profile, the correction, the list, the ledger, the audit log, the staff
  list and the overview.
- A member with no email address is findable by legacy card, by postcode however
  it is spaced, by surname and by full name, and through the single search box
  rather than only through a field selected first.
- Two members with no email coexist, and enrolling one is accepted; a duplicate
  email is refused with the existing account id so the console can offer a link.
- An enrolment nobody could find again — a first name and nothing else — is
  refused. 31 February is refused; 29 February is not.
- An email change is refused with `email_immutable`; resending the same address
  unchanged is not a change. A correction records only the fields that moved.
- A merged account refuses a posting and refuses an edit, with the surviving
  account id in the details. A cache rebuild on one is still allowed, because it
  writes no entry and a tombstone with a wrong cached balance is worth fixing.
- The adjustment preview writes no ledger entry and no audit entry, needs no
  reason, and reports an over-limit adjustment rather than refusing it, so the
  modal can say why before anyone types a reason.
- An adjustment with no reason, with a category but no notes, with "fixed it", or
  with ten spaces, is refused four different ways and posts nothing.
- An Agent over their limit gets `409 adjustment_limit_exceeded` with the limit
  and the request in the details; the limit applies to deductions equally; a
  personal override beats the rule default; a Manager is unrestricted.
- Repeating an adjustment with the same `Idempotency-Key` returns the original
  entry with 200, and the points move once and the log records it once.
- The reason reaches the ledger entry and the audit entry identically, so the
  member record and the audit log cannot tell different stories.
- Adjusted points carry an expiry from the rule version (C10); a deduction
  carries none.
- The running balance on a ledger page is the account balance at that moment:
  filtering the view to adjustments does not make it pretend the earnings never
  happened.
- A partial rule payload is rejected rather than merged, a self-contradictory one
  is rejected by the cross-field rules, and the previous version is untouched
  either way. A save is audited with only the fields that moved.
- The last Administrator cannot be demoted through the API; once a second exists,
  they can.

**The console — app frame, and Customers.** V1 as specified: Polaris web
components (`s-*`, globally registered by the App Bridge script, no import) with
`@shopify/app-bridge-react` for `NavMenu`. **`@shopify/polaris` is not a
dependency and must not become one.** App Bridge patches `fetch` and attaches the
session token to same-origin requests, so nothing on this side plumbs a token by
hand and `EnsureShopifySession` / `EnsureStaffRole` remain the enforcers.

| Piece | What it does |
| --- | --- |
| `App.jsx` | The route table. Every area of the agreed UI is routable from the first commit, so the navigation has no dead links |
| `app/AppFrame.jsx` | `ui-nav-menu` through App Bridge, so the links render in the admin's own left-hand menu rather than in a second menu the app drew for itself |
| `app/navigation.js` | The nav, in the grouping of the agreed UI: six under Programme, then Settings and Audit logs under Configuration |
| `app/SessionProvider.jsx` | One call to `GET /api/admin/me` before any screen renders, and the three ways it can end |
| `app/RoleGate.jsx` | Hides or disables an action by named permission. **UX only** — the server role floors are the control |
| `app/useElementEvent.js` | Subscribes to the DOM events Polaris components emit (`input`, `change`, `nextpage`), which React's synthetic system does not know |
| `api/client.js` | `fetch` plus the `{ error: { code, message, details } }` envelope, as a typed `ApiError` screens can branch on |
| `lib/format.js` | Pence and points as the agreed UI writes them: `£15`, `£72.40`, `1,120`, `+72`, `−200` |

**A2 — Customers, search and list.** One search box across email, name,
postcode, the club card number **and** the legacy Dynamics card number, or any
one of them on its own. A member with no email held is marked in red exactly as
the agreed UI marks them (MD1), because a blank cell reads as data that failed to
load. Segment and enrolment-channel filters as shown. Filter state lives in the
URL, so a search is a link a colleague can open and the back button works.

Two filter values were added beyond the agreed UI, both deliberately: **Unknown**
under Segment, because `unknown` is the schema default and most members hold it,
and **Console** under enrolment channel, because console enrolment exists in the
data. Leaving either out would make those members unreachable by filter.

**A3 — Customer profile.** The identity card (email as primary identifier,
postcode as supporting, both card numbers, segment, marketing consent), the five
balance cards, and the Points ledger / Vouchers tabs. Two requests for the whole
screen: the profile answers everything bar the movements, which are paged.

The five balance figures are the agreed UI's: available points marked *not shown
to the customer*, pending with its clear date, voucher value with points used and
carried forward, points to the next increment, and lifetime spend with the last
purchase date. The "next £5" label is built from the rule version rather than
hard-coded, so changing the increment in Settings changes the label.

**Nothing is invented where the programme does not hold it.** The agreed UI shows
a **Mobile** number and a **Klaviyo sync** state on the profile; this system holds
neither — Klaviyo is Sprint 4 — so both render as an em dash. Marketing consent is
three states, not two: *Opted in*, *Not subscribed* and *Not asked*, because MD4
turns on the difference between a member who declined and one who was never asked.

**Stubbed, not missing.** Dashboard, Loyalty, Voucher management, Transactions,
Reports, Settings and Audit log each render a placeholder naming the sprint that
owns them and what they will do. Four actions are present-but-disabled so the
agreed layout is reviewable without a dead button: **Enrol member** and **Adjust
points** (both gated by `RoleGate` first, so a Viewer never sees them at all),
**Issue voucher**, and **Export CSV**.

**A4 — Manual points adjustment.** The modal from the member profile, gated at
the adjustment floor so a Viewer never sees the trigger and the form is not even
mounted for them. Add/Deduct, points, reason category and notes, in the wording
of the agreed UI.

Three things about it are the server's, and deliberately:

- **The projection.** `preview: true` writes nothing and returns the arithmetic
  that will actually run, so "New balance will be 390 points → £15 available (90
  pts carried forward)" is never computed on this side. A second implementation
  of the voucher ladder here would be the untested one, and it is the one a
  member of staff would read out.
- **The reason.** `AuditLogger` refuses `points.adjusted` without one. The modal
  states the rule up front and then renders whatever the server says per field;
  it does not gate the request, because a rule enforced only in the browser is
  not enforced.
- **The limit.** An Agent over their personal limit gets
  `409 adjustment_limit_exceeded`, and both the message and the two numbers in
  the details are shown inline. A preview that is over the limit is *reported*
  rather than refused, so the modal can say so before anyone writes a reason for
  an adjustment that was never going to be accepted.

One idempotency key is generated per modal session, so a double click or a retry
after a dropped response returns the original adjustment. On success the profile
and the ledger are both re-read from the server rather than patched locally: the
balance after an adjustment is the ledger's answer.

Where the agreed UI predicts the reference ("ADJ-2262"), this does not — the
reference is derived from a ledger entry that does not exist until the save. It
says a reference is generated on save instead.

**A12 — Audit log.** Manager floor. Timestamp, user, role, action, target,
before → after, and reason, with filters for user, action and a date range, all
URL-backed. The action and user vocabularies come from the server's own meta, so
the user filter includes anyone who has acted on the shop — including someone
whose role was later revoked, which is exactly when they matter. A member target
is named by card number, as the agreed UI names it. The footer states that
entries cannot be edited or deleted.

A role below Manager never sees the nav item, and a direct URL hit renders the
server's refusal as a screen — naming the role needed and the role held — with
no table, so nothing reads as "nothing ever happened".

**A5 — Loyalty.** The five summary tiles, the programme ledger with type and
channel filters (URL-backed like the Customers search), the rules currently in
force, and a Rules settings button through to Settings. Every row names its
member and links to the profile.

The rules panel reads the current rule version rather than restating the launch
values, so changing a rule in Settings changes this panel and the two cannot
drift. Job health is included and is counted from the ledger: a queue that has
quietly stopped shows here as points that should have matured and have not,
which is the failure this programme is most exposed to and the one with no other
visible symptom.

**A10 — Settings, the rules engine.** Administrator floor on the save; a Manager
reads the screen with every control inert, because reading the rules is a Viewer
permission. Two properties of the rules layer shape it:

- **A version is a full snapshot.** `RuleSchema` rejects a partial payload rather
  than merging it, so the form sends every rule — including the ones it has no
  field for. Those are listed under *Carried forward unchanged* rather than left
  invisible: a field nobody can see is still a field they are saving. Most are
  held open by decisions that are not closed (`qualification` by C3, `earn_base`
  by D3), and a field for them would imply a choice nobody has made.
- **A change is audited with the old and the new value.** The confirm step is
  therefore a **diff**, not a yes/no: it lists each changed rule as before → after,
  which is exactly what the audit log will record, plus an optional change
  summary that goes into the same entry.

Where the agreed UI and the stored model disagree, the screen says what is
stored: points expiry is in **days after availability** (D2), not months after
earning; the voucher value and the redemption increment are **one field**,
because they are one value and two fields would let them disagree; and *Voucher
expiry* is the birthday reward's expiry, because per D1 the points-derived
voucher value has no expiry of its own.

**Shared frontend pieces added.**

| Piece | What it does |
| --- | --- |
| `components/FilterBar.jsx` | `SearchFilter`, `SelectFilter`, `DateFilter` and `useTablePagination` — the Customers, Audit and Loyalty screens all filter and page the same way |
| `components/AdjustPointsModal.jsx` | A4, including the preview, the limit and the per-field server errors |
| `lib/cardNumber.js` | The club card number for the audit log, where an entry carries a subject id and nothing else. **Coupled to C11** — the one place on this side that mirrors `MemberCardNumber` |

### Verified in the console

128 frontend tests across eleven files, and a console error or warning fails a
test, so every built screen loads clean.

- Every area of the agreed structure is reachable for an Administrator; the audit
  log is left out of the menu for a role that cannot read it; Settings stays for a
  Viewer, because reading the rules is a Viewer permission.
- The App Bridge nav menu is rendered, with the home link first.
- Nothing renders until the role is known. A staff member with no role is told an
  Administrator must give them one; any other failure is reported in the words the
  server used, not replaced with "something went wrong".
- `RoleGate` on **Adjust points**, asserted for all four roles: hidden for a
  Viewer, present for an Agent, a Manager and an Administrator. `disable` mode
  greys out rather than hiding. A permission the server has never heard of fails
  closed.
- All seven placeholder routes render, and an address that is not part of the
  console says so.
- The customers list shows every column the agreed UI shows, in its order and
  wording; a member with no email held is a red badge; the search field and all
  six field options are present; the query, field, segment and channel from the
  URL reach the API.
- The profile shows a day-and-month birthday without inventing a year (MD2), the
  five balance figures exactly, and the ledger row as `13 Aug 09:31 · Earn ·
  Qualifying spend £72.40 · Pending to 12 Sep 2026 · In-store · #POS-8841 · +72 ·
  340` — the last being the balance after the movement, not a total of the rows on
  screen.
- A reason a person wrote wins over any derived wording in the Detail column.
- A merged account is warned about, and a missing member says so plainly.

On the adjust-points modal:

- The projection shown is the one the endpoint returned, and the endpoint is
  called with `preview: true` and **no** idempotency key, because a preview
  writes nothing.
- A deduction previews as a deduction.
- Nothing is requested until a number of points has been entered.
- Short notes, a missing category and a missing note each render the server's own
  message against the field it belongs to.
- An Agent over their limit sees the server's message **and** its details — asked
  for 900, limit 500 — not a generic failure. A preview over the limit warns
  without refusing.
- Any other refusal, such as posting to a merged account, is shown in the words
  the server used.
- The reason and the notes reach the endpoint together with one idempotency key,
  and the profile is re-read afterwards.

On the audit log:

- Every column of the agreed UI, with the manager role written as "Loyalty
  manager" and a member target written as a card number.
- An actor holding no role — the system, the migration importer — is an em dash,
  never an invented role.
- The action and user filters are built from what has actually happened on the
  shop; a chosen filter goes into the URL, and the URL's filters reach the API.
- A role below Manager gets the refusal rendered as a screen, with no table.
- The footer states that entries cannot be edited or deleted.

On the Loyalty screen:

- All five tiles, with the three figures this system does not hold yet — the
  qualifying spend behind today's earning, the next expiry run, the refund and
  cancellation split — rendered as em dashes.
- The date the oldest pending points clear, which it does hold, is named.
- Scheduled work that is behind is reported as a warning with the points and
  entries involved; work that is up to date is stated plainly.
- The rules panel follows the saved rule version rather than the launch values.
- Rules settings links to Settings; each ledger row links to its member.

On Settings:

- Pence fields are entered in pounds (500 shows as 5) and the confirm step shows
  them the same way.
- Save and Discard stay inert until something has changed.
- The confirm step lists each changed rule as before → after.
- The save sends the **whole** snapshot — every key of the rule payload, not only
  what changed — and the untouched values go with it unaltered.
- A rejected rule renders against its own field.
- A Manager reads the screen with the fields and Save disabled; an Administrator
  gets it live.

**A1 — Dashboard.** The six cards and the recent activity strip, all from one
request. Two of the agreed UI's sub-figures are not figures this system holds,
and neither is invented:

- **Redemption rate.** Redemption arrives in Sprint 3, so there is no
  denominator. The card is an em dash, with the amount actually redeemed
  underneath — a real query against `loyalty_redemptions`, which will read zero
  until the till and the checkout start writing to it.
- **"7,683 vouchers unredeemed."** Per D1 nothing is issued: a member's reward
  value is derived from their available points on read and has no expiry of its
  own. There is no count of vouchers, so the card says where the figure comes
  from rather than inventing an object that does not exist.

The date-range control and Export summary are shown inert with a line saying
both arrive with Reports in Sprint 5 — the endpoint answers fixed windows, and a
control that silently did nothing would be worse than one that says so.

The activity strip links each row to its member and the strip itself to the
programme ledger. The agreed UI points that link at Transactions (A7), which
Sprint 2 owns; until it exists the link goes to the ledger showing the same
movements.

**One endpoint change.** `GET /api/admin/overview` gained
`points.points_issued_last_30_days`. "Points issued (30d)" is a card the agreed
UI asks for and the ledger plainly holds, so em-dashing it would have been
hiding a gap that took three lines to close. Added to the dashboard's own
composition rather than to `loyaltyTiles()`, because the Loyalty screen is a view
of now and a thirty-day total would sit oddly beside it. Two backend tests cover
it, including that the figure is a window: an earn inside thirty days counts and
one outside it does not.

On the Dashboard:

- All six cards, with the active and lapsed split, the change against the
  previous thirty days, and the online / in-store split.
- The redemption rate is an em dash and the card contains **no** percentage at
  all — asserted with a pattern, not just an absence of one string.
- The voucher card says where the figure comes from and does **not** claim a
  count of unredeemed vouchers.
- A first month with no history shows an em dash rather than 0%.
- The date range and Export summary are disabled and labelled as coming.
- The activity strip shows every column of the agreed UI, links each row to its
  member and the strip to the programme ledger, and says so plainly when nothing
  has happened yet.

### Fixed — the console rendered as unstyled text

**Reported 2026-08-27, after the browser pass.** In the embedded admin every
`s-*` element collapsed to inline text: no cards, no tables, no layout — while
the Shopify-rendered left navigation was perfect.

**Root cause: one missing script tag.** `index.html` loaded
`cdn.shopify.com/shopifycloud/app-bridge.js` and nothing else. That script
registers the App Bridge elements — `ui-nav-menu`, `ui-modal`, `ui-title-bar`,
`ui-save-bar` — and provides the `fetch` interception. It does **not** register
the Polaris `s-*` components. Those come from a second script,
`cdn.shopify.com/shopifycloud/polaris.js`, which defines all 68 of them and was
never added. Every `s-page`, `s-section` and `s-table` was therefore an unknown
element, and the browser laid out their text inline.

**Not** a stale bundle: the built `index.html` carried the real `client_id`.
**Not** CSP: `CspHeader` sets `frame-ancestors` only, which cannot block a
script. **Not** a 404: `app-bridge.js` returned 200 and ran.

**Why it was believed.** V1 recorded that the `s-*` elements "are globally
registered", which was read as *registered by App Bridge*. Reading App Bridge's
source appears to confirm it — the script contains `s-page` in a `querySelector`
and a `MutationObserver`, because it lifts the page heading and action buttons
into the admin title bar. It reads `s-page`; it never defines it. V1 is corrected
in `DECISIONS.md` with a table of which script registers what.

**Why no test caught it.** jsdom registers no custom elements at all, so an
unregistered `s-page` renders its children there exactly as a registered one
would. Every screen test passed against a DOM that could not tell the difference.
That is a permanent limit of the environment, not something to fix in the tests.

**The fix and the guard.**

- Both script tags are now in `index.html`, both classic (not `defer`/`async`) so
  they run before the app bundle, which Vite emits as a deferred module.
- `src/app/polarisGuard.js` runs at boot. If `customElements.get('s-page')` is
  still undefined after a short grace period, the app does not mount; it renders
  a **plain-HTML** banner instead — no Polaris component and no React, since the
  one thing known to be broken is `s-*`. The banner names the cause, names the
  script to look for in the Network tab, and explains that a working admin
  navigation beside a broken page is expected when only App Bridge loaded. That
  sentence is there because it is the detail that made this hard to place.
- Twelve tests in `tests/polarisGuard.test.js`: four assert `index.html` itself
  carries both scripts, keeps the api-key meta before App Bridge, leaves neither
  script deferred, and still does not reference `@shopify/polaris`; the rest
  cover the detection and the banner. The document tests are the ones that would
  have caught the original fault.

### Sprint 1 — done

Every screen in the agreed UI that Sprint 1 owns is built, tested and verified in
a browser. What remains stubbed is stubbed because a later sprint owns the thing
behind it, not because the screen was skipped:

| Stub | Why |
| --- | --- |
| Enrol member modal | `POST /api/admin/members` is built and tested; the modal is the last piece of M1 and was not in the agreed screen set. The button is shown disabled rather than dropped, so the layout stays reviewable |
| Export CSV / Export ledger / Export summary | No export endpoint exists in the plan before Sprint 5 Reports. Four buttons across Customers, Audit, Loyalty and the Dashboard are present and disabled, each next to a line saying when exports arrive |
| Date range on the Dashboard | The overview endpoint answers fixed windows. Selectable ranges are a Reports (Sprint 5) capability |
| Issue voucher | Sprint 2 owns the reward endpoints |
| Voucher management (A6), Transactions (A7), Reports (A8) | Sprint 2, Sprint 2 and Sprint 5. Each renders a placeholder naming its sprint and what it will do |
| Klaviyo tab on Settings | Sprint 4. Only the Rules tab exists |
| Migration report (A9) | Sprint 4 (M10). Not in the console navigation yet |

**Browser-verified on 2026-08-27.** Both modals open and close through
`command="--show"` / `commandFor`, the Settings diff-confirm shows the right
before → after, and the audit entries a save produces are correct. jsdom cannot
register `s-modal`, so that show/hide is the one behaviour the suite does not
cover — it was checked by hand instead, and needs re-checking after any change
to how a modal is opened.

That same pass found the missing `polaris.js` tag, above. The browser pass is not
optional on this project: jsdom cannot register a custom element, so the whole
class of "is this component real" faults is invisible to the suite. The boot
guard now catches the worst of it, but a screen still has to be looked at.

### Carried into Sprint 2

None of these belongs to a Sprint 1 screen; each is groundwork that Sprint 2 or a
deploy needs.

| Item | Notes |
| --- | --- |
| **D3 — the earn base** | `ASSUMED` 2026-08-27, no longer blocking. `earn_base` carries `post_discount_ex_tax_ex_shipping` (net of VAT and shipping) on every rule version and is shown on Settings under *Carried forward unchanged*. Sprint 2's earning calculation reads that value. Client notified; open to an objection, not waiting on an approval |
| ~~**D9 — proportional refund restore**~~ | **Done 31 Aug 2026.** Arithmetic, ledger mechanics (D9a–D9d) and the `refunds/create` and `orders/cancelled` handlers that drive them — see *Sprint 2 — complete* below |
| ~~**C4 — do lapsed members get the birthday reward?**~~ | **Confirmed 31 Aug 2026: yes, on DOB alone.** The birthday job is built with no Active/Lapsed check |
| ~~**D7 — two years of spend history**~~ | **`read_all_orders` APPROVED by Shopify 1 Sep 2026** (requested 27 Aug; the Partner Dashboard shows full order history access). Segmentation builds from our own ledger plus the migrated last-spend date regardless, so nothing is unwound. **Approval is not grant:** adding the scope to `shopify.app.toml` and `web/.env` forces one further merchant re-authorisation, held until the Sprint 3 dev-store run is finished. The historical backfill it unlocks **can now be scheduled** — Sprint 4, a one-shot per shop that moves no balance. On the go-live checklist |
| ~~Full scope set~~ | **Requested 31 Aug 2026** in `shopify.app.toml` and `web/.env` together. The **grant** is still outstanding: under the legacy install flow it forces one merchant re-authorisation, which is a merchant action, not a deploy step. On the go-live checklist |
| V2 volume pass | Seed the ledger to a projected two-year row count and confirm the profile query, nightly sweep and report queries stay indexed |
| ~~Queue worker and scheduler~~ | **Scheduler done 31 Aug 2026** — five commands in `routes/console.php`, asserted by `ScheduledEngineTest`. Production still needs the one cron entry (`* * * * * php artisan schedule:run`) and a **queue worker**, which the webhook-driven work will need |
| `Auditable` trait | Listed in the plan; `AuditLogger::logModelChange()` covers the need for now, so the trait waits until a screen wants it |

---

## Sprint 2 — complete

Delivered in two passes: the **scheduled engine** (maturity, expiry, birthday,
segmentation, verify-ledger) with the D9a–D9d reversal mechanics, and then the
**event-driven side** (order and refund webhooks, the earning calculator, the
replay command, the Klaviyo null driver) with the console catching up to what
now exists.

**Exit criteria, from the plan.** *A real order earns points* — yes, through
`orders/paid`. *A partial refund reverses them proportionally* — yes, D9c's
cumulative floor. *Points mature and expire on schedule with nobody running
anything* — yes, given the cron entry. *Segments recalculate nightly* — yes.

### The scheduled engine

`routes/console.php` is now the schedule. Every sweep is idempotent, so a missed
run is caught up by the next one rather than needing someone to work out what was
skipped, and each carries `withoutOverlapping()`.

| Command | Cadence | What it does |
| --- | --- | --- |
| `loyalty:mature-points` | hourly | Pending → available once the pending period is up (D2), **net of reversals** (D9b) |
| `loyalty:expire-points` | 02:10 daily | Retires what each expired lot has left, consuming that lot and no other |
| `loyalty:issue-birthday-rewards` | 00:20 daily | The £10 reward, on date of birth alone (C4, MD2, MD8) |
| `loyalty:recalculate-segments` | 03:00 daily | Active / Lapsed / Unknown from the ledger plus migrated last-spend (D7) |
| `loyalty:verify-ledger` | 04:00 daily | Changes nothing, exits non-zero on drift — the one that should alarm |

All five share `ShopScopedCommand`: per-shop, `--shop` to scope, and `--as-of` to
run against a stated date. That last is not a convenience — a sweep that can only
run at "now" cannot be tested at a boundary, and boundaries are where expiry,
maturity and segmentation go wrong.

**Ordering matters and is asserted.** Expiry runs at 02:10, inside an hour whose
maturity run has already happened at 02:00, so points that mature and expire on
the same date do both in the right order rather than in whichever order cron
fired.

### What each sweep got right that is easy to get wrong

**Maturity (D9b).** Matures the earn less every pending point already reversed
against it. The worked example is asserted directly: an 80 point earn with 20
reversed matures **60**, pending ends at **0**, never at −20. An earn reversed
away entirely matures nothing and is reported as *settled*.

**Expiry.** Consumes the lot that expired, not whichever was oldest by FIFO —
`LedgerPosting` now carries a target lot and `LedgerService` honours it.
Otherwise expiry retires someone else's points and the expired lot sits there
looking unspent. Only ever takes what a lot has **left**.

**Birthday (C4).** No Active/Lapsed check anywhere in `BirthdaySweep`, and the
class says so in as many words so nobody adds one later. Account **status** is
checked — a suspended account receives nothing — and the test names the
distinction so the two are not confused. Reads `dob_month`/`dob_day` and never
`date_of_birth` (MD2). The day comes from the shop's reporting timezone, not the
server clock, or a member is a day early in British Summer Time.

**Segmentation (D7).** Ledger first, then the migrated last-spend date, and the
later of the two where a member has both. The two-year window is inclusive at its
far edge — spend exactly two years ago is still active — and the boundary test
uses a fixed reference date, because "exactly two years" is not an assertion you
can make against a `now()` that moves while the fixture is being built. A member
with no recorded spend is **unknown**, never lapsed. Merged accounts are skipped.

### D9a–D9d, the reversal mechanics

Recorded in full in `DECISIONS.md`. In short: restored points return to their
original lots as **negative allocation rows** keeping their original expiry
(D9a); maturity nets off reversals (D9b); rounding is a **cumulative floor**,
never a per-refund fraction (D9c); a partial refund keeps the redemption's state
and tracks the reversed value (D9d).

`RefundReversalService` is the domain core the `refunds/create` handler will
call. It takes the **cumulative** refunded eligible value for the order, not the
size of the refund that just arrived — handing it a single refund's value would
reintroduce exactly the rounding D9c exists to avoid. That also makes redelivery
free twice over: the arithmetic computes an increment of zero before the
idempotency key on the posting is even consulted.

**The fault worth remembering.** Relaxing `ck_alloc_points` to `points <> 0` was
not enough. `loyalty_lot_allocations.points` was `unsignedInteger`, and MySQL
rejects a negative on an unsigned column *before any CHECK is consulted* — *Out
of range value for column 'points'*. **SQLite does not enforce unsigned at all**,
so all 274 tests passed on a schema that would have failed the first time a
refund ran in production. It was caught only by running the worked example
against MySQL 8.4 directly. The column is now signed, and a guard test reads the
migration source — not the live column — so a SQLite-only CI run cannot let the
unsigned back in.

That is a general lesson for this project, not a one-off: **the suite runs on
SQLite and MySQL-only constraints are invisible to it.** Every `CHECK`, every
unsigned column and every enum in these migrations is in the same position.

### Shared fixtures

`fixtures/loyalty-arithmetic.json` gains four sections, consumed by PHPUnit now
and by the discount function's Vitest suite in Sprint 3:

| Section | Cases | Covers |
| --- | --- | --- |
| `earning` | 15 | D3's base, qualification by tag and collection, the per-order floor |
| `refund_reversal` | 8 | D9c one refund at a time, including the £21 / p = 0.2625 case |
| `refund_reversal_sequences` | 4 | Split refunds totalling what one refund would, and full-refund-after-partials |
| `lot_release` | 6 | D9a's release planner and its per-lot cap |

Two tests assert a *contrast* rather than an answer, because each is the reason a
rule exists at all. Three £7 refunds restore **52** under the cumulative floor and
would restore **51** under per-refund rounding. Three £10.99 lines earn **32**
points floored once and **30** floored per line. If either pair ever agrees, the
rule has been quietly replaced by the thing it was written to prevent.

### Tests

Backend **334 / 1,610** (from 209 / 1,073 at the end of Sprint 1). Frontend
**134 / 11 files** (from 128). Both green — and the backend is green on **MySQL
8.4 as well as SQLite**, which it had never been before.

| File | Tests | What it holds |
| --- | --- | --- |
| `RefundReversalTest` | 12 | D9–D9d end to end, the negative-balance case (Q3), the signed-column guard |
| `LoyaltySweepsTest` | 24 | All four sweeps, their boundaries and their idempotency |
| `ScheduledEngineTest` | 10 | The schedule itself, cadence, overlap, and each command through artisan |
| `WebhookEarningTest` | 15 | Signature, fast acknowledgement, earning, both idempotency guards, edits, reversals, the C12 audit entries |
| `ReplayAndEventsTest` | 11 | Replay changing nothing, and all five Klaviyo events having a real call site |
| `MemberCardNumberTest` | 5 | C11's derivation, and the parser bug that returned another member's record |
| `CanonicalTest` | 7 | Map key order ignored, list order preserved |
| `JsonKeyOrderRegressionTest` | 5 | The Settings diff and the audit entry, on either connection |
| `LoyaltyArithmeticFixtureTest` | 52 | Every fixture section |

**Each of the three MySQL-only defects now has a regression test that fails on
SQLite too**, verified by reintroducing each defect in turn and confirming the
suite went red:

| Defect | Guarded by | How it works without MySQL |
| --- | --- | --- |
| Unsigned `points` column | `RefundReversalTest::test_the_allocation_points_column_is_signed` | Reads the **migration source** for the signed change, and additionally checks the live column type when the connection is MySQL |
| JSON key order | `CanonicalTest` (7) and `JsonKeyOrderRegressionTest` (5) | Builds the reordered payload **by hand** rather than waiting for MySQL to produce one |
| Card number parser | `MemberCardNumberTest` | A pure unit test; no database at all |

That verification matters more than the tests existing. Before it, breaking
`Canonical::equals` left all 322 tests green on SQLite; now it turns 7 of them
red. The other two were already caught.

### The webhooks, and what they deliberately do not do

Four business topics, each registered per shop through the Admin API (never in
the toml, which `use_legacy_install_flow` forbids):

| Topic | Handler | What it queues |
| --- | --- | --- |
| `orders/paid` | `OrdersPaid` | Earning |
| `orders/updated` | `OrdersPaid` | A recalculation after an edit |
| `orders/cancelled` | `OrdersCancelled` | A full reversal |
| `refunds/create` | `RefundsCreate` | A proportional reversal |

**Every handler records the delivery, queues the work, and returns.** Nothing
else. The 200 Shopify waits for must not depend on an Admin API round trip, and
a webhook endpoint that is slow gets throttled and eventually unsubscribed — a
loyalty programme that has quietly stopped earning is not something anyone
notices quickly. `test_the_endpoint_answers_without_doing_the_work` holds that
line: it asserts the response is returned with the order **never read** and no
ledger row written.

**The payload is a trigger, not the arithmetic input** (M3, M8). Every figure is
re-read from the order in the job, behind an `OrderSource` interface — because a
payload can be stale, can arrive out of order, and carries neither the discount
allocation nor the quantities left after an edit. `AdminApiOrderSource` is the
real one and needs `read_orders`; the suite binds a fake.

**Two idempotency guards, which fail differently.** The webhook id catches
Shopify sending the same delivery twice, and stops it before the Admin API is
touched at all. The ledger's idempotency key catches the same *effect* arriving
by a different route — which is what `orders/paid` followed by `orders/updated`
is, and what a replay is. Both are asserted end to end.

**M3's open validation is closed.** `orders/updated` is registered rather than
`orders/edited`. Earning is cumulative and re-reads the order, so the broader
topic is sufficient and strictly safer; `orders/edited` carries only the edit
delta, which this design neither needs nor would trust.

### Earning, per D3

`QualifyingValueResolver` decides which money counts; `EarningCalculator` decides
what it is worth. Kept apart because they fail differently.

- **Ex tax, ex shipping, after discount.** Shipping is not a line at all, because
  it never qualifies and a shipping figure on the order object would eventually
  be added to something. Tax is removed in the order source, from the order's own
  `taxesIncluded` flag — guessing would be wrong on half of all stores.
- **Floored once per order, not per line.** Three £10.99 lines earn 32 points
  together; flooring each would give 30 and cost a member two points for buying
  three things instead of one. That contrast is a fixture case.
- **A line with zero quantity earns nothing** — that is how Shopify represents a
  refunded or removed line, and paying for it would be paying for something the
  member no longer has.
- **Exclusions beat inclusions**, or an exclusion list would be unreachable
  inside an included collection, which is exactly where a merchant would use it.
- **`full_price_only` is refused at the calculator**, not only at the rule
  schema, so a rule version saved before that guard existed cannot pay out on a
  rule nobody has agreed (C3, Q1).

15 cases in `fixtures/loyalty-arithmetic.json` under `earning`, including the
zero-value order, the fully discounted line, the order made entirely of excluded
items, and a non-default earn rate so nothing hard-codes the launch value.

### The replay command

`loyalty:replay-orders`, aimed by `--order`, `--from`/`--to`, or `--failed`.

The whole design rests on one property, and it is asserted directly: **replaying
an order that was already processed correctly changes nothing.** Earning posts
the difference between what an order should have earned and what it already has;
reversals are a cumulative floor. So when nobody is sure what was missed, the
right move is to replay a wider range than necessary.

That is deliberate. A replay command that had to be aimed precisely would be used
late and nervously, which is the wrong instinct when points have stopped moving.
`--dry-run` reports what it would touch without even reading an order.

### Klaviyo, behind a null driver

`EventBus` with `NullEventBus`, which logs at debug and drops. All five Sprint 4
events emit from real call sites **today**:

| Event | Emitted from |
| --- | --- |
| `member.enrolled` | Enrolment (M1) |
| `points.available` | The maturity sweep |
| `voucher.increment_reached` | The maturity sweep, **only on a crossing** |
| `points.expiring_soon` | The expiry sweep, the day a lot enters the warning window |
| `reward.birthday_issued` | The birthday sweep |

The call sites are the expensive part, not the transport. Doing them in Sprint 4
would mean going back through earning, maturity, expiry, enrolment and the
birthday job and finding all the places again. Sprint 4 binds a driver and
changes nothing else.

Two details that are easy to get wrong and are tested: the voucher-increment
event fires only when the balance actually crosses an increment (forty points is
not a five pound voucher, and saying so would be a lie), and the expiry reminder
fires on the day a lot *enters* the warning window rather than every night it
sits inside it, so a flow gets one trigger per lot instead of thirty.

### The console catches up

Everything the agreed UI asked for that the programme now actually holds:

- **Qualifying spend behind today's earning** — held on every earn since Sprint 1,
  but nothing posted one until now.
- **Next expiry run** — read from the schedule itself rather than restated, so
  changing `routes/console.php` cannot leave the console quoting a stale time.
- **The refund and cancellation split** — a reversal from a refund carries a
  refund id; one from a cancellation does not.
- **When each sweep last ran**, from the audit log (C12). A sweep that stopped
  shows as a date that stopped moving.
- **The store on a ledger row**, where the movement carries one. Only the id is
  held — the location *name* needs a lookup nothing has built.
- **Segment "as at" date**, because segmentation is derived nightly rather than
  assigned, so when it was last worked out is part of what the pill means.

**The em-dash rule stands** for everything a later sprint owns. Nothing here
invents a figure the programme does not hold, and the fallback is still asserted:
when the reversal split is absent, the tile shows an em dash.

### Three MySQL-only defects, none of which the suite could see

The suite runs on in-memory SQLite. Production runs on MySQL. **SQLite is the
more forgiving of the two**, and running the suite against MySQL for the first
time found three real defects sitting behind a green build.

1. **`loyalty_lot_allocations.points` was unsigned.** D9a's release is a negative
   allocation row, and MySQL refuses a negative on an unsigned column *before any
   CHECK is consulted*. Relaxing `ck_alloc_points` was not enough; the column
   type had to change too. Every test passed on a schema that would have failed
   the first time a refund ran in production.
2. **MySQL's JSON columns do not preserve key order.** A rules payload comes back
   with its keys rearranged, so `qualification` read as *changed* on every save
   of a rule set nobody had edited — in the **Settings diff a person confirms**
   and in the permanent audit entry. Both now compare through
   `Support\Data\Canonical`, which sorts maps and leaves lists alone, because
   reordering a list of excluded collections **is** a change.
3. **`MemberCardNumber::parse()` kept whatever digits it found.** A postcode
   search for `SP4 6AB` became a primary-key lookup for account 46 and returned
   **an unrelated member's record**. Invisible on SQLite, where `RefreshDatabase`
   resets the auto-increment and the seeded members are always ids 1–3, so
   nothing ever collided. It now requires the input to *consist* of digits.

The habit that follows: **run the suite against MySQL before trusting a change to
a migration, a CHECK, an unsigned column, an enum, or anything that round-trips
through JSON.** The README says how.

### Still outstanding

Nothing of Sprint 2 remains. Carried forward:

| Item | Owner |
| --- | --- |
| **The cron entry, a queue worker, and the scope grant** | Deployment — code cannot supply these. On the go-live checklist and in the README |
| **V12 — prove the earn base on a tax-INCLUSIVE shop** | **GO-LIVE BLOCKER, added 3 Sep 2026.** The earn base subtracts both the discount allocation and tax when an order is tax-inclusive, and **only the tax-exclusive branch is proved** — the dev store is `taxesIncluded: false` and order `#1002` had no tax lines. OSC sells VAT-inclusive, so the unproved branch is the one that ships. Settle it by switching the dev store to tax-inclusive pricing and re-running a discounted order through A1–A3, or by reconciling one real discounted order by hand before launch. See V12 in `DECISIONS.md` |
| V2 volume pass | Seed to a two-year row count and confirm the sweep and report queries stay indexed. Sprint 5 at the latest |
| Location *names* on ledger rows | Needs a `read_locations` lookup and a cache. Only the id is held today |
| Sprint 2 console extras from the plan's frontend track | Adjustment and reward actions beyond A4, the staff roles screen. The audit viewer and job health are built |
| `Auditable` trait | `AuditLogger::logModelChange()` still covers the need |

---

## Sprint 3 — redemption

**Where this stands: everything is built and green in the suites. Nothing has
been proved against a real shop yet.** That distinction runs through this whole
section, and the dev-store script is what closes it.

### What was decided this sprint, and how

Three validations were run as spikes **before** anything was built on them, each
proved by compiling the function to WebAssembly and running it against the real
2026-07 schema rather than by reading documentation.

| | Outcome |
| --- | --- |
| **V4** — what the function can read at cart time | `RESOLVED`. Everything online redemption needs |
| ~~**V6a** — keeping item rules in step with the function~~ | `DECIDED`: app-synced metafields — and **VERIFIED on the real shop, 2 Sep 2026** at script step A2. See *A2 — V6a verified live* below |
| **V7** — POS Scanner API for the legacy card | `RESOLVED`, with one caveat needing a physical card |

**V4's finding is a security one and shapes the whole design.** A cart attribute
is writable by anyone holding the cart; a customer metafield in the app-reserved
namespace is not. So the **entitlement comes from the metafield** and the cart
attribute may only ever ask for **less**. Had the amount come from the attribute,
a customer could have set `loyalty_redeem_pence` to their basket total and paid
nothing. Two consequences follow and are built: an unauthenticated buyer gets
nothing, and the app publishes the **quote**, not the balance.

### A2 — V6a verified live (2 Sep 2026)

`loyalty:sync-exclusions` run against `loyalty-system.myshopify.com`:

```
loyalty-system.myshopify.com (rules v3) .. 17 product(s) - 0 excluded / 17 included
                                           - 17 flag(s) written - shop config written
```

`shop config written`, which is the outcome the dev-store script singled out as
the one thing V6a left unproven. Read back from Shopify rather than taken from
the command's own report:

| Metafield | Value |
| --- | --- |
| shop `app--414539251713/exclusions` | `{version: 3, online_redemption_enabled: true, pos_redemption_enabled: true, mode: "all", excluded_tags: [], excluded_collections: [], ...}` |
| product `app--414539251713/excluded` | `{excluded: false, reason: null, config_version: 3}` on every product sampled |

Two things this actually proves. The namespace really is the **app-reserved**
`app--` one, which is the whole basis for the function trusting the value; and
`config_version: 3` on the products matches `version: 3` on the shop, so a
half-finished sync would be visible rather than silent.

A gift-card product carries `excluded: false`, which is correct rather than a
defect: the flag encodes the **configured rule**, and the function reads
`isGiftCard` separately from its own input query.

**Found while running it:** the script's own ordering is wrong. A2 comes before
A3, but `ShopScopedCommand::shops()` derives its shop list from `LoyaltyAccount`,
and A3 is what creates the first account — so on a fresh store A2 reports
`No shops to sweep` and looks like a failure. Run it as
`loyalty:sync-exclusions --shop=<domain>`, or move A2 after A3.

**V6a's split turned out to be forced rather than chosen.** A shop metafield can
carry the config but cannot be applied, because `Product` in the function schema
exposes no enumerable tag list — only `hasAnyTag`/`hasTags` predicates whose
arguments are static in an input query. So the function could read a list of
excluded tags and still have no way to ask a product whether it has one. Each
carrier now does what it is actually good for:

- **shop metafield** — the shop-wide switch (`online_redemption_enabled` now
  takes effect without a redeploy);
- **product metafield** — one resolved boolean per product, computed through the
  same `QualifyingValueResolver` that decides earning, so a product that earns
  nothing also redeems nothing. The function never learns the rule's mode, which
  is why an include-only mode costs it nothing.

`ExclusionSync` runs on a rules save that actually changes `qualification`, and
by hand as `loyalty:sync-exclusions`. **It costs `write_products`**, which was not
in the granted set — added to the toml and `.env`, and it needs one further
re-authorisation.

**V7's fallback is detected, not assumed.** `shopify.scanner` exposes the list of
available scanner sources as well as scan events, so the tile asks whether a
scanner exists and offers keyboard entry when none does. A scan then takes
exactly the same path as typed input. The residual caveat needs a physical card:
whether an OSC legacy Dynamics barcode encodes the card number verbatim. If it
does not, the mapping goes in `src/lib/lookup.js` and nowhere else.

**D6 was corrected.** The signature is
`applyCartDiscount(type, title, amount)` — the middle parameter is **`title`**,
not `reason`. D6 recorded it as a reason string, and someone reading that would
look for a field that does not exist. The mechanism is unaffected and is better
than assumed: a title is the discount's **receipt-visible name**, which is
exactly what D6 wanted when it asked the receipt and the audit log to share one
identifier.

### The ladder, implemented twice

D8's ladder exists in PHP (`RedemptionLadder`, for the quote) and in TypeScript
compiled to Wasm (`extensions/voucher-discount/src/ladder.ts`, for the function),
because a Shopify Function cannot call back into the app at cart time.

**Both read `fixtures/loyalty-arithmetic.json` — the same file, not a copy.** The
Vitest suite resolves it at `../../../fixtures/`. Change the rule, change the
file, and whichever side was not updated fails.

Ten cases, including **the agreed UI's rejection example** (£70 basket, £45
eligible → offer £45) and eligible items worth less than one increment, which has
its own reason (`below_voucher_increment`) so the tile can explain it. Both
suites also assert the ladder as **properties** — never over the balance, the
eligible subtotal or the cap; strictly below the basket; always a whole increment
— so a new fixture case cannot pass by having its expectation written to match a
wrong answer.

### The quote lifecycle

```
quote    →  hold  →  confirm     points leave the ledger HERE, and only here
              ↓
            void                 expired, or the basket changed. Nothing spent.
```

- **Quote** (`POST /redemptions/quote`) writes nothing. The tile calls it before
  it shows an amount, so the number on screen is one the ladder has already
  agreed to and pressing redeem cannot fail on arithmetic.
- **Hold** (`POST /redemptions`) creates the row and publishes to the channel.
  `points_consumed` stays 0 — a basket abandoned at checkout is the common case
  and must cost the member nothing.
- **Confirm** happens on `orders/paid`, never on a button press.
- **Void** gives it back, and withdraws what was published.

**`orders/paid` → confirm.** The link between a paid order and its quote is **the
reference on the discount** — there is no other, because at quote time the order
did not exist. One code path confirms both channels: online the reference arrives
on an `AutomaticDiscountApplication` (the function's message), at the till on a
manual one from `applyCartDiscount`. `RedemptionReference` owns the format
because it is now read as well as written.

Four guards, each tested: the quote must belong to the order's member (**the
reference is printed on a receipt, so it is not a secret** — this is the only
place a copied one could spend someone else's points); a voided quote does not
spend; an unknown reference is ignored; a redelivery spends once.

**Quote expiry (`loyalty:expire-quotes`, every five minutes).** Added at the end
of this sprint, and not tidiness. An online hold publishes an entitlement the
function reads; if the member's points expired while it was still readable, the
function would offer the old amount and confirming it would spend points they no
longer have, driving the balance negative — which is not what Q3 permits, because
that is a redemption rather than a clawback.

**What an expired quote does — confirmed 31 Aug 2026 (C13).** It is **voided and
its published entitlement withdrawn, with no re-quote.** Both channels ask for a
fresh quote against the live basket anyway — the tile when the member screen
opens, the storefront on the next hold — so re-quoting here would be guessing at
a basket nobody is looking at. Voiding costs the member nothing, because a quote
never spent anything.

### The POS tile

Three screens, and one rule shaping all of them: **validation happens before an
amount is offered.**

| Screen | What it does |
| --- | --- |
| Lookup | One box, every identifier — email, name, postcode, Privilege Club number, legacy Dynamics number. Scan only where the device has a scanner |
| Member | Balance, and a redeem control stepping in whole increments up to what the server agreed. The increment comes from the quote, so changing it in Settings changes the control without a redeploy |
| Enrol | Surname plus email **or** postcode (MD1: an email is optional); birthday day-and-month with no year (MD2); a duplicate is handled as "already a member", not a failure (D10) |

**Offline (C7).** The tile refuses and says so in two sentences, the second of
which matters most: *continue the sale, points are added automatically once the
till is back online*. An assistant reading a dead tile as a broken till and
abandoning the sale is the failure that wording exists to prevent. Asserted
directly.

**Staff attribution.** Every hold carries the **pinned staff member**, which POS
reports separately from the signed-in user and which is who is actually at the
counter. Both are recorded. C9 is still open on whether a lower role should act
at the till at all; until it is answered the Sprint 1 floors govern — a POS action
is **attributed, not exempted**. Quote sits at Viewer (read-only); hold and void
at Agent, the adjustment floor.

The components are thin over tested modules (`money`, `reasons`, `lookup`,
`enrolment`, `tileState`, `api`), because a POS surface cannot be rendered in a
test environment and the parts that must be right are decisions, not markup.

---

## Verified by the suites vs. verified on a shop

**This is the most important distinction in the handover.** 413 backend tests
pass and every extension suite is green, and none of that proves the plumbing
between Shopify and this app.

### Green in the suites (no shop involved)

- The ladder, both implementations, against the shared fixtures.
- The function's behaviour against **real compiled Wasm** and the real 2026-07
  schema — 18 fixtures, including the security case
  (`a-cart-attribute-cannot-inflate-the-discount.json`).
- Quote → hold → confirm → void, including FIFO lot consumption and idempotency.
- `orders/paid` confirming by reference, and its four guards.
- Quote expiry, including the stale-entitlement case it exists for.
- The exclusion sync resolving rules to per-product flags.
- The tile's logic, including the offline state.

### Needs the dev-store script — `docs/DEV_STORE_TEST_SCRIPT.md`

Nothing below has ever run against a real shop. **Run the script first thing.**

| Step | What it proves | Why no test can |
| --- | --- | --- |
| **A** | The entitlement metafield is **live-readable by the function** at cart time | The spike proved the field is *selectable* and that Wasm reads a fixture. Only a live cart proves Shopify populates it from a `metafieldsSet` write |
| **A2** | **The shop-metafield write is permitted by the granted scopes** — the one genuinely unverified permission | Never attempted against a shop. If it fails, the sync reports `SHOP CONFIG NOT WRITTEN` and the function falls back to "enabled", so the rest still works — **but say so** |
| **B** | POS populates `cart.retailLocation`, and the pinned staff member reaches `staff_reference` | Readable in the schema, shaped in fixtures, never seen from a real till |
| **C** | A real sale reaches `state=confirmed`, with the **reference on the receipt** | The chain crosses four systems: tile → app → POS cart → order webhook → ledger |
| **D** | The offline state on real hardware, and points arriving afterwards | Airplane mode on an iPad is not something a test can do |

**If C leaves `state=quoted`**, the reference did not survive onto the order —
that is the interesting failure, because it is the only link. The script has the
GraphQL query to see what the discount is actually titled.

---

## What remains in Sprint 3 after the script run

| Item | Notes |
| --- | --- |
| **The storefront control for C1** | The only substantial build left. **Blocked on C1** and deliberately not guessed — see below |
| One further re-authorisation | For `write_products` (V6a). Do it as step zero of the script |
| Legacy barcode encoding | V7's residual. Needs one physical OSC card scanned on the device |
| Anything the script turns up | A, A2, B, C or D failing tells us something the schema could not |

**C1 is the only thing standing between Sprint 3 and done.** The *mechanism* is
built and swappable: the function reads one cart attribute,
`loyalty_redeem_pence`, honoured **downwards and in whole increments only**, with
the same seam in `RedemptionService::hold()`. Both possible answers are already
supported without touching the function or the ledger:

- *voucher applies automatically* → set no attribute, nothing changes;
- *customer chooses an amount* → a storefront control writes the attribute.

What is **not** built is the control itself: where it appears, what it looks
like, and whether a member can change their mind at checkout. None of that should
be guessed.

---

## Open client items

Everything currently waiting on someone outside the build.

| Ref | What is needed | Blocks | Who |
| --- | --- | --- | --- |
| **C1** | How a customer chooses an amount online — automatic, or a control? | The storefront control, and therefore the end of Sprint 3 | OSC |
| **C6 (live)** | The **named person** holding the first Administrator role on the production store | Go-live only. Mechanism is confirmed; this is a name, not a decision | Robert / OSC |
| **V7 residual** | One **physical legacy Dynamics card** to scan, to confirm what the barcode encodes | The scan path being trusted. Keyboard entry works regardless | OSC |
| **D8** | The redemption ladder order — **`ASSUMED`, client notified 27 Aug 2026** | Nothing. Open to an **objection**, not waiting on an approval | OSC |
| **D3, D9 (+D9a–D9d)** | Earn base, and the proportional refund rule — same `ASSUMED` posture | Nothing | OSC |
| **C2, C10, C11** | Below-zero clawback, adjusted-point expiry, club card numbering — all `ASSUMED` and built | Nothing | OSC |
| **C3, C9** | Full-price-only qualification; adjustments and overrides at the till | Not built, and deliberately so | OSC |
| ~~**D7**~~ | `read_all_orders` — **approved by Shopify 1 Sep 2026** | Nothing, and nothing was ever blocked. Adding the scope costs one re-authorisation, deferred until the Sprint 3 dev-store run is finished | — |

**The `ASSUMED` items are not blockers.** Each was built to its recommendation
and the client told which way; silence is a decision, not a delay. Only **C1**
blocks work, and only **C6 (live)** blocks go-live.

---

## Deployment requirements — nothing in code can supply these

Four, all in the README with how you would know each is missing:

1. **The scheduler** — one cron entry (`* * * * * php artisan schedule:run`).
   Six commands depend on it, including the new `loyalty:expire-quotes`.
2. **A queue worker** — the webhooks record and queue; without a worker nothing
   earns and nothing confirms.
3. **The scope grant** — granted on the dev store, **except `write_products`**;
   the production store needs the whole set at go-live.
4. **The real app URL and redirect URLs** — the released version still carries
   the scaffold placeholders (`default-app-home`).

---

## Decisions raised while building

**C10 — do adjusted points expire?** The Blueprint covers expiry for earned and
migrated points but not for points a member of staff adds by hand. Built to the
recommendation (they expire like earned points, carrying an expiry from the rule
version), flagged as PENDING in `DECISIONS.md`. Cheap to change before launch.

**C11 — where does the club card number come from?** The agreed UI shows two
card numbers on every member: the legacy Dynamics one, which is a real migrated
column, and a Privilege Club number (`0041-2287`), which nothing in the schema
holds — and search has to accept both. Built to the recommendation: the club
number is derived from the account id, so it is unique and stable without a
column and reversible, which makes searching for one an exact primary-key
lookup. If OSC is issuing physical cards with their own numbering this becomes a
column, and only `MemberCardNumber` and one search branch change. Flagged as
PENDING in `DECISIONS.md` with the two questions for OSC.

---

## Design notes worth remembering

**Two signed delta columns, not an amount plus a state column.** Points live in
one of two buckets and every movement is a transfer between them or in and out of
one. Balances are `SUM(pending_delta)` and `SUM(available_delta)` over immutable
rows, which is why a rebuild reproduces a balance exactly rather than
approximately.

**Lot allocations are rows, not a mutable column.** The ledger is append-only, so
the remaining life of an earning cannot be a column on the earn row. It is the
lot total minus its allocations, which makes expiry exact and the audit trail
free.

**The cache is refreshed by re-summing, not incrementing.** An increment can
drift; a re-sum cannot. After any posting, the cache equals the ledger by
construction.

**A lot only offers points that have actually matured.** An earn whose maturity
job has not run is still pending and must not be redeemable — the allocator
enforces this, so a redemption cannot quietly spend pending points.

**Two source-level bugs the tests caught, both now fixed.** `RuleSet::defaults()`
took no arguments, so PHP silently discarded override arrays passed to it — the
kind of failure that would have produced wrong rule values with no error. And
saving rules on a shop with no baseline made the user's save version 1 dated
*now*, so events predating it resolved to the new values: a rule change applying
retrospectively, which is exactly what the versioning exists to prevent.

---

## Environment notes

- Tests run on SQLite in memory; the schema is verified against both engines.
- `phpunit.xml` needs a 32-character `SHOPIFY_API_SECRET`: `firebase/php-jwt` v7
  refuses shorter HMAC keys, and a short one makes token tests fail with
  `DomainException` rather than an auth error.
- Pint was never clean across the pre-existing codebase. New and touched files
  are formatted. On 2026-08-27 a Pint run scoped too broadly also reformatted six
  scaffold files it need not have — `HealthController`, `ShopifyAuthController`,
  `WebhookController`, `CspHeader`, `EnsureShopifyInstalled`,
  `EnsureShopifySession` and `routes/web.php`. The changes are import order,
  concatenation spacing and `!` spacing only, all toward the standard every new
  file already follows, so they were kept rather than hand-reverted without a
  git history to reverse them against. `bootstrap/cache/*` was caught by the same
  run and regenerated with `php artisan package:discover`.
- `Reward` and `Redemption` had no `$table`, so Eloquent inferred `rewards` and
  `redemptions` rather than `loyalty_rewards` and `loyalty_redemptions`. Neither
  model had been queried before the profile endpoint read one. Both now declare
  it explicitly.
- **Frontend tooling.** `vitest` + `jsdom` + Testing Library, configured in
  `web/frontend/vitest.config.js` rather than `vite.config.js`, because that file
  throws without `SHOPIFY_API_KEY` and a test run has neither one nor a use for
  one. `npm test` in `web/frontend`.
- The suite pins `TZ=Europe/London`. Dates render in the browser's timezone, and
  that is both the programme's reporting timezone and OSC's only trading region.
- **React 19 writes a custom-element prop as an attribute until the matching
  property exists, then switches to the property.** A test that types into a
  field creates that property, so `getAttribute('value')` silently stops
  tracking from that point — `fieldValue()` in `tests/support.jsx` reads the
  property first. In a browser the registered Polaris component defines `value`
  from the start, so React always uses the property and the component always
  sees it.
- **`useElementEvent` uses a layout effect, not a passive one.** A passive effect
  runs after the browser already has the new DOM, leaving a window in which a
  control is on screen with nothing listening to it — a real lost keystroke, and
  the cause of a test that failed about one run in five. The handler is also held
  in a ref so the listener is attached once rather than torn down and re-added on
  every render.
- **`app-bridge.js` and `polaris.js` are two different scripts and both are
  needed.** App Bridge registers `ui-*` and intercepts `fetch`; `polaris.js`
  registers all 68 `s-*` components. App Bridge *reads* `s-page` to fill the
  admin title bar, which makes its source look like it registers them. It does
  not. `tests/polarisGuard.test.js` asserts both tags stay in `index.html`.
- **`s-*` elements are not registered in jsdom.** The App Bridge script that
  registers them does not load, so a component's `heading` or `label` stays an
  attribute and its text never reaches the DOM: `getByText` cannot see it. Tests
  read the attribute instead (`findHeading` in `tests/support.jsx`). This is a
  real limit of the environment, not a workaround to be replaced — what these
  tests can assert is the tree and the data in it, never how any of it looks.
- **npm resolves upward.** Running `npm install` from a directory with no
  `package.json` walks up and writes to the first one it finds — during this work
  that briefly added the console's dependencies to the *root* Shopify CLI
  manifest. Reverted; check `pwd` before installing, and check which manifest
  changed afterwards.
- `web/public/index.html` and `web/public/assets/` are **build output**, rebuilt
  by `shopify app build`. The API key is baked in at build time from
  `SHOPIFY_API_KEY`, so a build run with a placeholder key publishes that
  placeholder.
- Every business webhook topic is registered per shop through the Admin API.
  Nothing goes in `[[webhooks.subscriptions]]` — that block makes the app
  undeployable under `use_legacy_install_flow = true`.

---

## Next

**Sprint 3 is built and stopped. The next action is not a build — it is the
dev-store script.**

Everything in Sprint 3 passes in the suites and none of it has run against a real
shop. `docs/DEV_STORE_TEST_SCRIPT.md` covers the five things no test can reach:
the entitlement metafield being live-readable by the function, the shop-metafield
write permission, POS populating `retailLocation`, one end-to-end sale reaching
`state=confirmed` with the reference on the receipt, and the offline state on
real hardware.

Run it, send back the seven things it asks for, and the remaining work is:

1. Whatever the script turns up.
2. **C1**, once OSC answer it — the storefront control is the only substantial
   build left in Sprint 3.
3. Sprint 4 (Klaviyo, the customer account view) and Sprint 5 (migration,
   reporting, go-live), neither started.

**Sprints 1 and 2 are complete.** The ledger, the console, the scheduled engine,
earning, refunds and reversals are all built, tested and — for Sprint 2's
webhook path — exercised end to end in the suites.
