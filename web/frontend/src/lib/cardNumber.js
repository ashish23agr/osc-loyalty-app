/**
 * The Privilege Club card number, derived from the account id.
 *
 * This mirrors `App\Support\Members\MemberCardNumber::format()` on the server,
 * and it is the only place on this side that does. Everywhere a member payload
 * is available the server's `card_number` is used instead; this exists for the
 * audit log, where an entry carries a subject id and nothing else, and the
 * agreed UI shows a card number in the Target column.
 *
 * **Coupled to C11.** If OSC issues physical cards with their own numbering the
 * club number becomes a stored column, and this function has to go with it —
 * the audit log would otherwise keep showing a number nobody's card has.
 */
export function formatCardNumber(accountId) {
  const digits = String(accountId).padStart(8, '0');

  return `${digits.slice(0, -4)}-${digits.slice(-4)}`;
}
