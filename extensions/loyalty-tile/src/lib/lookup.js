/**
 * The lookup box (M1, C11, V7).
 *
 * One field, every identifier: email, name, postcode, the Privilege Club card
 * number and the legacy Dynamics one. The server's MemberSearch decides what a
 * term is; this only classifies it well enough to tell the assistant what the
 * till is about to look for, which is the difference between a search that
 * finds nothing and one that explains itself.
 */

const EMAIL = /@/;
const CLUB_CARD = /^\d{4}[- ]?\d{4}$/;
const LEGACY_CARD = /^D-?\d+$/i;
const POSTCODE = /^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i;

export function classify(term) {
  const trimmed = (term ?? '').trim();

  if (trimmed === '') {
    return 'empty';
  }

  if (EMAIL.test(trimmed)) {
    return 'email';
  }

  if (LEGACY_CARD.test(trimmed)) {
    return 'legacy_card';
  }

  if (CLUB_CARD.test(trimmed)) {
    return 'club_card';
  }

  if (POSTCODE.test(trimmed)) {
    return 'postcode';
  }

  return 'name';
}

export const LOOKUP_HINT = {
  empty: 'Search by email, name, postcode or card number',
  email: 'Searching by email address',
  legacy_card: 'Searching by Dynamics card number',
  club_card: 'Searching by Privilege Club card number',
  postcode: 'Searching by postcode',
  name: 'Searching by name',
};

export function hintFor(term) {
  return LOOKUP_HINT[classify(term)];
}

/**
 * A scan is typed input that arrived faster (V7).
 *
 * The Scanner API delivers whatever the barcode encodes, as a string, and it
 * goes through exactly the same lookup as something typed. That is right for a
 * card encoding its number verbatim and harmless otherwise — and if OSC's cards
 * turn out to encode something else, the mapping goes here and nowhere else.
 */
export function fromScan(scanned) {
  return (scanned ?? '').trim();
}

/** Should the tile offer a scan button at all? */
export function canScan(sources) {
  return Array.isArray(sources) && sources.length > 0;
}
