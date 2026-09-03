# OSC Privilege Club — Implementation Plan

The plan of record for the loyalty build. Derived from the Privilege Club
Blueprint (Rev 2), which was derived from Proposal v1.1.

| | |
| --- | --- |
| **Scope source** | `proposal.docx` (Proposal v1.1, 14 Aug 2026) |
| **Specification** | Privilege Club Blueprint, Rev 2 |
| **Decisions** | [DECISIONS.md](DECISIONS.md) — the authoritative register |
| **Schema** | `web/database/migrations/2026_08_26_*` — the authoritative DDL |
| **Admin API** | 2026-07 |
| **Status** | Sprint 1 in progress |

## Ownership of the documents

Three files, each authoritative for one thing, so none of them can drift from
another:

- **This file** owns the build design: architecture, module scope, API surface,
  integrations, dependencies, testing and sequencing.
- **`DECISIONS.md`** owns every decision, confirmation, open question and
  technical validation, with status and date. This file references decisions by
  ID and never restates their content.
- **The migrations** own the schema. Section 4 below describes intent and
  rationale; column-level truth is in the migration files.

A client-facing rendering of the original plan is published as an artifact. It
is a snapshot of 26 Aug 2026 and is refreshed on request; where the two differ,
this file wins.

---

## 1. How to read this

Requirements carry one of six marks. A plan that read as uniformly settled would
hide what is still open.

| Mark | Meaning |
| --- | --- |
| **Confirmed** | Supported by the Blueprint or a client confirmation. Implementable as written. |
| **Validate** | Needs a technical answer before the sprint that uses it. See the V register. |
| **OSC** | Needs a client decision. Customer-visible behaviour depends on it. |
| **Platform** | A Shopify limitation or dependency that shapes the design. |
| **Risk** | Carried, with a named handling in section 10. |
| **Open** | The Blueprint does not answer either way. See the Q register. |

---

## 2. Architecture

One integrated Shopify app. A single Laravel repository holds the backend, the
embedded React frontend and the three extensions; a single MySQL schema holds
the ledger. No second service, no separate frontend project.

```
Shopify Admin ──► React (embedded, Polaris web components + App Bridge)
                        │
                        ▼
                  Laravel API  ──►  MySQL (ledger, rules, audit)
                        ▲
      ┌─────────────────┼─────────────────┐
      │                 │                 │
  POS Pro tile    Shopify webhooks   Customer account
  (POS UI ext)    (per-shop, Admin API)   (UI extension)
                        │
                  Discount function (Wasm, runs inside checkout)
                        │
                  Klaviyo (outbound only)
```

### 2.1 Request paths

| Surface | Runs in | Reaches Laravel via | Authenticated by |
| --- | --- | --- | --- |
| Admin console | Shopify admin iframe | `/api/admin/*` | App Bridge session token (JWT) + staff role lookup |
| Loyalty tile | POS Pro app | `/api/pos/*` | POS session token; staff and location from the POS session |
| Customer loyalty view | Customer account pages | `/api/account/*` | Customer account session token; subject is a customer |
| Voucher discount | Shopify checkout (Wasm) | no callback possible | Runs inside Shopify; reads only its GraphQL input |
| Shopify events | Shopify servers | `/api/webhooks` | HMAC over the raw body |
| Scheduled work | Queue worker and scheduler | in process | no HTTP surface |

**Platform — the constraint that shapes online redemption.** A discount function
is WebAssembly running inside checkout. It cannot call the app, read the
database or hold a secret. Everything it needs to enforce the caps must already
be in its GraphQL input, which means customer metafields and cart attributes
written by the app *before* checkout. That single fact decides the design of M6.

### 2.2 Foundation already built and verified

Loyalty is additive; none of this is reworked.

| Component | Where | What loyalty needs from it |
| --- | --- | --- |
| Classic OAuth, sessions in MySQL | `ShopifyAuthController`, `DbSessionStorage` | Scope list extended once |
| Admin GraphQL service layer | `App\Services\ShopifyAdminApi` | Retry, cost-aware throttling, bulk operations |
| Webhook pipeline, HMAC and dispatch | `WebhookController`, `App\Lib\Handlers` | Six business handlers, all queued |
| Per-shop topic registration | `config('shopify.webhooks.topics')` | Six topics appended — never the app TOML |
| Embedded React shell | `web/frontend` | App Bridge, UI layer and routing added |
| Configuration guard tests | `ShopifyConfigurationTest` | Extended with scope and version parity |
| Extension scaffolds | `extensions/loyalty-tile`, `voucher-discount` | Template code replaced |

### 2.3 Frontend

**V1 resolved:** Polaris web components with App Bridge. The `s-*` custom
elements are globally registered and need no import; `@shopify/app-bridge-react`
supplies `useAppBridge`. `@shopify/polaris` must not be added as a dependency.

App Bridge intercepts `fetch` and attaches the ID token in the `Authorization`
header for same-origin requests, which is the path `EnsureShopifySession`
already decodes — no manual token plumbing.

| Route | Screen | Role floor |
| --- | --- | --- |
| `/` | Overview: member count, today's activity, job health | Viewer |
| `/members` | Member search and list | Viewer |
| `/members/:id` | Profile: balance, ledger, redemptions, rewards, actions | Viewer |
| `/members/:id/adjust` | Adjustment modal, reason mandatory | Agent |
| `/members/merge` | Duplicate review and merge | Manager |
| `/rules` | Rules editor and version history | Administrator |
| `/reports/:key` | The five reports, filters, export | Viewer |
| `/migration` | Batches, dry-run results, exception review | Administrator |
| `/audit` | Audit log search | Manager |
| `/settings/staff` | Role assignment | Administrator |
| `/settings/integrations` | Klaviyo state and connection test | Administrator |

### 2.4 Backend layering

Domain services own the arithmetic and are the only writers to the ledger.
Controllers validate and delegate; jobs and webhook handlers call the same
services, so an earn posted by a webhook and one posted by a replay command take
an identical path.

| Namespace | Holds |
| --- | --- |
| `App\Domain\Loyalty` | LedgerService, BalanceCalculator, EarningCalculator, RedemptionCalculator, ExpirySchedule, LotAllocator |
| `App\Domain\Rules` | RulesVersionRepository, RuleSetResolver (as-at), RuleSchema, RuleSet |
| `App\Domain\Identity` | AccountResolver, MatchStrategy (email → card → postcode+name), MergeService |
| `App\Domain\Rewards` | RewardIssuer, BirthdayEligibility, RewardStateMachine |
| `App\Domain\Redemption` | QuoteService, RedemptionGateway + FunctionGateway, PosGateway |
| `App\Domain\Segmentation` | SegmentCalculator, LastSpendResolver |
| `App\Domain\Migration` | Profiler, RowMapper, Matcher, DryRunner, Importer, ExceptionWriter |
| `App\Domain\Reporting` | Five report queries, Csv and Pdf renderers, ExportJob |
| `App\Integrations\Klaviyo` | KlaviyoClient, EventMapper, TraitSynchroniser, NullDriver |
| `App\Support\Audit` | AuditLogger, Auditable trait, RequestContext |
| `App\Support\Money` | Pence value object, rounding helpers, currency guard |

---

## 3. Module specifications

Fifteen modules cover the whole Blueprint. A heading that reads *not required*
is a deliberate statement, not an omission.

### Scope crosswalk

| Scope area | Module |
| --- | --- |
| Customer / unified profile; loyalty card and postcode matching | M1 |
| Loyalty core; loyalty ledger | M2 |
| Points earning; full-price qualification | M3 |
| Voucher / reward engine | M4 |
| Birthday rewards | M5 |
| Points and voucher redemption, online | M6 |
| Shopify POS, in-store loyalty | M7 |
| Refund and cancellation handling | M8 |
| Active / lapsed segmentation | M9 |
| Legacy customer migration | M10 |
| Klaviyo | M11 |
| Reporting and PDF exports | M12 |
| Admin console and roles | M13 |
| Audit and logging | M14 |
| Online loyalty, customer-facing | M15 |

---

### M1 — Customer and unified profile

**Confirmed.** D10 settled; MD1 changes the identity model.

**Functional scope.** One loyalty record per person, keyed to a Shopify
customer. Enrolment from the storefront, the till, the console and migration.
Identity resolution by email, then legacy card number, then postcode with
surname for human review. Profile fields the programme needs: birthday (day and
month, year optional), postcode, gender where OSC has populated the metafield,
legacy card number, enrolment channel and date. Duplicate detection with a merge
tool that replays both ledgers into one.

**Members with no email address (MD1).** An email is optional. Legacy members
without one migrate and must stay searchable at the till and in the console, so
`AccountResolver` and `MatchStrategy` carry a no-email path that matches on
**card number first, then postcode plus surname**. Nothing may assume an email
is present; `LoyaltyAccount::hasNoEmail()` makes the state explicit. The
`uq_account_email` index still forbids two members sharing one email, because
both MySQL and SQLite permit repeated NULLs in a unique index.

**Birthdays without a year (MD2).** `dob_month` and `dob_day` are the
authoritative fields and are directly writable. `date_of_birth` is optional and,
when present, populates them. Every enrolment form accepts a day and month with
no year.

**React.** `MemberSearch` (query, segment, channel filters, paginated),
`MemberProfile` header card, `ProfileEditForm`, `DuplicateReviewList`,
`MergePreview` (side-by-side balances and the resulting single ledger),
`EnrolMemberModal`. Search must accept a card number and a postcode, not only an
email.

**Laravel.** `AccountResolver`, `MatchStrategy` chain, `MergeService`,
`CustomerMetafieldWriter`, `MemberController`, `MergeController`,
`CustomersCreate` / `CustomersUpdate` handlers, `SyncCustomerProfile` job,
`LoyaltyAccount` model with normalisation on save.

**Tables.** `loyalty_accounts` (owner), `account_merges`; reads `loyalty_ledger`
for the merge replay; writes `loyalty_audit_log`.

**APIs.** `GET/POST /api/admin/members`, `GET/PATCH /api/admin/members/{id}`,
`GET /api/admin/members/duplicates`, `POST /api/admin/members/merge`,
`POST /api/admin/members/{id}/rebuild-cache`.

**Shopify GraphQL.** `customer(id:)`, `customers(query:)` for lookup and match;
`customerCreate` / `customerUpdate` for till and console enrolment;
`metafieldDefinitionCreate` once at install; `metafieldsSet` to publish loyalty
traits. Scopes `read_customers`, `write_customers`.

**Webhooks.** `customers/create` drives storefront enrolment;
`customers/update` keeps email, postcode, consent and the gender metafield in
step. Both queued, both idempotent on the Shopify webhook id.

**POS.** Search and in-store enrolment are consumed by M7, but the resolution
logic lives here so the till and the console can never disagree about who a
member is.

**External.** Migration (M10) is the fourth writer of accounts. Klaviyo (M11)
reads the profile for traits.

**Business rules.** Email is the primary identifier where present, normalised to
lower case and trimmed. Never create a duplicate silently (D10). Postcode and
surname support review, never a silent merge. A merge is one-directional and
audited; the source record is retained in a merged state, never deleted. Legacy
card numbers stay searchable indefinitely.

**Depends on.** Scope grant; D10; MD1; M2 for the ledger the merge replays.

**Testing.** Match-strategy table tests across exact email, case and whitespace
variants, card-only, postcode-plus-surname, and no match. **A no-email member is
findable by card and by postcode plus surname, and two of them coexist under the
unique index.** Merge test asserting the combined ledger sums to the two
originals and that balances are rebuilt rather than copied. Webhook idempotency
on repeated `customers/create`. A Viewer cannot merge.

---

### M2 — Loyalty core: ledger and rules engine

**Confirmed.** D1 and D2 settled.

**Functional scope.** The append-only ledger that is the single source of truth
for every balance; the versioned rule set every calculation is evaluated
against; balance derivation on read; lot tracking so expiry and consumption draw
from the right earnings; and a rebuild command that reconstructs every cached
total from the ledger alone.

**React.** `LedgerTable` (entry type, points, running balance, channel, staff,
order link, rule version), `RulesEditor` with per-field help text,
`RulesVersionHistory` and `RulesDiff`, `BalanceSummaryCard`.

**Laravel.** `LedgerService::post()` as the only write path,
`BalanceCalculator`, `LotAllocator` (FIFO), `RuleSetResolver::asAt()`,
`RulesVersionRepository`, `RuleSchema`, `loyalty:rebuild-balances` and
`loyalty:verify-ledger`, `RulesController`.

**Tables.** `loyalty_ledger`, `loyalty_lot_allocations`,
`loyalty_rules_versions`; updates the cached columns on `loyalty_accounts`.

**APIs.** `GET /api/admin/members/{id}/ledger`,
`POST /api/admin/members/{id}/adjustments`, `GET /api/admin/rules`,
`GET /api/admin/rules/versions/{version}`, `POST /api/admin/rules`.

**Shopify GraphQL.** Only `collection` and `productVariants` lookups, to resolve
eligible and excluded item rules into names the editor can display.
`read_products`, already held.

**Webhooks.** None of its own. M3, M7 and M8 post into it.

**POS.** No POS surface. The till reads balances through M7.

**External.** None.

**Business rules.** Append-only: a correction is a new row, never an edit — the
model refuses updates and deletes outright. Every entry carries an idempotency
key, unique per shop, so a redelivered webhook cannot double-post. Every entry
records the rule version in force, and a transaction is always evaluated against
that version; rule changes are never retrospective. Money in pence as integers,
points as integers. Cached balances are disposable and the ledger wins.
**D1:** the setting labelled voucher expiry configures points expiry.
**D2:** the expiry clock starts at availability, so `expires_at` is derived from
`matures_at`.

**Depends on.** D1, D2. Nothing else — no Shopify dependency beyond the held
scope, which is why it is built first.

**Testing.** Balance derivation across the Blueprint worked example (190
available points → £5, 90 points retained). Idempotency on a repeated key.
Rebuild test: a randomised ledger of ten thousand entries, caches zeroed,
rebuild, assert equality. As-at rule resolution where a transaction dated before
a rule change uses the older version. Property test that the sum of ledger
deltas equals the cached balance after every operation. Append-only enforcement.

---

### M3 — Points earning and item qualification

**Confirmed** mechanism; **OSC** D3 outstanding; **Open** Q1.

**Functional scope.** Earning on a paid order, online and in store, from the
qualifying merchandise value; recalculation when an order is edited; the pending
period and maturity to available; expiry of available points; and the item
qualification rules that decide what counts.

**React.** No screens of its own. Surfaces in `LedgerTable`, the
`EligibilityRuleFields` group inside the rules editor, and a `JobHealthCard` on
the overview showing last maturity and expiry runs.

**Laravel.** `EarningCalculator`, `QualifyingValueResolver`, `OrdersPaid` /
`OrdersUpdated` handlers, `CalculateEarningJob`, `MaturePointsJob` (hourly),
`ExpirePointsJob` (daily), `loyalty:replay-orders`.

**Tables.** Writes `loyalty_ledger` (earn, maturity, expiry) and
`webhook_events`; reads `loyalty_rules_versions`, `loyalty_accounts`.

**APIs.** No public endpoint. Exposed operationally through
`GET /api/admin/settings/health` and the replay command.

**Shopify GraphQL.** `order(id:)` with `lineItems` → `discountedTotalSet`,
`currentQuantity`, `product { id, tags, collections }`, plus `taxLines`,
`shippingLine`, `customer { id }`, `totalPriceSet`. The webhook payload is not
trusted for the arithmetic: the order is re-read so discount allocation and
current quantities are authoritative. `read_orders`.

**Webhooks.** `orders/paid` earns; `orders/updated` recalculates after an edit
and posts the difference as a new entry. **Validate:** Shopify also publishes a
dedicated `orders/edited` topic; confirm which carries the edit reliably before
Sprint 2 and register whichever is correct.

**POS.** A POS Pro sale becomes an ordinary Shopify order, so the same
`orders/paid` path earns in store. Per C7 this is also the offline recovery
path: the sale is recorded at the till and the points are added afterwards when
the webhook arrives.

**External.** Maturity emits the Klaviyo *Points available* event — never
earning, which is the whole point of the pending period.

**Business rules.** **D3 (pending):** the recommended earn base is line-item
totals after all discounts, excluding tax and shipping, rounded down per order.
Earning covers all qualifying items including sale and promotional stock.
Qualification is configured by collection or tag. Pending for thirty days, then
available; available expires six months after maturity (D2). One earn entry per
order, keyed `earn:{order_id}`. Orders with no member attached earn nothing and
are not retried.

**Depends on.** M1, M2, scope grant, D3, queue and scheduler.

**Testing.** Fixture-driven earning tests: mixed eligible and excluded lines,
order-level discount allocation, tax-inclusive pricing, shipping present,
zero-value lines, multi-currency guard. Idempotency on duplicate `orders/paid`.
Out-of-order delivery where `orders/updated` precedes `orders/paid`. Maturity
and expiry boundaries at exactly thirty days and six months, across a
daylight-saving change.

---

### M4 — Voucher and reward engine

**Confirmed.** D1 settled; MD7 confirms migrated balances arrive as points.

**Functional scope.** Two distinct things behind one customer-facing idea. The
**derived balance**: available points divided by the threshold, floored,
multiplied by the voucher value, computed on read and never stored as an issued
object. The **issued reward**: a real record with a value, issue date, expiry
and state, used by the birthday reward and by goodwill vouchers.

**React.** `VoucherBalanceCard` (balance, points to the next increment),
`RewardList` with state pills, `IssueGoodwillModal`, `CancelRewardModal` (reason
mandatory), `ReissueRewardAction`.

**Laravel.** `BalanceCalculator::voucherBalance()`, `RewardIssuer`,
`RewardStateMachine`, `RewardsController`, `ExpireRewardsJob` (daily),
`PublishBalanceMetafieldJob`.

**Tables.** `loyalty_rewards` (owner); reads `loyalty_ledger` for the derived
balance; writes `loyalty_audit_log`.

**APIs.** `GET /api/admin/members/{id}/rewards`, `POST /api/admin/rewards`,
`POST /api/admin/rewards/{id}/cancel`, `POST /api/admin/rewards/{id}/reissue`.

**Shopify GraphQL.** `metafieldsSet` to publish `voucher_balance_pence`,
`member_status` and `segment` onto the customer — the only way the discount
function and the account page can see a balance. **Validate V4.**

**Webhooks.** None.

**POS.** The till reads the derived balance and any outstanding issued reward
through M7, and may redeem either.

**External.** Emits *Voucher balance increased* and *Birthday reward issued*.

**Business rules.** Balance is `floor(available / threshold) * value`, launch
values 100 points and £5; the remainder stays on the account. **D1:** the
derived balance has no expiry of its own. Only genuinely issued objects live in
`loyalty_rewards`. **MD7:** migration posts points, never vouchers — a migrated
member's balance is derived on exactly the same terms as anyone else's. An
issued reward is never partially redeemed. Cancellation and reissue are separate
audited actions, Manager and above. The published metafield is a cache,
republished whenever the balance changes.

**Depends on.** M2, D1, `write_customers`, V4.

**Testing.** Derivation across threshold boundaries (99, 100, 199, 200 points).
An expiry that drops available below a multiple reduces the balance without
touching any reward row. Reward state-machine tests for every legal and illegal
transition. Metafield publish asserted once per balance change, not per ledger
entry.

---

### M5 — Birthday rewards

**Confirmed.** MD2 and MD8 apply.

**Functional scope.** A £10 reward issued once a year to each eligible member,
with its own expiry, redeemable online and at the till, reported on separately.

**React.** Appears in `RewardList` and the Birthday rewards report. No screen of
its own.

**Laravel.** `BirthdayEligibility`, `IssueBirthdayRewardsJob` (daily),
`RewardIssuer` shared with M4.

**Tables.** `loyalty_rewards`, unique on account + type + birthday year; reads
`dob_month` / `dob_day` on `loyalty_accounts`.

**APIs.** Read through the M4 reward endpoints. Issuing is automatic.

**Shopify GraphQL.** None beyond the metafield publish M4 performs.

**Webhooks.** None.

**POS.** Redeemable at the till as a fixed-amount discount through M7,
identically to a derived-balance redemption but consuming the reward.

**External.** *Birthday reward issued* to Klaviyo, which owns the message.

**Business rules.** One reward per member per calendar year, enforced by the
unique constraint rather than by job bookkeeping, so a re-run cannot
double-issue. **MD2:** eligibility reads `dob_month` and `dob_day` and never
`date_of_birth`, so a member who gave only a day and month is eligible on
identical terms; a member with no birthday at all is skipped and counted in the
job summary. **MD8:** the client accepts possible duplicate birthday vouchers in
year one, so no migration-year suppression is written. Value and expiry come
from the rule version in force on the issue date. Whether lapsed members receive
it is C4, pending.

**Depends on.** M1 (birthday on the profile), M4, scheduler.

**Testing.** Job run twice on the same day issues once. **A member with a day
and month but no birth year receives the reward.** A member with no birthday is
skipped, not failed. Leap-day handling. Reward expires on schedule and shows as
expired in the report. Boundary across the year change.

---

### M6 — Online redemption

**Confirmed** mechanism (D5); **Validate** V4, V5; **Open** Q2.

**Functional scope.** Applying voucher value to an online order through a
discount function, so the caps hold even when the basket changes after the
discount is calculated; re-validating on payment; and posting the redemption to
the ledger with a snapshot of the numbers that justified it.

**React.** No admin screen. The redemption appears in `LedgerTable` and
`RedemptionList` on the member profile, each row linking to the Shopify order.
The customer-facing surface is M15.

**Laravel.** `QuoteService` (the ordered limit ladder), `FunctionGateway`
implementing `RedemptionGateway`, `PublishRedemptionContextJob`,
`ConfirmRedemptionOnPaid` inside the `orders/paid` handler,
`RedemptionController`.

**Tables.** `loyalty_redemptions` (owner, with the validation snapshot),
`loyalty_ledger`, `loyalty_audit_log`.

**APIs.** `POST /api/account/redemptions/quote` and `GET /api/account/loyalty`
for the customer surface; `GET /api/admin/redemptions` and
`POST /api/admin/redemptions/{ref}/reverse` for the console.

**Shopify GraphQL (V5 resolved, validated against the 2026-07 schema).**
`discountAutomaticAppCreate` creates the app discount that runs the function,
identified by **`functionHandle: "voucher-discount"`** — the extension handle.
`functionId` is deprecated, and using the handle means **no `shopifyFunctions`
lookup is needed at install at all.** `discountClasses: [PRODUCT, ORDER]`;
`combinesWith` set for all three classes. `discountAutomaticAppUpdate`,
`discountAutomaticActivate` and `discountAutomaticDeactivate` for lifecycle;
`metafieldsSet` for the balance the function reads. Scopes **`write_discounts`
and `read_discounts`** — the mutation requires both. The validated operation is
recorded in `DECISIONS.md` under V5.

**Webhooks.** `orders/paid` confirms the redemption: the app compares the
discount actually applied against the quote, posts the ledger entry for what
really happened, and raises an audit exception on any mismatch.
`orders/cancelled` and `refunds/create` hand over to M8.

**POS.** Deliberately separate — function-based discounts are a checkout
mechanism and the till is not checkout. Both write through the same
`RedemptionGateway`, so the ledger cannot tell them apart.

**External.** None.

**Business rules.** The redeemable amount is the smallest survivor of an ordered
ladder: derived voucher balance → eligible subtotal (D8) → configured per-order
cap → must stay below basket value → minimum basket check → round down to the
voucher increment. The Blueprint worked example (£100 basket, £60 eligible, £50
balance) yields £50. Points are deducted only for the value actually redeemed. A
redemption is *quoted* until an order is paid, then *confirmed*; an unconfirmed
quote expires without consuming points. The validation snapshot is immutable.

**Depends on.** M2, M4, `write_discounts`, D8, C1, V4, V5, app released.

**Testing.** Function unit tests via `shopify app function run` against fixture
inputs: basket below the minimum, all items excluded, balance above the cap,
basket value below the voucher value, empty metafield, malformed attribute.
Ladder tests in PHP against the **same shared JSON fixture file**, so the two
implementations cannot drift. End-to-end: quote → checkout → paid → ledger entry
matches the discount on the order. Mismatch test where the basket changes
between quote and payment.

---

### M7 — Shopify POS and in-store loyalty

**Confirmed** mechanism (D6); C7 and MD10 settled; C9 pending; **Risk** R4.

**Functional scope.** A smart-grid tile that lets a till assistant find a
member, see their balance, enrol a new member, attach the member to the sale,
apply a redemption in £5 increments, and void it if the sale is abandoned — with
the store and staff member recorded on everything.

**Not in scope pending C9.** Manual point adjustments and voucher overrides at
the till. The migration discovery document implies these; the current design
places adjustments in the console only. **No POS adjustment endpoint is built
until C9 is confirmed.**

**React.** POS UI extension, not the admin app. `Tile.jsx` at
`pos.home.tile.render` showing the attached member and balance; `Modal.jsx` at
`pos.home.modal.render` hosting `MemberSearch`, `MemberCard`, `EnrolForm`,
`RedeemStepper` (£5 steps to the validated maximum) and `ConfirmSheet`. POS
component set, never Polaris. Enrolment accepts a day and month with no year
(MD2); search accepts a card number for members with no email (MD1).

**Laravel.** `PosSessionToken` middleware, `Pos\MemberController`,
`Pos\RedemptionController`, `PosGateway` implementing `RedemptionGateway`,
`StaffAttributionResolver`, dedicated CORS for the extension origin, throttle on
search.

**Tables.** `loyalty_redemptions` (channel `pos`, with location and staff
reference), `loyalty_ledger`, `loyalty_accounts`, `loyalty_audit_log`.

**APIs.** `GET /api/pos/members/search`, `GET /api/pos/members/{id}`,
`POST /api/pos/members`, `POST /api/pos/redemptions/quote`,
`POST /api/pos/redemptions/confirm`, `POST /api/pos/redemptions/{ref}/void`.

**Shopify GraphQL.** `customerCreate` for in-store enrolment;
`customers(query:)` for name and email search; `location(id:)` to name the
store. `write_customers`, `read_customers`, `read_locations`.

**Webhooks.** Relies on `orders/paid` to confirm the sale and settle the
redemption, exactly as online.

**POS.** POS Pro required. Cart API `applyCartDiscount(type, reason, amount)`
with the redemption reference in the reason string; Session API for staff
member, location and shop; session token for authenticated calls; Toast for
confirmation. **Validate V7** (Scanner API for the card barcode), **V8**
(extension API version alignment).

**External.** In-store enrolment emits *Joined Privilege Club*.

**Business rules.** The app validates the amount **before** the tile offers it;
the stepper never shows a value the backend has not approved. Staff attribution
comes from the POS session, never from anything the assistant types. A quote not
confirmed within a configured window is voided automatically. Redemption at the
till obeys the same ladder as online. Enrolment follows D10 and MD1.
**C7 confirmed:** offline, the tile shows no balance and offers no redemption;
the sale is recorded and points are added afterwards from the webhook. Offline
redemption is explicitly ruled out.

**Depends on.** M1, M2, M4, M6, POS Pro, app released, scope grant, C9 for any
adjustment capability.

**Testing.** Extension component tests for stepper bounds, empty and error
states. Backend tests for session-token verification, CORS preflight, role and
location resolution, quote/confirm idempotency, void-after-confirm refused.
**Device testing on the MD10 configuration: iPad 10th generation, POS app
11.11.1, single POS Pro store.** Cases in section 10.

---

### M8 — Refunds and cancellations

**OSC** D9 and Q3 outstanding.

**Functional scope.** Reversing earned points when an order is refunded or
cancelled, restoring redeemed points on the same basis, and doing both as new
ledger entries so history is never rewritten. Partial refunds are the normal
case.

**React.** Reversal and restoration entries appear in `LedgerTable` with the
refund reference; the profile shows a `NegativeBalanceBanner` when a clawback has
taken available points below zero.

**Laravel.** `ReversalCalculator`, `RefundsCreate` and `OrdersCancelled`
handlers, `ProcessReversalJob`, reusing `LedgerService` and `LotAllocator` so
reversals draw from the lots the earn created.

**Tables.** `loyalty_ledger`, `loyalty_lot_allocations`; updates
`loyalty_redemptions.state`; writes `loyalty_audit_log`.

**APIs.** No public write endpoint — reversals are event-driven.

**Shopify GraphQL.** `order(id:)` with
`refunds { refundLineItems { lineItem { id }, quantity, subtotalSet } }` and
`transactions`. The webhook payload is a trigger, not the arithmetic input.
`read_orders`.

**Webhooks.** `refunds/create` drives proportional reversal and restoration;
`orders/cancelled` drives full reversal. Both idempotent, both queued.

**POS.** An in-store return produces the same `refunds/create`. A refund against
a till redemption reconciles through the shared reference.

**External.** No Klaviyo event on reversal.

**Business rules.** **D9 (pending):** reverse earned and restore redeemed points
in proportion to the refunded eligible value, both as new entries. Reversal draws
from pending first, then available. Cancellation before maturity reverses the
pending entry in full. A restored redemption reopens points for future use but
does not resurrect a consumed issued reward without a Manager action. **Q3
(pending):** whether a clawback may push a balance below zero.

**Depends on.** M3, M6, M7, D9, Q3, `read_orders`.

**Testing.** Full refund; single-line partial; excluded line only; shipping
only; after maturity; after the points were spent; refund of an order that used
voucher value; two partial refunds summing to the whole. Idempotency on
redelivery. No ledger row is ever updated or deleted.

---

### M9 — Active and lapsed segmentation

**Confirmed** mechanism; **OSC** D7 outstanding; **Platform** limitation.

**Functional scope.** Recalculating each member as Active or Lapsed nightly,
from whether they have spent qualifying money in the last two years, and
publishing the result where the console, the reports and Klaviyo can use it.

**React.** Segment pill on `MemberProfile`, segment filter on `MemberSearch`,
the Active/Lapsed split on the Total members report.

**Laravel.** `SegmentCalculator`, `LastSpendResolver` (ledger first, then the
migrated last-spend date, then Shopify order history only if the protected scope
arrives), `RecalculateSegmentsJob` (nightly), chunked over accounts.

**Tables.** Updates `loyalty_accounts.segment` and `last_qualifying_spend_at`;
reads `loyalty_ledger`, `migration_records.last_spend_at`.

**APIs.** Read through the member and report endpoints. No write endpoint —
segmentation is derived, not assigned.

**Shopify GraphQL.** `orders(query:)` filtered by customer and date, and
`bulkOperationRunQuery` for a historical sweep — both limited to the permitted
window unless `read_all_orders` is approved. `metafieldsSet` publishes the
segment.

**Webhooks.** No dedicated topic. An `orders/paid` for a member updates the
last-spend date immediately, so a member never shows lapsed the day after buying
something.

**POS.** In-store spend counts identically, since it arrives as an order.

**External.** The segment is one of the four Klaviyo traits.

**Business rules.** **D7 (pending):** build from the ledger plus the migrated
last-spend date so launch does not depend on the protected scope. The
consequence is stated rather than hidden: segments are accurate for migrated
members and for activity after go-live, but blind to Shopify orders placed
between the export and launch. The two-year window is measured from the job run
date. A member with no recorded spend is `unknown`, not `lapsed`, until
migration or a first order settles it.

**Depends on.** M3, M10, D7, scheduler. `read_all_orders` is an enhancement,
never a dependency.

**Testing.** Boundaries at exactly two years, one day inside, one day outside. A
member with only migrated history; only post-launch history; neither.
Performance over fifty thousand accounts inside the nightly window. Safely
re-runnable with an identical result.

---

### M10 — Legacy customer migration

**OSC** D4 settled; MD1–MD10 apply; **Risk** R6.

**Functional scope.** The eight-stage pass: profile the export, map fields,
stage every raw row, match against Shopify customers, dry run with full counts
and an exception report, import once OSC signs off, take a delta at cutover,
reconcile. The measure of success is that no member has to do anything.

**React.** `BatchList`, `UploadBatchForm` (file plus mode), `ProfileReport`
(field completeness, duplicate emails, malformed postcodes, missing birthdays),
`DryRunSummary` (create / attach / combine / marketing-only / review / exception
counts), `ExceptionTable` with per-row resolution, `CommitBatchDialog` with a
typed confirmation.

**Laravel.** `Profiler`, `RowMapper`, `Matcher` (delegating to the M1 strategy
chain), `DryRunner`, `Importer`, `ExceptionWriter`, `MigrationController`,
`ProcessMigrationBatchJob` (chunked, resumable), `loyalty:migrate`.

**Tables.** `migration_batches`, `migration_records`, `migration_exceptions`;
writes `loyalty_accounts` and one opening-balance entry per member into
`loyalty_ledger`.

**APIs.** `POST /api/admin/migration/batches`,
`GET /api/admin/migration/batches`, `GET .../batches/{id}`,
`GET .../batches/{id}/exceptions`, `GET .../exceptions/export`,
`POST .../batches/{id}/commit`, `POST .../exceptions/{id}/resolve`.

**Shopify GraphQL.** `bulkOperationRunQuery` over customers to build the match
index once per batch rather than one query per row, then `currentBulkOperation`
and the JSONL result; `customerCreate` where a legacy member should exist in
Shopify; `metafieldsSet` to stamp the card number.

**Webhooks.** None. Migration is operator-driven.

**POS.** Legacy card numbers are stored and indexed so they stay searchable at
the till indefinitely. The physical card becomes optional the day migration
completes.

**External.** The Dynamics or ORD export: one file, plus one delta at cutover.
No connection to Dynamics is built.

**Business rules — client-confirmed 2026-08-06.**

| Ref | Rule |
| --- | --- |
| D4 | Each balance is imported as one `opening_balance` entry **dated at go-live**, with a configurable grace period defaulting to six months. |
| MD1 | Members with no email address import and stay searchable. Matching falls back to card, then postcode plus surname. |
| MD2 | A day and month with no birth year is a complete birthday for programme purposes. |
| MD3 | Two legacy rows with an **exact** email match are one person: **combine balances** into one account (`decision = combine`). The exception report is for **ambiguous** matches only. |
| MD4 | Unmatched migrated members start as **Not subscribed**. Matched members **keep their existing Shopify consent** — migration never overwrites it. |
| MD5 | Email-only contacts with no card and no membership become Shopify marketing contacts with **no loyalty account** (`decision = marketing_only`). |
| MD6 | Decimal balances round **down** to whole points. |
| MD7 | Balances carry over as **points**. No vouchers are auto-issued. |
| MD9 | No outstanding paper-voucher liability is imported. |

A dry run commits nothing. Import is idempotent on the batch checksum plus row
number, so a resumed run cannot double-post. Legacy data carries total spend but
no order-level history, so month-on-month and year-on-year reporting start
accumulating at go-live and cannot be backfilled.

**Depends on.** The export file with its field schema (an OSC receivable), D4,
D10, MD1–MD9, M1, M2. Profiling starts the day the file arrives, regardless of
sprint.

**Testing.** Anonymised sample fixtures covering every defect the profiler looks
for. **`RowMapper` fixture: `123.45 → 123` (MD6).** A dry run writes nothing to
`loyalty_accounts` or `loyalty_ledger`. Import then re-import produces identical
balances. **Two rows with the same email produce one account whose balance is
the sum of both (MD3).** **A `marketing_only` row creates no loyalty account and
no ledger entry (MD5).** **An unmatched member is Not subscribed; a matched
member keeps the Shopify value (MD4).** Delta run touches only changed rows.
Exception export matches the agreed format. Rollback on a failed mid-import
batch. Volume test at the real row count once known.

---

### M11 — Klaviyo

**Confirmed**; **Validate** V9; credentials are an OSC receivable.

**Functional scope.** Pushing five events and four profile traits. OSC owns
copy, branding and flow configuration; the app owns accuracy and timing.

**React.** `IntegrationStatusCard` (last successful push, failure count, retry
depth), `TestConnectionButton`, `SyncStateTable` filtered to failures.

**Laravel.** `KlaviyoClient` (keyed, revision-pinned, rate-limit aware),
`EventMapper`, `TraitSynchroniser`, `NullDriver`, `PushKlaviyoEventJob`,
`ReconcileKlaviyoJob` (nightly), `IntegrationsController`.

**Tables.** `klaviyo_sync_state` (owner); reads accounts, ledger, rewards.

**APIs.** `GET /api/admin/integrations/klaviyo`,
`POST /api/admin/integrations/klaviyo/test`,
`POST /api/admin/integrations/klaviyo/resync/{memberId}`.

**Shopify GraphQL.** None. Klaviyo is reached directly over HTTPS; consent state
comes from the customer record M1 syncs.

**Webhooks.** None inbound. Events are pushed, never polled.

**POS.** In-store enrolment fires *Joined Privilege Club* with the channel set
to in-store.

**External.** Klaviyo REST API: events, profile update, bulk profile import for
the migration backfill. Private key in configuration, never in the repository.
**Validate V9.**

**Business rules.** Five events only: *Joined Privilege Club*, *Points
available*, *Voucher balance increased*, *Expiry approaching*, *Birthday reward
issued*. **The pending gate belongs in the engine, not the flow** — *Points
available* fires on maturity, never on earning. *Voucher balance increased*
fires only when the derived balance crosses another increment. Traits: voucher
balance, member status, segment, outstanding rewards. A failed push is retried
with backoff and repaired by the nightly reconcile, and never fails a ledger
write. Migrated members respect MD4 consent defaults.

**Depends on.** Klaviyo credentials, M3, M4, M9, V9.

**Testing.** Contract tests against a recorded response set; no live calls in
CI. *Points available* cannot be emitted by an earn. Trait-hash test proving an
unchanged profile is not pushed twice. Simulated 429 retry. The null driver
keeps every other module fully functional with no credentials present.

---

### M12 — Reporting and exports

**Confirmed**; **Validate** V10; **Open** Q4.

**Functional scope.** Five reports, and only five — the Blueprint closes the set.
Each filterable by date range, channel, store, staff and gender where the
metafield is populated; each exportable as PDF and CSV; month-on-month and
year-on-year comparison once enough post-launch data exists.

**React.** `ReportShell` with a shared `FilterBar`, then `MembersReport`,
`SignupsReport`, `LoyaltyOrdersReport`, `ChannelComparisonReport`,
`BirthdayRewardsReport`; `ComparisonToggle`; `ExportMenu` with job progress.

**Laravel.** One query class per report, a `ReportRequest` filter object,
`CsvRenderer`, `PdfRenderer`, `GenerateExportJob`, `ReportsController`,
`ExportsController`.

**Tables.** Reads `loyalty_accounts`, `loyalty_ledger`, `loyalty_redemptions`,
`loyalty_rewards`; writes `report_exports`.

**APIs.** `GET /api/admin/reports/{members|signups|loyalty-orders|channels|birthday-rewards}`,
`POST /api/admin/reports/{key}/export`, `GET /api/admin/exports/{id}`.

**Shopify GraphQL.** Loyalty share of total sales needs a store-wide figure the
ledger does not hold. **Validate V10** — settle whether an order aggregate or
ShopifyQL reconciles with Shopify analytics, because a loyalty share that
disagrees with the admin dashboard is the first thing that will be questioned.

**Webhooks.** None.

**POS.** Store and staff filters exist so in-store performance is readable per
till and per assistant, from the attribution M7 records.

**External.** A pure-PHP PDF renderer (C8 pending), because hosting is outside
project scope and a headless-browser renderer would add a Chromium dependency to
the production box.

**Business rules.** Every figure derives from the ledger, so a report and a
member profile can never disagree. Money is formatted from pence at the
presentation edge only. Gender filtering applies only where the metafield is
populated; a blank value reports as unspecified rather than being excluded.
Comparisons are unavailable, and say so, for periods before go-live.

**Depends on.** M2, M3, M5, M6, M7, M9; D7 shapes the Active/Lapsed accuracy;
C5, C8, V10, Q4.

**Testing.** Hand-computed numeric fixtures per report. Filter combinations
including empty results. CSV byte-comparison against a golden file. PDF export
completes and contains the headline figures. Timezone test proving a date range
means the same thing in the report and the export. Query plan check at a
realistic row count.

---

### M13 — Admin console and roles

**Confirmed**; **Platform** limitation; C6 and C9 pending.

**Functional scope.** The embedded console that hands OSC control over money:
member lookup and history, adjustments, reward actions, rules screens, audit
viewer, migration screens, and the role assignment governing all of them.

**React.** The route table in section 2.3, plus `AppFrame` with navigation,
`RoleGate` (hides what a role may not do rather than failing after the click),
`ReasonRequiredForm`, `ConfirmDestructive`, `ErrorBoundary`, `EmptyState`.

**Laravel.** `EnsureStaffRole` middleware with a per-route floor,
`StaffRoleResolver`, policies per resource, `StaffController`,
`OverviewController`. Authorisation is enforced server-side on every endpoint;
the React gate is convenience, never the control.

**Tables.** `staff_roles` (owner); writes `loyalty_audit_log` on every
assignment.

**APIs.** `GET /api/admin/me`, `GET /api/admin/staff`,
`PUT /api/admin/staff/{staffId}`, `GET /api/admin/overview`,
`GET /api/admin/settings/health`.

**Shopify GraphQL.** Staff identity arrives in the session token, so there is no
second password. **Platform:** resolving it to a name needs `read_users`, which
is protected, so the app stores the identifier from the token and an
Administrator labels each staff member once — role management never waits on a
Shopify approval.

**Webhooks.** None.

**POS.** POS staff are the same identities. A till assistant needs no console
role to redeem, but every POS redemption is attributed and audited exactly as a
console action. **Whether a till assistant may adjust points is C9, pending.**

**External.** None.

**Business rules.** Four roles. *Viewer*: look up members, view history and
reports, change nothing. *Agent*: adjust points within a configured limit,
reason mandatory; no rule changes, no reward cancellation. *Manager*: issue,
cancel and reissue rewards, unrestricted adjustments; no rule changes.
*Administrator*: everything, including rules and role assignment. **C6
(pending):** the shop owner becomes Administrator on install; Sprint 1 proceeds
under that recommendation and only the identity of that person is needed.

**Depends on.** V1 (resolved), M14, and every module it exposes; C6, C9.

**Testing.** An authorisation matrix: every role against every write endpoint,
asserting the exact expected status. Session-token verification including an
expired token, wrong audience and missing signature. The Agent adjustment limit
enforced server-side, not only in the form. A role change takes effect on the
next request without a re-install.

---

### M14 — Audit and logging

**Confirmed.**

**Functional scope.** A searchable record of who did what and when —
adjustments, reward actions, rule changes, POS redemptions, merges, migration
commits, role assignments — written by the application and not editable from the
interface. Separately, the operational logging and alerting that makes a failed
job visible before a member notices.

**React.** `AuditSearch` (actor, action, subject, date range),
`AuditEntryDetail` showing before and after, `AuditTrailPanel` embedded in the
member profile.

**Laravel.** `AuditLogger` with a required reason for the actions that demand
one, an `Auditable` trait, `RequestContext` (staff, IP, request id, channel),
`AuditController`. Structured application logging with the request id on every
line, so an audit entry and a log line can be joined.

**Tables.** `loyalty_audit_log` (owner), `webhook_events` for delivery
forensics.

**APIs.** `GET /api/admin/audit`, `GET /api/admin/audit/{id}`. No write endpoint
exists at all — entries are only ever created in process.

**Shopify GraphQL.** None.

**Webhooks.** Every inbound delivery is recorded with its topic, Shopify webhook
id, HMAC result and processing outcome, which is what makes a late or duplicated
delivery diagnosable rather than mysterious.

**POS.** Every till redemption writes an entry carrying the location, the staff
member and the reference that also appears on the receipt.

**External.** Failed Klaviyo pushes are logged operationally, not into the audit
log — the audit log is for human actions.

**Business rules.** Append-only; the model refuses updates and deletes, and
production should withhold those grants from the application database user. A
reason is mandatory on adjustments, cancellations and merges, stored verbatim.
Entries are retained for the life of the programme.

**Depends on.** M13 for identity; every module writes into it.

**Testing.** Each sensitive action writes exactly one entry with the expected
actor and reason. An action refused by authorisation writes no entry. No code
path updates or deletes an entry. Search and pagination at realistic volume.

---

### M15 — Customer loyalty view

**Confirmed**; **Validate** V11.

**Functional scope.** Deliberately small: the voucher balance in the Shopify
customer account, and any outstanding birthday reward. Never points.

**React.** Customer account UI extension: `LoyaltyBlock` showing the balance,
the amount still to go to the next increment expressed in spend rather than
points, and an outstanding reward with its expiry.

**Laravel.** `AccountSessionToken` middleware, `Account\LoyaltyController`,
reusing `BalanceCalculator` so the number can never differ from the console.

**Tables.** Reads only.

**APIs.** `GET /api/account/loyalty` — balance, outstanding rewards, member
since, nothing else.

**Shopify GraphQL.** None at request time; the extension may read the published
metafield directly. **Validate V11** — this extension was not part of the D5/D6
spike, so confirm the target and that it deploys under
`use_legacy_install_flow = true`.

**Webhooks.** None. **POS.** Not applicable. **External.** None.

**Business rules.** Balance only. No points, no ledger, no history, no
redemption control (Q2). A customer with no loyalty account sees an enrolment
prompt, not an error.

**Depends on.** M4, V11, Q2.

**Testing.** Token verification asserting one customer cannot read another
balance. The response contains no points field at any nesting level — asserted
structurally, not by review. Non-member and zero-balance states.

---

## 4. Database design

**The migrations are the authoritative DDL:**
`web/database/migrations/2026_08_26_00000{1..6}_*`. This section records intent;
column-level truth lives there and is verified by `migrate` on MySQL 8.4 and by
the test suite on SQLite.

### 4.1 Conventions

- `BIGINT UNSIGNED AUTO_INCREMENT` ids; `DATETIME(3)` in UTC.
- Every root table carries `shop_domain`. The app installs per shop, and a
  scoped index means a second store can never see the first one's ledger.
- Shopify identifiers stored as the numeric id, GID reconstructed when needed.
- Enumerations declared as `ENUM` for readability at the console; adding a member
  is a migration, deliberately.
- Foreign keys are `RESTRICT` on delete, never `CASCADE`. Nothing in a ledger
  should be removable by removing something else.
- `CHECK` constraints are applied on MySQL only — SQLite cannot add one with
  `ALTER TABLE`, and the suite runs on SQLite in memory.

### 4.2 The fifteen tables

| Migration | Tables | Holds |
| --- | --- | --- |
| `…000001` | `loyalty_rules_versions`, `loyalty_accounts` | Versioned rule snapshots; one row per member |
| `…000002` | `loyalty_rewards`, `loyalty_redemptions` | Issued rewards; the money side joined to the points side |
| `…000003` | `loyalty_ledger`, `loyalty_lot_allocations` | Every movement of points; which earning was consumed |
| `…000004` | `loyalty_audit_log`, `staff_roles`, `account_merges` | Who did what; permissions; merge record |
| `…000005` | `migration_batches`, `migration_records`, `migration_exceptions` | Legacy import staging and exception reporting |
| `…000006` | `klaviyo_sync_state`, `webhook_events`, `report_exports` | Integration state, delivery record, generated files |

### 4.3 Two decisions worth restating

**Two signed delta columns on the ledger, not one amount plus a state column.**
Points live in one of two buckets, and every movement is a transfer between them
or in and out of one. Balances are then `SUM(pending_delta)` and
`SUM(available_delta)` over immutable rows, which is what lets
`loyalty:rebuild-balances` reproduce a balance exactly rather than approximately.

**Lot allocations are rows, not a mutable column.** Expiry must know which
points are oldest and redemption must consume the oldest first. Because the
ledger is append-only, remaining lot life cannot be a column on the earn row, so
it is the lot total minus its allocations. Correctness and the audit trail come
from the same rows.

### 4.4 Schema effects of the client confirmations

| Ref | Change | Verified |
| --- | --- | --- |
| MD1 | `loyalty_accounts.email` and `email_normalised` nullable; `uq_account_email` retained | Two no-email members coexist; both findable by card and by postcode plus surname; a duplicate email is still rejected by `uq_account_email` |
| MD2 | `dob_month` / `dob_day` are real writable columns and the authoritative birthday; `date_of_birth` optional and populates them when present | Day/month-only birthday persists with `date_of_birth` NULL; a full date populates both fields, leap day included |
| MD5 | `migration_records.decision` extended with `combine` and `marketing_only` | Enum confirmed on MySQL |
| D1 | `loyalty_rewards` holds issued objects only; no stored voucher balance | By design |
| D2 | Earn entries carry both `matures_at` and `expires_at`, the latter derived from the former | By design |

---

## 5. Laravel API specification

All routes live in `routes/web.php` alongside the existing OAuth and webhook
routes, grouped by prefix and middleware. There is no public unauthenticated
loyalty endpoint. `/api/health` remains as it is.

### 5.1 Authentication schemes

| Scheme | Prefix | Middleware | Establishes |
| --- | --- | --- | --- |
| A — Admin | `/api/admin` | `shopify.auth`, `staff.role:{floor}` | Shop, staff id from the token subject, role from `staff_roles` |
| B — POS | `/api/pos` | `pos.session`, `throttle:pos` | Shop, staff id, location id from the POS session claims |
| C — Customer | `/api/account` | `account.session` | Shop and customer id; a customer reads only their own record |
| D — Webhook | `/api/webhooks` | HMAC in the controller | Shop and topic; existing, unchanged |

All three token schemes verify the same way: a JWT signed with the app secret,
checked for signature, expiry, not-before, issuer and an audience equal to the
app client id, with the shop taken from the destination claim rather than any
request parameter. A verified token is never trusted for a role — the role is
always read from the database.

### 5.2 Conventions

- **Envelope.** Success returns the resource, or
  `{ "data": [...], "meta": { "page", "per_page", "total" } }` for collections.
  Failure returns `{ "error": { "code", "message", "details": {} } }`.
- **Money and points.** Every monetary field is an integer of pence with a
  `_pence` suffix. Points are integers. No endpoint accepts or returns a decimal.
- **Idempotency.** Every endpoint that moves points or money accepts an
  `Idempotency-Key` header; a repeat returns the original result with `200`.
- **Validation.** Form request classes, before any domain service is touched. A
  validation failure is `422` with per-field details; a business-rule violation
  the validator cannot express is `409` with a code.
- **Errors.** `401` unauthenticated, `403` role floor not met, `404` not found or
  not in this shop, `409` conflict, `422` validation, `429` throttled, `500`
  unexpected. A record belonging to another shop returns `404`, not `403`.
- **Audit.** Every write endpoint under scheme A or B writes an audit entry in
  the same transaction as its effect.

### 5.3 Sprint 1 endpoints

| Method and path | Purpose | Role | Notes |
| --- | --- | --- | --- |
| `GET /api/admin/me` | Role and permission set for the UI gate | Viewer | |
| `GET /api/admin/overview` | Members, today's activity, job health | Viewer | |
| `GET /api/admin/members` | Search and list | Viewer | `q` matches email, name, **card number and postcode** (MD1) |
| `POST /api/admin/members` | Enrol from the console | Agent | Email optional (MD1); day+month birthday accepted (MD2) |
| `GET /api/admin/members/{id}` | Profile with balances and segment | Viewer | |
| `PATCH /api/admin/members/{id}` | Correct profile fields | Agent | Email changes refused — it is the identifier |
| `GET /api/admin/members/{id}/ledger` | Full movement history | Viewer | Filters: type, from, to |
| `POST /api/admin/members/{id}/adjustments` | Manual adjustment, reason mandatory | Agent | `409 adjustment_limit_exceeded` for an Agent over limit |
| `POST /api/admin/members/{id}/rebuild-cache` | Recompute caches from the ledger | Manager | Returns before and after |
| `GET /api/admin/rules` | Current rule set plus version list | Viewer | |
| `GET /api/admin/rules/versions/{version}` | A historical rule set | Viewer | |
| `POST /api/admin/rules` | Save a new version | Administrator | Full snapshot only; partial payloads rejected |
| `GET /api/admin/audit` | Search the audit log | Manager | |
| `GET /api/admin/staff` | Staff and their roles | Administrator | |
| `PUT /api/admin/staff/{staffId}` | Assign a role, label a staff member | Administrator | Cannot remove the last Administrator |

The full inventory for later sprints (POS, customer account, reports, migration,
integrations — around 45 endpoints) is unchanged from the published plan and is
built with its module.

### 5.4 Console commands, which are part of the specification

| Command | Purpose |
| --- | --- |
| `loyalty:rebuild-balances [--member=]` | Rebuild every cached balance from the ledger |
| `loyalty:verify-ledger` | Assert cache and ledger agree; non-zero exit on drift, so cron can alarm on it |
| `loyalty:replay-orders --from= --to=` | Re-post earning for a date range after late or missed webhooks |
| `loyalty:migrate --file= --mode=` | The migration pipeline without the browser |
| `shopify:webhooks [--register]` | Existing command, extended to the full topic list |

---

## 6. Shopify integration matrix

### 6.1 Scopes — requested once

The classic install flow means changing scopes forces every merchant to
re-authorise, so the complete set goes into `shopify.app.toml` and
`config/shopify.php` together, in Sprint 1. Changing the app version also
revokes the stored access token, so re-authorising is part of the deploy
runbook, not an incident.

| Scope | Needed for | Modules | Status |
| --- | --- | --- | --- |
| `read_products` | Eligible and excluded item rules | M2, M3, M6 | Held |
| `read_customers` | Profile, lookup, deduplication | M1, M7, M10 | To add |
| `write_customers` | Loyalty metafields, till and console enrolment | M1, M4, M7 | To add |
| `read_orders` | Earning, reversals, reporting | M3, M8, M9, M12 | To add |
| `write_discounts` | Applying voucher value online | M6 | To add |
| `read_discounts` | Required alongside `write_discounts` by `discountAutomaticAppCreate` (V5) | M6 | To add |
| `read_locations` | Store attribution on POS activity | M7, M12 | To add |
| `read_all_orders` | Two-year spend history | M9 only | Protected — apply immediately (D7), never depend on it |
| `read_users` | Staff names | M13 | Protected — **not requested**; the console labels staff instead |

### 6.2 Webhooks

**No business topic may be declared in `shopify.app.toml`.** `shopify app
deploy` rejects the entire app version with *App-specific webhook subscriptions
are not supported when use_legacy_install_flow is enabled*, and that flag is a
hard constraint. Every topic is registered per shop through the Admin API from
`config('shopify.webhooks.topics')`. `shopify app dev` accepts declared topics
and resolves them against the tunnel, so a broken configuration works all
through development and fails the first time anyone deploys. The privacy
compliance topics are the exception: they stay in the TOML, deploy cleanly, and
cannot be created through the Admin API at all.

| Topic | Drives | Module | Idempotency key |
| --- | --- | --- | --- |
| `orders/paid` | Earning; confirmation of a redemption | M3, M6, M7 | `earn:{order_id}` |
| `orders/updated` | Recalculation after an edit | M3 | `earn-adjust:{order_id}:{version}` |
| `orders/cancelled` | Full reversal | M8 | `cancel:{order_id}` |
| `refunds/create` | Proportional reversal and restoration | M8 | `refund:{refund_id}` |
| `customers/create` | Enrolment from the storefront | M1 | `enrol:{customer_id}` |
| `customers/update` | Profile and consent sync | M1, M9 | webhook id in `webhook_events` |
| `app/uninstalled` | Session cleanup — already built | Foundation | — |
| privacy topics ×3 | Compliance — already built | Foundation | — |

Every handler is queued, records the delivery in `webhook_events`, and returns
200 before doing work. Late, duplicated and out-of-order delivery is assumed:
the idempotency key makes a repeat a no-op, and `loyalty:replay-orders` repairs
a gap.

### 6.3 Metafields

App-owned customer metafields, defined once at install:
`voucher_balance_pence` (number_integer), `member_status`, `segment`,
`legacy_card_number`, `member_since` (date). OSC's existing `gender` definition
is mirrored for report filtering.

**Validate V4:** that an app-owned customer metafield is readable from a
discount function input query and from a customer account UI extension, and that
a cart attribute is available in the function input. If any fails, the fallback
is the alternative D5 mechanism, which the `RedemptionGateway` interface exists
to accommodate.

### 6.4 Discounts and extensions

| Channel | Mechanism | Created by | Status |
| --- | --- | --- | --- |
| Online | Function-backed automatic app discount, `cart.lines.discounts.generate.run`, Wasm | `discountAutomaticAppCreate` at install, by `functionHandle` (V5) | Spiked, deployable (D5); mutation shape validated |
| In store | POS Cart API `applyCartDiscount(type, reason, amount)` | The tile, after the app validates the amount | Confirmed (D6) |

| Extension | Targets | Module | Status |
| --- | --- | --- | --- |
| POS UI — loyalty tile | `pos.home.tile.render`, `pos.home.modal.render` | M7 | Scaffolded, deploys under the legacy flow; template code |
| Discount function — voucher | `cart.lines.discounts.generate.run` | M6 | Scaffolded, compiles to Wasm, accepted in a version; template code |
| Customer account UI — loyalty block | to be confirmed (V11) | M15 | **Not yet spiked** |

**V8 — done 2026-08-26.** The tile moved from `api_version = "2026-01"` to
`"2026-07"`; the unused `cart.delivery-options.discounts.generate.run` target was
removed along with its source, input query and three test fixtures; and two
guard tests in `ShopifyConfigurationTest` now assert that every extension
manifest matches the Admin API version and that the voucher function declares
only the cart-lines target.

### 6.5 Release and deployment dependencies

- The app has never been released. A release is required before any real install,
  or the POS extension and discount function do not exist outside
  `shopify app dev`. Two unreleased spike versions exist and should be
  superseded, not released.
- `shopify app deploy --no-release` validates a configuration change
  server-side without altering what merchants see, and belongs in the definition
  of done for every configuration change.
- `application_url` must be a real, stable HTTPS address before any deploy.
  `include_config_on_deploy` is on, so deploying with the scaffold placeholder
  publishes that placeholder and points every webhook at a dead address.
- A queue worker and the scheduler must run continuously in the live
  environment. The application is configured for a database queue and nothing
  runs it today. Hosting is outside project scope, which makes this an OSC
  dependency rather than a task.

---

## 7. POS integration plan

**Prerequisites.** POS Pro on every till running the tile (an OSC receivable);
app released; scopes `read_customers`, `write_customers`, `read_locations`; CORS
and CSP for the extension origin — a POS UI extension runs in a sandbox, not the
admin iframe, so `CspHeader` does not cover it and `/api/pos/*` needs its own
allowance.

**Device matrix (MD10).** One location: **iPad 10th generation, POS app 11.11.1,
a single POS Pro store.**

| Flow | At the till | Behind it |
| --- | --- | --- |
| Identify | Search by card number, email, phone or surname; pick from up to 20 matches showing balance and segment | `GET /api/pos/members/search` through the M1 chain, throttled per staff member. Card lookup is an exact index hit; surname search is last because it is the ambiguous one. Members with no email are found by card or postcode plus surname (MD1) |
| Enrol | Capture email (optional), name, postcode, birthday day and month, consent. An existing member is shown, not duplicated | `POST /api/pos/members` creates the Shopify customer and the loyalty account in one transaction, channel `pos`, and fires *Joined Privilege Club* |
| Attach | The member is set on the sale and the tile shows their balance | The POS Customer API sets the customer on the cart, which is also what makes `orders/paid` arrive with a customer attached |
| Redeem | A stepper offers £5 increments up to a maximum. Confirm, and the discount appears with a reference on the receipt | `quote` returns the approved maximum and a reference; the tile calls `applyCartDiscount` with the reference in the reason; `confirm` records it. Points are consumed only when `orders/paid` arrives |
| Abandon | The customer changes their mind | `void` releases the quote. An unvoided quote expires on its own |

**Attribution.** Staff member, location and shop come from the POS session,
never from anything the assistant types, and are written onto the redemption, the
ledger entry and the audit entry. That is what makes the per-store and per-staff
report filters meaningful.

**Offline (C7, confirmed).** Record the sale; points are added afterwards via the
webhook; **no offline redemption.** The tile shows no balance and offers no
redemption, failing visibly rather than spinning. Earning is unaffected because
it is driven by `orders/paid` once connectivity returns.

**Not built pending C9.** Adjustments or voucher overrides at the till.

**Test cases.** Search by card, email, phone, surname. Enrol with an existing
email. Enrol with no email at all. Enrol with a day and month birthday. Redeem
the maximum on a mixed eligible and excluded basket. Redeem, add an item, redeem
again. Abandon after applying. Network drop between quote and confirm. Refund a
POS sale that used a redemption. Two tills, same member, at once.

---

## 8. External integration plan

**Klaviyo.** Outbound only; events and traits pushed, nothing polled. Private
API key in configuration with a pinned revision header (V9). Per-endpoint rate
limits honoured with `Retry-After` and exponential backoff. A failed push never
fails a ledger write — it lands in `klaviyo_sync_state` with a retry time and is
repaired by the nightly reconcile. A null driver keeps every other module
functional with no credentials. OSC owns flow copy, branding and creative.

**Dynamics / ORD export.** A file, uploaded through the console or passed to
`loyalty:migrate`. No connection to Dynamics is built. One full export rehearsed
as a dry run, then one delta at cutover. Field types, completeness, duplicate
emails, malformed postcodes and missing birthdays are unknown until the file
arrives; the profiler exists to answer that before anything is committed. Total
spend per member but no order-level history, so month-on-month and year-on-year
reporting start accumulating at go-live and cannot be backfilled.

**PDF rendering.** No library today. Recommendation: pure PHP, self-contained,
with the report layout designed to what it renders well. Rendering runs in a
queued job with the result in `report_exports`, because a two-year report is not
a request-cycle operation. C8 pending.

**Nothing else.** No email delivery from the app (Klaviyo owns messaging), no
SMS, no address-validation service, no analytics beyond Shopify, and no card
production or postal fulfilment of any kind.

---

## 9. Module dependencies

```
Foundation ──► M2 Ledger, rules ──► M3 Earning ──► M6 Online redeem ──► M12 Reporting
     │              │                   │              │
     ├──► M1 Profile ┤                   ├──► M8 Refunds │
     │              └──► M4 Voucher ────┼──► M7 POS ────┘
     │                     │             └──► M9 Segments ──► M11 Klaviyo
     └──► M13/M14 Console, audit         ├──► M5 Birthday
                    M1 ──► M10 Migration ─┘
                          M4 ──► M15 Account view
```

The critical path is Foundation → ledger → earning → online redemption →
reporting. Everything else can slip a sprint without stopping a demonstration;
nothing on that path can.

| Module | Depends on modules | Decisions | External |
| --- | --- | --- | --- |
| M1 | — | D10, MD1 | `read_customers`, `write_customers` |
| M2 | — | D1, D2 | — |
| M3 | M1, M2 | D3, D2 | `read_orders`, queue and scheduler |
| M4 | M2 | D1, MD7 | `write_customers`, V4 |
| M5 | M1, M4 | MD2, MD8, C4 | scheduler |
| M6 | M2, M4 | D8, C1 | `write_discounts`, app released, V4, V5 |
| M7 | M1, M2, M4, M6 | D8, C7, C9, MD10 | POS Pro, app released, V7, V8 |
| M8 | M3, M6, M7 | D9, Q3 | `read_orders` |
| M9 | M3, M10 | D7 | scheduler; `read_all_orders` optional |
| M10 | M1, M2 | D4, D10, MD1–MD9 | **the export file** |
| M11 | M3, M4, M9 | MD4 | **Klaviyo credentials**, V9 |
| M12 | M2, M3, M5, M6, M7, M9 | D7, C5, C8, Q4 | V10 |
| M13 | M14, all | C6, C9 | V1 (resolved) |
| M14 | M13 | — | — |
| M15 | M4 | Q2 | V11 |

---

## 10. Testing strategy

A test exists where a mistake would otherwise be silent and expensive.

| Layer | Tooling | Catches |
| --- | --- | --- |
| Unit — arithmetic | PHPUnit, table-driven from shared JSON fixtures | Earn base, maturity and expiry boundaries, balance derivation, the redemption ladder, proportional reversal |
| Unit — rules | PHPUnit | Schema validation, as-at version resolution, non-retrospective rule changes |
| Integration — webhooks | Feature tests with signed payloads | HMAC, dispatch, idempotency, out-of-order arrival, unknown topics |
| Integration — database | Feature tests | The unique constraints enforcing D10 and once-a-year birthdays; nullable email under a unique index (MD1); append-only enforcement |
| API | Feature tests per endpoint | Validation, error codes, envelope shape, idempotency headers, full role-by-endpoint authorisation matrix |
| Contract — Shopify | Recorded GraphQL responses | Query shape, error handling, throttle backoff, bulk polling. No live calls in CI |
| Function | `shopify app function run` with fixtures | The ladder as enforced in Wasm, including empty and malformed inputs |
| Extension | Component tests plus the MD10 device matrix | Stepper bounds, empty and error states, offline behaviour |
| Configuration guard | Extended `ShopifyConfigurationTest` | No declared webhook subscription; scopes match the TOML; API versions aligned across app and extensions |
| Migration | Fixtures from anonymised sample rows | Every defect the profiler claims to find; a dry run writes nothing; a re-import is idempotent; MD3–MD6 behaviours |
| Reporting | Hand-computed fixtures, golden CSV | Figures, filters, empty ranges, timezone boundaries, comparison refusal before go-live |
| Non-functional | Seeded volume tests, query plan assertions | Ledger at a projected two-year size; POS search latency; the nightly sweep inside its window |
| Security | Feature tests | Token forgery and audience mismatch, cross-shop access, cross-customer access, role escalation, IDOR |

**The shared fixture is the most important test artifact in the project.** The
redemption ladder is implemented twice — PHP for the quote, Wasm for the
function — and two implementations of one rule will drift. So the fixtures are a
single JSON file of cases and expected outcomes consumed by both suites, and
changing the rule fails whichever side was not updated.

### Arithmetic cases that must pass before any money moves

| Case | Input | Expected |
| --- | --- | --- |
| Derivation, Blueprint example | 190 available points | £5.00, 90 retained |
| Derivation, below threshold | 99 available points | £0.00 |
| Derivation, exact multiple | 200 available points | £10.00, 0 retained |
| Ladder, Blueprint example | Basket £100, eligible £60, balance £50, cap £50 | £50.00 |
| Ladder, eligible subtotal binds | Basket £100, eligible £20, balance £50 | £20.00 |
| Ladder, must stay below basket | Basket £20, eligible £20, balance £50 | £15.00 after rounding |
| Ladder, minimum basket unmet | Basket £10, minimum £25 | £0.00 with a reason |
| Earning, D3 base | Two lines, one excluded, order discount, tax and shipping | Points from post-discount eligible value only, rounded down |
| Maturity boundary | Paid 23:59, pending 30 days | Available at the same instant plus 30 days, across a clock change |
| Expiry boundary | Available since exactly the expiry period | Expired once, with its own entry |
| Reversal, partial refund | Half the eligible value refunded after redemption | Proportional reversal and restore, two new entries |
| Reversal below zero | Points already spent, full refund | Per Q3: full reversal, balance negative, voucher value floored at zero |
| **Migration, decimal balance (MD6)** | `123.45` in the export | `123` points |
| **Migration, duplicate email (MD3)** | Two rows, identical email, balances 100 and 250 | One account, 350 points |
| **Migration, marketing only (MD5)** | Email-only row, no card, no membership | No loyalty account, no ledger entry |

### Gates

- **Every pull request:** full PHPUnit suite, Pint, the configuration guard
  tests, and the shared-fixture suite on both implementations.
- **Every configuration change:** `shopify app deploy --no-release`.
- **Every sprint:** the exit criteria in section 11, demonstrated on the
  development store rather than described.
- **Before go-live:** the sterling-store rounding pass (R3), the volume pass
  (V2), the MD10 device matrix, migration dry-run sign-off, and a UAT script per
  role.

### Risks carried

| Ref | Risk | Handling |
| --- | --- | --- |
| R1 | `shopify app dev` accepts configuration that `deploy` rejects | `deploy --no-release` in the definition of done; guard tests assert the rules already broken twice |
| R2 | `read_all_orders` approval outside our control | Segmentation built without it (D7); the consequence is stated to OSC |
| R3 | Dollar development store, sterling rules | Money in minor units, currency configurable, a currency guard on rule saves, a rounding pass against a sterling store before sign-off |
| R4 | A till loses connectivity mid-transaction | C7: record the sale, no offline redemption, points added from the webhook |
| R5 | Webhooks late, duplicated, out of order | Idempotency key on every entry, `webhook_events`, `loyalty:replay-orders` |
| R6 | Legacy data quality unknown until the export arrives | The dry run surfaces it before anything is committed; profiling starts the day the file lands |
| R7 | Two implementations of the ladder drift | One shared fixture file consumed by both suites |
| R8 | No queue worker or scheduler in production | Named as an OSC dependency; the health endpoint makes a stopped scheduler visible |
| R9 | Eleven weeks assumes four parallel tracks | Capacity assumption stated; descope order agreed in advance; migration timing depends on a receivable |

---

## 11. Eleven weeks, five sprints

**Capacity assumption.** Eleven weeks for Blueprint phases P3–P12 works with
four parallel tracks: two backend, one frontend and extensions, one data and QA.
Single-threaded, the same scope is roughly eighteen to twenty weeks. Sprint 5 is
the compressed one, and migration cannot start until the export arrives. If
capacity is smaller, the descope order is: month-on-month and year-on-year
comparisons, then M15, then the merge tool UI in favour of the console command.
Nothing on the critical path can be descoped without changing what the programme
is.

### Week zero — mostly not development

- OSC responses to the remaining decisions: **D3, D7, D8, D9** and **C1–C6, C8,
  C9**.
- Submit the `read_all_orders` application. Nothing waits on it.
- Request the receivables: POS Pro, Klaviyo credentials, the export file with its
  field schema, the gender metafield on both stores, domain details.
- Close **V1** (done), **V3**, **V5**, **V8**.

### Sprint 1 — weeks 1–2 — the ledger exists

| Track | Work |
| --- | --- |
| Backend | All fifteen tables and migrations. `LedgerService`, `BalanceCalculator`, `LotAllocator`, rules versioning and as-at resolution, the rebuild and verify commands. Full scope set in the TOML and config, re-authorisation performed. Audit logger and staff roles. |
| Frontend | App Bridge, Polaris web components, routing, the app frame, `RoleGate`, and the member profile and ledger screens against real data. |
| Extensions | Align extension API versions (V8); remove the unused function target; extend the configuration guard tests. |
| Data and QA | Arithmetic fixtures written before the code that satisfies them. Volume seed and query-plan pass (V2). Authorisation matrix skeleton. |

**Exit criteria.** Points can be adjusted, matured, expired and rebuilt from the
ledger, demonstrated in the console; a rules change creates a version and an
audit entry; the rebuild command reproduces every balance exactly; a member with
no email and a member with a day-and-month birthday both round-trip.

**Blocked by.** C6 only.

### Sprint 2 — weeks 3–4 — the engine moves on its own

| Track | Work |
| --- | --- |
| Backend | Order and refund handlers, `EarningCalculator`, reversal arithmetic, maturity, expiry, birthday and segmentation jobs, the replay command, the Klaviyo event bus behind a null driver. |
| Frontend | Overview with job health, adjustment and reward actions, audit viewer, staff roles screen. |
| Extensions | Discount function input query drafted against V4 and V5; function ladder against the shared fixtures. |
| Data and QA | Webhook idempotency, out-of-order and redelivery suites. Queue and scheduler running locally as they must in production. |

**Exit criteria.** A real order earns points; a partial refund reverses them
proportionally; points mature and expire on schedule with nobody running
anything; segments recalculate nightly.

**Blocked by.** D3, D9, D7, C2, C4, scope grant.

### Sprint 3 — weeks 5–7 — redemption, both channels

| Track | Work |
| --- | --- |
| Backend | Quote service and the ladder, `RedemptionGateway` with both implementations, metafield and cart-attribute publishing, confirmation and re-validation on `orders/paid`, mismatch handling, POS endpoints with session-token verification, CORS and throttling. |
| Frontend | Redemption register and member redemption history. |
| Extensions | Discount function completed and released; POS tile built for real — search, member card, enrolment, stepper, confirmation, void. |
| Data and QA | Function fixtures in CI; the MD10 device matrix on real hardware; the two-till concurrency case. |

**Exit criteria.** A member checks out online and the voucher value is applied
within every cap; an assistant redeems at a real till and the reference appears
on the receipt and in the audit log; both post identical ledger entries.

**Blocked by.** D8, C1, C9 (for any till adjustment), V3, V4, V5, V7, POS Pro,
app released.

### Sprint 4 — weeks 8–9 — the console OSC actually uses

| Track | Work |
| --- | --- |
| Backend | Duplicate detection and the merge service, exception-driven enrolment paths, Klaviyo wired live with the five events and four traits, the nightly reconcile, integration health endpoint. |
| Frontend | Rules editor with version history and diff, merge review, full audit search, integration settings, every remaining role gate. |
| Extensions | Customer account loyalty block, once V11 confirms the target. |
| Data and QA | Full role-by-endpoint authorisation matrix green. Klaviyo contract tests. UAT scripts per role. |

**Exit criteria.** OSC staff can run the programme unaided: look up, adjust,
issue, cancel, merge, change rules, read the audit log. A customer sees their
balance. Klaviyo receives the five events with correct timing.

**Blocked by.** Klaviyo credentials, V9, V11.

### Sprint 5 — weeks 10–11 — migration, reporting, go-live

| Track | Work |
| --- | --- |
| Backend | Migration pipeline end to end: profile, map, stage, match, dry run, import, delta, reconcile — including `combine`, `marketing_only`, the MD4 consent defaults and MD6 rounding. The five report queries. CSV and PDF renderers with the export job. |
| Frontend | Migration screens including exception review and resolution; the five report screens. |
| Extensions | Final release; verify every extension against the released version rather than the dev tunnel. |
| Data and QA | Dry run on the real export, exception report to OSC, sterling rounding pass, volume pass, UAT execution, cutover rehearsal, delta run. |

**Exit criteria.** Migration reconciled and signed off; the five reports export
as PDF and CSV; queue and scheduler running in the live environment; app
released; delta imported; go-live.

**Blocked by.** The export file, C5, C8, V10, Q4, production hosting with a
worker and scheduler.

### Two things that start now regardless of the board

The `read_all_orders` application, and collection of the receivables. Neither is
within the project team's control, and both become critical-path items the
moment they are late.

---

## 12. Readiness checklist

**Decisions** — see `DECISIONS.md` for detail.

- [x] D1, D2, D10 approved as recommended
- [x] D4, C7 confirmed from migration discovery
- [x] MD1–MD10 confirmed
- [ ] **C6 — named first Administrator (blocks Sprint 1)**
- [ ] D3, D9, C2, C4 (block Sprint 2)
- [ ] D7 (blocks Sprint 2 segmentation)
- [ ] D8, C1, C9 (block Sprint 3)
- [ ] C5, C8 (block Sprint 5)
- [ ] C3 / Q1 — full-price qualification, only if wanted

**Receivables from OSC**

- [x] Shopify admin and Partner access
- [x] POS Pro subscription — **confirmed Active on both development-store locations, 3 Sep 2026**
- [ ] Klaviyo private API key (blocks Sprint 4 live wiring)
- [ ] Dynamics / ORD export with field schema (blocks Sprint 5)
- [ ] Gender metafield on development and live stores
- [ ] Domain details for the production application URL
- [ ] Live store currency and timezone confirmed (C5)

**Shopify platform**

- [ ] Full scope set in TOML and config together, re-authorisation performed
- [ ] `read_all_orders` application submitted
- [ ] Real, stable HTTPS `application_url` before any deploy
- [ ] **`APP_URL` in `extensions/loyalty-tile/src/lib/appUrl.js` set to the production URL** — POS gives an extension no way to discover its own app URL (2026-07), so the tile compiles it in. `web/serve.mjs` rewrites it to the tunnel on every `shopify app dev` start, so whatever is committed at release time is what ships to every till. Same requirement, and the same trap, as `application_url` in `shopify.app.toml`
- [ ] App released, superseding the two unreleased spike versions
- [ ] Extension API versions aligned, unused function target removed (V8)
- [x] Webhook placement settled — Admin API only, nothing in the TOML
- [x] POS UI extension and discount function proven deployable

**Technical validations**

- [x] V1 — UI layer confirmed: Polaris web components with App Bridge
- [x] V3 — no new dependency; middleware must add `aud` and `dest` checks
- [x] V5 — `functionHandle`, not the deprecated `functionId`; needs `read_discounts` too
- [x] V8 — extension versions aligned, unused target removed, guarded by test
- [ ] V2, V4, V6, V7, V9, V10, V11 scheduled against their sprints

**Environment and operations**

- [ ] Production hosting with a queue worker and scheduler running continuously
- [ ] No UPDATE or DELETE grant on `loyalty_ledger` and `loyalty_audit_log` in production
- [ ] Sterling test store or currency switch for the rounding pass
- [ ] Backup and restore rehearsed before the migration import
- [ ] Health endpoint monitored

**Process**

- [ ] Shared arithmetic fixture file created before the first calculation
- [ ] `deploy --no-release` in the definition of done
- [ ] Capacity confirmed against the four tracks; descope order agreed
- [ ] UAT scripts per role agreed before Sprint 4 ends
