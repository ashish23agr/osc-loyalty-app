/**
 * Why the till cannot offer a redemption, in words a person can act on.
 *
 * The server sends a code because a code is a contract; the wording belongs
 * here, where changing it costs nothing. Taken from the agreed UI where that
 * file names a thing.
 *
 * Every one of these is a sentence a member of staff can read out to a customer
 * with a queue behind them. "Cannot redeem" is not — it invites an argument
 * nobody at the till can settle.
 */
export const REFUSAL = {
  no_voucher_balance: {
    title: 'No voucher value yet',
    detail: 'This member has not earned enough points for a voucher.',
  },
  no_eligible_items: {
    title: 'Nothing in this basket qualifies',
    detail: 'Vouchers cannot be spent on the items in this sale.',
  },
  minimum_basket_not_met: {
    title: 'Basket is below the minimum',
    detail: 'This sale is too small to redeem against.',
  },
  below_voucher_increment: {
    title: 'Not enough qualifying items',
    detail: 'Vouchers are redeemed in whole increments, and the qualifying part of this basket is worth less than one.',
  },
};

const UNKNOWN = {
  title: 'Redemption is not available',
  detail: 'The till could not confirm an amount for this sale.',
};

export function refusalFor(reason) {
  return REFUSAL[reason] ?? UNKNOWN;
}

/** Is this a state the assistant can fix by changing the basket? */
export function isBasketFixable(reason) {
  return reason === 'no_eligible_items'
    || reason === 'minimum_basket_not_met'
    || reason === 'below_voucher_increment';
}
