# Dev-store test script — Sprint 3 redemption

**Purpose.** Three things the spikes proved against the schema and cannot prove
against a shop. Everything else in Sprint 3 is covered by 600 automated tests;
these are the ones that need a real cart.

| # | What it proves | Why code cannot |
| --- | --- | --- |
| A | A single-use discount code carries a redemption from quote to spent points | The suite fakes the writer, so only a real shop proves Shopify mints the code with the constraints we never re-check, and that the code survives onto the paid order as the reference `orders/paid` reads |
| B | POS populates `cart.retailLocation` | Same: readable in the schema, shaped in fixtures, never seen from a real till |
| C | One end-to-end redemption posts through the ledger with the reference on the receipt | The chain crosses four systems (tile → app → POS cart → order webhook → ledger) and nothing but a real sale exercises all of it |

---

## Before you start

```bash
# 1. Terminal one — the tunnel and the app. Leave this running.
shopify app dev

# 2. Terminal two — the queue worker. Nothing earns and nothing is
#    confirmed without it: orders/paid is acknowledged fast and queued.
cd web && php artisan queue:work --tries=5

# 3. Terminal three — the scheduler, for A4 only.
#    The quote expiry sweep is NOT queued: it runs synchronously inside the
#    scheduler process, so the queue worker above will never run it, and
#    nothing on a dev machine runs it unless this is up. A4 can also force it
#    by hand, in which case skip this terminal.
cd web && php artisan schedule:work

# 4. Terminal four — for the checks below.
cd web
```

**Scopes.** The code path needs `write_discounts` and `read_discounts`, both
granted. Confirm, and note the list is Shopify's collapsed form where each
`write_*` implies its `read_*`:

```bash
php artisan tinker --execute="echo App\Models\Session::first()->scope;"
```

Expect `write_discounts` present. If it is absent, A1 fails at the mint and
`storage/logs/laravel.log` will say so.

**Register the webhooks** (they follow the tunnel URL, which `shopify app dev`
has just changed):

```bash
php artisan shopify:webhooks --register
php artisan shopify:webhooks          # confirm none are [stale]
```

Expect `orders/paid`, `orders/updated`, `orders/cancelled`, `refunds/create`.

---

## A. The single-use discount code, end to end

**Replaces the old A entirely.** A1-A5 tested the discount function reading a
customer metafield. That path is not what ships: Shopify refuses to activate a
function from a custom app below Plus and the live OSC store is on Grow (D5,
revised 2 Sep 2026). The function gateway is kept as the Plus-and-above option
and has its own tests; what needs a real shop is the code path.

The member from the earlier run is reused rather than created again: **account id
10**, customer `9671675085040`, `ashish.agrawal@dotsquares.com`, 1000 points =
`voucher_balance_pence` 5000.

**Two synthetic ledger entries on this account are deliberate, not defects.**
Both are dev-store scaffolding and neither should be "corrected" by anyone
reading the ledger later:

| Key | What it is |
| --- | --- |
| `devstore:open:10` | The original 1000-point balance created for the first run |
| `devstore:a4-topup:10` | A further 1000 points added 2 Sep 2026, because A3 spends the whole balance and A4 needs something left to quote against. Kept on purpose so this script can be re-run |

There is also a real correction on this account, which IS meaningful: an
`earn_reversal` of `-50` against order `#1002`, parented to earn id 22. That is
the C14 fix being applied by replay, and the net earn for that order is 550
points rather than 600. See the 3 Sep 2026 change-log rows in `DECISIONS.md`.

### A1 - hold a quote, and see the code appear on Shopify

```bash
php artisan tinker
```

```php
$shop = 'loyalty-system.myshopify.com';
$member = App\Models\LoyaltyAccount::where('shop_domain', $shop)->findOrFail(10);

$held = app(App\Domain\Redemption\RedemptionService::class)->hold(
    account: $member,
    // The container decides the online mechanism. Resolving the interface is
    // the point: if this returns the function gateway, the binding is wrong.
    gateway: app(App\Domain\Redemption\RedemptionGateway::class),
    eligibleSubtotalPence: 6000,
    basketTotalPence: 10000,
    channel: 'online',
);

$r = $held['redemption'];
[$r->reference, $r->amount_pence, $r->discount_mechanism, $r->shopify_discount_gid];
```

**Expect** `discount_mechanism` of `discount_code`, `amount_pence` 5000, and a
non-null `shopify_discount_gid`. Note the reference - call it **REF**.

**If `shopify_discount_gid` is null** the mint failed and the quote has nothing
to apply. `storage/logs/laravel.log` carries the reason; a duplicate code or a
missing `write_discounts` grant are the likely ones.

Now read it back from Shopify, which is the half no test can reach:

```php
App\Services\ShopifyAdminApi::forShop($shop)->query(<<<'GQL'
query { codeDiscountNodeByCode(code: "REF_GOES_HERE") {
  codeDiscount { ... on DiscountCodeBasic {
    title status usageLimit appliesOncePerCustomer startsAt endsAt
    codes(first: 1) { nodes { code } }
    customerGets { value { ... on DiscountAmount { amount { amount currencyCode } } } }
  } }
} }
GQL);
```

**Expect** `status: ACTIVE`, `usageLimit: 1`, `appliesOncePerCustomer: true`, the
amount 50.00 GBP, `endsAt` equal to the quote's `quote_expires_at`, and the code
equal to REF. **These are the constraints the app deliberately does not
re-check**, so this read is the only thing standing behind them.

### A2 - apply it at checkout

1. Open the dev store **storefront** and **log in as that customer**. The code is
   bound to them: a guest or a different customer must be refused, and that
   refusal is a pass, not a failure.
2. Add **GBP 100 of ordinary products** (not gift cards).
3. At checkout, enter **REF** in the discount field.

**A2 passes if** the order summary shows a discount line of **GBP 50.00**
carrying REF.

**Worth trying deliberately, both are quick:** enter REF while logged out or as
another customer, and confirm it is refused. That is the customer binding doing
the only job that protects it, since REF is printed on receipts and is not a
secret.

### A3 - pay, and watch the points leave the ledger

1. **Take payment** (Bogus Gateway).
2. Wait a few seconds for the queue worker to pick up `orders/paid`.

```bash
php artisan tinker --execute="
\$r = App\Models\Redemption::latest('id')->first();
echo 'ref=', \$r->reference, ' state=', \$r->state,
     ' points_consumed=', \$r->points_consumed,
     ' order=', var_export(\$r->shopify_order_id, true), PHP_EOL;"
```

**Expect** `state=confirmed`, `points_consumed=1000`, and the order id set.

```bash
php artisan tinker --execute="
\$e = App\Models\LedgerEntry::whereIn('entry_type',['earn','redemption'])->latest('id')->take(5)->get();
foreach (\$e as \$x) { echo \$x->entry_type, ' pending=', \$x->pending_delta,
  ' available=', \$x->available_delta, ' key=', \$x->idempotency_key, PHP_EOL; }"
```

**A3 passes if you see both:**

- a `redemption` entry with `pending=0`, `available=-1000` and
  `key=redeem:REF`, and
- an `earn` entry with `pending=` the qualifying spend in points, net of the
  voucher and ex VAT and shipping (D3).

Then:

```bash
php artisan loyalty:verify-ledger     # expect: every cached balance matches
```

**If `state` is still `quoted`**, the code did not survive onto the order as a
readable code. That is the interesting failure, because the code is the *only*
link between a paid order and the quote behind it. Check what the order carries:

```php
App\Services\ShopifyAdminApi::forShop('loyalty-system.myshopify.com')->query(<<<'GQL'
query { order(id: "gid://shopify/Order/<ORDER_ID>") {
  discountApplications(first: 10) { nodes { __typename
    ... on DiscountCodeApplication { code } } } } }
GQL);
```

**Expect** `code` equal to REF. If the code is there and the state is still
`quoted`, send me the exact value.

### A4 - the quote expiry sweep retires an unused code

Points were never deducted at generation, so an unused code costs the member
nothing. What must happen is that the offer stops standing.

```bash
php artisan tinker --execute="
\$m = App\Models\LoyaltyAccount::findOrFail(10);
\$h = app(App\Domain\Redemption\RedemptionService::class)->hold(
  account: \$m, gateway: app(App\Domain\Redemption\RedemptionGateway::class),
  eligibleSubtotalPence: 6000, basketTotalPence: 10000, channel: 'online');
echo 'held ', \$h['redemption']->reference, ' gid=', \$h['redemption']->shopify_discount_gid, PHP_EOL;"
```

Wait for the sweep, or force it:

```bash
php artisan loyalty:expire-quotes --as-of="+30 minutes"
```

```bash
php artisan tinker --execute="
\$r = App\Models\Redemption::latest('id')->first();
echo 'state=', \$r->state, ' points_consumed=', \$r->points_consumed, PHP_EOL;"
```

**A4 passes if** `state=void`, `points_consumed=0`, **no ledger entry was
written for it** (the sweep's own `job.completed` audit entry is the record, at
the C12 grain of one entry per run), and the code reads back from Shopify as no
longer active.

**Note there is no gift-card step.** It was run on 2 Sep 2026 and closed:
Shopify excludes gift-card lines from a fixed-amount code itself, proved on paid
order `#1001` where the gift-card line came back with an empty
`discountAllocations`. There is no app-level behaviour left to test.
---

## B. POS populates `retailLocation`

> **Nothing in section B has been passed yet, as of 3 Sep 2026.** An earlier run
> reported all five searches finding the member; that was the results list
> rendering on the deep-link preview surface, and nobody tapped a result. Tapping
> was dead — every control in the modal was wired to `onPress`, which POS does
> not emit (V19). So the query path is verified and **nothing else is**. Re-run
> B from step 1, and treat tapping through to the member screen as something to
> verify rather than assume.


1. Open **Shopify POS on the iPad** (10th gen, POS app 11.11.1 — MD10), signed
   into the dev store, staff pinned.
2. Add any product to the cart.
3. Open the **Privilege Club tile** on the home screen.

**✅ The tile opens** and shows the lookup screen. (If it shows *"Privilege Club
unavailable"*, the iPad has no connection — that is C7 working, and step D below
tests it deliberately.)

4. Search for your test member by **email**, then by **surname**, then by
   **postcode** if you set one. Then by the Privilege Club card number shown on
   their console profile.

**✅ Each finds them.** Note any that do not.

5. Tap the member. The screen shows the balance and a redeem control.
6. Tap **Apply**.

Now confirm POS sent the location:

```bash
php artisan tinker --execute="
\$r = App\Models\Redemption::latest('id')->first();
echo 'ref=', \$r->reference, ' location=', var_export(\$r->shopify_location_id, true),
     ' staff=', \$r->staff_reference, PHP_EOL;"
```

**✅ B passes if `location=` is a number, not `NULL`.**
**✅ Staff attribution passes if `staff=` carries the pinned staff member's id**
(and, if the pinned member differs from the signed-in user, both).

### B2 - two locations, two distinct ids (strengthened 3 Sep 2026)

POS Pro is Active on **both** locations of the development store, so location
attribution can be tested properly rather than merely observed:

| Location | Address | Notes |
| --- | --- | --- |
| Shop location | United States | the store **default** |
| My Custom Location | 123 Main St, Toronto, Canada | |

Non-NULL on a single location proves only that *a* value arrived, which a
hardcoded default would also satisfy. Run the sale **once from each location**
— switch location in POS, which starts a new session — and compare:

```bash
php artisan tinker --execute="
App\Models\Redemption::where('channel','pos')->latest('id')->take(2)->get()
  ->each(fn (\$r) => print(\$r->reference.'  location='.var_export(\$r->shopify_location_id, true).PHP_EOL));"
```

**✅ B2 passes only if the two ids are DIFFERENT, and each matches the location
the sale was actually taken at.** Two identical ids across two locations is a
failure even though both are non-NULL — it means the tile is reporting something
other than the live POS session.

Note MD10 still targets **one** location for the production device matrix. Two
locations here is a testing opportunity, not a change to what OSC must run.


---

## C. End to end, with the reference on the receipt

Continuing from B, with the discount applied to the POS cart:

1. **Take payment** (Cash, exact amount).
2. Look at the receipt — on screen, or print/email it.

**✅ The discount line on the receipt reads `Privilege Club £N (REF)`** with the
same REF the tile applied. That is D6's requirement: the receipt and the audit
log share one identifier.

3. Wait for the queue worker (terminal two) to log the `orders/paid` job — a few
   seconds.

```bash
php artisan tinker --execute="
\$e = App\Models\LedgerEntry::whereIn('entry_type',['earn','redemption'])->latest('id')->take(5)->get();
foreach (\$e as \$x) { echo \$x->entry_type, ' pending=', \$x->pending_delta,
  ' available=', \$x->available_delta, ' order=', \$x->shopify_order_id, PHP_EOL; }"
```

**✅ C passes if you see both:**

- an `earn` entry with `pending=` the qualifying spend in points (£1 = 1 point,
  net of the voucher, ex VAT and shipping — D3), and
- a `redemption` entry with `available=` **minus** the points for the amount
  applied (£5 = 100 points, so £50 = −1000).

Then confirm the whole picture:

```bash
php artisan loyalty:verify-ledger     # expect: every cached balance matches
```

```bash
php artisan tinker --execute="
\$r = App\Models\Redemption::latest('id')->first();
echo 'state=', \$r->state, ' points_consumed=', \$r->points_consumed, PHP_EOL;"
```

**Expect** `state=confirmed` and `points_consumed=1000`.

**If `state` is still `quoted`**, the reference did not survive onto the order.
That is the interesting failure, because the reference is the *only* link between
a paid order and the quote that produced it. Check what the order actually
carries:

```bash
php artisan tinker
```

```php
App\Services\ShopifyAdminApi::forShop('loyalty-system.myshopify.com')->query(<<<'GQL'
query { order(id: "gid://shopify/Order/<ORDER_ID>") {
  discountApplications(first: 10) { nodes { __typename
    ... on AutomaticDiscountApplication { title }
    ... on ManualDiscountApplication { title description } } } } }
GQL);
```

**Expect** a title containing `(PC-nnnnnnnn)`. If the title is there but the
reference is not, POS or Shopify has truncated or rewritten it — send me the
exact title and I will change what the discount is named.

---

## D. The offline state (C7), deliberately

1. On the iPad, turn **Wi-Fi off** (Airplane mode).
2. Open the Privilege Club tile.

**✅ D passes if** the tile reads **"Privilege Club unavailable"** and the text
says to **continue the sale**, with points added once back online.

3. With Wi-Fi still off, add a product and **take payment**.
4. Turn Wi-Fi back on and wait for the queue worker.

**✅ The points for that sale arrive anyway**, via `orders/paid`:

```bash
php artisan tinker --execute="
echo App\Models\LedgerEntry::where('entry_type','earn')->latest('id')->first()?->pending_delta, PHP_EOL;"
```

---

## What to send back

1. The `codeDiscountNodeByCode` read from **A1** - status, `usageLimit`,
   `appliesOncePerCustomer`, amount and `endsAt`.
2. Screenshot of the **checkout summary** from A2 showing the GBP 50.00 line with
   REF, and what happened when you tried REF as another customer or logged out.
3. The `state` / `points_consumed` line and the two ledger entries from **A3**.
4. The `state=void` line from **A4**, and whether any ledger entry appeared
   against it (there should be none).
5. The `location=` / `staff=` line from **B**.
6. Photo or screenshot of the **receipt** discount line from **C**.
7. The ledger entries and `state=` from **C**.
8. Anything in the queue worker's output that looks like a warning.

Any of A, B or C failing tells us something the schema could not, which is the
whole reason for running it by hand.
