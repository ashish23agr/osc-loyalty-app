/**
 * What the tile shows, as a pure function of what the device knows.
 *
 * Kept out of the component so it can be tested without rendering anything, and
 * because "what should this say" is the part that has to be right — a tile that
 * says the wrong thing at a till is worse than one that says nothing.
 */

/**
 * C7 and the offline rule, confirmed by the client: **record the sale, add the
 * points afterwards.**
 *
 * Loyalty needs a live connection. Balances cannot be checked offline and a
 * redemption cannot be validated against a ledger nobody can reach, so the tile
 * refuses rather than guessing — but it refuses in a way that makes clear the
 * SALE is unaffected. Earning still happens: `orders/paid` arrives when
 * connectivity returns and the points land then.
 *
 * The failure mode this avoids is an assistant seeing a dead tile, assuming the
 * till is broken, and stopping the sale.
 */
export function tileState({connected = true, session = null, error = null} = {}) {
  if (!connected) {
    return {
      status: 'offline',
      heading: 'Privilege Club unavailable',
      // Two sentences, and the second is the important one.
      subheading: 'No connection. Continue the sale — points are added automatically once the till is back online.',
      enabled: false,
    };
  }

  if (error) {
    return {
      status: 'error',
      heading: 'Privilege Club unavailable',
      subheading: 'Continue the sale. Points are added automatically from the order.',
      enabled: false,
    };
  }

  if (!session) {
    return {
      status: 'loading',
      heading: 'Privilege Club',
      subheading: 'Checking…',
      enabled: false,
    };
  }

  return {
    status: 'ready',
    heading: 'Privilege Club',
    subheading: 'Look up a member, redeem a voucher, or enrol someone new',
    enabled: true,
  };
}

/** True when the device is online. POS reports a string, not a boolean. */
export function isConnected(connectivity) {
  return connectivity?.internetConnected !== 'Disconnected';
}
