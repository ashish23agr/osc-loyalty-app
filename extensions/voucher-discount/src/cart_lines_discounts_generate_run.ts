import {
  CartInput,
  CartLinesDiscountsGenerateRunResult,
  DiscountClass,
  OrderDiscountSelectionStrategy,
} from '../generated/api';
import {LadderRules, quote, toPence} from './ladder';

/**
 * Applying a member's voucher value at checkout (D5, D8, M6).
 *
 * A fixed-amount ORDER discount, not a product one: the voucher is money off
 * the sale, not a price change on a particular item, and modelling it as a
 * product discount would make it fight with genuine product promotions for the
 * same line.
 *
 * **What this function trusts, and what it does not.**
 *
 * A cart attribute can be written by anyone holding the cart — it is how the
 * storefront tells us what the customer asked for, and it is not evidence of
 * anything. The customer metafield is in the app-reserved namespace, which only
 * this app can write, so that is where the balance and the rule values come
 * from. The attribute can therefore only ever ask for LESS than the ladder
 * already permits; asking for more gets the ladder's answer.
 *
 * Without that split, a customer could set `loyalty_redeem_pence` to the value
 * of their basket and pay nothing.
 *
 * The ladder is re-applied here against the LIVE cart rather than trusting the
 * quote the app issued, because the cart can change after a quote — a line
 * removed, a quantity reduced — and the discount has to be right at the moment
 * it is applied.
 */
export function cartLinesDiscountsGenerateRun(
  input: CartInput,
): CartLinesDiscountsGenerateRunResult {
  const cart = input.cart;

  if (!cart?.lines?.length) {
    return {operations: []};
  }

  // A voucher is money off the order. Without the ORDER class there is nothing
  // this function can legitimately do.
  if (!input.discount.discountClasses.includes(DiscountClass.Order)) {
    return {operations: []};
  }

  // V6a: the shop-level half of the config. Per-product exclusion cannot live
  // here (see qualifies), but a shop-wide switch can, and this is the one that
  // matters: OSC turning online redemption off in Settings must take effect
  // without a redeploy.
  if (!onlineRedemptionEnabled(input)) {
    return {operations: []};
  }

  const entitlement = readEntitlement(cart);

  if (entitlement === null) {
    return {operations: []};
  }

  const eligibleSubtotalPence = eligibleSubtotal(cart);
  const basketTotalPence = toPence(cart.cost?.subtotalAmount?.amount);

  const offered = quote(
    entitlement.balancePence,
    eligibleSubtotalPence,
    basketTotalPence,
    entitlement.rules,
  );

  if (offered.redeemable_pence <= 0) {
    return {operations: []};
  }

  // C1 is open. Until it is answered the storefront may ask for less than the
  // full entitlement, and that request is honoured only downwards and only in
  // whole increments — so whatever control C1 turns out to specify, this side
  // does not have to change.
  const requested = requestedPence(cart);
  const applied =
    requested === null
      ? offered.redeemable_pence
      : Math.min(
          offered.redeemable_pence,
          Math.floor(requested / entitlement.rules.voucher_value_pence) *
            entitlement.rules.voucher_value_pence,
        );

  if (applied <= 0) {
    return {operations: []};
  }

  return {
    operations: [
      {
        orderDiscountsAdd: {
          candidates: [
            {
              // The reference travels with the discount so the order, the
              // ledger entry and the audit log share one id (D6 does the same
              // at the till).
              message: discountMessage(applied, entitlement.reference),
              targets: [{orderSubtotal: {excludedCartLineIds: excludedLineIds(cart)}}],
              value: {fixedAmount: {amount: (applied / 100).toFixed(2)}},
            },
          ],
          selectionStrategy: OrderDiscountSelectionStrategy.First,
        },
      },
    ],
  };
}

interface Entitlement {
  balancePence: number;
  rules: LadderRules;
  reference: string | null;
}

/**
 * The member's voucher value and the rules in force, from the app-owned
 * metafield.
 *
 * Returns null for anyone this function cannot vouch for: a guest, an
 * unauthenticated buyer, a customer with no loyalty metafield, or a payload
 * that is not the shape this app writes. Every one of those is an ordinary
 * checkout, not an error.
 */
function readEntitlement(cart: CartInput['cart']): Entitlement | null {
  const buyer = cart.buyerIdentity;

  // An unauthenticated buyer can claim to be anyone. The metafield would be
  // read from whoever they claim to be, so the claim has to be real first.
  if (!buyer?.isAuthenticated || !buyer.customer) {
    return null;
  }

  const raw = (buyer.customer as any).loyalty?.jsonValue;

  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const balancePence = asInteger(raw.voucher_balance_pence);
  const voucherValuePence = asInteger(raw.voucher_value_pence);

  // A rule set that would divide by zero, or a balance that is not a number,
  // is a broken write rather than a member with no voucher. Refusing is the
  // safe direction: nobody gets a discount they cannot be shown to be owed.
  if (balancePence <= 0 || voucherValuePence <= 0) {
    return null;
  }

  return {
    balancePence,
    rules: {
      voucher_value_pence: voucherValuePence,
      max_redemption_per_order_pence: asInteger(raw.max_redemption_per_order_pence),
      min_basket_pence: asInteger(raw.min_basket_pence),
    },
    reference: typeof raw.reference === 'string' ? raw.reference : null,
  };
}

/**
 * What the qualifying part of the basket is worth.
 *
 * Gift cards never qualify — a voucher that buys a gift card converts loyalty
 * value straight back into spendable money — and neither does anything the
 * merchant has tagged out.
 */
function eligibleSubtotal(cart: CartInput['cart']): number {
  return cart.lines.reduce((total, line) => {
    return qualifies(line) ? total + toPence(line.cost?.subtotalAmount?.amount) : total;
  }, 0);
}

function excludedLineIds(cart: CartInput['cart']): string[] {
  return cart.lines.filter((line) => !qualifies(line)).map((line) => line.id);
}

function qualifies(line: any): boolean {
  const merchandise = line.merchandise;

  // A CustomProduct has no product record to judge, so it cannot be shown to
  // qualify. Excluding it is the conservative direction.
  if (!merchandise || merchandise.__typename !== 'ProductVariant') {
    return false;
  }

  const product = merchandise.product;

  if (!product) {
    return false;
  }

  // A voucher that buys a gift card converts loyalty value straight back into
  // spendable money, so this one is not configurable.
  if (product.isGiftCard) {
    return false;
  }

  // V6a: one app-synced boolean, resolved from whatever the saved rule version
  // says. A product with no flag has not been excluded by anything.
  const flag = product.loyaltyExcluded?.jsonValue;

  return !(flag && flag.excluded === true);
}

/**
 * Whether OSC has online redemption switched on (V6a).
 *
 * From the shop metafield, in the app-reserved namespace, so it follows the
 * saved rule version without a redeploy. Absent config means enabled: a shop
 * that has never saved a rule set is on the launch defaults, where online
 * redemption is on, and refusing there would break redemption on day one.
 *
 * This is the right shape of thing for a SHOP metafield - one answer for the
 * whole store. Per-product exclusion is not, which is why it is a per-product
 * flag instead.
 */
function onlineRedemptionEnabled(input: CartInput): boolean {
  const raw = (input as any).shop?.loyaltyConfig?.jsonValue;

  if (!raw || typeof raw !== 'object') {
    return true;
  }

  return raw.online_redemption_enabled !== false;
}

function requestedPence(cart: CartInput['cart']): number | null {
  const raw = (cart as any).requestedRedemption?.value;

  if (typeof raw !== 'string' || raw.trim() === '') {
    return null;
  }

  const value = parseInt(raw, 10);

  return Number.isFinite(value) && value >= 0 ? value : null;
}

function discountMessage(pence: number, reference: string | null): string {
  const pounds = (pence / 100).toFixed(2).replace(/\.00$/, '');
  const label = `Privilege Club £${pounds}`;

  return reference ? `${label} (${reference})` : label;
}

function asInteger(value: unknown): number {
  const number = typeof value === 'number' ? value : parseInt(String(value ?? ''), 10);

  return Number.isFinite(number) ? Math.trunc(number) : 0;
}
