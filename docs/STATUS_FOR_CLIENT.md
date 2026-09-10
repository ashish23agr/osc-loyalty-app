# OSC Privilege Club — Project Status

**Prepared for:** Robert / OSC
**Date:** 9 September 2026

This is an honest current-state summary of the Privilege Club build: what is
finished, what is genuinely working, what is not yet built, what we still need
from OSC, and what can only be proven on your own store before launch.

> **Internal note, not for the client.** The authoritative register of open
> client decisions is **`DECISIONS.md` section 3, "Plan confirmations"**. This
> document is a client-facing *rendering* of that register and must not become a
> second copy of it: every decision in section 3 below carries its **Ref** back
> to section 3, and where the two disagree, section 3 wins. If a decision is
> raised with OSC, it is added to section 3 first and cited here second.

---

## 1. Currently outstanding

These are the largest areas of agreed scope that are **not yet built**. We have
checked each one against the specification, the agreed screen structure and the
actual software rather than against our own notes.

### The customer-facing Privilege Club section — not started

**This is at zero.** There is no member-facing area of any kind yet. A member
cannot currently see their own reward balance, their vouchers, their reward
history or an expiry warning by any route — online, in their Shopify account, or
anywhere else. Every balance in the system today is visible only to OSC staff,
either through the admin console or read out across the counter.

Three agreed screens are affected: **joining the Privilege Club online**,
**"My Privilege Club" inside the customer's Shopify account**, and the
**reward panel at online checkout**.

The engine behind it is ready — the system already calculates each member's
reward value correctly and already publishes that value onto the member's Shopify
customer record, so the data a member-facing page needs is being maintained. What
is missing is the page itself. We have identified four possible technical routes
for delivering it and confirmed that at least two of them are available to us, so
there is no risk of this work being blocked; it needs to be scheduled and built.

We want to be plain about the significance: **a member seeing their own balance is
roughly half of what the programme is for**, and it is the part with no
implementation yet. It has repeatedly been displaced by in-store point-of-sale
work, not because it matters less, but because point-of-sale problems announce
themselves and a missing screen does not. We are correcting that.

### The five reports — not started

**This is at zero.** All five agreed reports are outstanding:

| Report | Data available today? | Report built? |
| --- | --- | --- |
| **Total loyalty members** (with the Active / Lapsed split) | Yes | No |
| **New loyalty sign-ups** (by date range, online vs in-store) | Yes | No |
| **Loyalty-driven orders and revenue** (including loyalty as a % of total sales) | Partly — see below | No |
| **Online vs in-store activity** | Yes | No |
| **Birthday reward activity** (issued, redeemed, expired) | Partly — see below | No |

The agreed scope groups the reporting requirement into these **five reports and
only five**. Where you have asked us about "total members", "active members" and
"lapsed members" separately, those are the three figures inside the **first**
report rather than three reports of their own. "Revenue" is the third report,
"signups" the second, "online vs store activity" the fourth, and "birthday
voucher" the fifth. That grouping is what the proposal and the agreed screens
both describe, and we have not changed it.

Two of the five need something settled before they can be finished:

- **Loyalty-driven orders and revenue** needs to express loyalty activity as a
  percentage of *total* store sales. The loyalty half of that figure is held in
  our system; the total-sales half has to come from Shopify, and we need to
  settle which Shopify figure to use so that our number agrees with the one you
  see on your Shopify dashboard. A loyalty share that disagrees with Shopify's
  own reporting would be the first thing questioned, so we would rather settle it
  before building than afterwards.
- **Birthday reward activity** needs the reward record to be able to move from
  "issued" to "redeemed" or "expired". That part of the reward engine is not
  built yet — see the next section.

Alongside the reports, the agreed **date range, channel, store, staff and gender
filters**, the **month-on-month / year-on-year comparison**, and **PDF and CSV
export** are all still to be built. Export buttons already appear on several
admin screens and are deliberately shown greyed out rather than left off, so the
layout stays reviewable.

The **gender filter** additionally depends on the gender field being configured
on your development and live Shopify stores — that is one of the outstanding
items we need from OSC.

### Legacy Dynamics / ORD data migration — not started

**This is at zero as working software.** To be precise about what does and does
not exist:

- **The migration rules are complete and confirmed.** Every rule we agreed with
  you in the data-migration discovery is documented and built into the database
  design: members with no email address migrate and stay searchable; a day-and-
  month birthday with no year is a complete birthday; two records with an exact
  email match are the same person and their balances combine; unmatched members
  start as not subscribed while matched members keep their existing Shopify
  consent; email-only contacts with no card become marketing contacts with no
  loyalty account; decimal balances round down; balances carry over as points
  with no vouchers auto-issued; possible duplicate birthday vouchers in year one
  are accepted; and no paper-voucher liability is imported. Migrated balances
  will expire from the go-live date with a grace period, as agreed.
- **The database tables that hold a migration run, its records and its
  exceptions are built.**
- **Nothing that actually reads a file exists.** There is no import process, no
  data profiler, no field mapping, no matching against Shopify customers, no dry
  run, no exception report, no reconciliation, and no migration screen in the
  admin console.

Documented rules and a working importer are two different things, and we do not
want thorough documentation to read as progress on the software.

**This is blocked on one thing: the Dynamics / ORD export file.** We need the
full CSV export with its field list and any known data-quality issues. Profiling
starts the day it arrives. Until then this work cannot begin, and it is a
launch-blocking item because without it existing members would be reset — the
exact outcome the programme exists to avoid.

### Other agreed scope still outstanding

| Area | Position |
| --- | --- |
| **Voucher management screen** | Not built. Behind it sits a larger piece: the system can *issue* a birthday reward but cannot yet mark one as redeemed, expired, cancelled or reissued. Goodwill voucher issuance and the cancel/reissue actions are therefore also outstanding, along with the ability to redeem an issued birthday reward at the till |
| **Transactions screen** | Not built. The order-by-order view with staff, till, eligible spend and voucher used |
| **Migration screen** | Not built |
| **Automated Klaviyo messages** | Not wired up. The system already detects and announces all five loyalty moments internally — someone joining, points becoming available, a reward crossing another £5, an expiry approaching, and a birthday reward being issued — with the correct timing built in, including the rule that nothing is announced until points clear their 30-day pending period. What is missing is the live connection to Klaviyo itself and the four member profile fields that sync across. **This is blocked on the Klaviyo API key.** No live message has been sent yet |
| **Member merge tool** | Not built. Duplicate *prevention* is built and working; the tool for combining two records that already exist is not |
| **Linking a loyalty record to a Shopify customer record** | Not built. Members created today exist as loyalty records without being attached to a Shopify customer |
| **Joining online** | Not built. A customer registering on the website does not currently create a loyalty record, because the storefront side of enrolment has not been built |
| **Staff roles screen** | Not built. Role assignment works through the system and is fully enforced; the screen for doing it is outstanding |
| **"Enrol member" button in the admin console** | Shown greyed out. The underlying function is built and has been used successfully on a real store; the pop-up form is outstanding |
| **Store and location names** on ledger rows | Only the location's identifier is stored today. Showing the store name needs an additional lookup |
| **Mobile number and Klaviyo sync status** on the member profile | Shown as a dash. The system holds neither. Mobile number was not part of the agreed scope but does appear on the agreed screen layout |

### One point of scope we need to raise with you

The agreed screen structure shows **individual reward vouchers as separate items**
— each with its own code, its own issue date and its own expiry date, and each
able to be cancelled or reissued on its own. That appears both on the admin
voucher management screen and in the member's own account view.

A decision taken and approved earlier in the project changed this: the member's
reward value is now **calculated live from their points balance** rather than
issued as separate voucher records, and it falls automatically as points expire.
Only the **£10 birthday reward** is a genuine issued voucher with its own expiry.

This was the right decision — it removes a whole class of reconciliation problem
and it is what makes the balance identical online and in store in real time — but
it means the voucher management screen and the member's reward list will **look
different from the layouts you signed off**. There will be one live reward
balance plus any birthday rewards, rather than a list of individually coded
vouchers.

We would rather raise this now than build a screen that does not match what you
reviewed.

---

## 2. What is currently working

Everything below has genuine evidence behind it. Where something works but still
needs final proving on your own store or on a real till, we say so explicitly
rather than counting it as finished.

### Working and proven against real Shopify activity

| Capability | Evidence |
| --- | --- |
| **The loyalty ledger** | Every earn, redemption, adjustment, expiry and reversal is recorded as a separate permanent entry that cannot be edited or deleted. Balances are re-calculated from those entries rather than being incremented, so they cannot silently drift. A nightly check reconciles every balance against the ledger and reports any disagreement |
| **Earning loyalty points on a real order** | A real order placed on the test store earned points correctly through Shopify's own order notification. During that test we found and fixed a genuine defect — an order-level discount was not being deducted from the earning base, so a member would have earned on money they had not spent. The order was reprocessed and corrected, and that correction mechanism is now proven on real data |
| **Calculating reward value** | 100 points becomes £5, rounded down, with the remainder carried forward towards the next £5. This has been checked at every boundary and, more importantly, was exercised through a real online checkout **and** displayed correctly on a real till |
| **Online reward redemption** | Fully proven end to end on the test store: a single-use discount code was created against the member's account, applied at a real checkout, the order was paid, and the points were correctly deducted from the member's balance. An unused offer was correctly withdrawn afterwards. **This is the strongest evidence in the project** |
| **The redemption rules** | Minimum basket spend, the £50 per-order cap, the £5 increments, the rule that reward spend must stay below the basket total, and the reduction of the eligible amount where products are excluded — all applied in the correct order, including the worked example from your own agreed screens (a £70 basket with £45 of eligible products offers £45) |
| **Product eligibility rules reaching Shopify** | The eligible/excluded product configuration is pushed to Shopify and was read back from Shopify to confirm it had arrived correctly, on every product |
| **Rules configuration by OSC staff** | Earn rate, voucher threshold and value, birthday reward value, pending period, expiry, minimum basket spend, per-order cap, increment, and the separate online and in-store redemption switches are all configurable in the admin console with no developer involvement. Every change is saved as a complete new version, shown to you as a before-and-after list before you confirm it, and written to the audit log with your name and both values. **Rule changes are never applied retrospectively** — a purchase made last month is always valued at the rules in force when it happened |
| **Customer lookup** | One search box finds a member by email, name, postcode, new Privilege Club card number or legacy Dynamics card number — or by any one of those on its own. Postcodes work with or without a space. A member with **no email address held** is fully findable by card, by postcode and by surname, and is clearly marked so a blank cell never reads as missing data |
| **Customer profile** | One unified record per member showing contact details, both card numbers, segment, marketing consent, available and pending balances, reward value, points to the next £5, lifetime spend and last purchase, plus the full movement history and the rewards list |
| **Admin customer management** | Enrolling a member, correcting their details, and viewing their complete history. Enrolment and the duplicate-email check have both now been used successfully against a real store |
| **Manual points adjustments** | Add or deduct, with a mandatory reason category and notes, a per-person adjustment limit, and a live projection of the resulting balance before anything is saved. The projection is calculated by the engine, not by the screen, so what a member of staff reads out cannot disagree with what is actually recorded. Every adjustment is written to the audit log |
| **Roles and permissions** | Four roles — Viewer, Agent, Loyalty Manager, Administrator — enforced by the system on every single action, not just hidden in the interface. A role change takes effect on the very next click. The last remaining Administrator cannot be removed. Staff from one store cannot see another store's members |
| **Audit trail** | A searchable record of who did what and when, with the before and after values and the reason, filterable by user, action and date. Entries cannot be edited or deleted, and automated overnight work is recorded too — one entry per run with its counts, so a process that has silently stopped shows up as a date that stopped moving |

### Working, but still needs final validation on a real till or on your store

| Capability | What is proven | What still needs proving |
| --- | --- | --- |
| **In-store / POS loyalty tile** | A real till has searched for members, opened a member, displayed "£150 of voucher value", used the £5 up/down controls, and successfully reserved a £25 reward against a sale — twice, at two different store locations, with the correct location and the correct staff member recorded both times | **A complete in-store sale has never yet run all the way through.** Reserving the reward works; taking the payment and seeing the points leave the member's balance has not been completed. One clean sale on an online device closes this |
| **Offline tills** | Confirmed working on real hardware. When the till lost its connection, the tile correctly refused to act and told staff to continue the sale and that points would be added once the till was back online. Shopify queued the sales locally, exactly as designed | Nothing further |
| **Refunds and reversals** | The arithmetic is complete and carefully worked through: a partial refund reverses points in proportion to the value refunded, restored points go back to their original expiry dates rather than getting a fresh six months, and the rounding is calculated cumulatively so a series of small refunds cannot quietly cost a member points | **No real refund has yet been processed.** This is proven by calculation and not yet by a real Shopify refund. We are scheduling one |
| **Automatic overnight processing** | Points maturing after the 30-day pending period, points expiring, birthday rewards being issued, and Active/Lapsed segments being recalculated are all built, scheduled, and designed so that a missed run is caught up by the next one | **None of these has yet run on a real store with real effect**, because the scheduling service is not yet running in a live environment. No birthday reward has been issued yet |
| **Earning on VAT-inclusive prices** | Earning is proven on VAT-exclusive orders | **OSC sells VAT-inclusive, and that is the case we cannot prove on a test store** — see section 4 |

---

## 3. Decisions and information needed from OSC

### Business decisions we need answered

| # | Ref | Decision | Why we need it | Our recommendation |
| --- | --- | --- | --- | --- |
| 1 | C14 | **Does a Privilege Club reward reduce the points earned on that same order?** | A member who pays £550 of a £600 basket because a £50 reward covered the rest: do they earn 550 points or 600? | **550.** The programme rule is "£1 spent = 1 point", and £550 is what they spent. Earning on reward-funded value lets loyalty value earn further loyalty value, which compounds. **We have already built it this way** and need your confirmation before launch |
| 2 | C1 / Q2 | **How does a customer choose to redeem online?** | Does the reward apply automatically at checkout, or does the customer choose an amount? And where does that control appear? | The engine already supports both answers without any change. Our proposed default is a **"Shop with my £15" link from the account page that applies the reward automatically**, matching the button on your agreed account screen. We have not treated that as confirmed |
| 3 | **see note** | **How long is a reward code valid for, and what happens to an unused one?** | Currently an offer stands for twenty minutes and is then withdrawn at no cost to the member, and a reward code carries the same expiry so Shopify and our system cannot disagree | Confirm twenty minutes is acceptable for online checkout, or tell us the window you want |
| 4 | C15 / Q6 | **Should a member be told when a refund reduces their reward balance?** | The agreed scope commits to five member messages, and all five announce good news. Nothing currently tells a member their balance has gone **down** | This is genuinely your call. Telling them is more transparent; not telling them avoids drawing attention to a reduction they may not have noticed. If you want it, it is a sixth message against an agreed set of five |
| 5 | C9 | **Can a till assistant adjust points, or only redeem?** | The data-migration discussion implied point adjustments at the till with "all roles OK", which does not match the four-role model in the specification, where a Viewer changes nothing | We have built **no** till adjustment capability until you confirm. Three questions: may a till assistant adjust points at all; does the console role model apply at the till; and should a manual override be capped and require a reason? |
| 6 | V16 + C9 | **Is every till user going to be given a Privilege Club role?** | Only the first staff member to open the app is set up automatically. Every other till user currently gets a refusal, and the tile reads as broken | Either OSC assigns a role to each till user as a documented setup step, or we allow any verified till user read-only access automatically. **This must be settled before launch** — it would otherwise affect every till but one on day one |
| 7 | C10 | **Do manually adjusted points expire like earned points?** | The specification covers expiry for earned and migrated points but not for points a member of staff adds by hand | **Yes, they expire like earned points.** Points that never expire are a liability that only grows, and a member comparing two credits would see one lapse and the other not. Built this way; cheap to change before launch |
| 8 | C11 | **Do new physical cards carry their own numbers?** | Your agreed screens show two card numbers per member: the legacy Dynamics one and a new Privilege Club number | We currently generate the Privilege Club number from the member's record, so it is unique and stable and can be typed into the search box. Two questions: are new physical cards being issued, and if so who allocates the numbers? And should that number match anything in the legacy export? |
| 9 | C3 / Q1 | **Is a "full price only" earning rule wanted at all?** | Version 1.1 of the agreed scope removed full-price-only qualification throughout, and points now earn on all qualifying items including sale and promotional lines | We believe it is dropped and have built nothing for it. **Please confirm in writing** so we can close it |
| 10 | C8 | **PDF export approach** | Report exports need to render as PDF | We recommend a pure-PHP approach rather than one that requires a browser engine on the server, which would add a significant dependency to your production environment |
| 11 | **see note** | **International and non-GBP customers** | The scope does not say what happens if a customer buys online in a currency other than sterling — whether they earn, at what rate, and what a reward is worth to them | **We are not inventing a rule here.** You have confirmed OSC trades UK-only, which means this does not arise in practice today. If that is permanent we will note it and move on. If OSC may ever sell in another currency, we need a rule for earning and redemption and would build a safeguard before launch |
| 12 | C16 / Q7 | **Individual voucher records vs one live reward balance** | See the scope point at the end of section 1 | We recommend keeping the live calculated balance, and adjusting the voucher management screen and the member account view to match it |

> **Internal note on two rows above, not for the client.** Both are decisions
> this document puts to OSC that **`DECISIONS.md` section 3 does not carry**, so
> they are the exact failure the register exists to prevent — telling Robert
> something the authoritative list does not say.
>
> - **Row 3 (how long a reward code is valid, and what happens to an unused
>   one).** C13 `CONFIRMED` settles what an expired quote *does*; it does not
>   record the twenty-minute window itself as a client-confirmed figure. The
>   window is a constant in `RedemptionService::QUOTE_MINUTES`.
> - **Row 11 (international and non-GBP customers).** Related to **V24**, but
>   V24 is a technical validation of ours, not a client decision. There is no C
>   item asking OSC for an earning and redemption rule outside GBP.
>
> **Neither has been given an invented C number.** Adding entries to section 3 is
> a change to the authoritative register and needs sign-off; until then these two
> rows are cited as unregistered and must not be presented to OSC as tracked.

### Information and access we still need

| Item | What it blocks |
| --- | --- |
| **The full Dynamics / ORD data export (CSV), with its field list and any known data-quality issues** | **All migration work.** Nothing can start without it, and it is a launch blocker |
| **The Klaviyo private API key** | **All automated member messaging.** The five loyalty moments are already being detected correctly; only the connection is missing |
| **The gender field configured on both your development and live Shopify stores** | The gender filter on all five reports |
| **Domain details for the live application address** | The final release of the app, and its authorisation on your store |
| **The name of the person who will hold the first Administrator role on the live store** | Handing over the first login. This is a name, not a decision — the mechanism is settled |
| **Confirmation of your live store's currency and reporting timezone** | Report correctness |
| **One physical legacy Dynamics membership card** | Confirming what the barcode encodes, so the till can scan it. Typing the number in already works regardless, so this improves the experience rather than unblocking it |
| **Full permission grant on the live store** | Everything. Because of the way this app is installed, granting the app its permissions is an action someone at OSC performs in your Shopify admin — it is not something we can deploy |

---

## 4. Final live-store validation

Some things can only be proven on OSC's own Shopify store and tills. **This is
normal go-live validation, not development that was skipped.** Each item below
either depends on a fact about your business that no test environment can
reproduce, or on your real staff, real stores and real trading data.

We have been deliberate about this split: anything that *can* be proven on a test
store is being proven there, because finding a problem for the first time on your
live till, mid-trade, in front of staff and a customer, is a much worse outcome
than finding it on a test store.

| What still has to be proven on your environment | Why it cannot be proven elsewhere |
| --- | --- |
| **Points earning on a VAT-inclusive discounted order** | This is the most important one. OSC sells VAT-inclusive. Our test store's registered merchant address is locked to the United States, and Shopify does not require a non-UK merchant to collect UK VAT on orders over £135 — so every test checkout above that amount re-prices without VAT, leaving no VAT line to check the calculation against. We spent two sessions trying to work around this and the route is genuinely closed. **It closes by reconciling one real discounted order on your store before launch**: we take the order's totals, its discount and its VAT, run our calculation, and confirm the points the member received match the VAT-exclusive amount they actually spent. It is a half-hour check on one order |
| **A Privilege Club role for every till user** | Needs your real staff accounts. There is no way to simulate OSC's staff list |
| **The five reports reconciling with Shopify's own analytics** | Needs real order history at real volume. A percentage of total sales computed over three test orders proves nothing |
| **Klaviyo messages actually arriving** | Needs your private API key and your own Klaviyo account. There is no substitute |
| **Your live store's currency and reporting timezone** | These are facts about your store rather than tests |
| **Migration reconciliation and sign-off** | Needs the real export and real member records. We can prove the process works on sample data; only your real file can reconcile the totals you sign off |
| **Two years of historical spend being read to refine Active/Lapsed segments** | Needs two years of your real orders. Shopify has approved our request for access to full order history; this runs once per store, moves no balances, and is safe to re-run |
| **VAT-inclusive prices and receipts presenting correctly** | Follows your registered merchant address, which cannot be reproduced on a test store |
| **The live application address and its authorisation** | Test addresses are temporary by design |
| **Location and staff attribution across your real stores** | We have proven that two different locations record two different, correct location identifiers. Confirming it across OSC's actual stores and staff is a live check |

**One item is *not* on this list and we want to be clear about it.** Completing a
full in-store sale — reserve the reward, take the payment, watch the points leave
the balance — **can** be proven on our test store, and we are treating it as
something to close there rather than defer to yours. It has not completed yet for
practical reasons on the day (on the most recent attempt the till was offline and
was queuing sales locally, which is correct behaviour), not because of anything
about the test store.

---

## 5. Next development focus

In dependency order. No dates or revised estimates are implied by this list.

1. **Complete one full in-store sale on a real till.** It closes an entire
   section of the pre-launch checklist and it is a short piece of work. We have
   put a strict time limit on it so it cannot displace the item below again.

2. **Build the customer-facing Privilege Club section.** The largest outstanding
   area of agreed scope, and the one with no implementation at all. Nothing about
   it waits on OSC — we have confirmed workable technical routes — so it starts
   immediately after the till sale, whatever happens with that sale.

3. **Process a real refund, a real partial refund and a real cancellation.** The
   refund calculations are complete and carefully reasoned but have never met a
   real Shopify refund. Also get the overnight processing running so that points
   maturing, points expiring and a birthday reward being issued all happen once
   for real.

4. **Settle and build the reward lifecycle** — moving a reward from issued to
   redeemed, expired, cancelled or reissued, plus goodwill issuance. This unlocks
   the voucher management screen and the birthday reward report, so it comes
   before reporting.

5. **Build the five reports**, starting by settling which Shopify figure to use
   for total sales, so that our loyalty percentage agrees with your Shopify
   dashboard.

6. **Wire up Klaviyo**, as soon as the API key arrives. The detection and timing
   work is already done; this is connecting it and configuring the flows.

7. **Build the legacy migration**, starting the day the Dynamics / ORD export
   arrives. All the rules are agreed and the database is ready, so this is a
   pipeline against a known specification.

8. **Complete the remaining admin screens** — the transactions view, the staff
   roles screen, the enrol-member form, and the CSV and PDF exports.

9. **Final release and go-live preparation** — the live application address,
   the automated processing running continuously in your production environment,
   the permission grant on your store, and the live-store validations in
   section 4.

---

If anything above reads as more or less complete than you expected, please come
back to us on it. We would rather correct the record now than at launch.
