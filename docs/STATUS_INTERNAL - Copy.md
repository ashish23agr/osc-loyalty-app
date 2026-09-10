# OSC Privilege Club — Internal Status Review

**Prepared:** 9 September 2026
**Sources read in full:** `PROGRESS.md`, `DECISIONS.md`, `IMPLEMENTATION_PLAN.md`,
`proposal.docx`, `docs/ui/OSC_Privilege_Club_UI_Structure_v1_1_FINAL.html`,
`docs/DEV_STORE_TEST_SCRIPT.md`, `SESSION_HANDOVER.md`, `README.md`, and the
codebase (`web/app`, `web/routes`, `web/database/migrations`, `web/frontend/src`,
`extensions/`, `web/config`).

**No application code was modified. Nothing was committed or pushed.**

---

## How to read this report

### The verification rule applied

A module is **Built and Verified** only where every committed behaviour in it has
been proved against the real Shopify behaviour it depends on — a real order, a
real refund, a real customer, a real checkout, a real webhook delivery, a real
POS session, a real discount code, a real Admin API call.

Passing tests, fixtures, mocks, compiled-Wasm suites, local arithmetic and code
review do **not** count. Where a module's behaviour is only partly proved, the
module is downgraded to **Built but Unverified** and the verified half is named
explicitly. This is stricter than `PROGRESS.md`'s own audit tables in three
places, and each is called out below.

### One bound on every "Verified" in this project

Every piece of real-shop evidence in this project except the ledger arithmetic
was obtained on `loyalty-system.myshopify.com`, a development store whose
merchant address is **locked to the United States**, whose two POS locations are
US and Canadian, and whose base currency was changed twice by accident on
3 September 2026. That does not invalidate the evidence — a minted discount code
and a paid order are real events — but it bounds it. See the
*development store is artificial* rule in `DECISIONS.md`.

---

## 1. EXECUTIVE SNAPSHOT

### Built and Verified — 3

| Area | The real-Shopify evidence |
| --- | --- |
| **Ledger, rules engine and balance derivation (M2, part of M4)** | Real paid order `#1002` earned, was corrected by replay, and reconciles; `loyalty:verify-ledger` reconciles every cached balance; the derived voucher balance was exercised through a real checkout (2 Sep) **and** rendered on a real till (3 Sep) |
| **Online redemption — the single-use discount-code mechanism (M6, server side)** | Dev-store script A1–A4 passed 2 Sep 2026: code `PC-72347982` minted and read back from Shopify as `ACTIVE`/`usageLimit 1`/£50 GBP/customer-bound, applied at a real logged-in checkout (−£50 on £600), paid order `#1002` delivered `orders/paid`, `state=confirmed`, `points_consumed=1000`, and the expiry sweep voided an unused quote |
| **Qualification-rule sync to Shopify metafields (V6a)** | `loyalty:sync-exclusions` run live 2 Sep 2026; shop and product metafields read **back** from Shopify in the app-reserved namespace `app--414539251713`, `config_version` matching on both carriers |

### Built but Unverified — 4

| Area | Why it is not Verified |
| --- | --- |
| **Points earning on a tax-inclusive order (M3)** | **V12.** Only the tax-exclusive branch has ever run. OSC sells VAT-inclusive, so the branch that ships is the unproved one |
| **Refunds, cancellations and reversals (M8, D9/D9a–D9d)** | **No real Shopify refund has ever been processed.** `refunds/create` and `orders/cancelled` have never been delivered — the newest `webhook_events` row is `orders/paid`, 2 Sep 12:54:37. The arithmetic is heavily tested and has never met a real refund |
| **The scheduled engine — maturity, expiry, birthday, segmentation (M5, M9)** | Nothing runs `schedule:run` on the dev machine; `loyalty_rewards` holds **0 rows**; a held quote was observed expiring unswept. Every sweep's evidence is the test suite |
| **POS loyalty (M7)** | Two real holds exist (`PC-23502758`, `PC-46033088`) proving quote → member screen → steppers → Apply → `hold()` → location and staff attribution. **Tender → `orders/paid` → confirm → points leaving the ledger has never once completed.** Offline refusal (C7) does have real evidence, 9 Sep |

### Partially Built — 5

| Area | Built | Missing |
| --- | --- | --- |
| **Voucher / reward engine (M4)** | The derived balance, fully and verifiably | The **issued reward has no lifecycle** — V21. Five states declared, one reachable |
| **Customer / unified profile (M1)** | Five-identifier search, enrolment, profile, corrections | Shopify customer matching/linking, the **merge tool**, `customers/create` and `customers/update` webhooks |
| **Admin console (M13)** | 6 of 11 agreed screens, 19 endpoints, roles, audit | A6 Vouchers, A7 Transactions, A8 Reports, A9 Migration; staff-roles screen; enrol modal; every export |
| **Online enrolment (M1)** | Console and POS enrolment, D10 duplicate check | **Storefront enrolment does not exist**, and no `customers/create` webhook is registered |
| **Klaviyo (M11)** | The event seam: five events emitting from real call sites | No driver, no API key, no traits, no flows, no reconcile |

### Not Built — 5

| Area | State |
| --- | --- |
| **Customer-facing Privilege Club section (M15)** | **Nothing exists.** No customer account extension, no storefront surface, no app proxy, no theme extension, no `/api/account/loyalty`. A member cannot see their own balance by any route |
| **Reporting — all five reports (M12)** | `ReportsScreen` is a placeholder. No report endpoint, no query class, no CSV renderer, no PDF renderer, no export job, no `report_exports` writer |
| **Legacy Dynamics / ORD migration (M10)** | No importer, no profiler, no row mapper, no matcher, no dry runner, no `loyalty:migrate`, no migration screens. Three migration tables exist and **nothing writes them** |
| **Storefront / checkout redemption control (C1)** | Not built, deliberately — the mechanism is swappable, the control is not designed |
| **Reward state machine, goodwill issuance, cancel/reissue (M4, V21)** | No transition exists anywhere in `app/` |

### Blocked / Waiting for Decision — 6

| Ref | What is blocked |
| --- | --- |
| **C1** | The storefront/checkout redemption control — the last substantial Sprint 3 build |
| **C14** | Whether a Privilege Club voucher reduces the points earned on that order. Code is built to "yes"; unconfirmed |
| **V21** | The reward lifecycle, parked pending a briefing; overlaps V14 |
| **V14** | Reported spend after a correction, held until the Sprint 5 reporting design agrees what the column means |
| **C9** | Adjustments and overrides at the till; **V16's implicit-viewer decision is parked next to it** |
| **C3 / C5 / C8 / C10 / C11** | Full-price qualification; live currency and timezone; PDF export approach; adjusted-point expiry; club card numbering |

---

## 2. MODULE-BY-MODULE REVIEW

### 2.1 Loyalty / points engine (M2, M3) — **Built but Unverified**

**What exists now.** The complete append-only ledger and rules engine. Two
signed delta columns (`pending_delta`, `available_delta`) over immutable rows;
`LedgerService` as the single write path, idempotent and rule-versioned;
`LotAllocator` for FIFO consumption with signed release rows;
`BalanceCalculator` deriving balances and refreshing the cache by re-summing;
`RulesVersionRepository` with full-snapshot versions and as-at resolution;
`EarningCalculator` + `QualifyingValueResolver` per D3; `OrderEarningService`,
`OrderReversalService`, `RefundReversalService`, `RefundArithmetic`,
`AdjustmentService`, `MaturitySweep`, `ExpirySweep`, `SegmentSweep`,
`BirthdaySweep`, `ExpiryOutlook`, `RunningBalance`, `ProgrammeSummary`.
15 loyalty tables, applied to MySQL 8.4 and exercised on SQLite. Commands:
`loyalty:rebuild-balances`, `loyalty:verify-ledger`, `loyalty:mature-points`,
`loyalty:expire-points`, `loyalty:recalculate-segments`,
`loyalty:issue-birthday-rewards`, `loyalty:expire-quotes`,
`loyalty:replay-orders`, `loyalty:preflight`.

**What has actually been verified.**

- **Points earning against a real order.** Order `#1002` (`gid://…/7134863884528`)
  earned through a real `orders/paid` delivery, and the C14 defect (order-level
  discount ignored) was found *by* that order, fixed, and corrected by
  `loyalty:replay-orders`. The compensating-entry mechanism is therefore proved
  on real data.
- **Qualifying-spend base, tax-exclusive branch only.**
- **Balance derivation.** All four boundary cases the plan names (99→0, 100→£5,
  199→£5, 200→£10) plus the negative floor; the same derivation ran through a
  real checkout and rendered on a real till.
- **Ledger reconciliation.** `loyalty:verify-ledger` reconciles cached balances
  against the ledger and exits non-zero on drift.
- **Manual adjustments against a real store.** Account 10 holds points posted
  through the real embedded console with a real session token.

**What remains unverified.**

- **The tax-inclusive earn base (V12).** `gross − allocations − tax` has never
  run against a real VAT-inclusive discounted order. This is the branch that
  ships. **Go-live gate.**
- **Refund and partial-refund reversal.** `refunds/create` has never been
  delivered. D9's proportional restore, D9a's negative release allocations,
  D9b's maturity netting, D9c's cumulative floor and D9d's partial-refund state
  handling are all test-only. **This is stricter than `PROGRESS.md`, which lists
  "refunds, reversals" under BUILT AND VERIFIED on the strength of 478 tests
  plus the C14 replay — but the C14 replay is an earn correction, not a refund.**
- **Cancellation reversal.** `orders/cancelled` has never been delivered.
- **Maturity, expiry, expiry-warning, segmentation.** No `schedule:run` cron
  exists on the dev machine, so no sweep has run on a real shop with real
  consequence. A held quote was observed sitting unswept for exactly this reason.
- **V2 — the volume pass.** Never run. The ledger has never been seeded to a
  projected two-year row count and no query plan has been checked.
- **V14 — reported spend after a correction.** A compensating `earn_reversal`
  carries no `qualifying_value_pence`, so `ProgrammeSummary` and
  `MemberPresenter` overstate spend by the corrected amount. Account 10 sums to
  £600 against a real £550. Points, balances, maturity and segmentation are
  unaffected.

**Dependencies / blockers.** V12 needs one real discounted VAT-inclusive order
on OSC's store. Refund verification needs a real refund on any store — **this is
provable on the dev store today and has simply not been done.** V14 is held
until the Sprint 5 reporting design settles.

**Next.** Process one real refund and one real cancellation on the dev store
(cheap, mechanism-only, no UK establishment needed). Run the cron so at least
one maturity and one expiry sweep executes with real consequence.

---

### 2.2 Voucher / reward engine (M4) — **Partially Built**

The module splits cleanly in two and the halves are in completely different
states. Reading it as one row is what made an earlier status report read greener
than the evidence.

#### The derived balance — Built and Verified

`BalanceCalculator::derive()` — `floor(available / threshold) × value`, reading
the rule version, flooring negatives at zero per Q3. `VoucherBalance` value
object (increments, balance, remainder, points-to-next). `RedemptionLadder`
implementing D8's fixed order: eligible subtotal → minimum basket → configured
cap → below-basket rule → round down to increment. The ladder exists **twice** —
PHP for the quote, TypeScript compiled to Wasm for the function — both reading
the *same* `fixtures/loyalty-arithmetic.json`, and both asserted as properties as
well as cases.

Verified: through a real checkout (2 Sep) and rendered as "£150 of voucher value"
on a real till (3 Sep). Points-to-value conversion, remaining-points carry
forward, limits, minimum basket, maximum per order, below-basket rule and
increment rounding are all exercised. The agreed UI's own rejection example
(£70 basket, £45 eligible → offer £45) is a fixture case.

#### The issued reward — schema and birthday issuance only (**V21**)

| Item | State |
| --- | --- |
| `loyalty_rewards` table, five states, `cancelled_reason`, `superseded_by_reward_id`, `uq_reward_birthday` | Built. The schema anticipates the whole engine |
| `Reward` model | Built; `isOutstanding()` is its only behaviour |
| Birthday issuance (`BirthdaySweep`, `loyalty:issue-birthday-rewards`) | Built, scheduled, **never run on a real shop and has issued nothing** — the table holds 0 rows |
| `RewardStateMachine` | **Not built.** No transition to `redeemed`, `expired`, `cancelled` or `superseded` exists anywhere in `app/` — confirmed by grep |
| `ExpireRewardsJob` | **Not built.** An issued reward outlives its own `expires_at` indefinitely |
| `RewardIssuer` (goodwill path) | **Not built.** Only the birthday sweep issues |
| `RewardsController` — `POST /rewards`, `/cancel`, `/reissue` | **Not built** |
| Redeeming an issued reward at the till or online | **Not built.** `app/Domain/Redemption/` never references `Reward`; `Redemption.reward_id` exists and nothing sets it |
| `IssueGoodwillModal`, `CancelRewardModal`, `ReissueRewardAction` | **Not built.** `MemberProfileScreen.jsx:114` is a hardcoded `<s-button disabled>Issue voucher</s-button>` |

**`state` is currently decoration.** A birthday reward issued today would read
`issued` forever.

**UI status.** The reward *list* exists as `VouchersTab` inside
`MemberProfileScreen.jsx:429` with type, value, dates, a state pill and the
cancellation reason. The standalone **A6 Voucher management** screen is a
placeholder.

**Dependency.** V21 is **parked pending a briefing**, at the client-side lead's
direction, because it turns on the same question as V14: what a stored column is
allowed to mean, and whether expiry rewrites `state` or is derived on read the
way D1 derives the voucher balance from points.

**Next.** Brief V21 and V14 together. They cannot be decided independently
without the schema acquiring two incompatible conventions.

---

### 2.3 Online redemption (M6) — **Partially Built**

The server mechanism is the strongest evidence in the project. The customer's
half of the journey does not exist.

**What exists and is verified.** `DiscountCodeGateway` +
`AdminApiDiscountCodeWriter` (`discountCodeBasicCreate` /
`discountCodeDeactivate`). One quote → one single-use code, `usageLimit: 1`,
`appliesOncePerCustomer: true`, fixed amount, **customer-bound**, `endsAt` equal
to the quote's `quote_expires_at` so Shopify and the expiry sweep cannot
disagree. Confirmed on `orders/paid` by the reference on the discount —
`RedemptionReference` (`PC-` + 8 digits, digits-only by design because it is read
aloud across a counter). Four guards, each tested: the quote must belong to the
order's member; a voided quote does not spend; an unknown reference is ignored; a
redelivery spends once.

Proved on a real shop, 2 Sep 2026 — code minted and read back as `ACTIVE`,
applied at a real logged-in checkout, real paid order, real webhook,
`state=confirmed`, `points_consumed=1000`.

**The mechanism is chosen in exactly one place** — the `RedemptionGateway`
binding in `AppServiceProvider:112`, now `DiscountCodeGateway`. That
consolidation caught a real bug: `QuoteExpirySweep` chose its gateway by
*channel*, so once the online binding changed it would have withdrawn a
function-published quote through the code gateway. It now dispatches on the
redemption's recorded `discount_mechanism`.

**Grow-plan compatibility — settled.** D5 was `REVISED` on 2 Sep 2026. The
discount **function** path is Plus-only from a custom app
(`"Shop must be on a Shopify Plus plan to activate functions from a custom app."`
returned by `discountAutomaticAppCreate`). OSC is client-confirmed on **Grow**,
so the code path is production. The function (`DiscountFunctionGateway`,
`extensions/voucher-discount`) is **kept, not deleted**, as the Plus-and-above
implementation.

**Gift cards — closed empirically, no app guard needed.** Real paid order `#1001`
established that Shopify itself excludes gift-card lines from a fixed-amount
code. Worth knowing: the codebase has **no gift-card awareness at all**
(`grep isGiftCard|gift_card` over `web/app` returns nothing) — exclusion is
entirely Shopify's, which is fine on that evidence but is a dependency on
platform behaviour rather than our own rule.

**The real compromise in the code path.** The function re-applied the D8 ladder
to the live cart; a code cannot. `minimumRequirement.subtotal` covers the
min-basket and below-basket rungs, but **not the eligible-subtotal rule** — a
code applies to the whole order. **Harmless under the launch rules (`mode: all`,
nothing excluded), and a real gap the day OSC configures an exclusion.** This is
committed scope (proposal §4, UI A6's worked example) that the production
mechanism cannot currently honour.

**What is completely missing.** The customer's side. UI screen **C3 — Redeem at
online checkout** shows a Privilege Club panel at checkout with "£10 reward
applied", a "Use my remaining £5 too" control and an **Apply** button. None of
it is built, and C1 is deliberately not guessed. Under the code path the member
also needs a route to *receive* the code — the chosen default is an auto-apply
link (`/discount/PC-…`) mapping onto the account page's "Shop with my £15"
button, and **that default is not client-confirmed.**

**Next.** C1 is the gate. Nothing about the storefront control should be guessed;
the mechanism supports both answers already.

---

### 2.4 Shopify POS (M7) — **Built but Unverified**

**What exists.** A POS UI extension targeting `pos.home.tile.render` and
`pos.home.modal.render`, `pos.embedded = false`, with three screens:

| Screen | Contents |
| --- | --- |
| **Lookup** | One box over every identifier — email, name, postcode, Privilege Club number, legacy Dynamics number. Scanner offered only where `shopify.scanner` reports a source (V7); keyboard entry otherwise, and a scan takes the same path as typed input |
| **Member** | Balance, and a redeem control stepping in whole £5 increments up to what the server agreed. The increment comes from the quote, so changing it in Settings changes the control with no redeploy |
| **Enrol** | Surname plus email **or** postcode (MD1), day-and-month birthday with no year (MD2), duplicate handled as "already a member" (D10) |

Server side: `POST /redemptions/quote` (Viewer), `POST /redemptions` (Agent),
`DELETE /redemptions/{reference}` (Agent), `PosCartDiscountGateway`, and
`shopify.cart.applyCartDiscount(type, title, amount)` from the tile — D6, with
the title carrying the redemption reference so the receipt and the audit log
share an identifier.

**What has actually been verified on real hardware.** Two genuine POS holds:

- `PC-23502758` — `channel=pos`, £25 (stepped down from £50), location
  `95318016240` (US), staff `113711382768`, 9 Sep 10:32:59
- `PC-46033088` — £10, location `95318049008` (Canada), staff `113711382768`,
  9 Sep 12:48:47

Between them these prove: the tile reaching the app, a member screen (a hold
implies a quote), the **£5 steppers**, **Apply reaching `hold()`**, **location
attribution** with two distinct real location ids, **staff attribution** of the
pinned staff member, and `session.currency` arriving as a real populated field.

**Offline behaviour (C7) has real evidence, 9 Sep 2026.** The device was offline;
three tenders produced no order while two holds reached the app perfectly. That
split is exactly what C7 predicts — reads and holds are HTTP calls to us, order
creation is Shopify's own sync. POS queued the sales locally, which is correct
behaviour, not a fault.

**What has never happened.** **Tender → `orders/paid` → confirm → points leaving
the ledger has not once completed against this database.** There are zero POS
redemptions in a `confirmed` state, ever. Discount application at the till has
never been seen on a receipt. POS refund/reversal has never been exercised at
all.

**Error handling.** V17 is **fixed in code** (`messageFor()` in
`extensions/loyalty-tile/src/lib/reasons.js`): known codes get till wording
ending in an instruction — almost always *continue the sale*, per C7 — and
unknown codes fall back to the server's own message rather than a generic
string. **The `DECISIONS.md` validation table still lists V17 as `OUTSTANDING`;
that row is stale.**

**V19 is fixed in code** — `Modal.jsx` now carries 10 `onClick` handlers and zero
`onPress`, plus a visible Search button, and `handlerContract.test.js` derives an
allowlist from the installed platform types. **The `DECISIONS.md` table still
reads `OUTSTANDING — BLOCKS SPRINT 3`; that row is stale.**

**V18 is `WITHDRAWN` as a claim, and its code is still present.** The guard in
`RedemptionService::hold()` comparing `till_currency` against the rules currency
remains, along with `TillCurrencyGuardTest`. The withdrawal is of the *claim*
that it protects against a foreign till: the Canada hold proved
`session.currency` reports the **shop's** currency, so the guard duplicates V13.
The original defect is **uncovered** — see V24.

**V16 is unfixed and is a go-live gate.** `EnsureStaffRole` still returns
`403 no_role_assigned` with no implicit viewer floor for a verified POS token.
Only the first staff member on a shop is bootstrapped; every other till user gets
a tile that reads as broken. V22 (fixed) now logs the staff id on the refusal so
an Administrator can learn which id to assign.

**Next.** One uninterrupted POS sale, with the device confirmed online first and
the cart built and payment ready **before** Apply is pressed. `PROGRESS.md`
time-boxes this to **thirty minutes**, after which A1–A3 move to the live-store
list and customer-facing work starts regardless. That rule is written down for a
reason and should be honoured.

---

### 2.5 Customer / unified loyalty profile (M1) — **Partially Built**

**Built and well covered.** `MemberSearch` — one search box over five
identifiers, plus each on its own: email, name, postcode, club card number,
legacy Dynamics card number. `MemberCardNumber` derives the club number from the
account id (C11). Enrolment (`POST /members`) with the D10 duplicate-email check
returning the existing account id so the console can offer a link. Corrections
(`PATCH /members/{id}`) with an email change refused as `email_immutable`.
Profile presentation answering the whole screen in one call.

**Specifically confirmed against the requirements:**

- **Email matching** — normalised to lower case and trimmed; `uq_account_email`
  on `(shop_domain, email_normalised)` enforces one account per email at
  database level.
- **Name search** — surname and full name.
- **Postcode with and without spaces** — both. This is also the site of a real
  fixed defect: `MemberCardNumber::parse()` kept whatever digits it found, so a
  postcode search for `SP4 6AB` became a primary-key lookup for account 46 and
  returned **an unrelated member's record**. Now requires the input to *consist*
  of digits, with a unit-test guard.
- **Loyalty card number and legacy card number** — both searchable, both shown.
- **Members with no email (MD1)** — structural, not an afterthought. Two coexist
  under the unique index; each is findable by card, by postcode however spaced,
  and by surname; there is a filter for them and the console marks them in red
  as the agreed UI does.
- **DOB (MD2)** — `dob_month`/`dob_day` are real writable columns and the
  authoritative birthday; 31 February refused, 29 February accepted; a
  year-less birthday persists with `date_of_birth` NULL.
- **Legacy member ID** — `legacy_card_number` is the migrated column and stays
  searchable indefinitely.
- **Duplicate prevention** — at the database, at the endpoint, and at the till.

**Real-shop evidence.** Accounts 12 and 13 were enrolled from the admin console
on 9 Sep 2026, `enrolment_channel: admin`, both emitting `member.enrolled`, both
MD1 members. `POST /api/admin/members` and the D10 check are exercised against a
real shop.

**What is missing.**

- **Shopify customer matching and linking.** Both real enrolments carry
  `shopify_customer_id: null`. There is no `AccountResolver`/`MatchStrategy`
  chain against Shopify customers, no `customerCreate`/`customerUpdate` call, no
  `CustomerMetafieldWriter`, no `SyncCustomerProfile` job. The plan's M1 names
  all of these.
- **`customers/create` and `customers/update` webhooks are NOT registered.**
  `config('shopify.webhooks.topics')` holds six topics —
  `APP_UNINSTALLED`, `PRODUCTS_UPDATE`, `ORDERS_PAID`, `ORDERS_UPDATED`,
  `ORDERS_CANCELLED`, `REFUNDS_CREATE` — and neither customer topic. M1 says
  `customers/create` **drives storefront enrolment**. It cannot, today.
- **The merge tool.** No `MergeService`, no `GET /members/duplicates`, no
  `POST /members/merge`, no `DuplicateReviewList`, no `MergePreview`. Merged
  accounts are *honoured* everywhere (a posting is refused, an edit is refused,
  with the surviving id in the details) — there is simply no way to create one.
  D10 promises "an admin merge tool combines two records by replaying both
  ledgers into one"; the proposal promises duplicate prevention with resolution.
- **Gender** — the column exists and is writable from the console; the Shopify
  **gender metafield** on the development and live stores is still an outstanding
  OSC receivable, and nothing reads it.
- **Mobile number** — shown on UI screen A3 and held nowhere. Renders as an
  em dash.

**Online / store activity.** Held on every ledger entry as channel plus
`shopify_location_id`. Location **names** need a `read_locations` lookup and a
cache; only the id is held today.

---

### 2.6 Admin console (M13) — **Partially Built**

**Six real screens, browser-verified in the real embedded Shopify Admin on
27 August 2026** (that is genuine Shopify-environment evidence, not a test):

| UI ref | Screen | State |
| --- | --- | --- |
| A1 | Dashboard | **Built.** Six cards, active/lapsed split, 30-day change, online/in-store split, activity strip. Redemption rate is an em dash (no denominator before redemption data exists); the voucher card says where its figure comes from rather than claiming a count of unredeemed vouchers, per D1. Date range and Export summary shown **inert** and labelled |
| A2 | Customers — search and list | **Built.** One box over five identifiers, all six field options, segment and channel filters, URL-backed. No-email members badged red (MD1). Two filter values added beyond the agreed UI (Unknown segment, Console channel) because leaving them out would make those members unreachable |
| A3 | Customer profile | **Built.** Identity card, five balance cards, Points ledger and Vouchers tabs. Mobile and Klaviyo sync render as em dashes because the system holds neither |
| A4 | Manual points adjustment | **Built.** Add/Deduct, points, reason category, notes; server-computed projection via `preview: true`; per-person limit surfaced with both numbers; one idempotency key per modal session |
| A5 | Loyalty — programme ledger | **Built.** Five tiles, programme ledger with type and channel filters, rules in force read from the current rule version, job health counted from the ledger |
| A10 | Settings — rules engine | **Built.** Administrator floor on save, Manager reads it inert. Full-snapshot save; a diff confirm step showing before → after; rules with no field listed under *Carried forward unchanged* |
| A12 | Audit log | **Built.** Manager floor. Timestamp, user, role, action, target, before → after, reason; user/action/date filters built from the server's own meta; a sub-Manager role gets the refusal rendered as a screen |

**Not built — five areas of the agreed console:**

| UI ref | Screen | State |
| --- | --- | --- |
| A6 | Voucher management | **Placeholder.** `screens/placeholders.jsx`. Read narrowly: what is missing behind it is the reward state machine (V21), not a day's screen work |
| A7 | Transactions | **Placeholder.** No order-level transactions view exists; the Dashboard activity strip links to the programme ledger instead |
| A8 | Reports | **Placeholder.** See §2.10 |
| A9 | Migration report and exception review | **Not built and not even routed.** It is absent from `App.jsx` and from `navigation.js` |
| — | Staff roles screen (`/settings/staff`) | **Not built.** `GET /staff` and `PUT /staff/{staffId}` exist and are tested; there is no screen |
| — | Enrol member modal | **Not built.** The endpoint is built and now exercised on a real store; `CustomersScreen.jsx:130` renders `<s-button variant="primary" disabled>Enrol member</s-button>` inside a `RoleGate` |
| — | Klaviyo tab on Settings | **Not built.** Only the Rules tab exists |
| — | Every export | **Not built.** Four disabled Export buttons: Customers CSV, Audit CSV, Loyalty ledger, Dashboard summary |

**Endpoints — 19, each role-guarded, audited and tested.** `AdminApiRoutingTest`
fails if an endpoint is added or removed without the plan table being updated,
so the two cannot drift quietly. The plan's §5.3 lists fifteen; the extra four
are `GET /ledger` (the Loyalty screen needs a programme-wide ledger and neither
`/overview` nor the member-scoped ledger is that) plus the three redemption
endpoints. All recorded rather than folded in.

**Roles and permissions — genuinely enforced.** Four roles, a `staff.role:{floor}`
middleware alias on every route, and the full 4×4 role-by-floor matrix asserted
over HTTP. A revoked role takes effect on the very next request. The last
Administrator cannot be demoted. `RoleGate` in the console is UX only and is
documented as such. Cross-shop isolation is asserted as **not found**, never
forbidden.

**Audit trail — genuinely append-only.** `LedgerEntry` and `AuditEntry` refuse
updates and deletes at the model. `AuditAction` is a closed list, so a typo
cannot create a category nobody looks at. A reason is enforced centrally, and
blank or trivially short reasons are refused four different ways. C12's grain is
confirmed: one entry per sweep run per shop, one per order for webhook-driven
work, `actor_type = 'system'` with `actor_name` deliberately null. There is **no
write endpoint at all**.

**Not built from M14's spec.** `AuditTrailPanel` embedded in the member profile,
`GET /api/admin/audit/{id}`, the `Auditable` trait, and audit search at
realistic volume.

---

### 2.7 Customer-facing loyalty section (M15) — **Not Built**

**Verified by inspection, not taken from the record.**

```
extensions/            loyalty-tile (POS)    voucher-discount (function)
grep -r "customerAccount|customer-account|account/loyalty"
    across extensions/, web/app, web/routes, web/frontend/src   ->  no matches
```

There is **no customer account UI extension, no app proxy block, no theme app
extension, no storefront surface and no `GET /api/account/loyalty`**. There is no
`AccountSessionToken` middleware and no `Account\LoyaltyController`.

**A member cannot see their own balance, reward value, voucher list, expiry
warning or reward history by any route.** Every balance in the system is visible
only to staff — through the console, or read aloud across a counter.

**Backend capability that exists and has no consumer.**
`PublishBalanceMetafieldJob` (built 9 Sep 2026, with
`BalanceMetafieldPublishTest`) publishes the standing member position to the
customer's Shopify record from `BalanceCalculator::refreshCache()` — the single
chokepoint — and only when the balance actually moved. It writes **one JSON
`member` key** rather than plan §6.3's five typed metafields, a deliberate
deviation. **It has never been read back by anything, because there is nothing to
read it.**

> **This must not be read as the customer-facing section being partly built.**
> Backend capability exists; customer-facing UI does not exist at all.

**Committed scope that is absent.** UI screens C1 (Join online), C2 (My
Privilege Club inside the Shopify account, including the individual voucher
list, the birthday reward tile, "Your rewards so far" history and "Show my card
in store") and C3 (Redeem at online checkout). Proposal §2's
*"Customer-facing loyalty view in the online account"* and proposal §4's
*"Customer-facing display"*.

**Open items, neither started.** **V11** — whether a customer account UI
extension deploys and renders under `use_legacy_install_flow`; never spiked.
**C1** — the storefront redemption control; blocked on OSC.

**The fallback ladder is written down** (`PROGRESS.md`), in preference order:
**A** customer account UI extension (the plan; V11's question) → **B** app proxy
page passing `logged_in_customer_id` (strongest fallback, no extension framework,
unaffected by the legacy install flow) → **C** theme app extension reading the
published metafield (`PublishBalanceMetafieldJob` is load-bearing here, which is
not why it was built; the open question is storefront **access** to an app-owned
metafield in an app-reserved namespace with no definitions created) → **D** plain
Liquid. **The spike cannot leave us with nothing.**

**This is roughly half the programme's value, it has had no sprint time across
two full days of POS work, and no sprint has been planned that builds it.** It is
not losing on merit — POS yields a tractable defect every few hours and a missing
surface yields none.

---

### 2.8 Online enrolment (M1) — **Partially Built**

| Requirement | State |
| --- | --- |
| Customer joining the Privilege Club **online** | **Not built.** No storefront surface, and `customers/create` is not registered, so a Shopify registration creates no loyalty account |
| Enrolment from the console | **Built, and executed against a real store** (accounts 12, 13 on 9 Sep 2026) |
| Enrolment at the till | **Built** — the tile's third screen. Never completed on a real device |
| Shopify customer relationship | **Not built.** Real enrolments carry `shopify_customer_id: null` |
| Duplicate prevention | **Built** (D10) — database index, endpoint check returning the existing account id, and the till's "already a member" handling |
| Required fields | **Built.** An enrolment nobody could find again — a first name and nothing else — is refused. Surname plus email **or** postcode (MD1); day-and-month birthday (MD2) |
| Membership creation | **Built** |
| Enrolment confirmation | **Partially.** The endpoint returns the created member; there is **no member-facing confirmation** because there is no member-facing surface, and no welcome email because Klaviyo has no driver |
| Customer-facing UI | **Not built.** UI screen C1 (Join online) does not exist |
| Klaviyo enrolment trigger | **Seam only.** `member.enrolled` fires from the real call site and is dropped by `NullEventBus` |

---

### 2.9 Klaviyo integration (M11) — **Partially Built (a seam, not an integration)**

**What exists.** `EventBus` interface, `NullEventBus` (logs at debug, drops),
`LoyaltyEvent`, `LoyaltyEventName`, `MemberEvents`. **All five committed events
emit from real production call sites today:**

| Event | Emitted from | Timing rule |
| --- | --- | --- |
| `member.enrolled` | Enrolment (M1) | On creation |
| `points.available` | `MaturitySweep` | **On maturity, never on earning** — the pending gate is in the engine, not the flow |
| `voucher.increment_reached` | `VoucherCrossing`, shared by every path that can raise the available balance | **Only on an actual crossing** |
| `points.expiring_soon` | `ExpirySweep` | The day a lot **enters** the warning window, so a flow gets one trigger per lot rather than thirty |
| `reward.birthday_issued` | `BirthdaySweep` | On issue |

Doing the call sites early was the right call — they are the expensive part, and
Sprint 4 binds a driver and changes nothing else.

**V20 was found and fixed on 9 Sep 2026.** `voucher.increment_reached` fired from
`MaturitySweep` alone, so a member pushed over £5 by a manual adjustment or by
points restored on a refund was never told. The sweep behind it found **three
silent upward paths and a latent fourth**. The crossing rule now lives in
`VoucherCrossing`. **A downward crossing still has no event** — giving it one
needs a sixth event name against a proposal that commits to five, and that is
recorded rather than taken.

**What is missing entirely.**

- **No live driver.** No `KlaviyoClient`, no `EventMapper`, no
  `TraitSynchroniser`, no `PushKlaviyoEventJob`, no `ReconcileKlaviyoJob`, no
  `IntegrationsController`, no `klaviyo_sync_state` writer. `grep -ri klaviyo`
  over `web/app` returns only the event seam and two comments.
- **No API key.** An OSC receivable.
- **No profile traits.** The proposal commits four — voucher balance, member
  status, Active/Lapsed segment, outstanding rewards. **None is synced.** The
  member profile screen renders Klaviyo sync state as an em dash because the
  system holds none.
- **No flows configured.** Signup/welcome, points-available, reward-increment,
  expiry reminder and birthday are all committed and none exists as a
  configured flow.
- **No integration health card**, no `TestConnectionButton`, no Settings
  Klaviyo tab.
- **V9** — Klaviyo API revision and rate limits — `OUTSTANDING`, never
  investigated.

**Refund / balance-reduction communication is an open business decision.** The
proposal commits five events, all of which announce *good* news. Nothing tells a
member their balance went **down** after a refund. V20's sweep raised this
explicitly and left it undecided. **This needs OSC's position**: does a member
whose balance was reduced by a refund get told, and if so is that a sixth event
against a proposal that says five?

**Verified flows: none. Unverified flows: all five. Missing flows: the
refund/reduction case, if OSC wants it.**

---

### 2.10 Reporting (M12) — **Not Built**

`ReportsScreen` is a placeholder in `web/frontend/src/screens/placeholders.jsx`.
There is **no report endpoint, no report query class, no `ReportRequest` filter
object, no `CsvRenderer`, no `PdfRenderer`, no `GenerateExportJob`, no
`ReportsController`, no `ExportsController`, and nothing writes `report_exports`.**
`grep` for `Csv`, `Pdf` and `Reporting` over `web/app` returns nothing.

### The agreed grouping — five reports, and only five

The proposal, the Blueprint and the UI Structure agree exactly, and the set is
**closed** ("All other reporting is out of scope at this stage"). The mapping
between the questions asked in this review and the agreed reports is:

| Agreed report | Covers the requirement(s) | Backend data | Report UI | Filters | Export | Verified |
| --- | --- | --- | --- | --- | --- | --- |
| **R1 — Total loyalty members** | **Total Members**, **Active Members**, **Lapsed Members** — one report with the Active/Lapsed split, not three reports | **Available.** `loyalty_accounts.segment` + `SegmentSweep` (D7's two-year rule, Active / Lapsed / **Unknown**) | None | None | None | No |
| **R2 — New loyalty sign-ups** | **Signup Report** — enrolments over a date range, split online vs in-store | **Available.** `enrolment_channel`, `enrolled_at`; `/overview` already computes a 30-day figure with the online/in-store split | None | None | None | No |
| **R3 — Loyalty-driven orders & revenue** | **Revenue** — orders where a member earned or redeemed, **and loyalty activity as a % of total sales by both revenue and order count** | **Partially available.** The ledger holds the loyalty side. The **store-wide denominator does not exist** — see V10. And `qualifying_value_pence` currently overstates spend after any correction — see V14 | None | None | None | No |
| **R4 — Online vs in-store activity** | **Online vs Store Activity** — earning and redemption compared by channel | **Available.** Channel is on every ledger entry; `shopify_location_id` on POS movements | None | None | None | No |
| **R5 — Birthday reward activity** | **Birthday Voucher Report** — issued, redeemed and expired | **Not available.** `loyalty_rewards` holds 0 rows, and **`redeemed` and `expired` are unreachable states** (V21). Two of this report's three columns cannot be populated at all until the reward state machine exists | None | None | None | No |

**Filters committed to every report and not built:** date range, channel,
store/location, staff attribution, **gender** (from the Shopify customer
metafield — still an OSC receivable, and nothing reads it), and
**Month-on-Month / Year-on-Year comparison**.

**Exports committed and not built:** PDF **and** CSV on every report, plus the
four disabled Export buttons already sitting on Customers, Audit, Loyalty and
the Dashboard.

**Do not count the underlying queries as progress.** `ProgrammeSummary` and
`/overview` answer fixed dashboard windows. That is not a report with a
selectable range, five filters, a comparison toggle and two export formats.

**Open items blocking or shaping this.** **V10** — a store-wide sales figure that
reconciles with Shopify analytics (order aggregate vs ShopifyQL); never
investigated, and a loyalty share that disagrees with the admin dashboard is the
first thing that will be questioned. **V14** — must be settled *by* the reporting
design, not before it. **V21** — R5 depends on it. **C5** — reporting currency and
timezone. **C8** — PDF fidelity vs server dependency (a pure-PHP renderer, because
a headless-browser renderer would add Chromium to the production box). **Q4** —
which timezone and currency a report speaks.

---

### 2.11 Dynamics / ORD legacy migration (M10) — **Not Built**

**Migration rules and documentation are complete and client-confirmed. Working
migration implementation is at zero.** These two must not be conflated, and the
documentation being thorough is what makes it easy to.

#### What exists — rules and schema only

| Ref | Rule, all `CONFIRMED` 6 Aug 2026 |
| --- | --- |
| **D4** | Migrated balance expiry runs **from the go-live date**; one `opening_balance` ledger entry per member, configurable grace period defaulting to six months (`migrated_balance_grace_days`) |
| **MD1** | Members with no email migrate and stay searchable; matching falls back to card, then postcode plus surname |
| **MD2** | A day and month with no birth year is a complete birthday |
| **MD3 / D10** | Two legacy rows with an **exact** email match are one person → **combine balances**. The exception report is for **ambiguous** matches only |
| **MD4** | Unmatched migrated members start **Not subscribed**; matched members **keep their existing Shopify consent** — migration never overwrites it |
| **MD5** | Email-only contacts with no card and no membership become Shopify marketing contacts with **no loyalty account** (`decision = marketing_only`) |
| **MD6** | Decimal balances round **down** (`123.45 → 123`) |
| **MD7** | Balances carry over as **points**; no vouchers auto-issued |
| **MD8** | Duplicate birthday vouchers in year one are **accepted**; no migration-year suppression |
| **MD9** | **No paper-voucher liability import** |

Schema built: `migration_batches`, `migration_records`, `migration_exceptions`,
with `decision` extended to
`create, attach, combine, marketing_only, review, exception, skipped`.

#### What is missing — everything that runs

| Component | State |
| --- | --- |
| `Profiler`, `RowMapper`, `Matcher`, `DryRunner`, `Importer`, `ExceptionWriter` | **None exists.** `App\Domain\Migration` is not a namespace in this codebase |
| `loyalty:migrate` command | **Not built** |
| `MigrationController` and its seven endpoints | **Not built** |
| `ProcessMigrationBatchJob` (chunked, resumable) | **Not built** |
| Field mapping to the agreed source format | **Not built.** Nothing in the codebase references the Dynamics/ORD export at all |
| Legacy card number / Member ID / customer details / DOB / total spend / join date / points balance / reward scheme ingestion | **Not built.** The *columns* exist on `loyalty_accounts`; nothing populates them from a file |
| Last spend date | **`last_qualifying_spend_at` is read by `SegmentSweep` and `LastSpendResolver` reads `migration_records` — and nothing writes either from a migration.** The segmentation path is already wired to a source that does not yet exist |
| Members without email | Rule confirmed (MD1) and the schema supports it; no importer |
| Duplicate handling | Rule confirmed (MD3); no importer |
| Points rounding | Rule confirmed (MD6); the required `RowMapper` fixture (`123.45 → 123`) **does not exist because `RowMapper` does not exist** |
| Points expiry treatment | Rule confirmed (D4); the `opening_balance` entry type and its own `expires_at` exist and nothing posts one |
| Validation, dry run, exception report, reconciliation, pre/post totals | **None built** |
| A9 Migration report and exception review screen | **Not built and not routed** |
| Sample-data testing | **Not possible.** No anonymised sample fixtures exist, because no export has arrived |
| Final / live migration readiness | **Zero** |

**The hard dependency.** The **Dynamics/ORD export file with its field schema and
known data-quality issues** is an outstanding OSC receivable. The plan says
"profiling starts the day the file arrives, regardless of sprint" — the file has
not arrived.

**One consequence worth stating.** Legacy data carries total spend but no
order-level history, so month-on-month and year-on-year reporting start
accumulating at go-live and **cannot be backfilled**. That is agreed in the
proposal, not a gap.

---

### 2.12 Shopify webhooks / order integration (M8, M3) — **Built but Unverified**

**Registration — verified.** Six topics registered per shop through the Admin
API, never in the toml (`use_legacy_install_flow` makes a
`[[webhooks.subscriptions]]` block undeployable). `php artisan shopify:webhooks`
lists all six with no `[stale]` marker. Privacy-compliance topics are the
exception and live in the toml because they cannot be created through the Admin
API. Handlers exist for all three privacy topics.

| Topic | Handler | Queues | Real delivery seen? |
| --- | --- | --- | --- |
| `orders/paid` | `OrdersPaid` | Earning, and redemption confirmation | **Yes** — orders `#1001` and `#1002`, 2 Sep 2026 |
| `orders/updated` | `OrdersPaid` | Recalculation after an edit | **No** |
| `orders/cancelled` | `OrdersCancelled` | Full reversal | **No** |
| `refunds/create` | `RefundsCreate` | Proportional reversal | **No** |
| `products/update` | `ProductsUpdate` | Exclusion resync | **No** |
| `app/uninstalled` | `AppUninstalled` | Cleanup | **No** |

**Design properties that are right and are tested.**

- **Every handler records the delivery, queues the work, and returns.**
  `test_the_endpoint_answers_without_doing_the_work` asserts the response is
  returned with the order **never read** and no ledger row written. A slow
  webhook endpoint gets throttled and eventually unsubscribed, and a loyalty
  programme that has quietly stopped earning is not noticed quickly.
- **The payload is a trigger, not the arithmetic input.** Every figure is
  re-read from the order in the job behind an `OrderSource` interface, because a
  payload can be stale, can arrive out of order, and carries neither the
  discount allocation nor the quantities left after an edit.
- **Two idempotency guards that fail differently.** The Shopify webhook id
  catches a duplicate *delivery* before the Admin API is touched at all; the
  ledger idempotency key catches the same *effect* arriving by another route —
  which is what `orders/paid` followed by `orders/updated` is, and what a replay
  is.
- **Retry and repair.** `loyalty:replay-orders` aimed by `--order`,
  `--from`/`--to` or `--failed`, with `--dry-run`. Its whole design rests on one
  asserted property: **replaying an order already processed correctly changes
  nothing**, so when nobody is sure what was missed the right move is to replay a
  wider range than necessary.
- **M3's open validation is closed.** `orders/updated` is registered rather than
  `orders/edited` — earning is cumulative and re-reads the order, so the broader
  topic is sufficient and strictly safer.

**What is unverified.** Every reversal path. Also unverified: out-of-order
delivery and redelivery against a real shop (both are tested in-suite only), and
the `products/update` resync.

**Two silent failure modes now documented, both of which have actually bitten.**

1. **Webhook delivery was dead between 3 and 9 September 2026.** All six
   subscriptions pointed at a tunnel host that returns **NXDOMAIN** — not a 530,
   not a timeout; it does not resolve. Shopify had nowhere to deliver, and
   re-registration only happens on a fresh OAuth callback, so nothing corrected
   it. Nothing webhook-dependent was attempted in the window, so nothing is
   invalidated — but only because that was checked rather than assumed.
2. **A dead queue worker presents identically.** "The points never arrived" has
   at least two silent causes and both must be ruled out before it is treated as
   a defect.

> **The standing rule:** any webhook-dependent result obtained after a tunnel
> change and before a `shopify:webhooks --register` proves nothing.

**Note for the current working tree.** `shopify.app.toml` and
`extensions/loyalty-tile/src/lib/appUrl.js` are modified but uncommitted, moving
`application_url`, the three `redirect_urls` and the tile's compiled-in `APP_URL`
from `arrive-weddings-setting-graham` to `which-using-shaw-essays`. **A tunnel
change requires a `deploy`, not just a restart** (the OAuth `redirect_uri` is
validated against the *released* allowlist under the legacy install flow), and
webhooks must be re-registered. Assume the six subscriptions are stale again
until `shopify:webhooks` says otherwise.

---

### 2.13 International / market behaviour — **Open decision, and one uncovered defect**

**What is documented and settled.**

- **OSC trades UK-only** — client-confirmed. UK address, GBP, UK market
  throughout.
- **UK physical stores.** MD10 confirms the production device matrix is **one
  location**: iPad 10th generation, POS app 11.11.1, a single POS Pro store.
- **Shopify Markets, on the development store, read rather than inferred**
  (3 Sep 2026, after `read_markets` was granted): three ACTIVE REGION markets —
  United Kingdom (GBP, `INCLUDES_TAXES_IN_PRICE_BASED_ON_COUNTRY`), Canada (CAD),
  United States (USD). **There is no primary market and that is not a fault** —
  on 2026-07 `MarketType` has no `PRIMARY` value and `Market` carries no
  `primary` field. Do not change anything in Markets hoping to unblock V12;
  there is nothing there to fix.
- **A discount amount is denominated in the shop currency**, regardless of which
  market the customer is in, so the shop-level read is the correct check.

**What is built.**

- **V13's guard is implemented** — `AdminApiDiscountCodeWriter` refuses to mint
  when the shop currency and the rules currency disagree, with
  `CurrencyGuardTest` covering it. **The `DECISIONS.md` validation table still
  lists V13 as `OUTSTANDING — fix before go-live`; that row is stale, the fix is
  in the code.** V13 was a **live defect**, not a hypothesis: the dev store's
  base currency changed to EUR mid-session and the app minted a **€50** voucher
  for a GBP programme, `status: ACTIVE`, every other constraint correct. It would
  have spent exactly 1000 points at whatever the exchange rate happened to be.

**What is NOT resolved — do not invent a currency rule here.**

| Question | Status |
| --- | --- |
| **International online customers** | **No business rule exists.** Nothing in the proposal, the Blueprint, the plan or the register says what happens when a non-UK customer buys online |
| **Points earning in another currency** | **Unresolved.** `RedemptionService::pointsFor()` is **currency-blind**: it divides an amount in pence by `voucherValuePence` with no notion of which currency those pence are. `EarningCalculator` likewise earns on a number, not on a currency |
| **Reward value / redemption in another currency** | **Unresolved, and V24 is the uncovered defect.** `applyCartDiscount(type, title, amount?)` takes a **bare string with no currency parameter**, so the till always denominates. £50 of points would discount **$50** at a genuinely foreign till and still print "£50.00" on the receipt |
| **Non-GBP purchases / conversion** | **No rule, no conversion, no guard beyond V13's shop-level check** |
| **C5 — live store currency and reporting timezone** | **`PENDING`.** A fact about OSC's store rather than a test |

**V24's exact standing, and it must not drift.** It is **raised, argued from the
API type signature, and unobserved.** A hold was placed at the Canadian location
specifically so the resulting order's `presentmentMoney.currencyCode` could be
read; **the tender produced no order**, so there was no `presentmentMoney` to
read. The mechanism is established; the financial consequence has not been seen
happening.

**Priority.** Because OSC has confirmed UK-only trading, V24 is a **correctness
gap, not a launch gate** — at OSC the till, the shop and the rules will all read
GBP. **It must not be recorded as handled**, because the next person to read the
withdrawn V18 would otherwise believe a foreign till is caught. The direction
indicated by the evidence is a **server-side check at `confirm()` from the
order's own `currencyCode`/`presentmentMoney`** — no device trust needed, and
because `hold()` writes `points_consumed => 0`, a refusal there leaves the
member's points untouched and narrows exposure to one sale's discount.

---

### 2.14 Other committed scope not covered above

Compared `proposal.docx` and the UI Structure against the implementation. Items
below are committed and are **not** tracked in `PROGRESS.md`'s own status tables.

| # | Committed where | Item | State |
| --- | --- | --- | --- |
| 1 | UI A6, UI C2, proposal §4, §8 | **Individual voucher objects with codes, expiry dates and per-voucher Cancel / Reissue** | **Not built, and in conflict with a confirmed decision** — see §3.2. The agreed UI shows rows like `PC-5F2A-9911 · £10 · Points voucher · issued 02 Jul · expires 02 Jan · Active · Cancel`. D1 removed the points-derived voucher as an object |
| 2 | proposal §5 | **Four Klaviyo profile traits** — voucher balance, member status, Active/Lapsed segment, outstanding rewards | **Not built.** No trait sync exists |
| 3 | proposal §7, UI A8 | **Gender filter on every report**, from the Shopify customer metafield | **Not built.** Column exists; the metafield is an outstanding OSC receivable and nothing reads it |
| 4 | proposal §7, UI A8 | **Month-on-Month / Year-on-Year comparison** | **Not built** |
| 5 | UI A3 | **Mobile number** on the customer profile | **Not held anywhere.** Renders as an em dash. Also absent from the proposal |
| 6 | UI A3 | **Klaviyo sync state** on the customer profile | **Not held.** Em dash |
| 7 | UI A7 | **Transactions view** — order-level, with eligible spend and voucher used per order | **Not built** (placeholder) |
| 8 | UI C2 | **"Show my card in store"** on the member portal | **Not built** (no portal) |
| 9 | UI A1, A2, A5, A12, A8 | **Every export** (5 surfaces) | **Not built** |
| 10 | UI A2, A5, A7, A8 | **Store / location names** on ledger and transaction rows | **Not built.** Only `shopify_location_id` is held; names need a `read_locations` lookup and a cache |
| 11 | plan M13 | `GET /api/admin/settings/health` | **Not built.** `GET /api/health` exists (unauthenticated foundation health) |
| 12 | plan M14 | `AuditTrailPanel` on the member profile; `GET /audit/{id}`; `Auditable` trait | **Not built** |
| 13 | plan M1 | Merge tool — service, endpoints and both screens | **Not built** |
| 14 | plan §6.3 | Five **typed** customer metafields | **Deliberately deviated** to one JSON `member` key, pending V11 saying what shape the account page needs |
| 15 | plan M12 | `report_exports` table writer | **Not built** |
| 16 | D7 | **`read_all_orders` historical backfill** (one-shot per shop, moves no balance) | **Not built.** Scope is **approved by Shopify** and is **not yet in `shopify.app.toml`** — verified: the scope string holds nine scopes and `read_all_orders` is not among them |
| 17 | V7 | **Legacy Dynamics barcode encoding** | **Unresolved residual.** Needs one physical OSC card scanned on a device. Keyboard entry works regardless |
| 18 | Deprecation | `Order.physicalLocation` is deprecated on 2026-07 and is still selected by `AdminApiOrderSource::fetch()` | Raised on every call. Not blocking |
| 19 | V15 | `@shopify/ui-extensions` is installed at **2025.10.16** while the tile declares `api_version = "2026-07"` | **Verified still skewed.** Nothing pins the two together or fails the build on drift |
| 20 | Flagged, unidentified | Shopify's admin displayed **"This feature isn't currently available for your store"** on 9 Sep 2026, on a screen not yet identified | **Unidentified.** Worth chasing before starting anything that might depend on it |

---

## 3. UI STRUCTURE GAP ANALYSIS

`docs/ui/OSC_Privilege_Club_UI_Structure_v1_1_FINAL.html` against the codebase.
A screen appearing here does **not** mean it is implemented.

### 3.1 Screen-by-screen

#### A. Shopify Admin — embedded loyalty app

| Ref | Screen | Status | Evidence |
| --- | --- | --- | --- |
| **A1** | Dashboard | **Built but Unverified** | `DashboardScreen.jsx`, browser-verified 27 Aug in the real embedded admin. Redemption rate, the unredeemed-voucher count, date range and Export summary are all em-dashed or inert, each with a stated reason |
| **A2** | Customers — search & list | **Built but Unverified** | `CustomersScreen.jsx`. Every column in the agreed order and wording. **Export CSV disabled; Enrol member disabled** |
| **A3** | Customer profile | **Built but Unverified** | `MemberProfileScreen.jsx`. Both tabs present. **Mobile and Klaviyo sync em-dashed; Issue voucher hardcoded disabled at line 114** |
| **A4** | Manual points adjustment | **Built but Unverified** | `AdjustPointsModal.jsx`. Real projection from the server. The agreed UI predicts the reference (`ADJ-2262`); this says one is generated on save, because it derives from a ledger entry that does not exist until then |
| **A5** | Loyalty — programme ledger & expiry queue | **Built but Unverified** | `LoyaltyScreen.jsx`. All five tiles. **Export ledger disabled** |
| **A6** | Voucher management | **Not Built** | `placeholders.jsx`. Behind it: the reward state machine (V21), not a screen |
| **A7** | Transactions | **Not Built** | `placeholders.jsx` |
| **A8** | Reports — the five confirmed reports | **Not Built** | `placeholders.jsx`. No backend either |
| **A9** | Migration report & exception review | **Not Built** | Absent from `App.jsx` **and** from `navigation.js`. No route exists |
| **A10** | Settings — rules engine | **Partially Built** | `SettingsScreen.jsx`, Rules tab only. **The Klaviyo tab shown in the agreed UI does not exist.** Where the stored model and the agreed UI disagree, the screen states what is stored — points expiry in **days after availability** (D2) not months after earning; voucher value and increment as **one field**; *Voucher expiry* meaning the birthday reward's expiry (D1) |
| **A12** | Audit log | **Built but Unverified** | `AuditScreen.jsx`. Every agreed column. **Export CSV disabled** |
| — | Staff roles | **Not Built** | Endpoints built and tested; no screen, and no nav entry |

*There is no A11 in the agreed UI.*

#### B. Shopify POS Pro — Privilege Club tile

| Ref | Screen / flow | Status | Evidence |
| --- | --- | --- | --- |
| **B1** | Till screen & tile on the smart grid | **Built but Unverified** | `Tile.jsx`. Rendered on a real device |
| **B2** | Member lookup — name, postcode or card number | **Built but Unverified** | `Modal.jsx` lookup screen + `lib/lookup.js`. Search reached the backend on a real device 3 Sep. Scanner detected rather than assumed (V7) |
| **B3** | Search results | **Built but Unverified** | Results rendered on a real device; **tapping a result was dead until V19 was fixed**, and the earlier "all five searches return the member" report was corrected because nobody had tapped one |
| **B4** | Member view at the till | **Built but Unverified** | Real evidence: "£150 of voucher value" rendered on a real till 3 Sep; £5 steppers exercised (£50 → £25) |
| **B5** | Member attached to the sale | **Built but Unverified** | Two real holds carry location and staff attribution |
| **B6** | Voucher validated and applied | **Built but Unverified** | `applyCartDiscount` reached `hold()` twice for real. **Never seen on a receipt, and no sale has ever completed** |
| **B7** | Voucher rejected — reason shown on screen | **Built but Unverified** | `lib/reasons.js` covers every refusal in the agreed UI, incl. `below_voucher_increment` so the tile can explain it. The **offline** refusal has real evidence (9 Sep). The **basket/cap/eligibility** refusals have not been seen on a device |
| **B8** | Enrol a new member at the till | **Built but Unverified** | `lib/enrolment.js` + the enrol screen. Never completed on a real device |
| **B9** | Duplicate check before creating a record | **Built but Unverified** | D10 handling — "already a member", not a failure |
| **B10** | POS customer journey (steps 01–06) | **Partially Built** | Steps 01–04 have real evidence. **Step 05 "Payment completes → points earned, redemption closed, voucher balance updates on both channels" has never happened.** Step 06's Klaviyo message has no driver |

#### C. Member portal — online and in store

| Ref | Screen | Status | Evidence |
| --- | --- | --- | --- |
| **UI C1** | Join online — complete your loyalty profile | **Not Built** | No storefront surface; `customers/create` not registered |
| **UI C2** | My Privilege Club — inside the Shopify account | **Not Built** | No customer account extension, no app proxy, no theme extension, no endpoint |
| **UI C3** | Redeem at online checkout | **Not Built** | The server mechanism is verified; the checkout-side control does not exist. Blocked on decision **C1** |

*Note: UI refs C1–C3 are the member-portal screens and are distinct from decision
register items C1–C14.*

### 3.2 Screens designed but not implemented

A6, A7, A8, A9, the A10 Klaviyo tab, the staff roles screen, the enrol-member
modal, UI C1, UI C2, UI C3. **Nine screens plus one tab plus one modal.**

### 3.3 Screens partially implemented

- **A10 Settings** — Rules tab built, Klaviyo tab absent.
- **A1, A2, A5, A12** — built, with their **Export** actions present and
  disabled.
- **A2** — built, with **Enrol member** present and disabled.
- **A3** — built, with **Issue voucher** present and disabled, and two fields
  em-dashed because the system holds no data for them.
- **B10** — the first four journey steps have evidence; the last two do not.

### 3.4 Backend functionality with no UI

| Backend | Missing UI |
| --- | --- |
| `GET /staff`, `PUT /staff/{staffId}` | Staff roles screen |
| `POST /api/admin/members` (enrolment) | Enrol member modal in the console |
| `PublishBalanceMetafieldJob` — publishes the member's standing position to Shopify | **Nothing reads it.** No customer account page, no theme block, no storefront |
| `GET /rules/versions/{version}` (historical rule set with its diff) | No version-history browser; Settings shows the current version and its diff on save |
| `POST /members/{id}/rebuild-cache` | No console control; command-line only |
| Birthday reward issuance | No reward management screen (A6) |
| `loyalty:preflight`, `loyalty:replay-orders`, `loyalty:sync-exclusions`, `loyalty:rebuild-balances`, `loyalty:verify-ledger` | Command-line only, by design |
| `ExpiryOutlook` per-shop expiry queue | Partly surfaced on A5; the agreed UI's expiry queue detail is not a screen |

### 3.5 UI with incomplete backend behaviour

| UI | Backend gap |
| --- | --- |
| **A1 "7,683 vouchers unredeemed"** | No such object exists (D1). The card says where its figure comes from rather than inventing a count |
| **A1 "Redemption rate 42%"** | No denominator until redemption data accumulates. Em-dashed, asserted by pattern so no percentage can appear |
| **A3 Mobile**, **A3 Klaviyo sync** | No data held at all |
| **A5 "Vouchers expire 6 months after issue"** | Per D1 the points-derived balance has **no expiry of its own**. Only the birthday reward has one — and **nothing expires it** (V21) |
| **A6 / UI C2 individual voucher rows with codes and expiry** | **The most significant conflict in this analysis.** See below |
| **A8 R3 "% of total sales"** | The store-wide denominator does not exist (V10) |
| **A8 R5 "issued, redeemed and expired"** | `redeemed` and `expired` are unreachable reward states (V21) |
| **A8 gender filter** | Metafield not received, nothing reads it |
| **A6 worked example — cap calculated against the £60 eligible portion** | The production **discount-code** mechanism cannot enforce the eligible-subtotal rule; a code applies to the whole order |

> ### The A6 / UI C2 conflict, stated plainly
>
> The agreed UI shows **individual points vouchers as objects** — a code
> (`PC-5F2A-9911`), a value, an issue date, an **expiry date**, a status, and
> per-voucher **Cancel** / **Reissue** — on the admin Voucher management screen
> **and** on the member portal ("£10 Reward voucher · Use by 2 Jan 2027").
>
> **D1 (`CONFIRMED` 26 Aug 2026) removed that object.** The points-derived
> voucher balance has no expiry of its own and falls automatically as the
> underlying points expire; only the **birthday reward** is a real issued record.
>
> The two are not reconcilable as drawn. `PROGRESS.md` flags the consequence for
> the Dashboard card, but **does not flag that A6 and UI C2 both depend on an
> object class that D1 deleted.** Whatever is built for A6 and UI C2 will
> therefore look materially different from the agreed UI — which the client has
> reviewed and signed off. **This needs raising with OSC before either screen is
> designed.**

### 3.6 Navigation entries without completed functionality

`navigation.js` has eight entries. **Three lead to placeholders** — Voucher
management, Transactions, Reports. Every route resolves, so there are no dead
links, and each placeholder names its owning sprint and what it will do.
**A9 Migration has no navigation entry at all**, which is honest but means the
agreed console is missing a whole area rather than showing it as coming.

---

## 4. OPEN ITEMS / DECISIONS

Every open item found, with its original number preserved. Nothing older has
been dropped without explicit evidence of resolution.

### A. QUESTIONS / DECISIONS NEEDED FROM ROBERT / OSC

| Ref | Description | Status | Blocked on | Dev can continue? | Go-live gate? | Recommended next action |
| --- | --- | --- | --- | --- | --- | --- |
| **C1** (Q2) | Online: does the voucher apply automatically, or does the customer choose an amount — and where? | `PENDING`. Mechanism built and swappable both ways; **the chosen default (auto-apply `/discount/PC-…` link) is NOT client-confirmed** | OSC | Yes for everything else; **no** for the storefront/checkout control | No, but it is unfinished committed scope (UI C3) | Put both options to Robert with the auto-apply default named as our recommendation |
| **C14** | Does a Privilege Club voucher reduce the points earned on that order? Our call is **yes** — £550 of a £600 basket earns 550 | Raised 2 Sep 2026 from a live dev-store order. **Code is already corrected to "yes"** | Robert | Yes | **Yes** — a wrong earn base is invisible and compounds | Confirm before launch. It is one of only two items `DECISIONS.md` §1 lists as needing Robert |
| **C6 (live)** (Q5) | The **named person** holding the first Administrator role on the production store | Mechanism `CONFIRMED`; **live name `PENDING`** | Robert / OSC | Yes | **Yes** | A name, not a decision. Also ask whether OSC would rather it be set explicitly than by first arrival |
| **C3** (Q1) | Is a full-price-only rule wanted at all, and does it apply to earning, redemption or both? | `PENDING`. **Deliberately not built.** `full_price_only` is refused at the calculator *and* the rule schema, so a rule version saved before the guard cannot pay out | OSC | Yes | No | Confirm it is genuinely dropped. UI v1.1 removed full-price-only throughout, which suggests it is — get that in writing and close Q1, C3 and V6 together |
| **C5** (Q4) | Live store currency and reporting timezone | `PENDING` | OSC | Yes | **Yes** for reporting correctness | A fact about OSC's store. Collect with the other go-live facts |
| **C8** | PDF export: fidelity vs server dependency | `PENDING` | OSC | Yes | No — but it shapes M12 | Recommend pure-PHP rendering; a headless-browser renderer adds Chromium to the production box |
| **C9** | Adjustments and voucher overrides at the till. Migration discovery implies "all roles OK", which cuts across the four-role model | `PENDING`. **No POS adjustment endpoint is built until this is confirmed** | OSC | Yes | No | Three questions to put: may a till assistant adjust points, or only redeem? Does the console role model apply at the till? Is an override capped, and does it need a reason? **Ask alongside V16** |
| **C10** | Do manually adjusted points expire like earned ones? | `PENDING (assumed)`. Built to "yes, carrying an expiry from the rule version" | OSC | Yes | No | Cheap to change before launch (one argument at the call site), disruptive after |
| **C11** | Does the Privilege Club card carry its own number, or is it derived from the account? | `PENDING (assumed)`. Derived `NNNN-NNNN` from the account id | OSC | Yes | No | Two questions: are new physical cards being issued, and if so who allocates the numbers? Is the number expected to match anything in the legacy export? |
| **D3** | The earn base — after all discounts, ex tax, ex shipping | `ASSUMED`, client notified 27 Aug 2026 | — | Yes | No | Open to an **objection**, not waiting on approval. Note C14 is the part of D3 still genuinely open |
| **D8** | The redemption ladder order | `ASSUMED`, client notified 27 Aug 2026 | — | Yes | No | Objection only |
| **D9, D9a–D9d** | Proportional refund restore and its four mechanics | `ASSUMED`, client notified 27 Aug 2026 | — | Yes | No | Objection only |
| **C2** (Q3) | May a refund clawback push a balance below zero? | `ASSUMED` via D9. The ledger permits it; the voucher balance floors at zero | OSC | Yes | No | If it comes back the other way it changes what is *shown*, not what is recorded |
| **New — Klaviyo balance-reduction communication** | Does a member get told when a refund reduces their balance? The proposal commits five events and all announce good news | **Open, raised by V20's sweep 9 Sep 2026** | OSC | Yes | No | A sixth event name against a proposal that says five. Needs an explicit position |
| **New — A6 / UI C2 voucher objects** | The agreed UI shows individual points-voucher records with codes and expiry; D1 removed that object | **Open, raised by this review** | OSC | Yes | No — but both screens are committed scope | Raise before either screen is designed. See §3.5 |
| **V7 residual** | One **physical legacy Dynamics card** to scan, to confirm what the barcode encodes | `OUTSTANDING` | OSC | Yes — keyboard entry works regardless | No | If the encoding differs, the mapping goes in `extensions/loyalty-tile/src/lib/lookup.js` and nowhere else |

**Confirmed and closed — recorded so none is reopened as an open question.**
**D1** (voucher expiry means points expiry; the derived balance has none),
**D2** (the expiry clock runs from the availability date), **D4** (migrated
balance expiry runs from go-live with a grace period), **D5** (`REVISED` — the
single-use code path, not the function), **D6** (`applyCartDiscount`, and the
parameter is `title`, not `reason`), **D7** (`read_all_orders` approved by
Shopify 1 Sep 2026), **D10** (never create a duplicate silently), **C4**
(lapsed members **do** receive the birthday reward, on date of birth alone),
**C7** (offline tills: record the sale, add the points afterwards, no offline
redemption), **C12** (automated work is audited, one entry per run and one per
order), **C13** (an expired quote is voided, its entitlement withdrawn, and
nothing is re-quoted), and **MD1–MD10** in full.

**Receivables from OSC, still outstanding**

| Receivable | Blocks |
| --- | --- |
| **Dynamics / ORD export (CSV) with field list, schema and known data-quality issues** | **All of M10.** Profiling starts the day it arrives |
| **Klaviyo private API key** | All live Klaviyo wiring (M11), and V9 |
| **Gender metafield on both development and live stores** | The gender filter on all five reports |
| **Domain details for the production application URL** | The released app URL, the OAuth grant, and the tile's compiled-in `APP_URL` |
| Live store currency and timezone (C5) | Reporting |

*POS Pro is closed — confirmed Active on both development-store locations,
3 Sep 2026.*

### B. TECHNICAL VALIDATIONS WE NEED TO PERFORM

| Ref | Description | Status | Blocked on | Dev can continue? | Go-live gate? | Next action |
| --- | --- | --- | --- | --- | --- | --- |
| **V2** | Ledger stays indexed at a projected two-year row count | `OUTSTANDING`. Never run | Nothing — ours to do | Yes | No, but a slow nightly sweep at real volume is a production incident | Seed to a two-year row count; check the profile query, the sweeps and the report queries stay indexed. Sprint 5 at the latest |
| **V6** | Compare-at price in the discount function input | `OUTSTANDING` — **only if Q1 opens** | C3 / Q1 | Yes | No | Close with C3 |
| **V9** | Klaviyo API revision and rate limits | `OUTSTANDING` | Klaviyo key | Yes | No | With the Sprint 4 wiring |
| **V10** | A store-wide sales figure that reconciles with Shopify analytics | `OUTSTANDING` | Nothing to start; real volume to confirm | Yes | **Effectively yes for R3** | Settle order-aggregate vs ShopifyQL **before** building R3. A loyalty share that disagrees with the admin dashboard is the first thing that will be questioned |
| **V11** | Customer account UI extension target and deployability under `use_legacy_install_flow` | `OUTSTANDING`. **Never spiked** | Nothing — ours to do | It is the gate on route A only; **routes B, C and D do not depend on it** | No | **Spike it, with the fallback ladder already written: A → B → C → D.** The precedent is mildly encouraging — the POS extension and the discount function both deploy under this flag, and what the flag actually rejects is webhook subscriptions in the toml |
| **V12** | Earn base on a tax-**inclusive** shop: the order of the discount-allocation and tax subtractions | `OUTSTANDING`. **Unprovable on the dev store — that route is closed and must not be reopened** | A real discounted VAT-inclusive order on OSC's store | Yes | **YES — hard gate** | Reconcile one real discounted order by hand: take `discountedTotalSet`, `discountAllocations` and `taxLines`, compute `gross − allocations − tax`, and check the earn against the VAT-exclusive value actually spent |
| **V13** | Shop currency vs rules currency never compared | **Fix is IMPLEMENTED** (`AdminApiDiscountCodeWriter` + `CurrencyGuardTest`). The register table still says `OUTSTANDING` | — | Yes | No | **Update the register row.** Consider whether `pointsFor()` should assert a currency too, rather than trusting every caller |
| **V14** | A compensating `earn_reversal` carries no `qualifying_value_pence`, so reported spend overstates real spend | `OUTSTANDING`. **Held deliberately, not overlooked** | The Sprint 5 reporting design | Yes | No — reporting only; points, balances, maturity and segmentation are all correct | **Do not fix ahead of the reporting design.** Decide: negative `qualifying_value_pence` on the compensating entry, or derive spend from `parent_entry_id IS NULL` entries. The first changes what the column *means*, and a silent change of meaning in a column `ProgrammeSummary` already aggregates is exactly how the C14 defect happened |
| **V15** | `@shopify/ui-extensions` 2025.10.16 vs declared `api_version = "2026-07"` | `OUTSTANDING`. **Verified still skewed** | Nothing | Yes | No | Pin the package to the declared api_version and have `ShopifyConfigurationTest` assert it, as it already does for the Admin API version. Until then, **no conclusion drawn from those types is settled** |
| **V16** | Every till user needs a Privilege Club role; only the first staff member on a shop is bootstrapped | `OUTSTANDING`. **Unfixed — verified in `EnsureStaffRole`** | A decision, parked next to C9 | Yes | **YES** | Either OSC assigns a role to every till user as a documented onboarding step, or `EnsureStaffRole` grants an implicit `viewer` floor to a verified POS token. V22's fix now logs the staff id on refusal so an Administrator can learn which id to assign |
| **V17** | The tile discarded the reason a request failed | **FIXED** (`messageFor()` in `lib/reasons.js`). Register table still says `OUTSTANDING` | — | Yes | No | **Update the register row** |
| **V18** | "The till is a second denominator" | **`WITHDRAWN` 9 Sep 2026.** `session.currency` reports the SHOP's currency, so the guard duplicated V13. **The defect is uncovered, not handled** | — | Yes | No | The guard code and its 8 tests remain in place. Do not let anyone read V18 as covering a foreign till |
| **V19** | The tile listened for `onPress`/`onSubmit`, which POS never emits — nine controls inert | **FIXED** (10 `onClick`, 0 `onPress`; `handlerContract.test.js` derives an allowlist from the platform types and was verified by reintroducing the defect). Register table still says `OUTSTANDING — BLOCKS SPRINT 3` | — | Yes | No | **Update the register row** |
| **V20** | `voucher.increment_reached` fired from one code path only | **`RESOLVED` 9 Sep 2026** via `VoucherCrossing` | — | Yes | No | **A downward crossing still has no event.** That is a live open question, listed under §4.A |
| **V21** | The reward lifecycle is a missing state machine | **`PARKED` pending a briefing.** Overlaps V14 | A briefing | Yes | No — but it gates A6, R5 and any reward redemption | **Brief it with V14.** When briefed, extend rather than duplicate: `derive()` untouched; `RewardIssuer` extracted *from* `BirthdaySweep`; `RewardList` grows in the existing `VouchersTab`; `Redemption.reward_id` is the existing seam. The one genuinely new piece is the state machine, and the schema already dictates its shape |
| **V22** | `no_role_assigned` named no staff id | **`RESOLVED` 9 Sep 2026** — logged server-side | — | Yes | No | — |
| **V23** | A POS hold that PASSES records nothing about what it compared | **Answered by V18's withdrawal**, having been instrumented 9 Sep. `RedemptionService::hold()` now logs `till_currency`, `rules_currency`, `shop_currency`, location and amount for the POS channel | — | Yes | No | Close it in the register as answered by the Canada hold |
| **V24** | A foreign till can denominate a sterling voucher in its own currency and nothing catches it | `OUTSTANDING`. **Raised, argued from the API type signature, unobserved** | Nothing to fix; the direction is decided | Yes | **Only if OSC ever trades outside GBP.** Client-confirmed UK-only, so a correctness gap rather than a launch gate | Guard **server-side at `confirm()`** from the order's own `currencyCode`/`presentmentMoney`. Rejected alternatives are recorded: `Cart.cartDiscount.currency` is still device-reported; a percentage discount stops matching the points consumed exactly |
| **New** | **Refund and cancellation have never met a real Shopify event** | Not previously tracked as a validation | Nothing | Yes | **Effectively yes** — the refund path is committed scope carrying no real evidence | **Process one real refund and one real cancellation on the dev store.** This is mechanism, not fidelity, so it belongs here and is cheap |
| **New** | **No sweep has ever run on a real shop with real consequence** | Not previously tracked | A cron entry | Yes | Yes at the deployment level | Run `schedule:run` so at least one maturity and one expiry sweep executes against real data |
| **New** | **The "This feature isn't currently available for your store" admin message** | Unidentified, 9 Sep 2026 | Nothing | Yes | Unknown | **Identify what it applies to before starting anything that might depend on it.** A message of that form is a limitation announcing itself |

**Resolved, recorded so they are not reopened:** V1 (Polaris web components with
App Bridge — **corrected** 27 Aug: `polaris.js` and `app-bridge.js` are two
different scripts and both are needed), V3 (session tokens, plus the `aud` and
`dest` checks the JWT library omits), V4 (function input readability, spike),
V5 (`functionHandle`, not the deprecated `functionId`), V6a (app-synced
metafields — **verified live**), V7 (Scanner API, with a physical-card residual),
V8 (extension API versions aligned, unused target removed).

### C. SHOPIFY / PLATFORM CONSTRAINTS

| # | Constraint | Consequence | Status |
| --- | --- | --- | --- |
| 1 | **Functions from a custom app require Shopify Plus.** `discountAutomaticAppCreate` returns *"Shop must be on a Shopify Plus plan to activate functions from a custom app."* | OSC is on **Grow** (client-confirmed), so the discount function cannot be the production online mechanism | **Settled** — D5 `REVISED`; the code path ships, the function is kept for Plus-and-above |
| 2 | **`use_legacy_install_flow = true` forbids `[[webhooks.subscriptions]]`** in the toml — `shopify app deploy` rejects the whole app version. `shopify app dev` is more permissive, so it only shows up at deploy time | Every business topic is registered per shop through the Admin API | **Settled and built** |
| 3 | **The legacy install flow has no token exchange**, so every scope change forces **one merchant re-authorisation** | Three have already been asked for; `read_all_orders` will be a fourth | **Live constraint.** On the go-live checklist |
| 4 | **The OAuth `redirect_uri` is validated against the RELEASED app version's allowlist** under the legacy install flow | **Any tunnel change needs a `deploy`, not just a restart** | **Settled**, and the cause of a fault that made consent succeed with nowhere to return to |
| 5 | **`read_users` is protected**, so staff identity cannot be resolved to a name | The app stores the identifier from the token and an Administrator labels each staff member once. Role management never waits on a Shopify approval | **Settled and built** |
| 6 | **POS gives an extension no way to discover its own app URL** — documented for 2026-07, whose own example hardcodes it | The tile **compiles the URL in** (`lib/appUrl.js`), rewritten by `web/serve.mjs` on every `shopify app dev`. **Whatever is committed at release time is what ships to every till** | **Settled**, and on the go-live checklist |
| 7 | **A function input query is static**, and `Product` exposes no enumerable tag list — only `hasAnyTag`/`hasTags` predicates whose arguments are static | V6a's split is **forced, not chosen**: shop metafield carries the shop-wide switch, product metafield carries one resolved boolean per product | **Settled and verified live** |
| 8 | **`applyCartDiscount(type, title, amount?)` takes a bare amount with no currency parameter** | **The till always denominates.** No guard in this codebase changes that | **V24, uncovered** |
| 9 | **`session.currency` reports the SHOP's currency, not the location's** — proved by a hold at a CAD-market location reporting GBP | V18 duplicated V13 and is withdrawn | **Settled 9 Sep 2026** |
| 10 | **Shopify excludes gift-card lines from a fixed-amount code** — established on real order `#1001` | No app-level gift-card guard is built, deliberately | **Settled empirically.** Note the codebase has no gift-card awareness at all |
| 11 | **`Order.physicalLocation` is deprecated on 2026-07** and is still selected on every `AdminApiOrderSource::fetch()` | A deprecation warning on every order read | **Recorded, not addressed** |
| 12 | **A non-UK merchant is not required to collect UK VAT on orders over £135**, and the dev store's merchant address is **locked** to the US | **V12 is unprovable on the development store.** Every UK checkout above £135 re-prices to base at the payment step, with a "Price update" modal. Reproduced twice | **Final.** Do not reopen |
| 13 | **Tax treatment follows merchant establishment, not a location's address** | A UK-addressed *location* would give a genuine GBP till but would say nothing about VAT | **Settled**, and the reason a second UK development store was considered and rejected |
| 14 | **On 2026-07 `MarketType` has no `PRIMARY` value** and `Market` carries no `primary` or `enabled` field | "The shop baseline follows the primary market" could not have been checked the way it was framed | **Settled** |
| 15 | **jsdom registers no custom elements**, so an unregistered `s-page` renders exactly as a registered one | The whole class of "is this component real" faults is invisible to the frontend suite. `polarisGuard.js` catches the worst at boot; **a screen still has to be looked at** | **Permanent limit of the environment** |
| 16 | **A POS surface cannot be rendered in a test environment**, and `tile.test.js` imports only `src/lib/` | The four defects of 3 Sep were all outside the suite's reach *by construction*. `handlerContract.test.js` now checks the JSX against the platform's own type declarations | **Mitigated, not removed** |
| 17 | **The suite runs on SQLite; production runs on MySQL 8.4, and SQLite is the more forgiving of the two** | Three real defects sat behind a green build: an unsigned `points` column, MySQL's JSON columns not preserving key order, and a card-number parser that returned another member's record | **All three fixed with regression tests that fail on SQLite too, each verified by reintroducing the defect.** The habit stands: run against MySQL before trusting any change to a migration, a CHECK, an unsigned column, an enum, or anything that round-trips through JSON |

### D. ITEMS THAT CAN ONLY BE PROVEN ON OSC'S OWN STORE / ENVIRONMENT

Every item here is untestable elsewhere **by nature of the environment**, not for
want of trying. The governing rule is *mechanism belongs on the dev store,
fidelity belongs on OSC*.

| Ref | Item | Why it cannot move |
| --- | --- | --- |
| **B1 / V12** | Earn base on a tax-inclusive order | Needs a real VAT-inclusive **discounted** order. The dev store's merchant address is **locked** to the United States, and a non-UK merchant is not required to collect UK VAT above £135, so checkout re-prices to base with no tax line. The underlying question is Shopify's own arithmetic — are `taxLines` computed before or after an order-level allocation — and only an order that actually carries VAT can answer it |
| **B2 / V16** | A Privilege Club role for every till user | Needs **OSC's real staff accounts**. Only the first staff member on a shop is bootstrapped; the rest 403 |
| **B3 / V10** | The five reports reconciling with Shopify analytics | Needs **real order history at real volume**. A loyalty share computed over three dev-store orders proves nothing |
| **B4 / V9** | Klaviyo live wiring | Needs **OSC's private API key**. There is no substitute account |
| **B5 / C5** | Live store currency and reporting timezone | **A fact about OSC's store, not a test** |
| **B6** | Migration reconciliation, pre/post totals and exception sign-off | Needs the **Dynamics/ORD export and real member records**. Anonymised samples can prove the pipeline; only the real file can reconcile |
| **B7** | `read_all_orders` historical backfill | Needs **two years of real orders** |
| **B8** | VAT-inclusive storefront and receipt presentation | Follows **merchant establishment**, which is US on the dev store |
| **B9** | The production application URL and its OAuth grant | The dev tunnel is **ephemeral by construction** |
| **New** | Two real trading locations with real staff, for location and staff attribution at production scale | The dev store proved *distinct* location ids; OSC's own stores are the fidelity check |
| **New** | Physical legacy Dynamics card barcode encoding (V7 residual) | Needs **a physical OSC card** |

**What is NOT in this list, and must not drift into it.** The POS mechanism —
`Apply → hold → tender → orders/paid → confirm` — is **provable on the
development store today**. `PC-23502758` disproved the earlier claim that a
GB-addressed location was required: a hold succeeded on a US-addressed location
and the device reported GBP. What blocked it on 9 September was an **offline
device**, and before that a twenty-minute quote window with an incomplete sale.
Neither is a property of the store. Finding a V19-class defect for the first time
on OSC's live till, mid-trade, in front of staff and a customer, is categorically
worse than finding it here.

---

## 5. LIVE-STORE / GO-LIVE READINESS

### A. CAN BE VALIDATED BEFORE LIVE DEPLOYMENT

- [ ] **One uninterrupted POS sale end to end** — quote → hold → apply → tender →
      paid order → `orders/paid` → confirm → `points_consumed` → ledger entry
      keyed to the reference. Device confirmed **online** first; cart built and
      payment ready **before** Apply. Time-boxed to 30 minutes by written rule.
- [ ] **The reference on a real POS receipt.**
- [ ] **Two distinct `shopify_location_id` values** from sales at two locations —
      non-NULL on a single location would be satisfied by a hardcoded default.
- [ ] **One real refund** processed through `refunds/create`, and **one real
      cancellation** through `orders/cancelled`. Currently zero evidence for
      either.
- [ ] **One real partial refund**, to exercise D9c's cumulative floor against
      real data.
- [ ] **At least one maturity sweep and one expiry sweep** running under a real
      cron with real consequence.
- [ ] **One real birthday reward issued** — the table holds 0 rows and the job
      has correctly issued nothing, because the dev store's account has no date
      of birth. Add one and let the job run.
- [ ] **V11 spike** — does a customer account UI extension deploy and render
      under `use_legacy_install_flow`? Fallback ladder A→B→C→D already written.
- [ ] **V2 volume pass** — seed to a two-year row count, confirm indexes hold.
- [ ] **V15** — pin `@shopify/ui-extensions` to the declared api_version.
- [ ] **V10** — settle the store-wide sales figure approach before building R3.
- [ ] **Migration pipeline against anonymised sample data** — every defect the
      profiler looks for, `123.45 → 123`, dry run writes nothing, import then
      re-import produces identical balances, `combine`, `marketing_only`, MD4
      consent defaults.
- [ ] **Every remaining screen and export**, in a browser. jsdom cannot register
      a custom element, so the browser pass is not optional on this project.
- [ ] **The full role-by-endpoint authorisation matrix**, green.
- [ ] **UAT scripts per role**, agreed and executed.
- [ ] **Backup and restore rehearsed** before any migration import.

### B. CAN ONLY BE VALIDATED ON OSC'S OWN SHOPIFY STORE / POS

Each with the reason it cannot move — see §4.D for the full table.

- [ ] **V12** — earn base on a tax-inclusive discounted order. *Merchant
      establishment is locked to the US and UK VAT is not collected above £135.*
- [ ] **V16** — a role for every till user. *Needs OSC's real staff accounts.*
- [ ] **V10** — the five reports reconciling with Shopify analytics. *Needs real
      order history at real volume.*
- [ ] **V9** — Klaviyo live wiring and its five flows firing correctly. *Needs
      OSC's private API key.*
- [ ] **C5** — live currency and reporting timezone. *A fact about the store.*
- [ ] **Migration reconciliation and sign-off.** *Needs the real export.*
- [ ] **`read_all_orders` historical backfill.** *Needs two years of real
      orders.*
- [ ] **VAT-inclusive storefront and receipt presentation.** *Follows merchant
      establishment.*
- [ ] **The production application URL and its OAuth grant.** *The dev tunnel is
      ephemeral by construction.*
- [ ] **Location and staff attribution at OSC's two real trading locations.**
- [ ] **V7 residual** — the legacy barcode encoding. *Needs a physical card.*

### C. CLIENT DATA / ACCESS / CONFIGURATION STILL REQUIRED

- [ ] **Dynamics / ORD export (CSV)** with field list, schema and known
      data-quality issues — customer details, emails, postcodes, card numbers,
      point balances, dates of birth.
- [ ] **Klaviyo private API key.**
- [ ] **Gender metafield** configured on both development and live stores.
- [ ] **Domain details** for the production application URL.
- [ ] **The named first Administrator (C6 live).**
- [ ] **Live store currency and reporting timezone (C5).**
- [ ] **One physical legacy Dynamics card** (V7 residual).
- [ ] **Answers to C1, C14, C3, C9, C10, C11, C8**, plus the Klaviyo
      balance-reduction question and the A6 / UI C2 voucher-object conflict.
- [ ] **Full scope grant on the production store** — nine scopes, plus
      `read_all_orders` once added. Under the legacy install flow this is a
      **merchant action**, not a deploy step.

### D. GO-LIVE BLOCKERS

Each of these genuinely prevents a safe production launch.

| # | Blocker | Why it blocks |
| --- | --- | --- |
| 1 | **V12 — the tax-inclusive earn base is unproved** | OSC sells VAT-inclusive, so the unproved branch is the one that ships. Getting it wrong **under-pays every member on every discounted order**, silently and cumulatively. Closable only by reconciling one real discounted order on the live store |
| 2 | **V16 — till users other than the first get `403 no_role_assigned`** | Every till except one reads as a broken app on day one. Needs either a documented onboarding step or an implicit viewer floor |
| 3 | **The five reports do not exist** | Committed scope, OSC expectation 9, and the thing management will open first |
| 4 | **Legacy migration does not exist** | Committed scope, OSC expectation 6. **Without it every existing member is reset**, which is precisely the outcome the programme was bought to avoid |
| 5 | **Nothing customer-facing exists** | Committed scope, OSC expectations 2 and 5. A member cannot see their own balance by any route. Roughly half the programme's value |
| 6 | **Klaviyo is not wired** | Committed scope, OSC expectation 8. No welcome, no points-available, no reward, no expiry reminder, no birthday message |
| 7 | **The POS happy path has never completed** | An in-store redemption has never worked end to end anywhere. Expectation 4 |
| 8 | **The refund path has never met a real refund** | Real money and real balances. The arithmetic is good; the plumbing is unproved |
| 9 | **C14 unconfirmed** | The earn base is already built to our call. A wrong answer is invisible and compounds |
| 10 | **C6 (live) — no named first Administrator** | Nobody can be handed the first login |
| 11 | **Production hosting with a cron entry and a continuously running queue worker** | Code cannot supply these. Without the cron, nothing matures, expires, issues a birthday reward, resegments or sweeps a quote. Without the worker, **nothing earns and nothing confirms** |
| 12 | **The real application URL, redirect URLs and released app version** | The released version still carries the scaffold placeholders (`default-app-home`); `APP_URL` in the tile currently holds a dev tunnel |
| 13 | **Full scope grant on the production store** | A merchant action under the legacy install flow |
| 14 | **No UPDATE or DELETE grant on `loyalty_ledger` and `loyalty_audit_log`** for the production database user | Append-only is enforced at the model; production should enforce it at the grant too |

### E. NON-BLOCKING POST-BUILD VALIDATIONS

- **V24** — the foreign-till denominator. A correctness gap while OSC trades
  UK-only, and it **must not be recorded as handled**.
- **V13** — implemented; the register row needs updating. Consider whether
  `pointsFor()` should assert a currency too.
- **V14** — reporting only. Points, balances, maturity and segmentation are
  correct. Must be settled *by* the reporting design.
- **V15** — the types skew. Not blocking, but no conclusion drawn from those
  types is settled until it is fixed.
- **V21** — the reward lifecycle. Latent until the first genuine birthday on
  OSC's store, or migration. It **gates A6 and R5**, so it is not blocking
  *today* only because those are not built.
- **V2** — the volume pass, if OSC's row count turns out to be modest.
- **A downward voucher-increment event** — a sixth event name.
- **Location names on ledger rows** — needs a `read_locations` lookup and cache.
- **`Order.physicalLocation` deprecation.**
- **The `Auditable` trait**, `AuditTrailPanel`, `GET /audit/{id}`.
- **Month-on-Month / Year-on-Year comparison** — the plan's own first descope
  candidate, and meaningless until post-launch data accumulates.
- **The unidentified "This feature isn't currently available for your store"
  admin message** — identify it early rather than late.

---

## 6. CONTRADICTIONS BETWEEN DOCUMENTATION, UI STRUCTURE AND CODE

Nothing here is silently resolved in favour of one source.

| # | Sources that disagree | The disagreement | Which is right |
| --- | --- | --- | --- |
| **1** | **UI Structure (A6, UI C2) vs D1 (`CONFIRMED`)** | The agreed UI shows individual points-voucher **objects** with codes, expiry dates and per-voucher Cancel/Reissue, on both the admin screen and the member portal. D1 removed that object: the points-derived balance has no expiry of its own and only the birthday reward is an issued record | **D1 is the confirmed decision and the code follows it.** But the UI the client signed off shows something else, and **this has not been raised with OSC.** The most significant scope conflict in this review |
| **2** | **`PROGRESS.md` internal, same day** | The 3 Sep audit table records customer balance metafields as **BUILT, UNVERIFIED**, naming `PublishBalanceMetafieldJob`. The M4 audit later in the same file records `PublishBalanceMetafieldJob` as **NOT BUILT** | **The code settles it: it IS built** (`web/app/Jobs/PublishBalanceMetafieldJob.php`, `BalanceMetafieldPublishTest.php`). The M4 row was written before the job was built the same day and was never updated |
| **3** | **`DECISIONS.md` validation table vs the code** | V13, V17 and V19 are all listed `OUTSTANDING`, V19 as **BLOCKS SPRINT 3** | **All three are fixed in the code** — verified: `AdminApiDiscountCodeWriter` refuses a currency mismatch; `messageFor()` exists; `Modal.jsx` has 10 `onClick` and 0 `onPress`. Three stale register rows |
| **4** | **`PROGRESS.md` "Open client items" vs `DECISIONS.md` §3** | `PROGRESS.md` lists C1, C6(live), V7 residual, D8, D3/D9, C2/C10/C11, C3/C9. It **omits C14 and C5** | **`DECISIONS.md` is right.** C14 is one of only two items its §1 names as needing Robert, and C5 is `PENDING` |
| **5** | **`DECISIONS.md` §1 summary vs `DECISIONS.md` §3 table** | §1 says *"What still needs Robert — **Two items**: C6(live), C14."* §3 lists C1, C3, C5, C8, C9, C10 and C11 as `PENDING`, every one of which needs an OSC position | **§3 is right.** §1 understates by counting only the items that gate go-live. "Two items" reads as the whole client-facing list and is not |
| **6** | **`PROGRESS.md` 3 Sep audit vs the strict verification rule** | The audit puts *"Ledger, earning, **refunds, reversals**, maturity, expiry, segmentation"* in one **BUILT AND VERIFIED** row, evidenced by 478 tests plus a C14 replay | **The row is too broad.** No real Shopify refund or cancellation has ever been processed; the C14 replay is an earn correction. Refunds, reversals, maturity, expiry and segmentation are **Built but Unverified** under the strict definition |
| **7** | **`IMPLEMENTATION_PLAN.md` §12 readiness checklist vs `DECISIONS.md`** | The checklist still shows *"C6 — named first Administrator (**blocks Sprint 1**)"*, *"D3, D9, C2, C4 (block Sprint 2)"* and *"read_all_orders application submitted"* all unticked | **`DECISIONS.md` is right.** Sprints 1 and 2 are complete, C4 is `CONFIRMED`, D3/D9/C2 are `ASSUMED` and built, and `read_all_orders` was **approved** on 1 Sep. The plan's checklist has not been maintained |
| **8** | **`IMPLEMENTATION_PLAN.md` §2.4 namespaces vs the code** | The plan names `App\Domain\Rewards`, `App\Domain\Segmentation`, `App\Domain\Migration`, `App\Domain\Reporting`, `App\Integrations\Klaviyo` | **Segmentation exists under `Domain\Loyalty`** — cosmetic. **Rewards, Migration, Reporting and Klaviyo do not exist at all** — substantive |
| **9** | **`IMPLEMENTATION_PLAN.md` M1 vs the registered webhook topics** | M1: *"`customers/create` **drives storefront enrolment**; `customers/update` keeps email, postcode, consent and the gender metafield in step"* | **Neither topic is registered.** `config('shopify.webhooks.topics')` holds six topics and neither customer topic is among them. Storefront enrolment therefore has no trigger |
| **10** | **`IMPLEMENTATION_PLAN.md` §6.3 vs the code** | Five typed customer metafields | **One JSON `member` key.** A documented, deliberate deviation pending V11 |
| **11** | **`IMPLEMENTATION_PLAN.md` §5.3 vs the routes** | Fifteen Sprint 1 endpoints | **19 exist.** `GET /ledger` plus three redemption endpoints, each recorded rather than folded in. `AdminApiRoutingTest` keeps the two from drifting |
| **12** | **UI Structure A5/A10 vs D1/D2** | *"Points expire 6 months after **earning**"* and *"Vouchers expire 6 months after **issue**"* | **D2 moved the clock to the availability date** and **D1 gave the derived balance no expiry.** The Settings screen states what is stored, so the code and the register agree — but the client-facing UI still says otherwise |
| **13** | **`PROGRESS.md` test counts, internally** | 426, 438, 478 and 413 backend tests all appear as current figures in the same file | **No single figure is stated.** A direct count of test methods gives roughly 407 backend, 145 frontend, 46 POS tile, 8 discount-function files (data providers inflate the reported totals). **None of this is verification and none of it should be quoted to the client** |
| **14** | **`PROGRESS.md` "Console — 7 screens built... 3 stubbed" vs the agreed UI** | The header table says 3 screens are stubbed | **Four agreed console areas are absent** — A6, A7, A8 **and A9**, the last having no route or nav entry at all — plus the staff-roles screen, the enrol modal and the Klaviyo tab |
| **15** | **`proposal.docx` §5 vs the code** | Four Klaviyo profile traits synced — voucher balance, member status, Active/Lapsed segment, outstanding rewards | **No trait sync exists.** Only the five events, all dropped |

---

## 7. PROJECT COMPLETION VIEW

| Module | Status | Verified? | UI complete? | Main remaining work | Blocker / dependency | Go-live gate? |
| --- | --- | --- | --- | --- | --- | --- |
| **M2 Ledger & rules engine** | Built and Verified | **Yes** — real order, reconciled | n/a | V2 volume pass | — | No |
| **M3 Points earning** | Built but Unverified | Tax-**exclusive** only | n/a | Prove the tax-inclusive branch | **V12** — needs a real VAT-inclusive discounted order | **Yes** |
| **M4 Voucher engine — derived balance** | Built and Verified | **Yes** — real checkout + real till | Staff-facing yes | — | — | No |
| **M4 Voucher engine — issued reward** | Partially Built | No | A6 not built | Reward state machine, expiry job, issuer, controller, redemption seam, three modals | **V21 parked**; overlaps V14 | No (gates A6, R5) |
| **M5 Birthday rewards** | Built but Unverified | No — 0 reward rows, never run on a real shop | A6 not built | One real issuance; then the lifecycle | Cron; V21 | No |
| **M6 Online redemption — server** | Built and Verified | **Yes** — A1–A4 on a real shop | n/a | Eligible-subtotal rule cannot be enforced by a code | Only when OSC configures an exclusion | No |
| **M6 Online redemption — customer journey** | Not Built | No | UI C3 not built | The checkout/storefront control | **C1** | No, but unfinished committed scope |
| **M7 Shopify POS** | Built but Unverified | Holds, attribution and offline only | B1–B9 built, unverified | **Tender → paid → confirm has never completed.** POS refund untested | Device online; 30-min time-box; **V16** | **Yes** (V16, and the happy path) |
| **M8 Refunds & cancellations** | Built but Unverified | **No — no real refund has ever occurred** | n/a | One real refund, one real partial refund, one real cancellation | Nothing — provable here today | **Yes, effectively** |
| **M9 Segmentation** | Built but Unverified | No | Pills shown on A2/A3 | One real nightly run; then the `read_all_orders` backfill | Cron; D7 scope not yet added to the toml | No |
| **M1 Customer / unified profile** | Partially Built | Search and enrolment exercised on a real store | A2, A3 built; merge screens not | Shopify customer matching/linking, `customers/*` webhooks, **merge tool** | Gender metafield receivable | No |
| **M1 Online enrolment** | Partially Built | Console enrolment only | UI C1 not built | The storefront path in full | No storefront surface; `customers/create` unregistered | **Yes** — expectation 2 |
| **M13 Admin console & roles** | Partially Built | 6 screens browser-verified | **6 of 11**, plus staff screen, enrol modal, Klaviyo tab, 5 exports | A6, A7, A8, A9, staff roles, enrol modal, exports | A6 needs V21; A8 needs V10/V14/C5/C8 | **Yes** — via reports |
| **M14 Audit & logging** | Built but Unverified | Real audit entries written on a real store | A12 built; **Export CSV disabled**; no profile panel | Export, `AuditTrailPanel`, `GET /audit/{id}`, `Auditable` trait, volume search | — | No |
| **M11 Klaviyo** | Partially Built (seam only) | **No flow has ever fired** | Settings Klaviyo tab not built | Driver, traits, five flows, reconcile, health card, integration screens | **Klaviyo API key**; V9; the balance-reduction decision | **Yes** — expectation 8 |
| **M12 Reporting & exports** | **Not Built** | No | A8 not built | All five reports, five filters, comparison toggle, CSV **and** PDF, export job | **V10**, V14, C5, C8, Q4; V21 for R5 | **Yes** — expectation 9 |
| **M10 Legacy migration** | **Not Built** | No | A9 not built or routed | The entire eight-stage pipeline and its screens | **The Dynamics/ORD export file** | **Yes** — expectation 6 |
| **M15 Customer loyalty view** | **Not Built** | No | UI C1, C2, C3 all absent | Everything, after the V11 spike picks a route | **V11 never spiked**; C1 | **Yes** — expectations 2 and 5 |
| **International / non-GBP** | Blocked (open decision) | V24 unobserved | n/a | A server-side currency check at `confirm()` | No business rule exists; OSC is UK-only | No, while UK-only |
| **Deployment & operations** | **Not Built** | No | n/a | Cron, queue worker, production URL, released version, scope grant, DB grants | Hosting; a merchant action | **Yes** |

**No completion percentage is offered.** The evidence does not support one: five
modules are at zero, four have real evidence for part of their behaviour only,
and the largest single gap — everything customer-facing — has no implementation
and no verification story at all.

---

## 8. RECOMMENDED NEXT-WORK ORDER FROM TODAY

Ordered by dependency, risk and what can proceed while decisions are pending.
**This is deliberately not the original sprint numbering** — Sprint 4 and Sprint 5
work now outranks the tail of Sprint 3, because the tail of Sprint 3 is blocked
on the client and the unstarted sprints contain three go-live blockers that
nothing else waits on.

**1. One uninterrupted POS sale, strictly time-boxed to 30 minutes.**
It comes first only because it is cheap, it is the one thing that closes an
entire section of the checklist, and the preconditions are known. Check the
**offline banner first** — that single check would have saved the afternoon of
9 September. Run `loyalty:preflight` before and after: **a device run is verified
by the watermark moving, not by a report that it passed.** If it does not
complete inside thirty minutes, A1–A3 move to the live-store list and step 2
starts that morning. **Honour the box.** Two full days have already gone to POS
for a structural reason — POS yields a tractable defect every few hours and a
missing surface yields none, so the next POS defect always looks five minutes
from resolution, and there is always another behind it.

**2. The V11 spike, then build the customer-facing section.**
This goes second because it is the **largest committed gap in the project**, it
has had no sprint time across two full days, and **nothing about it is blocked on
OSC** — the fallback ladder is already written (A customer account extension →
B app proxy carrying `logged_in_customer_id` → C theme extension → D Liquid), so
the spike **cannot leave us with nothing**. It also unblocks decisions rather
than waiting on them: once a route exists, C1 becomes a question about a control
on a page that exists rather than an abstraction. Ahead of reports and migration
because those depend on client inputs that have not arrived, and this does not.

**3. One real refund, one real partial refund and one real cancellation on the
dev store.**
Third because it is hours of work, it is pure mechanism, and it removes an
**effective go-live blocker** from the list — the refund arithmetic is the most
carefully reasoned code in the project and has never met a real Shopify event.
Run the cron at the same time so at least one maturity and one expiry sweep
executes with real consequence, and add a date of birth so one birthday reward is
genuinely issued. Before reports, because a report over a ledger whose reversal
path has never run would be reporting on unproved numbers.

**4. Brief V21 and V14 together, then build the reward lifecycle.**
Fourth because it is the only item waiting on a **decision rather than
evidence**, and it gates two things downstream: A6 Voucher management, and R5's
`redeemed`/`expired` columns. Both turn on what a stored column is allowed to
mean, and **deciding them separately is how a schema acquires two incompatible
conventions.** Doing it before reporting means the reporting design is built
against settled column semantics rather than the other way round.

**5. Reporting (M12), starting with V10.**
Fifth because it is a go-live blocker, it is OSC expectation 9, and it is what
management will open first — but it must come after step 4 so that V14 and V21
are settled, and it must **start** with V10 (the store-wide sales denominator),
because R3's headline figure is a percentage of total sales and a loyalty share
that disagrees with the Shopify admin dashboard is the first thing that will be
questioned. Four of the five reports have their data available today; R5 does
not, which is why step 4 precedes this.

**6. Klaviyo (M11), the moment the API key arrives.**
Sixth because the expensive half — the five events emitting from real production
call sites, with the pending gate in the engine rather than the flow — is already
done, so this is binding a driver, adding four profile traits and configuring
flows. It is a go-live blocker and OSC expectation 8, but it is **hard-blocked on
a receivable**, so it should be picked up when the key lands rather than
scheduled against a date. Get the balance-reduction decision answered before the
flows are configured.

**7. Legacy migration (M10), the moment the export arrives.**
Seventh for the same reason and more strongly: it is a go-live blocker and OSC
expectation 6 — **without it every existing member is reset**, the precise
outcome the programme was bought to avoid — and it is completely blocked on a
receivable that has not arrived. All ten business rules are confirmed and the
schema is built, so this is a pipeline against a known specification. Start
profiling the day the file lands, regardless of what else is in flight. Build and
test the pipeline against **anonymised sample fixtures** in the meantime if
samples can be obtained ahead of the full file.

**8. Close the remaining console gaps: A7 Transactions, the staff-roles screen,
the enrol modal, and every export.**
Eighth because each is a screen over an endpoint that already exists and is
tested, so they are the lowest-risk work on the list and the easiest to fit
around a blocked item. A7 and the exports are committed scope; the staff-roles
screen is what makes V16's onboarding step actually performable in the console
rather than by hand.

**9. V16 and C9 together, then V24's server-side guard.**
Ninth for V16 only because its **decision** is parked next to C9 — the code
change is small either way and can land the day the answer comes, so it should
not hold anything up; it just cannot be built unbriefed. V24 comes last of the
substantive items because OSC is UK-only, which makes it a correctness gap rather
than a launch gate — and the direction is already decided: guard **server-side at
`confirm()`** from the order's own currency, where a refusal leaves the member's
points untouched.

**10. Deployment hardening, and the register hygiene pass.**
Last: the production URL and released app version, the cron entry, the queue
worker, the full scope grant, `read_all_orders` added to the toml, and the
database grants withholding UPDATE and DELETE on the ledger and audit log. In
the same pass, correct the four stale register rows this review found (V13, V17,
V19 as fixed; V23 as answered), reconcile the plan's readiness checklist, and add
C14, C5 and the two newly-raised questions to `PROGRESS.md`'s open client items.
Cheap, and it stops the next reader inheriting a register that reads greener —
and in three rows redder — than the code.

**No revised timeline is offered, and none should be inferred from this
ordering.**

---

## 9. THE STANDING RISK, RESTATED

The project's own record names it: **a status report reading greener than the
evidence supports.** It has already happened four times — "all five searches
return the member" before anyone tapped a result; V18's guard claimed as resolved
for six days when it only ever duplicated V13; a POS run reported as passing end
to end when Shopify held no order created that day; and a screen full of passing
tests over a tile in which every single control was dead.

The mitigations that came out of those are worth keeping in view because they
apply to this report too:

- **A device run is verified by the watermark moving, not by a report that it
  passed.** `loyalty:preflight` gives four numbers — newest redemption, ledger
  entry, webhook event and Shopify order. If they are unchanged, the run did not
  reach the system, whatever the device showed.
- **A guard that can only pass is not evidence when it passes.** Ask whether any
  other outcome was possible before treating one as informative.
- **If a test supplies what production derives, something else must test the
  derivation** — and prove the test would have failed by reintroducing the
  defect.
- **A placeholder screen and a missing state machine look identical in a status
  table and are not the same thing.**
- **Read the environment prefix when reading a log.** `testing.*` is the suite,
  `local.*` is a plain shell, `development.*` is everything the POS device and
  the admin console actually do.
