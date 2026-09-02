# Dev-store test script — Sprint 3 redemption

**Purpose.** Three things the spikes proved against the schema and cannot prove
against a shop. Everything else in Sprint 3 is covered by 600 automated tests;
these are the ones that need a real cart.

| # | What it proves | Why code cannot |
| --- | --- | --- |
| A | The entitlement metafield is visible to the discount function at cart time | The spike proved the field is *selectable* and that compiled Wasm reads a fixture. Only a live cart proves Shopify actually populates it from a `metafieldsSet` write |
| B | POS populates `cart.retailLocation` | Same: readable in the schema, shaped in fixtures, never seen from a real till |
| C | One end-to-end redemption posts through the ledger with the reference on the receipt | The chain crosses four systems (tile → app → POS cart → order webhook → ledger) and nothing but a real sale exercises all of it |

---

## Before you start

```bash
# 1. Terminal one — the tunnel and the app. Leave this running.
shopify app dev

# 2. Terminal two — the queue worker. Nothing earns without it.
cd web && php artisan queue:work --tries=5

# 3. Terminal three — for the checks below.
cd web
```

**Re-authorise once.** `write_products` was added for V6a after the last grant.
Open the app in the dev store admin and accept the prompt. Confirm:

```bash
php artisan tinker --execute="echo App\Models\Session::first()->scope;"
```

Expect `write_products` in the list. If it is absent, the sync in step A2 will
write nothing and step A will fail for the wrong reason.

**Register the webhooks** (they follow the tunnel URL, which `shopify app dev`
has just changed):

```bash
php artisan shopify:webhooks --register
php artisan shopify:webhooks          # confirm none are [stale]
```

Expect `orders/paid`, `orders/updated`, `orders/cancelled`, `refunds/create`.

---

## A. The entitlement metafield is visible to the function

### A1 — Create the discount, once

The function needs an automatic app discount to exist before it runs at all.

```bash
php artisan tinker
```

```php
$shop = 'loyalty-system.myshopify.com';
App\Services\ShopifyAdminApi::forShop($shop)->query(<<<'GQL'
mutation { discountAutomaticAppCreate(automaticAppDiscount: {
  title: "Privilege Club voucher"
  functionHandle: "voucher-discount"
  startsAt: "2026-01-01T00:00:00Z"
  discountClasses: [ORDER]
  combinesWith: { orderDiscounts: true, productDiscounts: true, shippingDiscounts: true }
}) { automaticAppDiscount { discountId status } userErrors { field message code } } }
GQL);
```

**Expect** `status: ACTIVE` and `userErrors: []`.
**If `userErrors` mentions scopes**, the re-authorisation above did not take.

### A2 — Sync the exclusion flags

```bash
php artisan loyalty:sync-exclusions
```

**Expect** a line like
`loyalty-system.myshopify.com (rules v1) — 12 product(s) · 0 excluded / 12 included · 12 flag(s) written · shop config written`.

**If it says `SHOP CONFIG NOT WRITTEN`**, note it and carry on — that is the one
thing V6a flagged as unverified, and the function treats an absent shop config as
"enabled", so the rest of the script still works. **Tell me if you see it.**

### A3 — Give a member some voucher value

In the dev store admin, pick a customer with an email you can log in as. Note
their customer id from the URL.

```php
// tinker
$shop = 'loyalty-system.myshopify.com';
$member = App\Models\LoyaltyAccount::create([
    'shop_domain' => $shop,
    'shopify_customer_id' => <CUSTOMER_ID>,
    'email' => '<their email>',
    'first_name' => 'Test', 'last_name' => 'Member',
    'enrolment_channel' => 'admin', 'enrolled_at' => now(),
]);

app(App\Domain\Loyalty\LedgerService::class)->post($member,
    App\Domain\Loyalty\LedgerPosting::openingBalance(
        points: 1000, idempotencyKey: 'devstore:open:'.$member->id,
        occurredAt: now()->subMonth(), expiresAt: now()->addMonths(5),
        reason: 'Dev store test balance',
    ));

$member->refresh()->voucher_balance_pence;   // expect 5000
```

### A4 — Hold a quote, which publishes the metafield

```php
// tinker, continuing
$held = app(App\Domain\Redemption\RedemptionService::class)->hold(
    account: $member->refresh(),
    gateway: app(App\Domain\Redemption\DiscountFunctionGateway::class),
    eligibleSubtotalPence: 6000,
    basketTotalPence: 10000,
    channel: 'online',
);
$held['redemption']->reference;      // note this down — call it REF
$held['redemption']->amount_pence;   // expect 5000
```

Confirm Shopify actually stored it:

```php
App\Services\ShopifyAdminApi::forShop($shop)->query(<<<'GQL'
query { customer(id: "gid://shopify/Customer/<CUSTOMER_ID>") {
  metafield(key: "voucher") { namespace key jsonValue } } }
GQL);
```

**Expect** a `jsonValue` containing `voucher_balance_pence: 5000` and your REF,
and a `namespace` beginning `app--`. **The `app--` prefix is the point** — it is
the app-reserved namespace, which is why the function may trust it.

### A5 — The actual proof: put it in a cart

1. Open the dev store **storefront** and **log in as that customer**. This
   matters: the function reads `buyerIdentity.isAuthenticated`, and a guest
   checkout is designed to get nothing.
2. Add **£100 of ordinary products** (not gift cards).
3. Go to checkout — do **not** pay yet.

**✅ A passes if** the order summary shows a discount line reading
**`Privilege Club £50 (REF)`**.

**If no discount appears**, in order of likelihood:

| Check | How |
| --- | --- |
| Are you logged in, not a guest? | The account menu shows the customer's name |
| Did the discount get created? | A1 returned `status: ACTIVE` |
| Is the function deployed? | `shopify app dev` is running and shows the extension |
| Did the metafield write? | A4's query returned a `jsonValue` |

Please **screenshot the checkout summary** either way.

---

## B. POS populates `retailLocation`

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

1. Screenshot of the **checkout summary** from A5 (discount line, or its absence).
2. The `namespace` value from A4 — confirming the `app--` prefix.
3. The `location=` / `staff=` line from B.
4. Photo or screenshot of the **receipt** discount line from C.
5. The ledger entries and `state=` from C.
6. Whether A2 said `shop config written` or `SHOP CONFIG NOT WRITTEN`.
7. Anything in the queue worker's output that looks like a warning.

Any of A, B or C failing tells us something the schema could not, which is the
whole reason for running it by hand.
