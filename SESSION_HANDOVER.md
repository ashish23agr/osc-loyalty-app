# Session handover — end of 3 September 2026

For a fresh session tomorrow. **Assumes you know nothing about today.**

Canonical records are `PROGRESS.md` and `DECISIONS.md`; this is a route into them,
not a replacement. Where they disagree with this file, they win.

---

## The one-paragraph version

Today was almost entirely POS. The Privilege Club tile had never been used on a
real device, and when it was, **nothing in it worked** — four separate defects,
each invisible to a green test suite. All four are fixed, tested and pushed. The
tile can now search, find a member and open the member screen on a real Android
till. **It still cannot complete a redemption**, and on this development store it
never will, for a reason that is not a bug. Nothing customer-facing exists yet at
all.

---

## What was fixed today

Four defects, all in the POS tile, all found by using it rather than by testing
it. Each is recorded in `DECISIONS.md` with its own entry.

### 1. The base URL — the tile was never sending anything

`Modal.jsx` built its API client with `appUrl: shopify.environment?.appUrl ?? ''`.
**`shopify.environment` does not exist in the POS API surface** — confirmed
against the 2026-07 docs, which state that no API gives an extension its app URL
and whose own example hardcodes it. So `appUrl` was always `''`, every request
went to the relative `/api/admin/...`, and from the POS sandbox that reaches
nothing. No request arrived, no error was raised, and the tile simply showed no
members.

**Fix.** `resolveAppUrl()` in `extensions/loyalty-tile/src/lib/appUrl.js` with the
URL compiled in as a constant, which `web/serve.mjs` rewrites at `shopify app
dev` start from the tunnel handshake it already keeps for `php artisan`. So a
tunnel change needs no manual edit and no extension redeploy. `TillApi` now
refuses any base that is not an absolute `https` origin, returning `no_app_url`
rather than issuing a relative fetch.

**`APP_URL` currently holds a dev tunnel and MUST be the production URL at
release.** On the go-live checklist in `IMPLEMENTATION_PLAN.md`.

### 2. V17 — the tile threw away the reason a request failed

The backend returned a 403 saying *"This staff member has no Privilege Club role.
An Administrator must assign one."* The tile rendered **"That search could not be
run."** `MemberView` was worse: it mapped every failure to a basket refusal, so an
authorisation problem read as *"Redemption is not available"*.

**Fix.** `messageFor()` in `lib/reasons.js`. Known codes get till wording that
ends with an instruction — almost always *continue the sale*, per C7. Unknown
codes fall back to **the server's own message**, never to a generic string,
because a new backend error arriving silently generic is the failure mode that
cost the day.

### 3. V18 — the till is a second denominator

V13 (fixed earlier) makes `hold()` compare the **shop** currency with the rules
currency. Both are GBP for OSC, so it passes. But a POS sale is transacted in the
currency of its **location**: the dev store's "Shop location" is in the US and its
till reports `USD` while the shop stays `GBP`. `applyCartDiscount` takes a bare
number and lets the till denominate it, so **£50 of points would have discounted
$50 and printed "£50.00" on the receipt**. `session.currency` was never
referenced anywhere in the tile.

**Fix.** The till currency is sent up (`session.currency` → `till_currency`) and
compared with the rules currency at `hold()`, **for the POS channel only** — there
is no till in an online checkout. **It fails closed**: a POS hold that cannot
state its currency is refused. That broke eight existing POS tests, every one of
which had been holding at a till without saying what currency it was in; each now
says so.

**Read the status precisely: the refusal path is verified, the success path is
not.** See "What is blocked" below.

### 4. V19 — every control in the tile was dead

`Modal.jsx` wired nine controls to `onPress` and the search field to `onSubmit`.
**POS emits neither.** Preact maps an `onX` prop on a custom element to a listener
for event `x`, so a wrong name attaches a listener that never fires — silently.
`onPress` appears in **zero files** in `@shopify/ui-extensions`; `Button` and
`Clickable` declare `onClick`, `SearchField` declares `onInput`/`onChange`/
`onBlur`/`onFocus`.

The device had already run the experiment without anyone noticing: `Tile.jsx` used
`onClick` and the tile opened; `onInput` fired and the search hints updated;
`onPress` and `onSubmit` did nothing. So search, member selection, the £5
steppers, **redeem**, both enrol paths, cancel and back were all inert.

**Fix.** Nine renames to `onClick`, and the search field's unreachable `onSubmit`
replaced with a **visible Search button** — a rename alone would have left a till
user typing a name with nothing to press.

**And the part that matters more than the rename:**
`extensions/loyalty-tile/tests/handlerContract.test.js` reads every
`<s-tag onX=` out of the JSX and checks it against the installed
`@shopify/ui-extensions` type declarations. The allowlist is **derived, not
written down**, so upgrading the package re-checks the tile for free. Verified by
reintroducing the defect: four failures, naming `<s-button onPress=` in the
output.

---

## What Step B actually proved, and what it did not

**Steps 1–5 pass. Steps 6–12 have never run.**

Proven on a real Android device, 3 Sep 2026:

- The tile opens from the smart grid with a cart.
- The new Search button renders and fires.
- All five identifiers find the member: club card `0000-0010`, email
  `ashish.agrawal@dotsquares.com`, surname `Member`, postcode `SP4 6AB`, legacy
  card `D-118422`.
- **Tapping a result opens the member screen** — showing "£150 of voucher value",
  "3000 points available", "Card D-118422", the −£5/+£5 steppers and "Apply £50".
  **That tap path had never once been exercised before V19 was fixed.**
- Bare `118422` without the `D-` prefix **misses**, as expected — `legacy_card` is
  a prefix match. Recorded as a finding about what staff might type, not a bug.
- The snowboard prices at **£600** at the US till, with no VAT.

**Not proven, and not attempted:** pressing Apply, the redemption completing, the
reference reaching the receipt, `orders/paid` confirming, points leaving the
ledger, the two-location attribution test (B2), and the offline state (D).

**A correction that matters.** An earlier report that "all five searches return
the member" was recorded before V19 was found. It meant the results *list*
rendered; nobody had tapped a result, and tapping was dead. It evidenced the
**query path only**. Section B of `docs/DEV_STORE_TEST_SCRIPT.md` now carries a
warning to that effect, and B must be re-run from step 1.

---

## What is blocked, and on what

### Tomorrow's first decision point

**Can POS Pro be assigned to a third, UK-addressed location?** An admin and
billing question, being answered by the client-side lead directly — **not a code
question, and not one to investigate from here.**

- **If POS Pro assigns and the tile reports `cur=GBP`** → checklist items A1–A3
  become provable on this store and the POS happy path closes here.
- **If it does not** → POS redemption joins section B and closes only on OSC's
  store. POS Lite does not run POS UI extensions at all, so there is no partial
  result to salvage.

Either way there is an inventory prerequisite: `The Collection Snowboard:
Hydrogen` is inventory-tracked with 48 units at the **existing** locations, so a
new location starts at zero and POS will not add a tracked, zero-stock item to a
cart. Move units across, or use `The Inventory Not Tracked Snowboard` (£949.95,
untracked) and expect the figures to differ from the run-sheet.

### The development store is artificial — this is essential context

Its merchant address is **locked to the US**, its POS locations are US and
Canadian, and its currency configuration was set up by accident (the base
currency changed twice in one day). **OSC's store is entirely UK** — UK address,
GBP, UK market throughout. So **V12 and V18 are development-store artifacts, not
client defects.** At OSC the till is GBP, the rules are GBP, both sides agree and
V18's guard simply passes.

That does not make the guards unnecessary. V13 fired for real here and minted a
**€50 voucher for a GBP programme** — that is what a misconfiguration looks like
when nothing checks.

**A UK location would be genuine on currency and misleading on tax.** Till
currency resolves through the REGION market matching the location's country,
proven by contextual pricing: `GB → 720.00 GBP`, `CA → 1080.00 CAD`,
`US → 824.00 USD`, each exactly its market's base currency. Tax follows merchant
**establishment**, which is still US, so a VAT line appearing or not appearing
says nothing about OSC. One exception: V12's real question is Shopify's own
arithmetic, so *if* a discounted POS sale there returns `taxesIncluded: true` with
a VAT line, V12 can close on it. Do not plan around that.

### Live-store checklist

Full version with reasons is in `DECISIONS.md` under "Live-store verification
checklist". Summary:

**Provable here if a UK location is added:** A1 V18 success path · A2 steps 9–12 ·
A3 the POS happy path end to end.

**Live store only:** B1 V12 tax-inclusive earn base · B2 V16 a role for every till
user · B3 V10 the five reports reconciling · B4 V9 Klaviyo live · B5 C5 live
currency and timezone · B6 migration reconciliation · B7 `read_all_orders`
backfill · B8 VAT-inclusive presentation · B9 the production URL and its grant.

### Other open items

- **V14** — a compensating `earn_reversal` carries no `qualifying_value_pence`, so
  reported spend overstates real spend. **Held deliberately** as a Sprint 5 gate:
  the candidate fix changes what the column means and the reporting design must
  agree first. **Do not fix this ahead of Sprint 5.**
- **V15** — `@shopify/ui-extensions` is installed at `2025.10.16` while the
  extension declares `api_version = "2026-07"`. Not blocking, but
  `handlerContract.test.js` is only as truthful as that package, so it is now
  load-bearing.
- **V16** — only the first staff member on a shop is bootstrapped; every other
  till user gets `403 no_role_assigned` and the tile looks broken. A go-live gate.
  Whether POS staff get an implicit `viewer` floor is parked next to C9.
- **C1** — the storefront redemption control, blocked on OSC, and the only
  substantial build left in Sprint 3.

---

## The audit's three cautions

A per-module status audit is at `PROGRESS.md` → "Per-module status audit —
3 Sep 2026". Read it with these:

1. **"Verified" means verified on an artificial store**, everywhere except the
   ledger arithmetic. Online redemption is the strongest evidence in the project
   and it still ran against a US-addressed, then-EUR shop.
2. **The POS tile is the standing warning.** It was "built" for days, carried 31
   passing tests, and every control in it was dead. Steps 1–5 passing today is the
   first real evidence any of it worked. All four of today's defects came from the
   same place: the parts no test could see.
3. **Nothing customer-facing exists**, and no sprint has yet been planned that
   builds it. No customer account extension, no storefront surface, no route by
   which a member can see their own balance, points history, vouchers or expiry
   warnings. Roughly half the programme's value has no implementation and no
   verification story. V11 was never spiked; C1 is blocked on OSC.

There is also a standing testing rule, now at **four instances in two days**
(`discountAllocations`/C14, `appUrl`, an incidental fixture, `onPress`):
**if a test supplies what production derives, something else must test the
derivation** — generalised today to *a test standing where the defect cannot be
seen from*. `DECISIONS.md`, "Testing principle".

---

## Next actions, in order

1. **The client-side lead checks whether POS Pro assigns to a third, UK-addressed
   location.** Everything in section A waits on this. Do not investigate it from
   a session — it is an admin task.
2. **If yes:** create the UK location (address country **United Kingdom** is the
   only field that decides currency), activate POS Pro on it, move inventory or
   switch product, switch the device to it, confirm the till is GBP, then run
   **Step B from step 1** including the newly-live tap path — and on to steps
   6–12. Expect the snowboard at **£720** there, since the UK market is
   VAT-inclusive.
3. **If no:** move A1–A3 into section B of the live-store checklist and stop
   pursuing POS redemption on this store.
4. **Then plan the next sprint**, using the audit. The customer-facing gap is the
   thing to decide about first.
5. **Client update to Robert** is the lead's to write. The audit's customer-facing
   paragraph is what he will react to — worth leading with rather than burying
   under the POS work.

## Environment, in case it is needed

`shopify app dev` allocates a **new tunnel every restart**, and a tunnel change
needs a `deploy`, not just a restart, because under `use_legacy_install_flow` the
OAuth `redirect_uri` is validated against the **released** app version. Order is
always: restart dev → update `shopify.app.toml` → `npx shopify app deploy
--force` → re-grant via `/api/auth`. A plain re-grant is enough for a scope
change; no uninstall needed. `web/serve.mjs` now warns at boot if the CLI's
injected `SCOPES` disagree with the toml, which is a failure that cost a full
reinstall to discover today.

`web/serve.mjs` also rewrites the tile's `APP_URL` at dev start, so the extension
follows the tunnel with no manual step.

**Suite state at close:** backend **461 tests / 2,078 assertions**, extension
**73**, frontend **134**, Pint clean, tree clean, everything pushed to
`origin/main`. Today's three temporary diagnostic commits have been reverted.
