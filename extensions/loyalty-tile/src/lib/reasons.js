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

/**
 * What went wrong with a request, in words a person at a till can act on.
 *
 * **Why this exists (V17).** On 3 Sep 2026 `EnsureStaffRole` returned a 403
 * carrying "This staff member has no Privilege Club role. An Administrator must
 * assign one." — a reason precise enough to act on without help — and the tile
 * rendered it as "That search could not be run." `MemberView` was worse: it
 * mapped every failure to `refusalFor(null)`, so a role problem read as
 * "Redemption is not available", blaming the basket for something the basket
 * had nothing to do with. Diagnosing it needed a resolver test and a
 * `staff_roles` query to reach a conclusion the response body already stated.
 *
 * Two rules, and the second is the one that matters:
 *
 * 1. **Codes we know get till wording**, because at a till "what do I do now"
 *    is as much of the message as "what is wrong". Every one of these ends with
 *    an instruction, and the instruction is almost always *continue the sale* —
 *    C7's rule, and the only thing that keeps a queue moving.
 * 2. **Codes we do not know fall back to the server's own message**, never to a
 *    generic string. A new backend error must never arrive silently generic;
 *    that is precisely the failure mode that cost a day.
 */
const MESSAGES = {
  no_role_assigned: {
    title: 'Your till account is not set up yet',
    detail: 'You do not have a Privilege Club role. Continue the sale, and ask an administrator to assign one.',
  },
  role_floor_not_met: {
    title: 'Your account cannot do that',
    detail: 'Your Privilege Club role does not allow this action. Continue the sale, and ask an administrator.',
  },
  invalid_session_token: {
    title: 'The till session could not be verified',
    detail: 'Sign out of POS and back in, then try again. Continue the sale in the meantime.',
  },
  no_session: {
    title: 'No permission to use Privilege Club',
    detail: 'This POS user is not permitted to use the app. Continue the sale, and ask an administrator.',
  },
  no_app_url: {
    title: 'Loyalty is not configured for this device',
    detail: 'Continue the sale and report it — the app has no address to call.',
  },
  unreachable: {
    title: 'The loyalty system could not be reached',
    detail: 'Continue the sale. Points for it are added automatically once the till is back online.',
  },
};

const UNEXPLAINED = {
  title: 'Privilege Club is not responding properly',
  detail: 'Continue the sale and report it.',
};

/**
 * Wording for a failed TillApi response.
 *
 * Takes the whole response rather than just the code, because the fallback needs
 * `message` — the server's own sentence — and dropping it is the defect this
 * function exists to prevent.
 */
export function messageFor(response) {
  const known = MESSAGES[response?.error];

  if (known) {
    return known;
  }

  // Unknown code. The server said something; say it rather than inventing a
  // vaguer version of it.
  if (typeof response?.message === 'string' && response.message.trim() !== '') {
    return {
      title: 'Privilege Club could not do that',
      detail: response.message.trim(),
    };
  }

  return UNEXPLAINED;
}
