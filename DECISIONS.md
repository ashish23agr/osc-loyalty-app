# OSC Privilege Club — Decision Register

The authoritative record of every decision governing the loyalty build: what is
settled, what is still open, and what each one blocks.

**Status values**

| Status | Meaning |
| --- | --- |
| `CONFIRMED` | Settled. Safe to build against. Source and date recorded. |
| `ASSUMED` | Built to the recommendation, and the client told which way it was built and when. Open to an **objection**; does not wait on an approval. Each entry states how far it is built and what a late change would cost. |
| `IN PROGRESS` | Requested or under way, with the outcome outside our control. The build does not depend on it landing. |
| `PENDING` | Not settled. A recommendation exists but must not be assumed silently. |
| `RESOLVED` | Technical validation answered from documentation or a spike. |
| `OUTSTANDING` | Technical validation not yet run. |
| `REVISED` | Was settled and has been re-decided against new evidence. The superseded answer is kept, with what changed and what it costs, because a decision that quietly changes shape is indistinguishable from a mistake. |

`ASSUMED` exists so that "we are waiting on the client" never quietly means "we
stopped". A decision moves there only once the recommendation is actually built
and the client has been told; silence is then a decision, not a delay.

**Sources**

- `proposal.docx` — Proposal v1.1, 14 Aug 2026
- Privilege Club Blueprint, Rev 2 — technical specification, 25 Aug 2026
- Implementation Plan — [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md), 26 Aug 2026
- D5/D6 spike — 25 Aug 2026
- **Migration discovery client feedback — `data-migration-client-feedback-06-08-2026`, 6 Aug 2026**
- Client approval of D1, D2, D10 — 26 Aug 2026
- **Client notified of the assumed decisions D3, D8, D9 — 27 Aug 2026**
- **`read_all_orders` requested through the Partner Dashboard — 27 Aug 2026**
- **`read_all_orders` approved by Shopify — 1 Sep 2026** (Partner Dashboard: "Your app can access the full order history for a store")
- **Client confirmation of C4 and of the C6 Administrator mechanism — 31 Aug 2026**
- **Client direction on the D9 reversal mechanics (D9a–D9d) — 31 Aug 2026**
- **Direction on the audit grain for automated work (C12) — 31 Aug 2026**
- **V4 spike — discount function input readability, 31 Aug 2026**
- **V6a and V7 mini-spikes — 31 Aug 2026**
- **Full scope set granted on the development store — 31 Aug 2026**
- **Live OSC store confirmed on the Shopify Grow plan — client-confirmed, 2 Sep 2026** (previously our own inference)
- **Gift-card gate test run manually at checkout on the development store — 2 Sep 2026**

---

## 1. Summary

| Register | Confirmed | Assumed or in progress | Deferred to its sprint |
| --- | --- | --- | --- |
| Blueprint decisions (D) | D1, D2, D4, D6, **D7**, D10 · **D5 `REVISED`** | **D3, D8, D9 (with D9a–D9d)** assumed | — |
| Plan confirmations (C) | **C4**, C6 (mechanism + dev store), C7, **C12**, **C13** | C2, C10, C11 assumed · **C6 live Administrator** pending, on the go-live checklist | C1, C3, C5, C8, C9 |
| Migration discovery (MD) | MD1–MD10 | — | — |
| Open questions (Q) | — | Q3 (via C2) | Q1, Q2, Q4, Q5 map to the C items beside them |
| Technical validations (V) | V1, V3, **V4**, V5, V8 resolved | — | V2, V6, V7, V9, V10, V11 — ours to close, not the client's |

### What still needs Robert

**Two items:**

| Ref | Question | Needed by |
| --- | --- | --- |
| **C6 (live)** | Who holds the first Administrator role on the production store? | Go-live |
| **C14** | Does a Privilege Club voucher reduce the points earned on that order? Our call is yes — spend £550 of a £600 basket, earn 550 points — because the rule is "£1 spent = 1 point" and earning on voucher-paid value compounds loyalty value. Raised 2 Sep 2026 from a live dev-store order. | Before go-live; the code is being corrected to this now |

**C4 is answered.** Confirmed 31 Aug 2026: lapsed members *do* receive the
birthday voucher, on date of birth alone. It is off this list and out of
Sprint 2's way. The item that remains is a name, not a decision — the C6
**mechanism** is confirmed, and the named live-store Administrator is carried on
the **go-live checklist** for OSC to confirm before launch.

Nothing else is waiting on him. The **assumed** decisions — D3, D8 and D9, with
C2, C10 and C11 alongside them — were built to their recommendation and the
client was notified on 27 Aug 2026: they are open to an **objection**, and do not
need an approval to proceed. Each entry states how far it is built and what a late
change would cost. The **deferred** items belong to a sprint that has not
started, and will be raised with the work that needs them rather than now.

**Sprint 1 is complete and nothing blocks Sprint 2.** D3 and D9 were the two
that did; both are now assumed and built to their recommendation. C4, the last
item Sprint 2 itself needed, was confirmed on 31 Aug 2026.

---

## 2. Blueprint decisions

### D1 — What voucher expiry means · `CONFIRMED` 2026-08-26

Approved as recommended. Two distinct concepts. The points-derived voucher
balance has no expiry of its own and falls automatically as the underlying
points expire. The birthday reward is a real issued record with its own expiry.
The admin setting labelled "voucher expiry" therefore configures **points
expiry** and is presented that way.

*Implemented:* `loyalty_rewards` holds issued objects only; the derived balance
is computed on read by `BalanceCalculator`. Rule key `points_expiry_days`.

### D2 — When the expiry clock starts · `CONFIRMED` 2026-08-26

Approved as recommended. The clock runs from the **availability date**, giving
every member a full six months of usable life. Expiring from the order date
would quietly shorten the usable window to five months.

*Implemented:* on an earn entry, `matures_at = occurred_at + pending_period` and
`expires_at = matures_at + points_expiry_days`. Rule key
`expiry_clock_starts: "availability"`.

### D3 — What one pound of spend means · `ASSUMED` 2026-08-27

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** No longer blocks Sprint 2.

The base is the amount the customer actually pays for qualifying merchandise —
line-item totals after all discounts, **excluding tax, excluding shipping, and
excluding any Privilege Club voucher applied to the order**, rounded down to
whole points per order. Including VAT inflates every balance by roughly a fifth
and would be visible to members immediately as faster accrual.

**The voucher counts as a discount — stated explicitly as of 2 Sep 2026, and
this is our call pending client confirmation.** "After all discounts" was
already the rule here and was still implemented wrongly (see the change log for
2 Sep), so it is now spelled out: a member who pays £550 of a £600 basket
because a £50 voucher covered the rest earns on **£550**. The programme rule is
"£1 spent = 1 point" and £550 is what they spent. Earning on voucher-paid value
lets loyalty value earn further loyalty value, which compounds and over-earns
financially. **On the client-questions list for Robert.**

*How far it is built.* `earn_base` carries `post_discount_ex_tax_ex_shipping` on
every rule version, is versioned like any other rule, and is listed on the
Settings screen under *Carried forward unchanged* so nobody saves a rule set
without seeing it. The calculation that consumes it is Sprint 2 — this is the
value that calculation will read.

*Cost of a late change.* Low before go-live and high after. The base is a rule,
so changing it writes a new version and is not retrospective; balances already
earned under the old base stay as they are, which is correct but means a mid-life
change leaves two populations accruing on different bases.

### D4 — Expiry for migrated balances · `CONFIRMED` 2026-08-06

Client confirmed: **migrated balance expiry runs from the go-live date.** Each
member balance is imported as a single opening-balance ledger entry dated at
go-live, with a configurable grace period defaulting to six months. Members keep
what they earned and get a fair window to use it.

*Implemented:* rule key `migrated_balance_grace_days`; ledger entry type
`opening_balance` carries its own `expires_at`.

See also MD7 (balances carry over as points, not vouchers).

### D5 — How a voucher is applied online · `REVISED` 2026-09-02

**REVISED — production online redemption is the single-use-code path, not the
discount function.** The live OSC store is on the **Grow** plan (our own
confirmation, 2 Sep 2026), and Shopify refuses to activate a function from a
custom app below Plus:

```
discountAutomaticAppCreate → userErrors[0]
  field:   ["automaticAppDiscount", "functionId"]
  message: "Shop must be on a Shopify Plus plan to activate functions from a custom app."
  code:    INVALID
```

Found on the dev store (`Grow App Development`, `shopifyPlus: false`) at test
script step A1 on 1 Sep 2026, and it applies to the live store for the same
reason. The constraint is the **plan plus the distribution model**, not the
legacy install flow — a public App Store app would not hit it.

*What the original decision said.* Option A, the **discount function**: scaffolded,
compiled to WebAssembly and accepted in an app version alongside
`use_legacy_install_flow = true`, targeting `cart.lines.discounts.generate.run`.
That finding still stands on its own terms — the legacy install flow places no
restriction on functions. Plan and distribution do, and that was never checked.

*What is built now.* `DiscountFunctionGateway` is complete and proven: V4 against
real compiled Wasm, V6a's app-synced metafields verified on a live shop at step
A2. **It is kept**, as the Plus-and-above implementation and as the future option
if OSC ever moves plan or the app is published. Nothing is deleted.

*What has to be built.* A **code-based gateway** implementing the same
`RedemptionGateway` interface: a single-use discount code issued per quote,
withdrawn on expiry, confirmed on `orders/paid`. **This is now Sprint 3 work.**
The interface is what makes this a swap rather than a rewrite — the reason it
exists is recorded in `RedemptionGateway`'s own docblock, and this is the case it
was written for.

*Unaffected.* **POS redemption is untouched.** D6 applies the discount through
`shopify.cart.applyCartDiscount` from the tile
(`extensions/loyalty-tile/src/Modal.jsx:321`), which is not a function and has no
plan requirement. The ledger, the D8 ladder and the shared reference (D6) are all
mechanism-agnostic and do not change.

*Consequence for the register.* C1 — how a customer chooses an amount online —
now resolves against the code path rather than the cart attribute, since a
single-use code carries its own amount. The cart-attribute mechanism stays with
the function it belongs to.

### D6 — How a voucher is applied at the till · `CONFIRMED` 2026-08-25 (spike)

Confirmed, with a cleaner mechanism than assumed. The POS Cart API exposes
`applyCartDiscount(type, reason, amount)` for a fixed-amount cart-level
discount. A POS UI extension targeting `pos.home.tile.render` and
`pos.home.modal.render` deployed successfully under the legacy install flow,
with `pos.embedded = false` left as it is.

The app validates the amount **before** the tile offers it, and the reason
string carries the redemption reference, which gives the till receipt and the
audit log a shared identifier for free.

### D7 — Where two years of spend history comes from · `CONFIRMED` 2026-09-01

**CONFIRMED — `read_all_orders` requested through the Partner Dashboard on 27 Aug 2026 and APPROVED by Shopify on 1 Sep 2026.** The Partner
Dashboard reads *"Your app can access the full order history for a store"*.
Requested 27 Aug, approved 1 Sep, five days.

Segmentation is built from **our own ledger plus the migrated last-spend date**
regardless, and that does not change now the scope has arrived. Designing around
the grant rather than for it is why nothing had to wait on it, and why nothing
has to be unwound now it is here.

*What the approval unlocks.* A backfill job reads the historical orders once and
posts nothing to the ledger — it only refines `last_qualifying_spend_at` and the
segment. No balance moves. Without it, segments are accurate for migrated members
and for all activity after go-live, but blind to Shopify orders placed between
the legacy export and launch; members in that window read as more lapsed than
they are until their next purchase, and correct themselves on it. The backfill
removes that blind window.

**Approval is not the same as grant, and the remaining steps are deliberately
not taken yet.** Shopify has approved the app to *request* `read_all_orders`; the
shop has not granted it. Three things still have to happen, in this order:

1. Add `read_all_orders` to `[access_scopes]` in `shopify.app.toml` and to
   `SCOPES` in `web/.env` — the two must move together, and
   `ShopifyConfigurationTest` fails the build if they drift.
2. **One further merchant re-authorisation**, because `use_legacy_install_flow`
   has no token exchange. That is the third re-auth this app has asked for
   (the full scope set on 31 Aug, `write_products` for V6a, and now this).
3. Build the backfill job. **It can now be scheduled** — the approval was the
   only thing outside our control, and it has landed. It is Sprint 4 work,
   nothing in Sprint 3 calls it, and it joins the six existing scheduled
   commands in `routes/console.php` as a one-shot rather than a sweep: it reads
   the historical orders once per shop, refines `last_qualifying_spend_at` and
   the segment, and posts nothing to the ledger. Because no balance moves it is
   safe to run at any time, including after go-live, and safe to re-run.

*Why not now.* Changing the scope list forces a re-authorisation, and a
re-authorisation mid-verification invalidates the Sprint 3 dev-store run in
progress. The scope is banked; it costs nothing to add it once that run is
finished. **On the go-live checklist.**

### D8 — Redemption limit with excluded items · `ASSUMED` 2026-08-27

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).**

The limits apply in a fixed order: eligible subtotal, minimum basket check,
configured cap, the "below basket value" rule, then round down to the voucher
increment. Order matters — rounding before the cap, or capping before the
eligible subtotal, produces different answers on the same basket.

*How far it is built.* The ladder is encoded in
`fixtures/loyalty-arithmetic.json` under `redemption_ladder` as the Sprint 3
contract, with the Blueprint worked example first and eight cases in total, each
naming which rung binds. PHPUnit consumes those fixtures today and the discount
function's Vitest suite will consume the same file in Sprint 3, so both sides of
the redemption path are held to one arithmetic.

### D9 — Refunds against a redeemed voucher · `ASSUMED` 2026-08-27

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** No longer blocks Sprint 2.

Redeemed points are restored and earned points reversed **in proportion to the
refunded eligible value**, both as new ledger entries, never as edits. Full
restoration on a partial refund is exploitable — return one item, keep the whole
reward — and the proportional rule closes that.

*How far it is built.* The ledger already carries what the rule needs: the
`redemption_restore` and `earn_reversal` entry types, and lot allocations that
record which earning each consumption drew from, which is what makes a
proportional restore exact rather than approximate. A clawback taking the
available balance below zero while the voucher value floors at zero is already
tested. The handlers that post these entries from `refunds/create` are Sprint 2.

*Related and still open:* C2 asks whether a clawback may push a balance below
zero at all. The ledger permits it today because refusing would make the ledger
untrue; if C2 comes back the other way it changes what is shown, not what is
recorded.

*Mechanics.* Building the jobs pass surfaced four questions the proportional rule
does not answer on its own — where restored points go, what maturity does with a
reversal that preceded it, how a fraction is rounded, and what a partial refund
does to the redemption record. They are settled as **D9a–D9d** below. All four sit
**inside** the rule the client was already notified of on 27 Aug 2026, so they
carry D9's posture rather than opening anything new.

### D9a — Where restored points go · `ASSUMED` 2026-08-31

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** Mechanics within D9.

Restored points **return to the lots the redemption consumed, keeping their
original expiry.** A fresh lot would let a redeem-and-refund cycle extend the
life of points indefinitely — the same exploit class the proportional rule
closes, arrived at from the other direction.

*How it is built.* The ledger is append-only and so is `loyalty_lot_allocations`,
so a give-back cannot edit or delete the original allocation. It is a **negative
allocation row** against the same lot: a *release*. The invariant is

```
lot remaining = lot total − SUM(allocations)        releases being negative
0 ≤ lot remaining ≤ lot total
```

Both existing readers — `LotAllocator::openLotsFor()` and `ExpiryOutlook` — were
already `SUM(alloc.points)`, so a signed row nets correctly with **no change to
either query**. `LotAllocator::planRelease()` is the pure half; it releases
oldest-lot-first, the same order the consumption used, and caps each lot at what
that lot actually gave. `uq_alloc` still forbids two releases of one lot by one
restore.

*Two schema changes, and the second is the one that mattered.* `ck_alloc_points`
was relaxed from `points > 0` to `points <> 0`. That alone was **not enough**:
`loyalty_lot_allocations.points` was `unsignedInteger`, and on MySQL an unsigned
column rejects a negative before any CHECK is consulted — *Out of range value for
column 'points'*. SQLite does not enforce unsigned at all, so the whole test
suite passed on a schema that would have failed in production. The column is now
signed, verified against MySQL 8.4, and a guard test reads the migration source
so a SQLite-only run cannot let the unsigned back in.

*Sub-choice worth recording.* Release is **oldest-lot-first**, matching the FIFO
of consumption rather than unwinding in reverse. One ordering rule for both
directions is easier to reason about, and it is the conservative reading: points
go back to the lot that expires soonest, which is exactly where they would have
been had the redemption never happened. A consequence follows and is tested —
points released into a lot whose expiry has already passed expire on the next
sweep rather than living on, which is why the expiry job dates its idempotency
key instead of keying on the lot alone.

### D9b — Maturity nets off reversals · `ASSUMED` 2026-08-31

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** Mechanics within D9.

The maturity job matures **the earn less every pending point already reversed
against it**, never the raw earn amount.

*Why it is not optional.* In the worked example an 80 point earn has 20 reversed
by a refund ten days in. A sweep that matured `pending_delta` would post −80
pending against a bucket holding 60, leaving the member on **−20 pending for
ever** and available overstated by the same twenty. `parent_entry_id` on an
`earn_reversal` exists precisely to connect it back to the earning it nets
against.

*How it is built.* `MaturitySweep::netOf()` sums the reversals' `pending_delta`
(already negative) against the earn. The worked example is asserted directly:
**at T0+30d the sweep matures 60, pending ends at 0, and never at −20.** An earn
reversed away entirely matures nothing, is reported as *settled* rather than
skipped, and — a second fix this forced — no longer counts for ever in the
console's *overdue maturities* tile, which it would have done since it never
receives a maturity entry.

### D9c — Rounding is a cumulative floor · `ASSUMED` 2026-08-31

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** Mechanics within D9.

**Per-refund fractions are never rounded.** Every reversal is computed from the
cumulative refunded value, floored once, minus what is already posted:

```
this = floor(points × cumulative_refunded / order_eligible) − already_posted
```

Restores use the same cumulative floor against the redeemed amount.

*Why.* Floors do not add up. Three £7 refunds against the £80 order floor to 17
points each and restore **51**; the single £21 refund they amount to restores
**52**. The member is a point down for having returned things separately, and a
full refund taken in instalments never quite gets back to zero.

*Consequences, both asserted.* Any split of a refund posts exactly the totals a
single refund of the same value would have — three £7 refunds equal one £21
refund entry for entry, 21 reversed and 52 restored. And a **100% refund always
nets to exactly zero remaining**, every earned point reversed and every redeemed
point restored, whatever route was taken to get there.

*How it is built.* `RefundArithmetic` is pure integer arithmetic — multiply then
`intdiv`, so no float is ever seen and there is no representation error to round
around. An over-refund clamps to the order rather than extrapolating, because
Shopify permits refunding more than was paid. A revised-down total posts nothing
rather than clawing back. The cases live in `fixtures/loyalty-arithmetic.json`
under **`refund_reversal`** (8 cases), **`refund_reversal_sequences`** (4) and
**`lot_release`** (6, D9a's planner) — PHPUnit consumes them now, Vitest in
Sprint 3. The £21 / p = 0.2625 case and a full-refund-after-partials case are
both in the set, and one test asserts the contrast itself: if the cumulative
floor ever silently becomes per-refund rounding, the 52-versus-51 case fails.

### D9d — A partial refund does not close the redemption · `ASSUMED` 2026-08-31

**ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval).** Mechanics within D9.

A partial refund **leaves `loyalty_redemptions.state` as it is** and records what
has gone back; only a **full** reversal flips the state to `reversed`. Flipping
on the first partial would throw away the handle the next refund needs — the
redemption would read as finished while it still had value to give back.

*One correction to the instruction as given.* The direction said a partial should
keep `state = redeemed`. There is no `redeemed` value on this column: the enum is
`quoted, applied, confirmed, void, reversed`, and a redemption that has been paid
for sits at **`confirmed`**. Built as `confirmed`, which is what "keeps its
existing state" means here. `redeemed` is a value on `loyalty_rewards.state`, a
different table.

*How it is built.* Two new columns, both cumulative rather than per-refund
because D9c's arithmetic needs the running total: `points_restored` and
`amount_reversed_pence`, with MySQL CHECKs holding each at or below its
counterpart. `Redemption::pointsOutstanding()`, `isPartiallyReversed()` and
`isFullyReversed()` let a caller tell *partly given back* from *finished*
without reading the state and guessing.



### D10 — Two members, one email · `CONFIRMED` 2026-08-26, migration branch refined 2026-08-06

Approved as recommended: **never create a duplicate silently.** Online
enrolment attaches to the existing record; POS shows the match to staff; an
admin merge tool combines two records by replaying both ledgers into one.

**Migration branch, client-confirmed 2026-08-06 (supersedes the original
"route every duplicate to the exception report"):** the unique identifier is
the email, so two legacy records with an **exact** email match are the same
person and the default resolution is **combine balances into one account**. The
exception report is reserved for genuinely **ambiguous** matches.

*Implemented:* `uq_account_email` on `(shop_domain, email_normalised)` enforces
one account per email at database level; `migration_records.decision` gains
`combine`. See MD1 for the no-email case, which this index deliberately permits.

---

## 3. Plan confirmations

| Ref | Question | Status | Blocks |
| --- | --- | --- | --- |
| C1 | Online: does the voucher apply automatically, or does the customer choose an amount? (Q2) | `PENDING` — **mechanism built and swappable**, see below | Sprint 3 |
| C2 | May a refund clawback push a balance below zero? (Q3) | `ASSUMED` — built to the recommendation, see D9 | Sprint 2 |
| C3 | Is a full-price-only rule wanted, and does it apply to earning, redemption or both? (Q1) | `PENDING` | Not built |
| C4 | Do lapsed members receive the birthday reward? | `CONFIRMED` 2026-08-31 — **yes, on DOB alone** | — |
| C5 | Live store currency and reporting timezone (Q4) | `PENDING` | Sprint 5 |
| C6 | Who is the first Administrator? (Q5) | Mechanism `CONFIRMED` 2026-08-31 · dev store `CONFIRMED` · **live name `PENDING`, go-live checklist** | Go-live |
| C7 | Do OSC tills operate while offline? | `CONFIRMED` | — |
| C8 | PDF export fidelity vs. server dependency | `PENDING` | Sprint 5 |
| C9 | Manual adjustments and voucher overrides at the till | `PENDING` | Not built |
| C10 | Do manually adjusted points expire like earned ones? | `PENDING` (assumed) | Built to the recommendation |
| C11 | Does the Privilege Club card carry its own number, or is it derived from the account? | `PENDING` (assumed) | Built to the recommendation |
| C12 | Is automated work audited, and at what grain? | `CONFIRMED` 2026-08-31 — **yes, one entry per run / per order** | — |
| C13 | What happens to a redemption quote nobody paid for? | `CONFIRMED` 2026-08-31 — **voided, entitlement withdrawn, no re-quote** | — |

### C1 — How a customer chooses an amount online · `PENDING`

**Still open, and deliberately not assumed.** The Blueprint specifies no
storefront control, and the public loyalty page is out of scope, so there is no
agreed answer to build to.

**What is built is the mechanism, not the choice.** The discount function reads a
single cart attribute, `loyalty_redeem_pence`, and honours it **only downwards
and only in whole increments** — it can ask for less than the ladder allows and
never for more. Everything else follows automatically: with no attribute set, the
full quoted entitlement applies.

That means both live answers to C1 are already supported without touching the
function or the ledger:

| If OSC decides… | What changes |
| --- | --- |
| The voucher applies automatically | Nothing. Set no attribute. |
| The customer chooses an amount | A storefront control writes the attribute. |

The same seam exists in PHP: `RedemptionService::hold()` takes an optional
`requestedPence`, clamped identically. The two are tested against the same rule.

**What C1 still blocks:** the storefront control itself — where it appears, what
it looks like, and whether a member can change their mind at checkout. None of
that is built, and none of it should be guessed.

### C4 — Birthday reward for lapsed members · `CONFIRMED` 2026-08-31

Client confirmed: **lapsed members do receive the £10 birthday voucher.**
Eligibility is **date of birth on record only** — a day and month is sufficient,
per MD2 — and the birthday job performs **no Active/Lapsed check**.

*Why this way round.* Segment status governs marketing and reporting; it does
not gate the reward. A lapsed member still has a birthday, and the voucher is
the one mechanism most likely to bring them back, so the lapsed member is
precisely who it is worth most on. Gating it would have made the reward quietest
exactly where it should be loudest.

*How it is built.* The birthday job is Sprint 2 and not yet written, so nothing
has to be unwound. Its selection reads `dob_month` / `dob_day` against the run
date and nothing else, over `ix_account_birthday` on
`(shop_domain, dob_month, dob_day)` — **no join to segment state, and no
`date_of_birth` read**, which is what keeps MD2's year-less birthdays eligible on
identical terms. `uq_reward_birthday` on
`(shop_domain, loyalty_account_id, reward_type, birthday_year)` stays the only
guard, against a job re-run double-issuing within one year (MD8).

*No longer blocks Sprint 2, and no longer awaits Robert.*

### C6 — First Administrator · mechanism `CONFIRMED`, live name `PENDING`

**Mechanism, confirmed 2026-08-26 and reconfirmed 2026-08-31:** the **shop owner
becomes Administrator automatically on install**, and every subsequent role is
assigned in the console and audited. The alternative — every staff member
defaulting to Administrator until configured — is unacceptable in a system that
moves money.

**What the role carries, confirmed 2026-08-31:** the **Administrator role has
full access to the admin console and to all administrative functions** — rules,
staff and roles, adjustments, rewards, members, reports and the audit log, with
nothing held back behind a higher tier. It is the top of the four-role model in
Blueprint §12, and the only role that can create another Administrator.

**Development store:** the first Administrator is the **shop owner of
`loyalty-system.myshopify.com`**. This unblocks Sprint 1.

**Live store: `PENDING` — on the go-live checklist.** The named person who will
hold the first Administrator role on the production store is still not known,
and **OSC will confirm the name before go-live**. It is carried as a go-live
checklist item rather than an open question: the mechanism is settled and
identical either way, so only the identity is outstanding. Needed before go-live
(Sprint 5), not before any sprint between here and there.

Nothing in the code depends on the answer: the name is only needed so OSC know
who to hand the first login to.

**Mechanism as built (2026-08-26).** Offline OAuth tokens carry no user identity,
so "shop owner" is not a signal the app can read at install. The bootstrap
therefore grants Administrator to **the first staff member to open the app on a
shop that has no roles at all** — in practice whoever installed it. It is
guarded by a locked existence check inside a transaction, so two simultaneous
first requests cannot both claim it, can fire only once per shop, and is written
to the audit log as `staff.bootstrapped` because it is the one role nobody
assigned. Every staff member after that gets nothing until an Administrator
assigns a role, and is refused with `403 no_role_assigned`.

If OSC would rather the first Administrator be set explicitly rather than by
first arrival, that is a small change and worth raising alongside the name.

### C7 — Offline tills · `CONFIRMED` 2026-08-06

Client confirmed: **record the sale, and add the points afterwards via the
webhook. No offline redemption.**

This matches the design already planned. Earning is unaffected because it is
driven by `orders/paid` once connectivity returns. The tile shows no balance and
offers no redemption while offline, failing visibly rather than spinning. What
is now explicitly ruled out is redeeming offline against a balance nobody can
check.

### C9 — Adjustments and overrides at the till · `PENDING` (new, raised 2026-08-26)

The migration discovery document implies manual point adjustments and voucher
overrides **at the till**, noting "all roles OK". The current plan places
adjustments in the admin console only, gated at Agent with a mandatory reason
and an adjustment limit, and gives the POS tile no adjustment capability at all.

These are materially different postures. "All roles OK" would also cut across
the four-role model in Blueprint §12, under which a Viewer may change nothing
and only a Manager may act on rewards.

**No POS adjustment endpoint is built until this is confirmed.** Flagged for
clarification:

1. Should a till assistant be able to adjust points, or only redeem?
2. If yes, does the console role model apply at the till, or is any signed-in
   staff member permitted?
3. Should a voucher override at the till be capped, and does it require a reason?

### C10 — Do adjusted points expire? · `PENDING` (assumed, raised 2026-08-26)

Building the ledger forced a question the Blueprint does not answer. Earned
points expire; migrated opening balances expire (D4). Nothing says what happens
to points a member of staff adds by hand.

**Built to this recommendation:** a positive adjustment into the available bucket
carries an expiry date derived from the rule version in force, so adjusted points
behave exactly like earned ones.

**Why not the alternative:** points that never expire are a liability that only
grows, and they would behave differently from every other point on the same
account — a member comparing two credits would see one lapse and the other not.

Cheap to change before launch (one argument at the call site), disruptive after.
Worth an explicit OSC position rather than an assumption that hardened.

### C11 — Where the club card number comes from · `PENDING` (assumed, raised 2026-08-27)

Building the customers list forced a question the plan does not answer. The
agreed UI (v1.1 FINAL) shows **two** card numbers on every member: the legacy
Dynamics one (`D-118422`), which is a real migrated column, and a Privilege Club
card number (`0041-2287`), which nothing in the schema holds. Search has to
accept both.

**Built to this recommendation:** the club number is **derived from the account
id** and formatted `NNNN-NNNN`. It is therefore unique and stable without a
column, and reversible, so typing one into the search box is an exact
primary-key lookup rather than a scan. `Support\Members\MemberCardNumber` is the
only place the format lives.

**Why not the alternative:** a stored column needs a generator, a uniqueness
guard and a reconciliation rule against whatever the migration file contains,
none of which can be designed before OSC say whether physical cards are being
reissued at all.

**What would change it.** If OSC issues physical cards carrying their own
numbering — or the Dynamics export turns out to hold a club number distinct from
the legacy one — this becomes a nullable indexed column and only
`MemberCardNumber` and one search branch change.

**Questions for OSC:**

1. Are new physical cards being issued, and if so who allocates the numbers?
2. Is the number on the card expected to match anything in the legacy export?

### C12 — Audit grain for automated work · `CONFIRMED` 2026-08-31

**Automated work is audited.** Scheduled sweeps, artisan commands and webhook
handlers all act as a **system actor**, and the console already renders those
rows as *System* in the agreed UI. This is a direction from the project side
rather than an OSC answer, and it matches what M8 already assumed.

**The grain, which is the part worth deciding:**

| What ran | What it writes |
| --- | --- |
| A scheduled sweep | **One entry per run, per shop** — the action, the counts (members touched, points matured or expired, rewards issued), the duration, and the as-of date |
| A webhook-driven reversal | **One entry per order**, naming the order and the refund |
| A webhook-driven earning | **One entry per order**, naming the order and what it posted |

*Why not per member.* A sweep that matured four thousand members' points has
already written four thousand ledger entries, each naming the member, the
earning and the amount. Copying that into the audit log would double the storage
to say nothing new, and would bury the entries a dispute actually needs — who
changed a rule, who adjusted a balance, who merged two members — under automated
noise. **The audit log answers "who did what"; it is not a second copy of the
ledger.** Per-member detail is one ledger query away, joined by time.

*A failed run is recorded too*, as `job.failed` with the error. A sweep that
stops silently is the failure this programme is most exposed to, because points
simply stop moving and no screen says why.

*How it is built.* `RequestContext::forJob()` and `::forWebhook()` are the two
ways in; both land on `actor_type = 'system'` with `actor_name` deliberately
**null**, because an automated actor has no name and putting one there would put
a fiction in the User column. `JobAuditor` wraps every sweep so the grain is
decided in one place rather than per command. New actions: `job.completed`,
`job.failed`, `order.earned`, `order.reversed` — the last kept distinct from
`redemption.reversed`, which is a member of staff acting by hand and rightly
demands a reason.

*It pays for itself immediately.* Because every run writes one entry with its
counts, the Loyalty screen can now say **when each sweep last ran** without a job
table — and a sweep that has stopped shows as a date that stopped moving.

### C13 — What an expired quote does · `CONFIRMED` 2026-08-31

A redemption quote stands for twenty minutes. Until the end of Sprint 3 nothing
made that true: the only things that ended a quote were a confirmation or an
explicit void, so `quote_expires_at` was a promise nothing kept.

**Confirmed: an expired quote is voided, its published entitlement is withdrawn,
and nothing is re-quoted.**

*Why it needed deciding at all, rather than being obvious tidying.* An online
hold publishes an entitlement the discount function reads. Mostly a stale one is
harmless — the function re-applies the ladder to the live cart, so it cannot
exceed what the new basket allows. **The case that is not harmless is a balance
that falls in between.** If a member's points expire overnight while the
entitlement is still readable, the function would still offer the old amount, and
confirming it would spend points the member no longer has and drive the balance
negative. Q3 permits a negative balance from a **clawback**; a redemption
creating one is a different thing and is not intended.

*Why no re-quote.* Both channels already ask for a fresh quote against the live
basket — the tile when the member screen opens, the storefront on the next hold.
Re-quoting inside the sweep would be computing an offer against a basket nobody
is looking at, and would publish an entitlement no one had asked for.

*Cost to the member: none.* A quote never spent anything; `points_consumed` stays
0 until an order is paid for. Voiding one takes nothing back because nothing was
taken.

*Implemented:* `QuoteExpirySweep`, run by `loyalty:expire-quotes` every five
minutes — a twenty minute quote therefore lives at most twenty-five, which is
close enough to what the member was told. The query is indexed on
`(shop_domain, state, quote_expires_at)`, so a frequent run is cheap. A till quote
published nothing, so there is nothing to withdraw. Ten tests, including the
stale-entitlement case the sweep exists for.

---

## 4. Migration discovery confirmations

All confirmed by the client in `data-migration-client-feedback-06-08-2026`
(6 Aug 2026), applied to the plan and schema on 26 Aug 2026.

### MD1 — Members with no email address · `CONFIRMED` · schema change

Legacy members with no email address **migrate, and must be searchable at the
till and in the console.** An email address is therefore optional, not a data
defect.

*Implemented and verified:*
- `loyalty_accounts.email` and `email_normalised` are nullable.
- `uq_account_email` is retained; MySQL and SQLite both permit repeated NULLs in
  a unique index, so any number of members may exist with no email while the
  index still forbids two members sharing one.
- Identity for these members falls back to **legacy card number, then postcode
  plus surname** — a no-email path through `AccountResolver` / `MatchStrategy`.
- `LoyaltyAccount::hasNoEmail()` makes the state explicit so no code assumes an
  email is present.

### MD2 — Day and month birthdays with no year · `CONFIRMED` · schema change

A customer may supply **only a day and month**, with no birth year, and must
still receive the birthday voucher.

*Implemented and verified:*
- `dob_month` and `dob_day` are real, writable columns — the authoritative
  birthday fields — not generated columns.
- `date_of_birth` (full `DATE`) is optional; when present it populates the day
  and month, when absent they stand on their own.
- The birthday job reads `dob_month` / `dob_day` and never `date_of_birth`, so a
  member with no birth year is eligible on identical terms.
- All enrolment forms (storefront, POS, console, migration) accept a day and
  month without a year.
- Index `ix_account_birthday` covers `(shop_domain, dob_month, dob_day)`.

### MD3 — Exact duplicate emails combine · `CONFIRMED`

Two legacy records with an exact email match are the same person; the default
resolution is to **combine balances into one account**. See D10.

### MD4 — Consent defaults for migrated members · `CONFIRMED`

- Migrated members **not** matched to an existing Shopify customer start as
  **Not subscribed**.
- Migrated members **matched** to an existing Shopify customer **keep their
  existing Shopify consent** — migration never overwrites it.

### MD5 — `marketing_only` migration decision · `CONFIRMED` · schema change

Email-only contacts with no card and no membership become **Shopify marketing
contacts with no loyalty account**, no ledger and no opening balance.

*Implemented:* `migration_records.decision` enum extended to
`create, attach, combine, marketing_only, review, exception, skipped`.

### MD6 — Decimal balances round down · `CONFIRMED`

Decimal point balances in the export are rounded **down** to whole points.
`RowMapper` test fixture required: `123.45 → 123`.

Consistent with the earning rule, which also rounds down, and with points being
stored as integers throughout.

### MD7 — Balances carry over as points · `CONFIRMED`

Migrated balances carry over as **points**. No vouchers are auto-issued at
migration. The voucher balance is derived from those points on read, exactly as
for any other member, which is what D1 already requires.

### MD8 — Duplicate birthday vouchers in year one · `CONFIRMED`

The client **accepts possible duplicate birthday vouchers in year one.** No
migration-year deduplication is needed.

*Effect:* the `uq_reward_birthday` constraint on
`(shop_domain, loyalty_account_id, reward_type, birthday_year)` stays as the
guard against a job re-run double-issuing within one year, but no extra
suppression logic is written for members who may already have had a voucher from
the legacy system in the migration year.

### MD9 — No paper-voucher liability import · `CONFIRMED`

No outstanding paper-voucher liability needs importing. Migration covers member
records and point balances only.

### MD10 — POS device matrix · `CONFIRMED`

Device testing targets **one location**: iPad 10th generation, POS app 11.11.1,
a single POS Pro store. This narrows the Sprint 3 device matrix from "a real POS
terminal" to a specific, testable configuration.

**POS Pro closed 3 Sep 2026.** Confirmed **Active on both** development-store
locations, so it is no longer an open receivable or a gate on Sprint 3 device
testing:

| Location | Address | Notes |
| --- | --- | --- |
| Shop location | United States | the store **default** |
| My Custom Location | 123 Main St, Toronto, Canada | |

Two consequences, both recorded rather than acted on:

**Location attribution becomes properly testable.** MD10 still targets one
location for the *production* device matrix, and that is unchanged. But two POS
Pro locations on the development store means the store-location-per-POS-transaction
requirement can be asserted rather than merely observed: a sale from each
location must produce **two distinct `shopify_location_id` values**, each
matching where the sale was taken. Non-NULL on a single location would be
satisfied by a hardcoded default, so it was never a real check.
`docs/DEV_STORE_TEST_SCRIPT.md` step **B2** now states this as the pass
criterion.

**Neither location is in the UK, and the US one is the default** — further
corroboration for V12. The development store cannot present a UK-domiciled
merchant from any angle: the merchant address is locked to the United States,
and now the POS locations are United States and Canada. Deliberately **not**
changed, on the reasoning that fewer moving variables while the tile is being
diagnosed is worth more than a UK address that still would not settle V12 — V12
closes on the live store regardless. See the V12 entry.


---

## 5. Open questions

Places the Blueprint does not answer either way. Each carries a recommendation;
none is silently assumed.

| Ref | Question | Maps to |
| --- | --- | --- |
| Q1 | Is full-price qualification a requirement at all? The Blueprint says earning includes sale and promotional stock, and offers only collection and tag eligibility. | C3, V6 |
| Q2 | Where does a customer choose to redeem online? No storefront control is specified, and the public loyalty page is out of scope. | C1 |
| Q3 | May a refund clawback push a balance below zero when the points are already spent? | C2 |
| Q4 | Which timezone and currency does a report speak, given a dollar development store and sterling rules? | C5, R3 |
| Q5 | Who is the first Administrator, before any role exists? | C6 |

---

## 6. Technical validations

| Ref | Question | Status | Blocks |
| --- | --- | --- | --- |
| V1 | Current recommended UI layer for app home | `RESOLVED` 2026-08-26 | Sprint 1 |
| V2 | Ledger stays indexed at a projected two-year row count | `OUTSTANDING` | Sprint 2 |
| V3 | Session-token verification for admin, POS and customer account tokens | `RESOLVED` 2026-08-26 | Sprint 3 |
| V4 | Metafield and cart-attribute readability from function and account extension | `RESOLVED` 2026-08-31 (spike) | Sprint 3 |
| V5 | Mutation and discount-class shape for a function-backed automatic app discount on 2026-07 | `RESOLVED` 2026-08-26 | Sprint 3 |
| V6 | Compare-at price in the discount function input | `OUTSTANDING` | Only if Q1 opens |
| V6a | Qualification rules must follow a saved rule version, online and at POS | **`DECIDED`** 2026-08-31 — app-synced metafields, spike-proven | Sprint 3 |
| V7 | POS Scanner API for the legacy card barcode | `RESOLVED` 2026-08-31 (spike) — usable, with one caveat needing a physical card | Sprint 3 |
| V8 | Extension API version alignment; remove unused function target | `RESOLVED` 2026-08-26 | Sprint 1 |
| V9 | Klaviyo API revision and rate limits | `OUTSTANDING` | Sprint 4 |
| V10 | Store-wide sales figure that reconciles with Shopify analytics | `OUTSTANDING` | Sprint 5 |
| V11 | Customer account UI extension target and deployability under the legacy install flow | `OUTSTANDING` | Sprint 4 |
| **V12** | **Earn base on a tax-INCLUSIVE shop: the order of the discount-allocation and tax subtractions** | `OUTSTANDING` — **BLOCKS GO-LIVE**; unprovable on the dev store, closed by live-store reconciliation | Before production |
| **V13** | **Nothing checks that the shop currency and the rules currency agree** — a live defect, found 3 Sep 2026 | `OUTSTANDING` — fix before go-live; not a gate while both are GBP | Sprint 3 tail |
| **V14** | **A compensating `earn_reversal` carries no `qualifying_value_pence`, so reported spend overstates what the member actually spent** — found 3 Sep 2026 | `OUTSTANDING` — reporting only; points and segmentation are correct | Sprint 5 |
| **V15** | **`@shopify/ui-extensions` installed at 2025.10.16 while the loyalty tile declares `api_version = "2026-07"`** - nothing pins the two together - found 3 Sep 2026 | `OUTSTANDING` - not blocking; resolve before trusting any conclusion drawn from those types | Sprint 3 tail |
| **V16** | **Every till user needs a Privilege Club role and only the first staff member on a shop is bootstrapped** — an unassigned assistant gets `403 no_role_assigned` and the tile looks broken — found 3 Sep 2026 | `OUTSTANDING` — **BLOCKS GO-LIVE**; the implicit-viewer decision is parked next to C9 | Before production |
| **V17** | **The tile discards the reason a request failed**, mapping every error but two to "That search could not be run." — raised 3 Sep 2026 | `OUTSTANDING` — small fix; third time in one day that a swallowed error cost time | Sprint 3 tail |
| **V18** | **The TILL is a second denominator that V13 could not see** — a US-located till reports `USD` while the shop stays `GBP`, so GBP 50 of points would have discounted $50 and printed "£50.00" — found 3 Sep 2026 | **`RESOLVED` 3 Sep 2026** — till currency sent up and compared at `hold()`, failing closed | Sprint 3 |
| **V19** | **The tile listens for `onPress` and `onSubmit`, which POS components never emit** — nine buttons, the results list, the step controls and redeem are all inert — found 3 Sep 2026 | `OUTSTANDING` — **BLOCKS SPRINT 3**; steps 9-12 were never reachable | Sprint 3 |

### V1 — UI layer · `RESOLVED` 2026-08-26, **corrected 2026-08-27**

**Polaris web components with App Bridge.** Confirmed against current Shopify
documentation (`app-home-ui-extension/2026-07`): the `s-*` custom elements need
no npm import, and `@shopify/app-bridge-react` supplies `useAppBridge`. Polaris
React is not the current direction for app home, and `@shopify/polaris` must not
be added as a dependency. **That prohibition stands and is unchanged.**

**Correction, 2026-08-27 — two script tags are required, not one.** This entry
originally said the `s-*` elements "are globally registered", which was read as
*registered by App Bridge*. They are not. The two CDN scripts do different jobs:

| Script | Registers |
| --- | --- |
| `cdn.shopify.com/shopifycloud/app-bridge.js` | `ui-nav-menu`, `ui-modal`, `ui-title-bar`, `ui-save-bar`, plus the `fetch` interception |
| `cdn.shopify.com/shopifycloud/polaris.js` | All 68 `s-*` components — `s-page`, `s-section`, `s-table`, everything the console renders |

App Bridge *reads* `s-page` (it lifts the heading and the action buttons into the
admin title bar, via `querySelector` and a `MutationObserver`), which is why
inspecting its source appears to support the original reading. It never defines
it. Loading only App Bridge gives a working admin navigation above a page of
unstyled text — see the change log entry for 2026-08-27.

`@shopify/polaris-types` is the companion package, and is types only. This app is
JavaScript, so it is not needed.

Consequence for authentication: App Bridge intercepts `fetch` and automatically
attaches the ID token in the `Authorization` header for requests to the app
domain, which is exactly the path the existing `EnsureShopifySession`
middleware already decodes. No new dependency, and no manual token plumbing.

### V3 — Session tokens · `RESOLVED` 2026-08-26

**No new dependency is needed for any of the three surfaces.** All three tokens
(admin, POS, customer account) are JWTs signed with the app secret using HS256,
so `Utils::decodeSessionToken` handles all of them, and `firebase/php-jwt` v7 is
already present transitively via `shopify/shopify-api`.

Verified by probe against the library. What it **does** enforce:

| Case | Result |
| --- | --- |
| Valid token | accepted |
| Wrong signing secret | `SignatureInvalidException` |
| Expired (`exp` past) | `ExpiredException` |
| Not yet valid (`nbf` future) | `BeforeValidException` |
| `alg: none` downgrade | `UnexpectedValueException` |

What it does **not** enforce, and the middleware therefore must:

| Case | Result | Consequence |
| --- | --- | --- |
| Wrong `aud` | **accepted** | The middleware must assert `aud` equals the app client id, or a token minted for another app would be honoured |
| `dest` is another shop | **accepted** | The middleware must take the shop from `dest` and assert it, never trusting a request parameter |

**Side finding, fixed.** `firebase/php-jwt` v7 refuses an HMAC key shorter than
32 bytes. `phpunit.xml` carried a 15-byte `test-api-secret`, which would make
every session-token test fail with `DomainException` rather than an auth error.
It is now a 32-character secret, matching the length of a real Shopify client
secret.

### V4 — What the discount function can read at cart time · `RESOLVED` 2026-08-31 (spike)

**It can read everything online redemption needs.** Proven by compiling the
function to WebAssembly and running it against the real 2026-07 schema, not by
reading documentation: the input query validates, and the compiled function
reads the values and acts on them. 14 fixtures, all passing.

| Needed | Available as | Trustworthy? |
| --- | --- | --- |
| The member's voucher entitlement | `cart.buyerIdentity.customer.metafield(key:)` with **no namespace**, i.e. the app-reserved namespace | **Yes** — only this app can write it |
| The rule values in force | The same metafield, carried alongside the balance | Yes |
| What the customer asked to spend (C1) | `cart.attribute(key:)` | **No** — client-writable |
| The redemption reference | `cart.attribute(key:)`, and echoed on the discount message | No, and it does not need to be |
| Basket and eligible subtotal | `cart.cost.subtotalAmount`, `cart.lines[].cost.subtotalAmount` | Yes |
| Line eligibility | `merchandise … on ProductVariant { product { isGiftCard hasAnyTag(…) } }` | Yes |
| The store, for a till sale | `cart.retailLocation { id name }` | Yes |
| Whether the buyer is who they claim | `cart.buyerIdentity.isAuthenticated` | Yes |

**The finding that matters most is the split.** A cart attribute is writable by
anyone holding the cart. Had the voucher amount come from there, a customer
could set `loyalty_redeem_pence` to the value of their basket and pay nothing.
So the **amount comes from the app-owned metafield** and the attribute may only
ever ask for **less**. There is a fixture named after exactly this
(`a-cart-attribute-cannot-inflate-the-discount.json`), and it is the one to keep
if any others are ever dropped.

Two further consequences, both built:

- An **unauthenticated** buyer gets nothing. `isAuthenticated` is false while a
  buyer merely claims an email, and the metafield would be read from whoever
  they claimed to be.
- The app publishes the **quote**, not the balance. A member holding £50 who is
  offered £20 against this basket must not have £50 sitting somewhere the
  function would give away.

*What the spike does NOT prove*, and what remains a deploy-time check on the
development store: that a metafield written through `metafieldsSet` is visible to
the function at cart time on a live shop, and that POS populates
`retailLocation`. Both are readable in the schema and shaped correctly in the
fixtures; only a real cart can confirm the plumbing.

**V6a, raised by this spike.** A function input query is **static** — it is
compiled into the extension. `hasAnyTag(tags: […])` therefore carries a tag list
fixed at deploy time, which **cannot follow a saved rule version**. With the
launch rules nothing is excluded beyond gift cards, so the two agree today; the
moment OSC configures an exclusion in Settings, the function would not know. The
options are a redeploy on every rule change, an app-written product metafield the
function reads instead, or accepting that online exclusions lag. Not urgent, and
not something to decide silently — recorded rather than chosen.

### V6a — Keeping qualification rules in step with the function · `DECIDED` 2026-08-31

**Decided by the client side, not assumed.** The proposal's confirmed requirement
is that eligible and excluded item rules are **OSC-configurable without developer
intervention** and **apply consistently online and at POS**. Redeploying the
function on every rule change breaks the first; letting online exclusions lag
breaks the second. So neither was acceptable and the mechanism is an app-synced
metafield.

**The mini-spike, run before building the sync, to the same standard as V4.**
Both metafields are readable by the compiled function at cart time — proven
against real Wasm, 18 fixtures.

| Carrier | Readable? | What it can express |
| --- | --- | --- |
| `Input.shop.metafield(key:)` | **Yes** | One answer for the whole store |
| `product.metafield(key:)` per line | **Yes** | A per-product answer |

**The finding that decided the split.** A shop metafield can carry the config,
but it **cannot be applied**: `Product` in the function schema exposes no
enumerable tag list, only `hasAnyTag(tags:)` and `hasTags(tags:)` **predicates
whose arguments are static in an input query**. So the function could read a list
of excluded tags and still have no way to ask a product whether it has one.
Product-level sync is therefore required regardless — it is not a fallback, it is
the only carrier that works.

The two are used for what each is actually good for:

- **Shop metafield** — the shop-wide switch. `online_redemption_enabled` from
  the saved rule version now takes effect without a redeploy, which is the
  requirement working as intended.
- **Product metafield** — one resolved boolean per product. The app evaluates
  the rule, **whatever its mode**, include or exclude, and writes the answer. The
  function never learns what the rule was, which is why an include-only mode
  costs it nothing.

*Consequence, and it is not free:* writing product metafields needs
**`write_products`**, which was not in the set granted on 31 Aug. It has been
added to `shopify.app.toml` and `web/.env`, and needs **one further
re-authorisation**. Flagged rather than slipped in.

*Still to verify on a live shop:* that a shop-owned metafield write is permitted
by the granted scopes. The product and customer writes are ordinary
`metafieldsSet` calls; the shop-owned one is the least certain, and the function
already treats an absent shop config as "enabled", so a refusal degrades to
today's behaviour rather than breaking redemption.

### V7 — POS Scanner API for the legacy card barcode · `RESOLVED` 2026-08-31 (spike)

**Usable.** `shopify.scanner` exposes two things the tile needs:

| API | What it gives |
| --- | --- |
| `scanner.scannerData.current` | A reactive signal of `{data, source}` — the scanned string and which hardware read it |
| `scanner.sources.current` | The scanner sources available: `camera`, `external`, `embedded` |

`sources` is the part that settles the fallback question properly: the tile can
**ask whether a scanner exists** rather than assuming one, and offer keyboard
entry when none does. The fallback is detected, not guessed.

**The caveat, which no amount of code can close.** The API delivers whatever the
barcode encodes, as a string. Whether an OSC legacy Dynamics card encodes
`D-118422` verbatim, or a check-digit format, or a prefixed variant, needs **a
physical card scanned on the device**. Until then the tile treats a scan exactly
as typed input and runs it through the same lookup, which is correct for the
verbatim case and harmless otherwise. If the cards turn out to encode something
else, a mapping goes in one place.

**D6 correction.** The signature is
`applyCartDiscount(type: 'FixedAmount', title: string, amount?: string)` — the
middle parameter is **`title`**, not `reason`. D6 records it as a reason string,
and someone reading that would look for a field that does not exist. The
mechanism is unaffected and is arguably better than assumed: a title is the
discount's **customer-visible name on the receipt**, which is exactly what D6
wanted when it said the till receipt and the audit log should share one
identifier.

### V5 — Function-backed automatic app discount · `RESOLVED` 2026-08-26

Validated against the 2026-07 schema. Two findings, both material:

**1. `functionId` is deprecated; use `functionHandle`.** The handle is the
extension handle from `shopify.extension.toml` — `voucher-discount`. This also
**removes the need for the `shopifyFunctions` lookup entirely**, so M6 no longer
has to resolve a function id at install, and the integration matrix drops one
query.

**2. The mutation requires `read_discounts` as well as `write_discounts`.** The
scope list in the plan carried only `write_discounts`. Since changing scopes
forces every merchant to re-authorise under the classic install flow, this had
to be caught before the single scope request, not after.

The validated shape:

```graphql
mutation CreateVoucherDiscount($startsAt: DateTime!) {
  discountAutomaticAppCreate(
    automaticAppDiscount: {
      title: "Privilege Club voucher"
      functionHandle: "voucher-discount"
      startsAt: $startsAt
      discountClasses: [PRODUCT, ORDER]
      combinesWith: { orderDiscounts: true, productDiscounts: true, shippingDiscounts: true }
    }
  ) {
    automaticAppDiscount {
      discountId
      title
      status
      discountClasses
    }
    userErrors { field message code }
  }
}
```

`DiscountClass` valid values are `ORDER`, `PRODUCT`, `SHIPPING`.

### V8 — Extension API versions and the unused target · `RESOLVED` 2026-08-26

Applied:

- `extensions/loyalty-tile` moved from `api_version = "2026-01"` to `"2026-07"`.
- The discount function target
  `cart.delivery-options.discounts.generate.run` removed from
  `shopify.extension.toml`, along with its source, input query and three test
  fixtures. Nothing in the Blueprint uses it, and an unused entry point reads as
  load-bearing to the next person.
- Two guard tests added to `ShopifyConfigurationTest`: every extension manifest
  declares the same `api_version` as the Admin API, and the voucher function
  declares only the cart-lines target. Nine tests pass.

---

### V12 — Earn base on a tax-inclusive shop · `OUTSTANDING` · **blocks go-live**

Raised 2 Sep 2026 while fixing the C14 defect. The earn base is now
`gross − allocations − tax` when the order is tax-inclusive and
`gross − allocations` when it is not. **Only the tax-exclusive branch has been
proved against a real shop.**

*Why it matters and why the dev store cannot answer it.* OSC sells VAT-inclusive,
so the tax-inclusive branch is the one that ships. The development store is
`taxesIncluded: false` and paid order `#1002` carried no tax lines at all, so
nothing has confirmed the arithmetic on the branch that will actually run.

*The specific doubt.* Shopify computes a line's `taxLines` on the amount actually
charged — after the order-level allocation — whereas `discountedTotalSet`
excludes that allocation. The two figures are therefore on different bases, and
subtracting both from `gross` may double-count the tax on the discounted portion.
Getting it wrong under-pays the member, which is the mirror of the C14 defect and
just as invisible.

*Why the development store cannot settle it — established 3 Sep 2026, and this
is now final.* Three facts compose into a wall. The dev store's merchant address
is in the **United States** (`shop.billingAddress.countryCodeV2` reads `US`);
Shopify's UK rules for a merchant located outside the UK do not require VAT to be
collected at checkout on orders **over £135**; and the store address country is
**locked on this store**, so the first fact cannot be changed. Every UK checkout
above £135 therefore reverts to base pricing at the payment step, with no VAT
line to reconcile.

It presents as a "Price update" modal on pressing Pay: the cart and checkout show
the VAT-inclusive £720.00 that `Dynamic tax display` computes for a GB visitor,
then checkout resolves that it will not collect UK VAT and re-prices to the
stored £600.00. Reproduced twice on 3 Sep 2026 and abandoned deliberately rather
than paid, because a £600 order with `taxesIncluded: false` and no tax lines is
order `#1002` again and proves nothing. Confirmed independently by the
abandoned checkout from 2 Sep 2026 19:34Z, which shipped to `SP4 6AB` and still
recorded `taxesIncluded=false`, `tax 0.0`, no tax lines — after UK VAT collection
had been enabled.

A sub-£135 basket would be charged VAT even by a US-addressed store and would
exercise the branch, but the £50 voucher against a basket that small collides
with the D8 ladder rungs and is not the shape production has. Rejected as a
route.

*What settles it — the only remaining route.* **Reconcile one real discounted
order on the live store before launch**, by hand: take the order's
`discountedTotalSet`, its `discountAllocations` and its `taxLines`, compute
`gross − allocations − tax`, and check the earn the member actually received
against the VAT-exclusive value they actually spent. If Shopify computes
`taxLines` post-allocation, as the code assumes, the two agree; if pre-allocation,
the tax on the discounted portion is double-counted and the member is under-paid.
`OrderPayloadMappingTest::test_tax_inclusive_takes_off_both_the_allocation_and_the_tax`
locks in the current behaviour so a change to it is deliberate; it does not vouch
for it.

For the record, the arithmetic the dev store *would* have produced had it been
able to charge VAT: gross £720.00, allocation £50.00, tax £111.67, giving a base
of £558.33 — which equals the cross-check from the other direction, £600.00
ex-VAT less the voucher's ex-VAT value of £41.67. The two bases agreeing is the
whole of what V12 asks. That agreement is arithmetic, not evidence: it holds only
if Shopify's `taxLines` are post-allocation, which is precisely the fact no test
can supply.

*Further corroboration, 3 Sep 2026.* POS Pro was confirmed Active on both
development-store locations, and **neither is in the UK**: "Shop location"
(United States, the store default) and "My Custom Location" (Toronto, Canada).
So the store cannot present a UK-domiciled merchant from any angle - locked US
merchant address, and now US and Canadian POS locations. The addresses were
deliberately left unchanged: fewer moving variables while the POS tile is being
diagnosed is worth more than a UK address that still would not clear the £135
rule. Nothing here reopens the dev-store route.

*Status.* `OUTSTANDING`, and a **hard go-live gate** on the checklist in
`PROGRESS.md` — not merely noted. Two sessions were spent trying to settle it on
the development store; that route is closed and should not be reopened.

### V13 — The shop currency and the rules currency are never compared · `OUTSTANDING`

**A live defect, not a validation question.** Found 3 Sep 2026 when the
development store's base currency changed to EUR mid-session and the app carried
on as though nothing had happened.

*What happens now.* `AdminApiDiscountCodeWriter::create()` takes `currencyCode`
from `RuleSet::currency()` and sends an amount; Shopify denominates that amount
in the **shop's** currency and ignores ours. With rules on GBP and the shop on
EUR it minted a **€50** voucher for a programme denominated in pounds, with
`status: ACTIVE` and every other constraint correct — a plausible-looking code
in the wrong money. At a GBP checkout it would have applied about £43.66.

*Why it is worse than a display bug.* `RedemptionService::pointsFor()` is
currency-blind: it divides an amount in pence by `voucherValuePence` with no
notion of which currency those pence are. Confirming that €50 code would have
spent exactly 1000 points — identical to a £50 one — at whatever the exchange
rate happened to be. The ledger would balance and the member would be wrong.

*The fix.* The writer should refuse to mint when the shop currency and the rules
currency disagree, and say so loudly rather than produce a code. Cheap, and it
would have caught this the moment it happened instead of at a checkout. Worth
considering whether `pointsFor()` should assert a currency too, rather than
trusting every caller.

*Exposure.* Low in production while OSC's store and the rules are both GBP, so
this is not a go-live gate — but the failure mode is silent, which is exactly
the kind this register exists to stop being rediscovered. **Mine to have missed
when the writer was written on 2 Sep 2026.**

### V14 — A compensating reversal carries no qualifying value · `OUTSTANDING`

Raised 3 Sep 2026, found while reading the ledger that the C14 replay left
behind. Reporting only: no points figure and no segment is wrong because of it.

*What is on the ledger.* Account 10, after order `#1002` was replayed to correct
its earn:

```
22  earn            qvp=60000  parent=NULL  key=earn:order:7134863884528:t600
25  earn_reversal   qvp=NULL   parent=22    key=earn:order:7134863884528:t550
```

Earning is cumulative and corrects by compensation, so the pair is right and the
net earn is the intended 550 points. But `qualifying_value_pence` is carried on
the original entry alone. The reversal leaves it `NULL`, so **the sum over the
account still reads 60000** — £600 — while the member's qualifying spend on that
order was £550. Reported spend overstates real spend by exactly the corrected
amount, and it will do so for every replay, refund reversal and manual
correction, silently and cumulatively.

*Where it surfaces.* Two call sites, both aggregations over the same column:

- `ProgrammeSummary` — `->sum('qualifying_value_pence')`, the programme-level
  spend figure.
- `MemberPresenter` — `coalesce(sum(qualifying_value_pence), 0) as spend`, the
  per-member spend shown in the console.

*Where it does not surface.* Points, balances and maturity never read the
column. Segmentation does not either: `SegmentSweep` works from
`LastSpendResolver` and a last-spend **date**, not an amount, so Active/Lapsed is
unaffected. This is why it is a Sprint 5 reporting item and not a defect in the
ledger.

*The question to settle, which is why this is a gate and not a fix.* Should a
compensating entry carry a **negative** `qualifying_value_pence` (here `-5000`,
making the sum 55000), or should reported spend be derived some other way — for
instance by summing only entries whose `parent_entry_id` is `NULL` and applying
corrections separately? The first is a one-line change at the posting site and
makes every existing aggregation correct without touching them. It also makes the
column mean "the qualifying value this entry accounts for" rather than "the
qualifying value of the order", which is a change of meaning that reporting in
Sprint 5 has to agree with before it is made. **Do not fix this ahead of the
Sprint 5 reporting design**; a silent change of meaning in a column that
`ProgrammeSummary` already aggregates is how the C14 defect happened.

*Exposure.* Live from the moment any correction is posted, which has already
happened once on the development store. Nothing on the production store yet.
Related: C14, V12.

### The development store's market configuration, read rather than inferred · `SETTLED` 2026-09-03

Two sessions were spent inferring this from `contextualPricing`, because the app
had no `read_markets` scope and the Markets admin screen showed no market
labelled "Primary". The scope was added on 3 Sep 2026 and the configuration read
directly. Recorded here so nobody infers it a third time.

```
SHOP CURRENCY: GBP        MARKETS: 3

United Kingdom  (united-kingdom)   status ACTIVE   type REGION
   base currency    GBP
   localCurrencies  false      roundingEnabled true
   inclusiveTax     INCLUDES_TAXES_IN_PRICE_BASED_ON_COUNTRY
   inclusiveDuties  ADD_DUTIES_AT_CHECKOUT
   adaptivePricing  false
   regions          United Kingdom

Canada          (canada)           status ACTIVE   type REGION   base CAD
United States   (us)               status ACTIVE   type REGION   base USD
```

**All three markets are ACTIVE.** The working hypothesis that the UK market was
in `DRAFT` — which would have explained a cart pricing at £720 and a checkout
refusing to — was wrong. Nothing in Markets is half-configured.

**There is no primary market, and that is not a fault.** On API version 2026-07
`MarketType` is `NONE, REGION, LOCATION, COMPANY_LOCATION, CHANNEL`; there is no
`PRIMARY` value, and `Market` carries no `primary` or `enabled` field at all —
both were removed and a query written against them fails outright. The admin
list showing no "Primary" label was telling the truth rather than hiding a
misconfiguration, and the earlier suspicion that "the shop baseline follows the
primary market" could not have been checked the way it was framed.

**`INCLUDES_TAXES_IN_PRICE_BASED_ON_COUNTRY` is the £720.** £600 × 1.2, from
configuration rather than deduced from a price. This is the field the 2 Sep notes
guessed at as `MarketPriceInclusions.inclusiveTaxPricingStrategy`, now read.

*What this means for V12, and it is the important part.* **The market
configuration is correct.** The UK market is active, GBP, and set to include VAT
in displayed prices — everything V12 needs from Markets is already true. V12 is
unprovable on this store purely because the merchant address is locked to the
United States and Shopify does not require a non-UK merchant to collect UK VAT
on orders over £135, so checkout re-prices to the £600 base at the payment step
whatever Markets says. **Do not change anything in Markets hoping to unblock
V12; there is nothing there to fix.** See the V12 entry.

*What this means for V13.* The UK market's base currency is GBP, so the
precondition V13 guards is genuinely satisfied rather than apparently so. Note
that the guard deliberately compares the **shop** currency, not the market's:
Shopify denominates a discount amount in the shop currency regardless of which
market a customer is in, so the shop-level read is the correct check and reading
markets does not change it.

*Dev-store state as left on 3 Sep 2026.* Base currency GBP, UK VAT collecting,
tax display `Dynamic`, the three markets above, gift card variants stocked and
sellable, one free `Standard` shipping rate and one £5.82 rate that was priced
during the EUR window and needs deleting and re-adding.

### V15 — `@shopify/ui-extensions` is on 2025.10.16 while the extension declares `api_version = "2026-07"` · `OUTSTANDING`

Raised 3 Sep 2026 while diagnosing why the POS tile's search request never
reaches the backend.

*What the skew is.* `extensions/loyalty-tile/shopify.extension.toml` declares
`api_version = "2026-07"`, and `ShopifyConfigurationTest` keeps that in step with
the Admin API version. The installed types package is
`@shopify/ui-extensions 2025.10.16`. The extension has no dependency on it of its
own — `extensions/loyalty-tile/package.json` lists only `vitest` — so the version
comes from the root install and nothing pins it to the declared api_version or
fails the build on drift, unlike the api_version check itself.

*Why it matters, and it is narrower than it sounds.* It does not change what the
POS host does at runtime: the host provides the `shopify` global, and a types
package cannot alter it. What it does undermine is **evidence**. The current
diagnosis of the tile — that `shopify.environment` is not part of the POS API
surface, because `appUrl` appears nowhere in the package and `StandardApi`
composes no `EnvironmentApi` — is drawn from the 2025.10.16 types. If the surface
gained an `environment` in 2026-07, that reasoning is reading a stale map.
`StandardApi`'s `{[key: string]: any}` index signature means the types would not
have complained either way, so this is exactly the case where a stale package is
invisible.

**How to apply.** Do not treat any conclusion about what the POS host injects as
settled until the installed package matches the declared api_version, or until
the runtime output from the modal-open diagnostic contradicts or confirms it
directly. Runtime evidence outranks the types here.

*Narrowed 3 Sep 2026, same day.* The specific conclusion about `appUrl` no
longer rests on the types at all: the POS UI extensions documentation for
**2026-07** states that no API gives an extension its app URL, and its own
worked example hardcodes `https://YOUR_DEVELOPMENT_SERVER/api/...`. So the
absence of `shopify.environment` is documented for the declared version, not
merely absent from a stale package. The skew still matters for every OTHER
inference drawn from those types, which is why this stays open.

*Not blocking.* The modal-open diagnostic reads the runtime rather than the
types, so it is authoritative regardless of which package version is installed.
Resolve the skew before relying on the types again - and consider whether the
extension should depend on `@shopify/ui-extensions` explicitly, pinned, with
`ShopifyConfigurationTest` asserting it against the declared api_version the way
it already does for the Admin API version.

### Testing principle — if a test supplies what production derives, something else must test the derivation · `RULE` 2026-09-03

Four instances in two days, which is well past bad luck. Twice now the
untested seam was a **single word**.

**C14, 2 Sep 2026.** `AdminApiOrderSource` read a line's `discountedTotalSet` and
never its `discountAllocations`, so an order-level discount landed nowhere and a
member earned on money they had not spent. The whole suite was green, because
every fixture built an `OrderSnapshot` or a `lines` array **by hand** — each test
started *after* the mapping and asserted arithmetic it had itself been fed. The
payload-to-snapshot step was the only step no test exercised, and it was the step
that was wrong.

**The POS tile base URL, 3 Sep 2026.** `Modal.jsx` built its API client with
`appUrl: shopify.environment?.appUrl ?? ''`. `shopify.environment` is not part of
the POS surface, so this was always `''`, every till request went to a relative
path that reaches nothing from the extension sandbox, and search silently
returned no members for as long as the tile had existed. `tests/tile.test.js`
constructs `TillApi` with `appUrl: 'https://app.example.com'` — so again, the one
line that was wrong was the one line no test touched.

**And in miniature, the same day.** `"a staff member with no app permission is a
state, not a crash"` passed `appUrl: ''` as incidental scaffolding while its
subject was the missing *token*. The moment `TillApi` learned to refuse an
unusable base, that test stopped reaching its own subject. An arbitrary fixture
value had been deciding what the test proved.

**`onPress`, 3 Sep 2026 — the fourth, and the one that hid the most.** `Modal.jsx`
wired nine controls to `onPress` and the search field to `onSubmit`. POS emits
neither, so every button, the member list, the step controls, the redeem button,
both enrol paths, cancel and back were inert. `tile.test.js` passed 31 tests
throughout, because it imports only `src/lib/` and **never imports the JSX at
all** — so the handler names were not merely under-tested, they were outside the
suite's reach by construction. Its docblock said so in as many words, and read
as a design note rather than the warning it was.

The generalisation this forced: the pattern is not only about a test *supplying*
a value. It is about a test **standing where the defect cannot be seen from**.
Supplying `appUrl` and never importing `Modal.jsx` are the same mistake at
different distances. `handlerContract.test.js` answers it by checking the JSX
against the platform's own type declarations, and was verified by reintroducing
the defect — four failures, naming `<s-button onPress=` in the output.

**The rule.** *If a test supplies what production derives, something else must
test the derivation.*

Not "never supply a value" — the request-shaping tests in `tile.test.js` pass
`appUrl` in deliberately and correctly, because their subject is headers,
methods and payloads. They are simply not evidence about *where* a request goes,
and nothing should read them as if they were.

**How to apply.** Two questions when writing or reviewing a test:

1. **What did I hand this code that it would normally work out for itself?**
   That value is uncovered until something else covers it. `OrderSnapshot`,
   `lines`, `appUrl` — each was a hand-supplied stand-in for a derivation.
2. **Can the derivation even be called?** An inline expression at a construction
   site cannot be reached by a test. Both fixes turned the derivation into a
   named, importable unit before testing it — `AdminApiOrderSource::mapOrder()`
   and `resolveAppUrl()`. Extracting the seam is part of the fix, not tidying
   afterwards.

**And prove the test would have failed.** The C14 mapping tests were verified by
reintroducing the defect: 5 of 7 failed, on the exact 60000-against-55000
figures. The base-URL test constructs `TillApi` with no `appUrl` at all and
asserts `fetchImpl` was never called, which is the precise condition the device
was in. A regression test nobody has seen fail is a hypothesis.

### V16 — Every till user needs a Privilege Club role, and only the first one is bootstrapped · `OUTSTANDING` · **blocks go-live**

Found 3 Sep 2026, on the first real POS session token ever to reach the backend.

*What happened.* Search worked from the deep-link preview surface and then failed
from POS proper with a generic "That search could not be run." The cause was not
the tile: `EnsureStaffRole` resolved the POS user through `staff_roles`, found
nothing, and returned **403 `no_role_assigned`**. The preview surface had
authenticated as the account that installed the app — staff id
`113711382768`, bootstrapped as Administrator on 27 Aug 2026 — while the device
was signed in as a different Shopify user, `113711437936`.

*Why it is a go-live gate and not a dev-store quirk.*
`StaffRoleResolver::bootstrapFirstAdministrator()` grants Administrator to the
**first** staff member on a shop with no roles, once per shop, and by design
"an unrecognised staff member on a shop that already has roles gets nothing".
That is correct for the console, where an Administrator assigns roles
deliberately. It is a problem at a till: **every OSC staff member who opens the
tile needs a role assigned before it will do anything.** A new Saturday
assistant gets a 403, and to them the app is simply broken.

*The decision to make, parked next to C9.* Should POS staff receive an implicit
`viewer` floor — enough to look up a member and see a balance, with `agent`
still required to hold or void a redemption? C9 already asks whether a till
assistant on a lower role should be able to act at all, and this is the same
question arriving from the other direction. **Not to be settled now.** The
options, so nobody re-derives them:

1. **Implicit viewer for a verified POS token.** The tile works for everyone the
   moment POS is signed in; redemption still needs an assigned `agent`. Cheapest
   for OSC, and it means a verified staff identity is trusted for *reading* where
   today it is trusted for nothing.
2. **Explicit assignment for everyone.** No code change. Requires an onboarding
   step per staff member, and a documented one, or this recurs on every new hire.
3. **Bootstrap on first POS use** the way the console bootstraps the first
   Administrator. Rejected on sight for granting a role to whoever happens to
   open the tile first, but recorded because it will be suggested.

*What must happen before go-live either way.* If option 2 stands, the onboarding
step goes in the runbook and OSC has to be told. If option 1 is chosen it is a
change to `EnsureStaffRole` with its own tests. **Neither is done, and the tile
is unusable by any unassigned staff member until one of them is.**

*Dev-store state.* `113711437936` was granted `agent` directly in the database
on 3 Sep 2026 to unblock the POS run — not through
`PUT /api/admin/staff/{staffId}`, so there is no audit row for it. See V17 for
the separate matter of the tile hiding the reason.

### V17 — The tile discards the reason a request failed · `OUTSTANDING`

Raised 3 Sep 2026. Small fix, and the third time in one day that swallowing a
specific error cost real time.

*What it is.* `Lookup` in `Modal.jsx` maps every failure to one of two strings:

```js
response.error === 'unreachable'
  ? 'The loyalty system could not be reached. Continue the sale.'
  : response.error === 'no_app_url'
    ? 'Loyalty is not configured for this device. Continue the sale and report it.'
    : 'That search could not be run.'
```

Everything else lands in that last branch. So `EnsureStaffRole` returning
**403 `no_role_assigned`** with the message *"This staff member has no Privilege
Club role. An Administrator must assign one."* — a reason precise enough to act
on without help — reached the till as "That search could not be run."
`TillApi.request()` already captures `payload.error.code` and
`payload.error.message`; the tile simply drops them.

*The cost, three times on 3 Sep 2026.*

1. `appUrl` empty produced a relative request that reached nothing, and the tile
   reported no members — indistinguishable from a member who does not exist.
   Fixed, and `no_app_url` now has its own words.
2. The 403 above presented as the same generic string, and the diagnosis needed
   a resolver test and a `staff_roles` query to reach a conclusion the response
   body already stated.
3. In miniature: the search failure and an empty result set look identical to
   whoever is standing at the till, so "it did not work" is all anyone can
   report.

*Why it matters more at a till than in the console.* There is a customer waiting
and no developer present. The person who can act on *"an Administrator must
assign one"* is the assistant reading it; nobody can act on "that search could
not be run".

*The fix.* Surface the reason. Map the codes the backend actually returns —
`no_role_assigned`, `role_floor_not_met`, `invalid_session_token` — to wording a
staff member can act on, and fall back to the server's own
`payload.error.message` when there is one rather than to a generic string.
`lib/reasons.js` already exists for refusal wording and is the natural home.

*The rule this is an instance of.* A specific, actionable error that the client
replaces with a generic one is worse than no message, because it destroys
evidence that was already in hand. Related: the testing rule recorded at
`RULE 2026-09-03`, which is the same failure in a different medium — evidence
discarded rather than never gathered.

### V19 — The tile listens for events the POS components never emit · `OUTSTANDING` · **blocks Sprint 3**

Found 3 Sep 2026, diagnosing why member search does nothing on Android POS. The
search box was the first symptom, not the defect.

*What is wrong.* `Modal.jsx` wires its controls to `onPress` and `onSubmit`.
Neither is an event these components emit. Preact maps an `onX` prop on a custom
element to a listener for event `x`, so a wrong name attaches a listener that
never fires — silently, with no error and no warning.

| Handler | Sites | Event emitted? | Result |
| --- | --- | --- | --- |
| `onInput` | 7 | yes | works — the hint line under the search box updates as you type |
| `onSubmit` | 1 | **no** | the search can never be run |
| `onPress` | 9 | **no** | **every button, the results list, the step controls, redeem, enrol and cancel are all dead** |
| `onClick` | 1, in `Tile.jsx` | yes | works — the tile opens the modal |

*The evidence, from two independent directions.*

From the types: `ClickableJSXProps` exposes `onClick`; `ButtonJSXProps` exposes
`onClick`; `SearchFieldJSXProps` exposes `onInput`, `onChange`, `onBlur` and
`onFocus` and **no `onSubmit`**; and `onPress` appears in **zero files** in the
whole of `@shopify/ui-extensions`.

From the device, which matters more because V15's version skew makes the types
untrustworthy on their own: `Tile.jsx` uses `onClick` and the tile opens;
`onInput` fires and the hints move; `onPress` and `onSubmit` produce nothing at
all. **The device has already run the experiment on both names.** So this
conclusion does not rest on the types, which is the only reason it can be
trusted while V15 is open.

*What it actually costs.* Not just search. Every one of these is inert:
selecting a member from the results (`s-clickable`), both enrol entry points, the
£5 step up and step down controls, **the redeem button**, enrol submit, cancel
and back. **Steps 9 to 12 of the dev-store script were never reachable**, and
would not have been even if search had worked.

*Why the deep-link preview surface appeared to work.* Search ran there this
morning and the results list rendered. Most likely that surface renders through a
browser-based host where `s-search-field` wraps a native search input inside a
form, so pressing Enter fires a native, bubbling `submit` — while native Android
POS has no form and emits nothing. **This is inference, not established.** Worth
noting that nobody ever tapped a result on that surface either: "searches return
the member" meant the list appeared, which needs no handler at all.

*Why no test caught it, and this is the fourth instance.* `tile.test.js` states
its own scope in its docblock — *"The POS tile's logic, tested without rendering
POS"* — and imports only from `src/lib/`. **It never imports `Modal.jsx` or
`Tile.jsx`.** So the pure functions are thoroughly tested and every event name is
entirely uncovered. Same shape as C14 and the `appUrl` defect: the wiring is
invisible to tests that exercise what the wiring calls. See the rule at
`RULE 2026-09-03`; this is its fourth instance in two days and the second where
the untested seam was a single word.

*The fix, not yet built.* Rename the nine `onPress` handlers to `onClick`. Replace
the search field's `onSubmit` with a **visible search button** using `onClick` —
which is also the right answer on its own merits, because a soft-keyboard submit
is not a discoverable affordance for a till user: an assistant types a name, sees
nothing, and has nothing to press. That was raised separately and is absorbed
here. Then add coverage that asserts the handler names the components actually
support, rather than testing `lib/` alone.

*Not to be confused with V17.* V17 was the tile discarding a reason it had been
given. This is the tile never asking. Both were invisible for the same underlying
reason: a failure with no wording looks exactly like a feature that does nothing.

### V18 — The till is a second denominator, and V13's guard could not see it · `RESOLVED` 2026-09-03

Found and fixed 3 Sep 2026, from `cur=USD` on a live Android device.

*Why V13 was not enough.* V13 made `hold()` compare the **shop** currency with
the rules currency. For OSC both are GBP, so that comparison passes — and a POS
sale is transacted in the currency of its **location**, which the comparison
never looks at. On the development store, "Shop location" is in the United
States and its session reports `USD` while `shop { currencyCode }` stays `GBP`.
Two different denominators, and V13 only knew about one.

*What would have happened.* `shopify.cart.applyCartDiscount('FixedAmount', title,
'50.00')` passes a **bare number** and lets the till denominate it, exactly as
`discountCodeBasicCreate` lets the shop denominate. So 1000 points priced at
£50 would have discounted **$50**, while `formatPence` printed **"£50.00"** in
the receipt title. Wrong money, and a receipt contradicting the sale it is
printed on. `session.currency` — which POS does provide, and which the `Session`
type has carried all along — was **never referenced anywhere in the tile**.

*The fix.* The till currency is sent up rather than assumed:
`Modal.jsx` reads `session.currency`, `TillApi.hold()` sends `till_currency`,
`RedemptionController` validates it as a three-letter code, and `hold()` compares
it with `RuleSet::currency()` **for the POS channel only** — there is no till in
an online checkout and requiring one there would refuse every web redemption.
`TILL_CURRENCY_MISMATCH` and `TILL_CURRENCY_UNKNOWN` are separate reasons, and
the tile gives both wording that ends with *continue the sale*.

**It fails closed.** A POS hold that does not state a currency is refused, not
trusted: an unverifiable POS redemption is precisely what this guard exists to
stop, and nothing in production has ever held one, so there was no compatibility
to preserve.

*What that cost, and why it was worth it.* Failing closed broke **eight existing
POS tests**. Every one of them was holding at a till without stating what
currency the till was in — so the breakage was the guard doing its job on the
suite before it ever saw a shop. Each now states it. Two in `CurrencyGuardTest`
needed it specifically so they keep reaching their own subject rather than being
refused earlier for a different reason; that is the same incidental-fixture trap
as the `appUrl: ''` one, and it is noted at the site.

*Verified by removing the guard*, which fails 4 of the 8 new tests in
`TillCurrencyGuardTest` — including one that asserts explicitly that V13's
comparison is satisfied while V18's is not, so the gap between them is a test
rather than a paragraph.

*What this does NOT fix, deliberately.* The hardcoded `£` in
`PosCartDiscountGateway::discountTitle()` and in the tile's `formatPence()`. Both
are now correct **by construction** rather than by check: a till whose currency
disagrees with the rules cannot hold a redemption at all, so nothing reaches
those strings in the wrong money. If OSC ever runs a non-GBP programme they
become wrong again, and the guard will not catch it because both sides would
agree. Recorded rather than fixed, because inventing multi-currency formatting
for a single-currency programme is scope nobody asked for.

*Exposure before the fix.* Nil in production — OSC's tills are UK. Live on the
development store, which is exactly where it was found.

## 7. Change log

| Date | Change |
| --- | --- |
| 2026-08-25 | D5 and D6 closed by spike. Blueprint revised to Rev 2. |
| 2026-08-26 | Implementation Plan published. C1–C8, Q1–Q5, V1–V11 registers created. |
| 2026-08-26 | D1, D2, D10 approved as recommended by client. Sprint 1 authorised. |
| 2026-08-26 | Migration discovery feedback applied: MD1–MD10 confirmed; D4 and C7 closed; D10 migration branch refined. MD1 and MD2 applied to the schema and verified. C9 raised as a new pending item. |
| 2026-08-26 | C6 mechanism confirmed and answered for the development store (shop owner of `loyalty-system.myshopify.com`). The named person for the live store is pending client confirmation — **ask OSC / Robert**. Sprint 1 unblocked. |
| 2026-08-26 | Week-zero validations: V1, V3, V5 and V8 all resolved. V5 changed the scope list (`read_discounts` added) and simplified M6 (`functionHandle` removes the `shopifyFunctions` lookup). V3 found and fixed a too-short test secret in `phpunit.xml`. |
| 2026-08-27 | Sprint 1 API endpoints built. C11 raised: the club card number is derived from the account id rather than stored, pending an OSC position on physical cards. |
| 2026-08-27 | **V1 corrected.** The console shipped rendering as unstyled text: `index.html` loaded `app-bridge.js` only, and the Polaris `s-*` components come from a second script, `polaris.js`. App Bridge registers the `ui-*` elements and reads `s-page` but defines no `s-*`, so the admin navigation worked while every page did not. Both tags are now loaded, with a boot guard and a document test against recurrence. |
| 2026-08-27 | **Sprint 1 complete.** 16 admin endpoints, each role-guarded and audited; every Sprint 1 screen built (A1–A5, A10, A12) and verified in a browser. Stubbed to their owning sprints: the enrol-member modal (M1, endpoint already built and tested) and every export (Sprint 5 Reports). 209 backend tests / 1,073 assertions and 128 frontend tests, all green. |
| 2026-08-27 | **D3, D8 and D9 marked `ASSUMED`** — built to their recommendation, client notified, awaiting an objection rather than an approval. D3 (earn base net of VAT and shipping) and D9 (proportional refund restore) no longer block Sprint 2; D8 (the redemption ladder order) is fixed in the shared arithmetic fixtures as the Sprint 3 contract. |
| 2026-08-27 | **D7 marked `IN PROGRESS`** — `read_all_orders` requested through the Partner Dashboard. Segmentation is built from our own ledger plus the migrated last-spend date regardless, so nothing waits on the grant and it can land at any time, including after go-live. |
| 2026-08-27 | Register reconciled: **C4 and C6 (live store name) are the only items awaiting Robert.** C2 recorded as assumed alongside D9; C1, C3, C5, C8 and C9 deferred to the sprints that need them. |
| 2026-08-31 | **C4 `CONFIRMED` by the client: lapsed members do receive the £10 birthday voucher.** Eligibility is date of birth on record only — day and month sufficient per MD2 — and the birthday job carries **no Active/Lapsed check**. The job is Sprint 2 and unwritten, so nothing had to be unwound; its selection is `dob_month`/`dob_day` over `ix_account_birthday`, with no join to segment state. C4 promoted from a table row to a full entry. |
| 2026-08-31 | **C6 mechanism confirmed in full: the Administrator role has full access to the admin console and to all administrative functions**, and is the only role that can create another Administrator. The **named live-store Administrator remains `PENDING`**, to be confirmed by OSC before go-live, and is kept on the **go-live checklist**. |
| 2026-08-31 | **D7 restated: the `read_all_orders` request is submitted to Shopify and awaiting approval.** Timing remains Shopify's; segmentation continues from our own ledger plus the migrated last-spend date, so nothing waits on the grant. |
| 2026-08-31 | **D3, D8 and D9 given one uniform status line** — "ASSUMED — implemented to the industry-standard default, client notified 27 Aug 2026, pending client objection (not approval)." — replacing three differently-worded paragraphs, so the posture reads identically on all three and cannot be misread as awaiting an approval. |
| 2026-08-31 | Register reconciled: **C6 (live store name) is now the only item awaiting Robert**, and it is a name on the go-live checklist rather than a decision. Nothing outstanding blocks Sprint 2. |
| 2026-08-31 | **D9a–D9d recorded as sub-decisions of D9**, all `ASSUMED` on D9's posture: they are mechanics inside the proportional rule the client was notified of on 27 Aug 2026, not new client asks. **D9a** restored points return to their original lots as negative *release* allocations, keeping their original expiry. **D9b** maturity matures the earn net of reversals. **D9c** rounding is a cumulative floor, never a per-refund fraction. **D9d** a partial refund keeps the redemption's state and tracks the reversed value; only a full reversal flips it. |
| 2026-08-31 | **D9a found a schema fault that no test could see.** Relaxing `ck_alloc_points` to `points <> 0` was not sufficient: `loyalty_lot_allocations.points` was `unsignedInteger`, which on MySQL rejects a negative release before any CHECK is consulted, while SQLite — where the suite runs — does not enforce unsigned at all. The column is now signed, verified against MySQL 8.4 by probe, and a guard test reads the migration source so a SQLite-only run cannot reintroduce it. |
| 2026-08-31 | **C12 confirmed: automated work is audited, at one entry per sweep run and one per order.** System actor via `RequestContext::forJob()` / `::forWebhook()`, `actor_name` null so the console renders "System" as the agreed UI does. Per-member detail stays in the ledger; the audit log answers who did what. Four new actions: `job.completed`, `job.failed`, `order.earned`, `order.reversed`. |
| 2026-08-31 | **Sprint 2 completed.** `orders/paid`, `orders/updated`, `orders/cancelled` and `refunds/create` handlers, all fast-acknowledging and queueing; `EarningCalculator` and `QualifyingValueResolver` per D3; `loyalty:replay-orders`; the Klaviyo `EventBus` with its null driver and all five Sprint 4 events emitting from real call sites. Backend **322 tests / 1,588 assertions**, frontend **134**. |
| 2026-08-31 | **M3's open validation closed: `orders/updated` is registered, not `orders/edited`.** Earning is cumulative and re-reads the order, so the broader topic is sufficient and strictly safer — `orders/edited` carries only the edit delta, which this design does not need and would not trust. |
| 2026-08-31 | **The full scope set requested in one change**: `read_customers`, `write_customers`, `read_orders`, `read_discounts`, `write_discounts`, `read_locations`, `read_products`, in `shopify.app.toml` and `web/.env` together. Under the legacy install flow this forces **one merchant re-authorisation**, which is a merchant action and is on the go-live checklist. |
| 2026-08-31 | **Three MySQL-only defects found and fixed, none of which the SQLite suite could see.** (1) `loyalty_lot_allocations.points` was unsigned, so D9a's negative release was refused by the column type before any CHECK. (2) MySQL's JSON columns do not preserve key order, so `qualification` read as changed on every rules save — in the **Settings diff the user confirms** and in the audit entry, both now compared through `Support\Data\Canonical`. (3) `MemberCardNumber::parse()` stripped non-digits, so a postcode search for "SP4 6AB" became a primary-key lookup for account 46 and returned **an unrelated member's record**; it now requires the input to consist of digits. The suite is green on MySQL as well as SQLite for the first time, and **each of the three now has a regression test that fails on SQLite too** — verified by reintroducing each defect in turn: the unsigned column is caught by reading the migration source, the key ordering by `CanonicalTest` and `JsonKeyOrderRegressionTest` building the reordered payload by hand, and the parser by a pure unit test. |
| 2026-08-31 | **`orders/paid` wired to confirm a held redemption.** A paid order now spends the points it was quoted, found by **the reference on its discount** — the only link between an order and its quote, since at quote time no order exists. D6's shared identifier turns out to be load-bearing rather than decorative, and one code path confirms both channels: an `AutomaticDiscountApplication` online, a manual one from `applyCartDiscount` at the till. `RedemptionReference` owns the format because it is now read as well as written. Four guards, each tested: the quote must belong to the order's member (the reference is printed on a receipt, so it is not a secret), a voided quote does not spend, an unknown reference is ignored, and a redelivery spends once. |
| 2026-08-31 | **C13 `CONFIRMED`: an expired quote is voided, its entitlement withdrawn, and nothing re-quoted.** Built as `QuoteExpirySweep` / `loyalty:expire-quotes`, every five minutes. The behaviour was flagged as an assumption when built and has been confirmed as decided; nothing about the sweep changes. |
| 2026-08-31 | **Gap recorded, then closed: `quote_expires_at` was written and nothing enforced it.** A twenty-minute quote stands indefinitely. Mostly harmless — the function re-applies the ladder to the live cart — but if a member's points expire while a published entitlement is still readable, confirming it would spend points they no longer have and drive the balance negative, which is not what Q3 permits. The fix is a sweep on the existing schedule; what a stale quote should do is a behaviour question and is left open. |
| 2026-08-31 | **Sprint 3 tile built and testable.** POS endpoints (quote / hold / void) with the Sprint 1 role floors intact; the tile's lookup, member view, £5-step control, on-screen refusal reasons, enrol-at-till and attach-to-sale. **Validation happens before an amount is offered** — the control is built from a server quote, so pressing redeem cannot fail on arithmetic. C7's offline state asserted directly. A dev-store test script is handed over for the three things only a live cart can prove. |
| 2026-08-31 | **V6a `DECIDED`: app-synced metafields.** Mini-spike proved both shop and product metafields readable by the compiled function (18 fixtures). The split is forced, not chosen: a shop metafield cannot express a per-product rule because `Product` exposes no enumerable tag list and the tag predicates take static arguments. Shop metafield carries the shop-wide switch; product metafield carries one resolved boolean per product, so the function never learns the rule's mode. **Costs `write_products` and one further re-authorisation.** |
| 2026-08-31 | **V7 `RESOLVED` by spike.** `shopify.scanner` gives scan events and, importantly, the list of available scanner sources — so keyboard fallback is detected rather than assumed. Residual caveat needs a physical card: what an OSC legacy barcode actually encodes. **D6 corrected**: the parameter is `title`, not `reason`, and it is the receipt-visible discount name — which serves D6's shared-identifier requirement better than a hidden reason would. |
| 2026-08-31 | **Full scope set granted on the development store.** No code carried a missing-scope workaround to remove; what existed were comments asserting the scopes were ungranted, now corrected. `read_all_orders` remains outstanding with Shopify (D7) and nothing depends on it. |
| 2026-08-31 | **Sprint 3 started. V4 resolved by spike before anything was built on it.** The function can read the app-reserved customer metafield, cart attributes, cart costs, line eligibility, `retailLocation` and `isAuthenticated` — proven against real compiled Wasm and the 2026-07 schema, 14 fixtures. **The security finding is the split**: the amount comes from the app-owned metafield, and the client-writable cart attribute may only ask for less. Without that, a customer could set their own discount. |
| 2026-08-31 | **V6a raised by the V4 spike**: a function input query is static, so the qualification tag list is fixed at deploy time and cannot follow a saved rule version. Harmless under the launch rules (nothing is excluded beyond gift cards); needs a decision before OSC configures an exclusion. |
| 2026-08-31 | **The D8 ladder is implemented twice and green on both sides.** `RedemptionLadder` in PHP and `src/ladder.ts` compiled to Wasm, both reading `fixtures/loyalty-arithmetic.json`. Two cases added: **the agreed UI's rejection example** (£70 basket, £45 eligible → offer £45) and eligible items worth less than one increment, which now has its own reason (`below_voucher_increment`) so the tile can explain it. |
| 2026-08-31 | **C1's mechanism built and recorded as swappable.** One cart attribute, honoured downwards and in whole increments only, with the same seam in `RedemptionService::hold()`. Both live answers to C1 are supported without touching the function or the ledger; the storefront control itself is not built and is not guessed. |
| 2026-08-31 | **D9d built as `confirmed`, not `redeemed`.** `loyalty_redemptions.state` has no `redeemed` value — the enum is `quoted, applied, confirmed, void, reversed` and a paid redemption sits at `confirmed`. `redeemed` belongs to `loyalty_rewards.state`. Recorded so the register matches the column rather than the instruction. |
| 2026-09-01 | **D7 `CONFIRMED`: `read_all_orders` approved by Shopify.** Requested 27 Aug, granted 1 Sep; the Partner Dashboard reads "Your app can access the full order history for a store". **The backfill job can now be scheduled** — Sprint 4, a one-shot per shop that moves no balance and is safe to re-run. Nothing is unwound and nothing changes today — segmentation continues from our own ledger plus the migrated last-spend date, exactly as designed. What the grant unlocks is the historical backfill that refines `last_qualifying_spend_at` and the segment without moving a single balance. **Approval is not grant:** adding `read_all_orders` to `shopify.app.toml` and `web/.env` forces one further merchant re-authorisation under the legacy install flow, so it is deliberately held until the Sprint 3 dev-store verification run is finished, and is on the go-live checklist. |
| 2026-09-02 | **D5 `REVISED`: production online redemption moves to the single-use-code path.** The live OSC store is on the **Grow** plan (our own confirmation), and Shopify refuses to activate a function from a custom app below Plus - `"Shop must be on a Shopify Plus plan to activate functions from a custom app."`, returned by `discountAutomaticAppCreate` at test script step A1. The constraint is plan **plus distribution model**, not the legacy install flow, and it was never checked: no mention of Plus, custom-app distribution or plan requirements existed anywhere in the register, the plan or the progress record. `DiscountFunctionGateway` is kept, complete and proven (V4 on real Wasm, V6a verified live at A2), as the Plus-and-above implementation and the future option. A **code-based gateway behind the same `RedemptionGateway` interface is added to Sprint 3**. **POS is unaffected** - D6 uses `applyCartDiscount`, which has no plan requirement. |
| 2026-09-02 | **"Confirm the OSC live store plan" is answered by the client: Grow**, and the app is a custom app with no public distribution planned. The Plus development-store switch is dropped from the open items and A1 will not be retried against the discount function. **The function path is dropped for production** because Functions-based discounts from a custom app require Plus or public distribution; single-use discount codes created through the Admin API are the Grow-compatible route and work on all plans. `DiscountFunctionGateway` and `extensions/voucher-discount` are kept intact as the Plus-and-above option, not deleted. |
| 2026-09-02 | **Gift-card gate test run empirically at checkout — the D5 code path is cleared to build, with one residual noted below.** Method: code `GIFTCARDGATE1`, `discountCodeBasicCreate`, $20 fixed amount, `context: {all: ALL}`, `customerGets.items {all: true}`, `usageLimit: 1`, `appliesOncePerCustomer: true`, all `combinesWith` false; node `gid://shopify/DiscountCodeNode/1485398081776`, since deleted. Products: gift card `gid://shopify/Product/10172497789168` variant `gid://shopify/ProductVariant/50160866787568`–`50160866885872`, tested with the $25 variant `gid://shopify/ProductVariant/50160866820336`; ordinary line `gid://shopify/ProductVariant/50160866754800` ($600). **Result 1 — gift-card-only cart: the code is REJECTED by Shopify**, "discount code isn't valid for the items in your cart". A member therefore cannot convert loyalty value into gift-card value on a gift-card-only basket, which was the cash-out hole. **Result 2 — mixed basket** (gift card £25 + snowboard £600, subtotal £625): the code is ACCEPTED and applies as an **order-level** −£20.00, total £605, with the gift-card line still showing £25. **Line-level allocation is NOT verified**: the cart was never paid and no abandoned checkout was created, so no server-side `discountAllocations` record exists and checkout shows only an order-level line. Shopify's own item-validity check is the protection that was demonstrated; per-line allocation on a mixed basket remains unproven. |
| 2026-09-02 | **Gap found while closing the gift-card gate: the codebase has no gift-card awareness at all.** `grep -rn "isGiftCard\|gift_card"` over `web/app` returns nothing, and `eligible_subtotal_pence` reaches `RedemptionController::store()` from the caller validated only as `integer, min:0`. Gift cards were excluded **solely** by the discount function's own `isGiftCard` input query, so moving to the code path removes that protection and nothing server-side replaces it. Result 2 above means loyalty value can still part-fund a gift card on a mixed basket. Mitigation requires line-level data at quote time, which the current quote API shape does not carry — raised for decision, not assumed. |
| 2026-09-02 | **Gift-card residual CLOSED empirically: Shopify itself excludes gift-card lines from a fixed-amount code, so NO app-level guard is built.** A real paid order settled what checkout could not show. Order `#1001`, `gid://shopify/Order/7134545019120`, guest checkout (`ashish.agr@yopmail.com`), subtotal £625, code `GIFTCARDGATE2` ($20 fixed amount, `customerGets.items {all: true}`), total £605. The discount application reports `allocationMethod: ACROSS`, `targetType: LINE_ITEM`, `targetSelection: ALL` — i.e. it was offered to every line. **Per-line `discountAllocations`: the snowboard line (`isGiftCard: false`) carries the whole £20.00; the gift-card line (`isGiftCard: true`) carries `[]` — an empty allocation list, `totalDiscountSet` 0.0, `discountedTotalSet` still £25.00.** Loyalty value therefore cannot fund a gift card even on a mixed basket, and the protection is Shopify's own rather than ours to write. Per the 2 Sep direction, the quote contract is NOT extended with line-level data and the POS tile is NOT touched. |
| 2026-09-02 | **Reading note for anyone re-running that check: `discountAllocations` is the authoritative field, not `totalDiscountSet`.** On order `#1001` the snowboard line shows `totalDiscountSet` 0.0 and `discountedTotalSet` £600.00 while its `discountAllocations` carries £20.00, because an `ACROSS` order-level allocation does not appear in the line's own discount totals. Reading only the totals would say the discount landed nowhere. |
| 2026-09-02 | **The `orders/paid` path ran on the gate order and correctly posted nothing — verified, not assumed.** `ProcessOrderPaid` fetched order 7134545019120 through `AdminApiOrderSource` at 11:08:16 UTC, and our side holds 0 ledger entries and 0 redemptions for it. **The reason matters and corrects an assumption made when designing the test:** a guest checkout does NOT leave the order without a customer — Shopify created customer `9673034727664` from the email. The earn was skipped because `OrderEarningService::accountFor()` found no `LoyaltyAccount` for that customer, not because the customer id was null. A guest order under an email that IS a member would still earn. |
| 2026-09-02 | **Deprecation surfaced by the gate order: `Order.physicalLocation` is deprecated on 2026-07.** Raised by the Admin API on every `AdminApiOrderSource::fetch()` call, which selects it. Not touched under the D5 code change (it belongs to the order/earning path, which is out of that change's scope) and recorded here so it is not rediscovered as a failure later. |
| 2026-09-02 | **D3 restated: the earn base excludes the loyalty voucher as well as VAT and shipping, and `C14` is raised to have the client confirm it.** D3 already read "after all discounts" and the register and the dev-store script already agreed; what was missing was that nobody had written down that a Privilege Club voucher IS one of those discounts, and the implementation did not treat it as one. A member paying £550 of a £600 basket earns 550 points, not 600. **Our call, pending client confirmation — not an assumption the code waits on**, since the alternative over-earns. |
| 2026-09-02 | **Defect found by dev-store step A3, in the earning path and NOT in the redemption change: the earn base ignores an order-level discount.** On real paid order `#1002` (`gid://shopify/Order/7134863884528`) a £600 line with a £50 voucher earned **600 points on a £600 base** (`qualifying_value_pence` 60000) instead of 550 on £550. Cause: `AdminApiOrderSource.php:158` builds `discounted_total_ex_tax_pence` from the line's `discountedTotalSet`, and **an `ACROSS` order-level allocation never appears in a line's own discount totals** — the line read `originalTotalSet` £600.00, `discountedTotalSet` £600.00, `totalDiscountSet` £0.00, while its `discountAllocations` carried £50.00. This is the same reading trap recorded earlier the same day against order `#1001`, not connected to the earning path at the time. **Pre-existing since Sprint 2 and mechanism-independent** — the discount function allocated `ACROSS` identically, so the code path did not introduce it; the suite missed it because fixtures set `discounted_total_ex_tax_pence` directly and never exercised the Shopify mapping. Fix is scoped as its OWN change, deliberately not folded into the D5 redemption work. |
| 2026-09-02 | **Dev-store script A1–A4 all PASS on the single-use code path.** A1: code minted as `PC-72347982`, read back from Shopify as `ACTIVE`, `usageLimit 1`, `appliesOncePerCustomer true`, £50.00 GBP, bound to customer `9671675085040`, `endsAt` equal to `quote_expires_at` (14 checks). A2: applied at a logged-in checkout, −£50.00 on a £600 subtotal. A3: paid order `#1002` confirmed the redemption — `state=confirmed`, `points_consumed=1000`, ledger `redemption` entry `pending 0 / available -1000` keyed `redeem:PC-72347982`, balance 1000 → 0, and the order carries `DiscountCodeApplication` with the code, which is the only link between an order and its quote (15 checks). A4: an unused quote swept to `void` with `points_consumed 0`, **no ledger entry**, the member's points untouched, the Shopify code moved `ACTIVE` → `EXPIRED`, and the sweep's own `job.completed` audit row as the record at the C12 grain (8 checks). |
| 2026-09-02 | **A4 incidentally proved the `QuoteExpirySweep` mechanism-dispatch fix against real data.** The sweep voided two quotes in one run: the new code quote and the stale `PC-97244372` held on 1 Sep through the **function** gateway. Dispatching on `discount_mechanism` rather than channel meant each was withdrawn through the mechanism that actually published it; the previous channel-based dispatch would have tried to clear a metafield for the code quote and deactivate a code for the function quote. |
| 2026-09-03 | **C14 defect fixed in `AdminApiOrderSource`, as its own change.** The line query now selects `discountAllocations` (it did not — only the order-level `discountApplications` were read, for reference extraction) and `lines()` subtracts the allocated amounts, so the earn base is `gross − allocations` and, on a tax-inclusive order, `gross − allocations − tax`. `fetch()` was split so the payload-to-snapshot mapping is a public seam, `mapOrder()`: **the step that was wrong was the only step no test exercised**, because every fixture built a snapshot or a `lines` array directly. Seven mapping tests added in `tests/Unit/OrderPayloadMappingTest.php` from the real `#1002` payload shape, and **verified by reintroducing the defect — 5 of the 7 fail, on the exact 60000 against 55000 figures.** Suite 445 tests / 2033 assertions, Pint clean. |
| 2026-09-03 | **Order `#1002` replayed to correct its earn.** `loyalty:replay-orders --order=7134863884528` reported `1 earned (-50 points)`. Earning is cumulative, so it **supersedes by compensation rather than duplicating or editing**: the original `earn` (id 22, `pending 600`, `qualifying_value_pence 60000`) stands and a new `earn_reversal` (id 25, `pending -50`, `parent_entry_id 22`, key `earn:order:7134863884528:t550`) corrects it. **Net earn for the order is 550 points**, member `points_pending` 550, and `loyalty:verify-ledger` reports every cached balance matching. Nothing was deleted — the ledger is append-only and the record of what the member was credited at the time survives. The A3 verification suite re-run at **18/18**. |
| 2026-09-03 | **V12 raised and put on the go-live checklist: the tax-inclusive earn base is unproven.** The dev store is tax-exclusive and `#1002` had no tax lines, so only `gross − allocations` is empirically verified. OSC sells VAT-inclusive, so the unverified branch is the one that ships. |
| 2026-09-03 | **The development store's base currency changed from GBP to EUR during tax configuration, which invalidated the V12 run.** `shop.currencyCode` read `GBP` earlier in the session and on order `#1002`; it now reads `EUR`. The £600 snowboard is a €600 product, and the UK market presents it converted — which is the whole explanation for the £524.00 subtotal seen at checkout (€600 × ~0.8733 = £523.98). **It was read as a tax figure and it never was one**; there was no VAT line to find. Suspected cause is the primary market's currency, since the shop baseline follows it, but this is unconfirmed — **check Settings → Markets (which market is Primary, and its currency) and Settings → General (Store currency) before changing anything.** Note Shopify normally refuses to change a base currency once a store has orders, and this one has `#1001` and `#1002`. |
| 2026-09-03 | **V13 raised: nothing compares the shop currency with the rules currency**, so a €50 voucher was minted for a GBP programme with every other constraint correct. `pointsFor()` is currency-blind, so confirming it would have spent 1000 points regardless. Quote `PC-40690629` was voided unused rather than applied. See the V13 entry. |
| 2026-09-03 | **V12 remains OUTSTANDING and gated — not settled tonight.** UK VAT collection was enabled successfully (Settings → Taxes and duties → Regional settings → United Kingdom → Collect VAT) and the market's **Tax display** was already set to `Dynamic tax display`, so step 2 changed nothing — the missing piece had been collection, and `#1002` had neither collection nor a UK destination. The run was then stopped by the currency finding above rather than by anything in the code. **V12's alternative route stands: reconcile one real discounted order on the live store before launch.** Dev-store state left as: UK VAT collecting, tax display Dynamic, base currency EUR, gift card variants stocked and sellable. |
| 2026-09-03 | **V12 is unprovable on the development store and the attempt is parked.** The store address is locked to the United States, and Shopify does not require a non-UK merchant to collect UK VAT on orders over £135, so every UK checkout above that reverts to base pricing at the payment step — reproduced twice, with a "Price update" modal striking £720.00 through in favour of the stored £600.00. Neither order was paid, since a £600 order with no tax lines is `#1002` again. **V12 stays a hard go-live gate, now closable only by reconciling one real discounted order on the live store before launch.** Development-store state left deliberately UK-realistic: GBP base currency, UK VAT collecting, `Dynamic tax display`, GB market configured. |
| 2026-09-03 | **V14 raised: a compensating `earn_reversal` carries no `qualifying_value_pence`.** Account 10 sums to 60000 against a real qualifying spend of £550, because entry 22 holds the value and the correcting entry 25 leaves it `NULL`. Reporting only — `ProgrammeSummary` and `MemberPresenter` aggregate the column; points, balances and maturity never read it, and `SegmentSweep` works from a last-spend date rather than an amount. Held as a **Sprint 5 gate** rather than fixed, because the candidate fix changes what the column means and reporting has to agree with that first. |
| 2026-09-03 | **`read_markets` added to the access scopes**, in `shopify.app.toml`, `web/.env` and `web/.env.example` together, after two sessions spent inferring market currency and tax-inclusive pricing from `contextualPricing` because the config could not be read. Forces one merchant re-authorisation under the legacy install flow, and invalidates the stored offline session until it happens — `loadOfflineSession` refuses a token whose granted scopes no longer match the configured set. The deploy that publishes the 3 Sep tunnel URLs carries this change too, so the re-grant must follow the deploy, not precede it. |
| 2026-09-03 | **The development store market configuration read directly, after `read_markets` was granted.** Three ACTIVE REGION markets (United Kingdom GBP, Canada CAD, United States USD); the UK market carries `inclusiveTaxPricingStrategy: INCLUDES_TAXES_IN_PRICE_BASED_ON_COUNTRY`, which is the £720 shown against a stored £600. **No primary market exists and that is not a fault** — 2026-07 `MarketType` has no `PRIMARY` value and `Market` has no `primary` or `enabled` field. The market configuration is correct, so **V12 is blocked solely by the locked US merchant address and the £135 rule and nothing in Markets can unblock it.** Ends two sessions of inferring this from `contextualPricing`. |
| 2026-09-03 | **The POS tile search request never reaches the backend, and the route is not at fault.** Confirmed state (a): no line for `/api/admin/members` in the dev request log. The endpoint, params and auth are all correct - the extension sends `q` and `per_page`, `MemberController@index` defaults `field` to `all`, every param passes `MemberSearchRequest`, and all five identifiers find account 10 **when the query is run directly against the database** — see the correction of 3 Sep 2026 below, which is the only sense in which search was ever shown to work. CORS is fine too: a live preflight from `extensions.shopifycdn.com` returns 204 with the right headers. The suspect is `Modal.jsx` line 43, `shopify.environment?.appUrl ?? ''` - **the 2026-07 POS UI extensions docs state that no API gives an extension its app URL**, and the worked example hardcodes it, so `appUrl` is almost certainly the empty string. A temporary modal-open diagnostic was committed to read the runtime and is to be reverted once read. **V15 raised** for the `@shopify/ui-extensions` version skew. |
| 2026-09-03 | **POS Pro confirmed Active on both development-store locations, closing it as an open receivable.** "Shop location" (United States, the store default) and "My Custom Location" (123 Main St, Toronto, Canada). Two consequences: location attribution is now properly testable, so **step B2 of the dev-store script requires two DISTINCT `shopify_location_id` values from sales at the two locations** rather than merely non-NULL at one - a hardcoded default would pass the old check; and **neither location is in the UK and the US one is the default, corroborating V12** further. Location addresses deliberately left alone: fewer moving variables while the tile is being diagnosed, and V12 closes on the live store regardless. MD10 still targets one location for the production matrix. |
| 2026-09-03 | **POS tile base URL fixed, and the pattern behind it written down as a rule.** `shopify.environment?.appUrl ?? ''` was always `''` - POS supplies no app URL at any version, confirmed against the 2026-07 docs - so every till request went to a relative path that reaches nothing from the extension sandbox. The URL is now compiled in via `resolveAppUrl()` in `extensions/loyalty-tile/src/lib/appUrl.js`, rewritten by `web/serve.mjs` at dev start from the tunnel handshake, so a tunnel change needs no manual edit and no extension redeploy. `TillApi` now refuses any base that is not an absolute https origin, returning `no_app_url` rather than issuing a relative fetch, and the tile says so. **Third instance of a test supplying what production derives** (after C14, plus one in miniature the same day), so it is recorded as a standing rule: *if a test supplies what production derives, something else must test the derivation.* Extension suite 53/53, backend 453/2057, Pint clean. |
| 2026-09-03 | **The POS tile reached the backend for the first time, and the first real POS token was rejected 403 `no_role_assigned`.** Search worked from the deep-link preview surface, which authenticates as the installing account (`113711382768`, bootstrapped Administrator), and failed from POS proper, signed in as `113711437936`. Not the tile: `staff_roles` held one row and `StaffRoleResolver` grants nothing to an unrecognised staff member on a shop that already has roles. **V16 raised and gated for go-live** — every OSC till user needs a role assigned, and whether POS staff get an implicit `viewer` floor is parked next to C9. **V17 raised** — the tile rendered that 403 as "That search could not be run.", discarding a message precise enough to act on, which is the third swallowed error to cost time today. `113711437936` granted `agent` directly in the database to unblock the run (not via `PUT /api/admin/staff/{staffId}`, so no audit row). |
| 2026-09-03 | **V19: the POS tile listens for events its components never emit, so almost nothing in it works.** Member search doing nothing on Android POS was the first symptom, not the defect. `Modal.jsx` wires 9 controls to `onPress` and the search field to `onSubmit`; POS emits neither, and Preact attaches a listener for the literal event name, so they fail silently. `onPress` appears in **zero files** in `@shopify/ui-extensions`; `Clickable` and `Button` expose `onClick`, `SearchField` exposes `onInput`/`onChange`/`onBlur`/`onFocus`. **The device settled it independently of the types**, which matters while V15 is open: `Tile.jsx` uses `onClick` and the tile opens, `onInput` fires and the hints update, `onPress`/`onSubmit` do nothing. **Steps 9-12 were never reachable** — selecting a member, the step controls, redeem, enrol and cancel are all inert. No test caught it because `tile.test.js` tests "without rendering POS" and never imports the JSX: **fourth instance** of the untested-wiring pattern recorded as a rule this morning. |
| 2026-09-03 | **CORRECTION to the earlier report that "all five searches return the member".** That overstates what happened and should not be read as search having worked. What was observed was the **results list rendering** on the deep-link preview surface. Nobody ever tapped a result — and tapping was dead, because `s-clickable` was wired to `onPress` (V19). So the observation evidenced the **query path only**: that `MemberSearch` with `field=all` finds account 10 by club card, email, surname, postcode and legacy card, which was separately verified by running the query directly against the database. It is not evidence that the tile could search, that a member could be selected, or that anything downstream of selection worked. **Step B was never passed and must be re-run in full**, with tapping through to the member screen verified rather than assumed. |
| 2026-09-03 | **V19 fixed, and the fourth instance of the coverage pattern recorded.** Nine `onPress` handlers renamed to `onClick`, and the search field's unreachable `onSubmit` replaced with a visible Search button — a rename alone would have left a till user typing a name with nothing to press. `tests/handlerContract.test.js` now derives an allowlist from the installed `@shopify/ui-extensions` type declarations and checks every `<s-tag onX=` pair in the JSX against it; verified by reintroducing the defect, which fails four tests and names `<s-button onPress=` in the output. The `tile.test.js` docblock is rewritten as a warning: **"tested without rendering POS" was a reasonable trade-off that became the reason a broken tile shipped.** The rule entry is updated to four instances and generalised — the pattern is not only a test supplying a derived value, it is a test standing where the defect cannot be seen from. Extension suite 73/73, backend 453/2057, Pint clean. |
| 2026-09-03 | **V18 raised and fixed: the till is a second denominator, and V13 could not see it.** V13 compares the shop currency with the rules currency; both are GBP for OSC, so it passes — while a POS sale is transacted in the currency of its LOCATION. `cur=USD` from a live device proved it: 1000 points priced at £50 would have discounted **$50** and printed "£50.00" on the receipt, because `applyCartDiscount` passes a bare number and `session.currency` was never referenced anywhere in the tile. Now sent up as `till_currency` and compared at `hold()` for the POS channel only, **failing closed** — which broke eight existing POS tests, every one of which had been holding at a till without stating its currency. Verified by removing the guard (4 of 8 new tests fail). The hardcoded `£` in `discountTitle()` and `formatPence()` is now correct by construction rather than by check, and is recorded rather than fixed. Backend 461/2078, extension 73/73, Pint clean. |
